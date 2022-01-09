<?php

namespace Gateway\Pay\DirectWeChat;

use App\Library\CurlRequest;
use App\Library\Helper;
use Gateway\Pay\ApiInterface;
use GuzzleHttp\Psr7\LazyOpenStream;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Direct 直清渠道
 * Class Api
 * @package Gateway\Pay\Demo
 */
class Api implements ApiInterface
{
    //异步通知页面需要隐藏防止CC之类的验证导致返回失败
    private $pay_id = '';
    private $url_notify = '';
    private $url_return = '';
    private $wechat_client = null;
    private $wechat_platformPublicKeyInstance = null;
    private $wechat_platformCertificateSerial = null;

    public function __construct($id)
    {
        $this->pay_id = $id;
        $this->url_notify = SYS_URL_API . '/pay/notify/' . $id;
        $this->url_return = SYS_URL . '/pay/return/' . $id;
    }

    /**
     * Before `verifier` executing, decrypt and put the platform certificate(s) into the `$certs` reference.
     *
     * @param string $apiv3Key
     * @param array<string,?string> $certs
     *
     * @return callable(\Psr\Http\Message\ResponseInterface)
     */
    private static function wechat_certsInjector(string $apiv3Key, array &$certs)
    {
        return static function ($response) use ($apiv3Key, &$certs) {
            $body = (string)$response->getBody();
            /** @var object{data:array<object{encrypt_certificate:object{serial_no:string,nonce:string,associated_data:string}}>} $json */
            $json = \json_decode($body);
            $data = \is_object($json) && isset($json->data) && \is_array($json->data) ? $json->data : [];
            \array_map(static function ($row) use ($apiv3Key, &$certs) {
                $cert = $row->encrypt_certificate;
                $certs[$row->serial_no] = \WeChatPay\Crypto\AesGcm::decrypt($cert->ciphertext, $apiv3Key, $cert->nonce, $cert->associated_data);
            }, $data);

            return $response;
        };
    }

    /**
     * After `verifier` executed, wrote the platform certificate(s) onto disk.
     *
     * @param array<string,?string> $certs
     *
     * @return callable(\Psr\Http\Message\ResponseInterface)
     */
    private static function wechat_certsRecorder(array &$certs)
    {
        return static function ($response) use (&$certs) {
            $body = (string)$response->getBody();
            /** @var object{data:array<object{effective_time:string,expire_time:string:serial_no:string}>} $json */
            $json = \json_decode($body);
            $data = \is_object($json) && isset($json->data) && \is_array($json->data) ? $json->data : [];
            \array_walk($data, static function ($row, $index, $certs) {
                $serialNo = $row->serial_no;

                /*
                var_dump(
                    'Certificate #' . $index . ' {',
                    '    Serial Number: ' . ($serialNo),
                    '    Not Before: ' . (new \DateTime($row->effective_time))->format(\DateTime::W3C),
                    '    Not After: ' . (new \DateTime($row->expire_time))->format(\DateTime::W3C),
                    '    Content: ', '', $certs[$serialNo] ?? '', '',
                    '}'
                );
                */

                // \file_put_contents($outpath, $certs[$serialNo]);
            }, $certs);

            return $response;
        };
    }

    private function wechat_certs($config)
    {
        $apiv3Key = $config['api_key'];
        $merchantId = $config['merchant_id'];

        // 商户API私钥 apiclient_key.pem
        $merchantPrivateKeyFile = "-----BEGIN PRIVATE KEY-----\n" . wordwrap($config['cert_private_key'], 64, "\n", true) . "\n-----END PRIVATE KEY-----";
        $merchantPrivateKeyInstance = \WeChatPay\Crypto\Rsa::from($merchantPrivateKeyFile, \WeChatPay\Crypto\Rsa::KEY_TYPE_PRIVATE);
        // 商户API证书序列号
        $merchantCertificateSerial = $config['cert_serial'];

        $certs = Cache::get('wechat_' . $this->pay_id . 'platformCertificates');
        if (!$certs || !is_array($certs) || count($certs) === 0) {

            $certs = ['any' => null];
            $temp_ins = \WeChatPay\Builder::factory([
                'mchid' => $merchantId,
                'serial' => $merchantCertificateSerial,
                'privateKey' => $merchantPrivateKeyInstance,
                'certs' => &$certs,
            ]);

            /** @var \GuzzleHttp\HandlerStack $stack */
            $stack = $temp_ins->getDriver()->select(\WeChatPay\ClientDecoratorInterface::JSON_BASED)->getConfig('handler');
            // The response middle stacks were executed one by one on `FILO` order.
            $stack->after('verifier', \GuzzleHttp\Middleware::mapResponse(self::wechat_certsInjector($apiv3Key, $certs)), 'injector');
            $stack->before('verifier', \GuzzleHttp\Middleware::mapResponse(self::wechat_certsRecorder($certs)), 'recorder');

            $temp_ins->chain('v3/certificates')->getAsync(
                ['debug' => false]
            )->otherwise(static function ($exception) {
                Log::error('Pay.DirectWeChat wechat_certs error: ' . $exception->getMessage());
                if ($exception instanceof \GuzzleHttp\Exception\RequestException && $exception->hasResponse()) {
                    /** @var \Psr\Http\Message\ResponseInterface $response */
                    $response = $exception->getResponse();
                    Log::error('Pay.DirectWeChat wechat_certs error message: ' . $response->getBody());
                }

                throw new \Exception('微信证书下载失败, 请检查日志');
            })->wait();

            unset($certs['any']);
            Cache::put('wechat_' . $this->pay_id . 'platformCertificates', $certs);
        }
        return $certs;
    }

    /**
     * @param $config
     * @return \WeChatPay\BuilderChainable|null
     */
    private function wechat_client($config)
    {
        if ($this->wechat_client) {
            return $this->wechat_client;
        }

        $apiv3Key = $config['api_key'];
        $merchantId = $config['merchant_id'];

        // 商户API私钥 apiclient_key.pem
        $merchantPrivateKeyFile = "-----BEGIN PRIVATE KEY-----\n" . wordwrap($config['cert_private_key'], 64, "\n", true) . "\n-----END PRIVATE KEY-----";
        $merchantPrivateKeyInstance = \WeChatPay\Crypto\Rsa::from($merchantPrivateKeyFile, \WeChatPay\Crypto\Rsa::KEY_TYPE_PRIVATE);
        // 商户API证书序列号
        $merchantCertificateSerial = $config['cert_serial'];

        // 微信支付平台证书 apiclient_cert.pem
        $certs = $this->wechat_certs($config);
        foreach ($certs as $serial => $cert_content) {
            $certs[$serial] = \WeChatPay\Crypto\Rsa::from($cert_content, \WeChatPay\Crypto\Rsa::KEY_TYPE_PUBLIC);

            if (!$this->wechat_platformPublicKeyInstance) {
                $this->wechat_platformPublicKeyInstance = $certs[$serial];
                $this->wechat_platformCertificateSerial = $serial;
            }
        }

        // 构造一个 APIv3 客户端实例
        $this->wechat_client = \WeChatPay\Builder::factory([
            'mchid' => $merchantId,
            'serial' => $merchantCertificateSerial,
            'privateKey' => $merchantPrivateKeyInstance,
            'certs' => $certs
        ]);

        return $this->wechat_client;
    }


    const PAYWAY_NATIVE = 'NATIVE';
    const PAYWAY_JSAPI = 'JSAPI';


    /**
     * @param array $config
     * @param string $out_trade_no
     * @param string $subject
     * @param string $body
     * @param int $amount_cent
     * @throws \Exception
     */
    function goPay($config, $out_trade_no, $subject, $body, $amount_cent)
    {
        $instance = $this->wechat_client($config);

        try {
            $payway = $config['payway'];
            $openid = null;

            if (strpos(@$_SERVER['HTTP_USER_AGENT'], 'MicroMessenger') !== false) {
                $payway = self::PAYWAY_JSAPI; // 微信内部
                $pay_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://{$_SERVER['HTTP_HOST']}" . '/pay/' . $out_trade_no;
                $auth_url = 'https://open.weixin.qq.com/connect/oauth2/authorize?appid=' . $config['app_id'] . '&redirect_uri=' . urlencode($pay_url) . '&response_type=code&scope=snsapi_base#wechat_redirect';

                if (!isset($_GET['code'])) {
                    header('Location: ' . $auth_url);
                    exit;
                }

                $request_url = 'https://api.weixin.qq.com/sns/oauth2/access_token?appid=' . $config['app_id'] . '&secret=' . $config['app_secret'] . '&code=' . $_GET['code'] . '&grant_type=authorization_code';
                $ret = @json_decode(CurlRequest::get($request_url), true);
                if (!is_array($ret) || empty($ret['openid'])) {
                    if (isset($ret['errcode']) && $ret['errcode'] === 40163) {
                        // code been used, 已经用过(用户的刷新行为), 重新发起
                        header('Location: ' . $auth_url);
                        exit;
                    }
                    die('<h1>获取微信OPENID<br>错误信息: ' . (isset($ret['errcode']) ? $ret['errcode'] : $ret) . '<br>' . (isset($ret['errmsg']) ? $ret['errmsg'] : $ret) . '<br>请返回重试</h1>');
                }
                $openid = $ret['openid'];
            } else {
                // 如果在微信外部, 且支付方式是JSAPI, 二维码为当前页面
                if ($payway === self::PAYWAY_JSAPI) {
                    $pay_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://{$_SERVER['HTTP_HOST']}" . '/pay/' . $out_trade_no;
                    // $auth_url = 'https://open.weixin.qq.com/connect/oauth2/authorize?appid=' . $config['app_id'] . '&redirect_uri=' . urlencode($pay_url) . '&response_type=code&scope=snsapi_base#wechat_redirect';
                    // $auth_url 太复杂了以至于不能生成二维码....
                    header('Location: /qrcode/pay/' . $out_trade_no . '/wechat?url=' . urlencode($pay_url));
                    exit;
                }
            }

            if ($payway == self::PAYWAY_NATIVE) {
                // Native
                // https://pay.weixin.qq.com/wiki/doc/apiv3_partner/apis/chapter7_2_12.shtml
                $resp = $instance
                    ->chain('v3/pay/partner/transactions/native')
                    ->post(['json' => [
                        'sp_appid' => $config['app_id'], // 服务商申请的公众号appid。示例值：wx8888888888888888
                        'sp_mchid' => $config['merchant_id'], // 服务商商户号。示例值：1230000109
                        // 'sub_appid' => $config['sub_app_id'], //  二级商户在开放平台申请的应用appid。示例值：wxd678efh567hg6999
                        'sub_mchid' => $config['sub_merchant_id'], //  二级商户的商户号，由微信支付生成并下发。示例值：1900000109
                        'out_trade_no' => $out_trade_no,
                        'description' => $subject,
                        'notify_url' => $this->url_notify,
                        'amount' => [
                            'total' => $amount_cent,
                            'currency' => 'CNY'
                        ],
                        'settle_info' => [
                            'profit_sharing' => true,
                        ],
                    ]]);

                $response = @json_decode($resp->getBody(), true);
                if (!$response || !isset($response['code_url'])) {
                    Log::error('Pay.DirectWeChat goPay error message#1: ' . $resp->getBody());
                    throw new \Exception('支付请求失败, 请检查日志 #1');
                }

                header('Location: /qrcode/pay/' . $out_trade_no . '/wechat?url=' . urlencode($response['code_url']));

            } else if ($payway === self::PAYWAY_JSAPI) {
                // JSAPI
                // https://pay.weixin.qq.com/wiki/doc/apiv3_partner/apis/chapter7_2_2.shtml
                $resp = $instance
                    ->chain('v3/pay/partner/transactions/native')
                    ->post(['json' => [
                        'sp_appid' => $config['app_id'], // 服务商申请的公众号appid。示例值：wx8888888888888888
                        'sp_mchid' => $config['merchant_id'], // 服务商商户号。示例值：1230000109
                        // 'sub_appid' => $config['sub_app_id'], //  二级商户在开放平台申请的应用appid。示例值：wxd678efh567hg6999
                        'sub_mchid' => $config['sub_merchant_id'], //  二级商户的商户号，由微信支付生成并下发。示例值：1900000109
                        'out_trade_no' => $out_trade_no,
                        'description' => $subject,
                        'notify_url' => $this->url_notify,
                        'amount' => [
                            'total' => $amount_cent,
                            'currency' => 'CNY'
                        ],
                        'settle_info' => [
                            'profit_sharing' => true,
                        ],
                        'payer' => [
                            'sp_openid' => $openid // 用户在服务商appid下的唯一标识。
                        ],
                    ]]);

                $response = @json_decode($resp->getBody(), true);
                if (!$response || !isset($response['prepay_id'])) {
                    Log::error('Pay.DirectWeChat goPay error message#1: ' . $resp->getBody());
                    throw new \Exception('支付请求失败, 请检查日志 #1');
                }

                // 微信内部
                $params = [
                    'appId' => $config['app_id'],              // 公众号app_id，由商户传入
                    'timeStamp' => strval(time()),              // 时间戳，自1970年以来的秒数
                    'nonceStr' => md5(time() . 'nonceStr'), // 随机串
                    'package' => 'prepay_id=' . $response['prepay_id'],
                    'signType' => 'RSA',                        // 微信签名方式：
                ];

                $JSAPI_Signer = function ($params) use ($config) {
                    $string = $params['appId'] . "\n" . $params['timeStamp'] . "\n" . $params['nonceStr'] . "\n" . $params['package'] . "\n";

                    $merchantPrivateKeyFile = "-----BEGIN PRIVATE KEY-----\n" . wordwrap($config['cert_private_key'], 64, "\n", true) . "\n-----END PRIVATE KEY-----";
                    $merchantPrivateKeyInstance = \WeChatPay\Crypto\Rsa::from($merchantPrivateKeyFile, \WeChatPay\Crypto\Rsa::KEY_TYPE_PRIVATE);

                    return \WeChatPay\Crypto\Rsa::sign($string, $merchantPrivateKeyInstance);
                };
                $params['paySign'] = $JSAPI_Signer($params); // 微信签名

                header('Location: /qrcode/pay/' . $out_trade_no . '/wechat?url=' . urlencode(json_encode($params)));
            } else {
                throw new \Exception('暂不支持支付方式: ' . $config['payway']);
            }

        } catch (\Exception $e) {
            // 进行错误处理
            $error = $e->getMessage();

            if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
                $r = $e->getResponse();
                $error = $r->getBody() ?? $error;

                $json = @json_decode($error, true);
                if (is_array($json) && isset($json['message'])) {
                    throw new \Exception('支付请求失败: ' . $json['message']);
                }
            }

            Log::error('Pay.DirectWeChat goPay error message#2: ' . $error);
            throw new \Exception('支付请求失败, 请检查日志 #2');
        }

        exit;
    }


    /**
     * 请求分账
     * @param array $config
     * @param \App\Order $order
     * @return bool
     * @throws \Exception
     */
    function profit_sharing($config, $order)
    {
        $instance = $this->wechat_client($config);

        if (!isset($config['sub_merchant_id'])) {
            $config = array_merge($config, $order->pay_sub->$config);
        }

        try {

            try {
                // 先查询
                // https://pay.weixin.qq.com/wiki/doc/apiv3_partner/apis/chapter7_4_2.shtml
                $resp = $instance
                    ->chain('v3/ecommerce/profitsharing/orders')
                    ->get(['query' => [
                        'sub_mchid' => $config['sub_merchant_id'], // 分账出资的电商平台二级商户，填写微信支付分配的商户号。
                        'transaction_id' => $order->pay_trade_no,  // 微信支付订单号。
                        'out_order_no' => $order->order_no, // 商户系统内部的分账单号，在商户系统内部唯一（单次分账、多次分账、完结分账应使用不同的商户分账单号），同一分账单号多次请求等同一次。
                    ]]);

                // Log::debug('Pay.DirectWeChat profit_sharing.query response:' . $resp->getBody());
                $response = @json_decode($resp->getBody(), true);
                if (is_array($response) && isset($response['status'])) {
                    /*
                    PROCESSING：处理中
                    FINISHED：分账完成
                    */

                    // already submitted
                    // Log::debug('Pay.DirectWeChat profit_sharing.query already processed: ' . $order->order_no, $response);
                    if ($response['status'] === 'FINISHED') {
                        $order->pay_sub_profit_status = \App\Order::PAY_SUB_PROFIT_STATUS_FINISH;
                    } else {
                        $order->pay_sub_profit_status = \App\Order::PAY_SUB_PROFIT_STATUS_ING;
                    }
                    if (is_array($order->pay_sub_profit_info) && isset($order->pay_sub_profit_info['error'])) {
                        $temp = $order->pay_sub_profit_info;
                        unset($temp['error']);
                        $order->pay_sub_profit_info = $temp;
                    }
                    return true;
                }

            } catch (\Throwable $e) {
                // 进行错误处理
                $error = $e->getMessage();

                if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
                    $r = $e->getResponse();
                    $error = $r->getBody() ?? $error;
                }

                if (strpos($error, 'RESOURCE_NOT_EXISTS') !== FALSE) {
                    // 记录不存在 = 没有分账过, 正常情况
                } else {
                    Log::debug('Pay.DirectWeChat profit_sharing.query exception: ' . $error);
                }
            }

            // 请求分账
            // https://pay.weixin.qq.com/wiki/doc/apiv3_partner/apis/chapter7_4_1.shtml
            $resp = $instance
                ->chain('v3/ecommerce/profitsharing/orders')
                ->post(['json' => [
                    'appid' => $config['app_id'], // 服务商申请的公众号appid。示例值：wx8888888888888888
                    'sub_mchid' => $config['sub_merchant_id'], //  二级商户的商户号，由微信支付生成并下发。示例值：1900000109
                    'transaction_id' => $order->pay_trade_no,  // 微信支付订单号。
                    'out_order_no' => $order->order_no, // 商户系统内部的分账单号，在商户系统内部唯一（单次分账、多次分账、完结分账应使用不同的商户分账单号），同一分账单号多次请求等同一次。
                    'receivers' => [
                        [
                            'type' => 'MERCHANT_ID', // 分账接收方类型, 商户
                            'receiver_account' => $config['merchant_id'],
                            'amount' => $order->fee,
                            'description' => '订单手续费: ' . $order->order_no,
                        ]
                    ],
                    'finish' => true,
                ]]);

            $response = @json_decode($resp->getBody(), true);
            if (!$response || !isset($response['status'])) {
                Log::error('Pay.DirectWeChat profit_sharing error message#1: ' . $resp->getBody());
                throw new \Exception('分账请求失败, 请检查日志 #1');
            }

            if ($response['status'] === 'FINISHED') {
                $order->pay_sub_profit_status = \App\Order::PAY_SUB_PROFIT_STATUS_FINISH;
            } else {
                $order->pay_sub_profit_status = \App\Order::PAY_SUB_PROFIT_STATUS_ING;
            }
            if (is_array($order->pay_sub_profit_info) && isset($order->pay_sub_profit_info['error'])) {
                $temp = $order->pay_sub_profit_info;
                unset($temp['error']);
                $order->pay_sub_profit_info = $temp;
            }

            // Log::debug('Pay.DirectWeChat profit_sharing', ['order_no' => $order->order_no, '$response' => $response]);
            return true;

        } catch (\Exception $e) {
            // 进行错误处理
            $error = $e->getMessage();

            if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
                $r = $e->getResponse();
                $error = $r->getBody() ?? $error;
            }

            $order->pay_sub_profit_info = array_merge($order->pay_sub_profit_info ?? [], [
                'error' => $error
            ]);

            Log::error('Pay.DirectWeChat profit_sharing error message#2: ' . $error);
            throw new \Exception('分账请求失败, 请检查日志 #2');
        }
    }

    /**
     * @param $config
     * @param callable $successCallback
     * @return bool|string
     * @throws \Exception
     */
    function verify($config, $successCallback)
    {
        $isNotify = isset($config['isNotify']) && $config['isNotify'];
        if ($isNotify) {
            // 没有这一步

            $inWechatpaySignature = request()->header('Wechatpay-Signature');// 请根据实际情况获取
            $inWechatpayTimestamp = request()->header('Wechatpay-Timestamp');// 请根据实际情况获取
            $inWechatpaySerial = request()->header('Wechatpay-Serial');// 请根据实际情况获取
            $inWechatpayNonce = request()->header('Wechatpay-Nonce');// 请根据实际情况获取
            $inBody = file_get_contents('php://input'); // 请根据实际情况获取;

            $apiv3Key = $config['api_key'];

            // 根据通知的平台证书序列号，查询本地平台证书文件，
            $certs = $this->wechat_certs($config);
            if (!is_array($certs) || !isset($certs[$inWechatpaySerial])) {
                Log::error('Pay.DirectWeChat Notify: cert not found: ' . $inWechatpaySerial);
                return false;
            }

            $platformPublicKeyInstance = \WeChatPay\Crypto\Rsa::from($certs[$inWechatpaySerial], \WeChatPay\Crypto\Rsa::KEY_TYPE_PUBLIC);

            // 检查通知时间偏移量，允许5分钟之内的偏移
            $timeOffsetStatus = 300 >= abs(\WeChatPay\Formatter::timestamp() - (int)$inWechatpayTimestamp);
            $verifiedStatus = \WeChatPay\Crypto\Rsa::verify(
            // 构造验签名串
                \WeChatPay\Formatter::joinedByLineFeed($inWechatpayTimestamp, $inWechatpayNonce, $inBody),
                $inWechatpaySignature,
                $platformPublicKeyInstance
            );

            if ($timeOffsetStatus && $verifiedStatus) {
                // 转换通知的JSON文本消息为PHP Array数组
                $inBodyArray = (array)json_decode($inBody, true);
                // 使用PHP7的数据解构语法，从Array中解构并赋值变量
                ['resource' => [
                    'ciphertext' => $ciphertext,
                    'nonce' => $nonce,
                    'associated_data' => $aad
                ]] = $inBodyArray;
                // 加密文本消息解密
                $inBodyResource = \WeChatPay\Crypto\AesGcm::decrypt($ciphertext, $apiv3Key, $nonce, $aad);
                // 把解密后的文本转换为PHP Array数组
                $inBodyResourceArray = (array)json_decode($inBodyResource, true);
                // print_r($inBodyResourceArray);// 打印解密后的结果

                // Log::error('Pay.DirectWeChat verify.notify: ', $inBodyResourceArray);

                // 支付成功
                if ($inBodyArray['event_type'] === 'TRANSACTION.SUCCESS') {
                    $order_no = $inBodyResourceArray['out_trade_no'];
                    $total_fee = (int)$inBodyResourceArray['amount']['total'];
                    $pay_trade_no = $inBodyResourceArray['transaction_id']; //支付流水号
                    $successCallback($order_no, $total_fee, $pay_trade_no);
                    return true;
                }
            }

            return false;

        } else {

            // 用于payReturn支付返回页面第二种情况(传递了out_trade_no), 或者重新发起支付之前检查一下, 或者二维码支付页面主动请求
            // 主动查询交易结果
            if (!empty($config['out_trade_no'])) {
                $order_no = @$config['out_trade_no'];  //商户订单号
                /** @var \App\Order $order */
                $order = \App\Order::whereOrderNo($order_no)->firstOrFail();
                $config = array_merge($config, $order->pay_sub->config); // 需要从 order->pay_sub 获取 sub_merchant_id
                $instance = $this->wechat_client($config);

                try {
                    // 进行一些查询逻辑
                    // https://pay.weixin.qq.com/wiki/doc/apiv3_partner/apis/chapter7_2_5.shtml
                    // wechat driver chain has a bug, have to use getDriver->request instead
                    $resp = $instance->getDriver()->request('GET', 'v3/pay/partner/transactions/out-trade-no/' . $order_no, [
                        'query' => [
                            'sp_mchid' => $config['merchant_id'], // 服务商户号
                            'sub_mchid' => $config['sub_merchant_id'], //  二级商户的商户号，由微信支付生成并下发。示例值：1900000109
                        ]]);

                    $response = @json_decode($resp->getBody(), true);
                    if (!$response || !isset($response['trade_state'])) {
                        Log::error('Pay.DirectWeChat verify.query error message#1: ' . $resp->getBody());
                        return false;
                    }
                    /*
                    SUCCESS：支付成功
                    REFUND：转入退款
                    NOTPAY：未支付
                    CLOSED：已关闭
                    REVOKED：已撤销（付款码支付）
                    USERPAYING：用户支付中（付款码支付）
                    PAYERROR：支付失败(其他原因，如银行返回失败)
                    ACCEPT：已接收，等待扣款
                    */
                    if ($response['trade_state'] === 'SUCCESS') {
                        $total_fee = (int)$response['amount']['payer_total'];
                        $pay_trade_no = $response['transaction_id']; //支付流水号
                        $successCallback($order_no, $total_fee, $pay_trade_no);
                        return true;
                    }

                } catch (\Exception $e) {
                    // 进行错误处理
                    $error = $e->getMessage();

                    if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
                        $r = $e->getResponse();
                        $error = $r->getBody() ?? $error;
                    }

                    Log::error('Pay.DirectWeChat verify.query error message#2: ' . $error);
                    return false;
                }
            }


            // 这里可能是payReturn支付返回页面的第一种情况, 支付成功后直接返回, config里面没有out_trade_no
            // 这里的URL, $_GET 里面可能有订单参数用于校验订单是否成功(参考支付宝的AliAop逻辑)
            if (1) {
                // 进行一些校验逻辑, 如果检查通过
                // 微信没有pay_return
            }

            return false;
        }
    }

    /**
     * 退款操作
     * @param array $config 支付渠道配置
     * @param string $order_no 订单号
     * @param string $pay_trade_no 支付渠道流水号
     * @param int $amount_cent 金额/分
     * @return true|string true 退款成功  string 失败原因
     */
    function refund($config, $order_no, $pay_trade_no, $amount_cent)
    {
        $instance = $this->wechat_client($config);

        $could_refund = false;

        try {
            // 分账回退
            // https://pay.weixin.qq.com/wiki/doc/apiv3_partner/apis/chapter7_4_3.shtml
            // https://pay.weixin.qq.com/wiki/doc/apiv3_partner/apis/chapter7_4_4.shtml
            try {
                /** @var \App\Order $order */
                $order = \App\Order::whereOrderNo($order_no)->firstOrFail();

                $resp = $instance
                    ->chain('v3/ecommerce/profitsharing/returnorders')
                    ->post(['json' => [
                        'sub_mchid' => $config['sub_merchant_id'], //  二级商户的商户号，由微信支付生成并下发。示例值：1900000109
                        'out_order_no' => $order->order_no,  // 原发起分账请求时使用的商户系统内部的分账单号  ===>  直接用订单号
                        'out_return_no' => $order->order_no, // 生成回退单号，在商户后台唯一  ===>  直接用订单号
                        'return_mchid' => $config['merchant_id'], // 原分账请求中接收分账的商户号。
                        'amount' => $order->fee,
                        'description' => '订单退款，分账回退',
                    ]]);

                $response = @json_decode($resp->getBody(), true);
                if (!$response || !isset($response['result'])) {
                    Log::error('Pay.DirectWeChat refund.returnorders error message#1: ' . $resp->getBody());
                    throw new \Exception('退款(分账退回)请求失败, 请检查日志 #1');
                }

                if ($response['result'] === 'PROCESSING') {
                    // 需要查询分账结果
                } else if ($response['result'] === 'SUCCESS') {
                    $could_refund = true;
                } else {
                    Log::error('Pay.DirectWeChat refund.returnorders unknown status: ' . $resp->getBody());
                }

                /*
                PROCESSING：处理中
                SUCCESS：已成功
                FAIL：已失败
                */
            } catch (\Throwable $e) {
                // 进行错误处理
                $error = $e->getMessage();

                if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
                    $r = $e->getResponse();
                    $error = $r->getBody() ?? $error;
                }

                if (strpos($error, '不存在') !== FALSE) {
                    // 没有分账过
                    $could_refund = true;
                } else {
                    Log::error('Pay.DirectWeChat refund.returnorders error message#2: ' . $error);
                }
            }

            if (!$could_refund) {

                // 查询分账回退结果
                try {
                    /** @var \App\Order $order */
                    $order = $order ?? \App\Order::whereOrderNo($order_no)->firstOrFail();

                    $resp = $instance
                        ->chain('v3/ecommerce/profitsharing/returnorders')
                        ->get(['query' => [
                            'sub_mchid' => $config['sub_merchant_id'], //  二级商户的商户号，由微信支付生成并下发。示例值：1900000109
                            'out_order_no' => $order->order_no,  // 原发起分账请求时使用的商户系统内部的分账单号  ===>  直接用订单号
                            'out_return_no' => $order->order_no, // 生成回退单号，在商户后台唯一  ===>  直接用订单号
                        ]]);

                    $response = @json_decode($resp->getBody(), true);
                    if (!$response || !isset($response['result'])) {
                        Log::error('Pay.DirectWeChat refund.returnorders.query error message#1: ' . $resp->getBody());
                        throw new \Exception('退款(查询分账退回结果)请求失败, 请检查日志 #1');
                    }

                    /*
                    PROCESSING：处理中
                    SUCCESS：已成功
                    FAIL：已失败
                    */

                    if ($response['result'] === 'SUCCESS') {
                        $could_refund = true;
                    }

                } catch (\Throwable $e) {
                    // 进行错误处理
                    $error = $e->getMessage();

                    if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
                        $r = $e->getResponse();
                        $error = $r->getBody() ?? $error;
                    }
                    Log::error('Pay.DirectWeChat refund.returnorders.query error message#2: ' . $error);
                }
            }

            if (!$could_refund) {
                throw new \Exception('分账退回失败, 退款失败');
            }

            $resp = $instance
                ->chain('v3/ecommerce/refunds/apply')
                ->post(['json' => [
                    'sp_appid' => $config['app_id'], // 服务商申请的公众号appid。示例值：wx8888888888888888
                    'sp_mchid' => $config['merchant_id'], // 服务商商户号。示例值：1230000109
                    // 'sub_appid' => $config['sub_app_id'], //  二级商户在开放平台申请的应用appid。示例值：wxd678efh567hg6999
                    'sub_mchid' => $config['sub_merchant_id'], //  二级商户的商户号，由微信支付生成并下发。示例值：1900000109
                    'out_trade_no' => $order_no,  // 原支付交易对应的商户订单号。
                    'out_refund_no' => $pay_trade_no, // 商户系统内部的退款单号，商户系统内部唯一
                    'amount' => [
                        'refund' => $amount_cent, // 退款金额，币种的最小单位，只能为整数，不能超过原订单支付金额。
                        'total' => $amount_cent, // 原支付交易的订单总金额，币种的最小单位，只能为整数。
                        'currency' => 'CNY'
                    ],
                ]]);

            $response = @json_decode($resp->getBody(), true);
            if (!$response || !isset($response['refund_id'])) {
                Log::error('Pay.DirectWeChat refund error message#1: ' . $resp->getBody());
                throw new \Exception('退款请求失败, 请检查日志 #1');
            }

            return true;

        } catch (\Exception $e) {
            // 进行错误处理
            $error = $e->getMessage();

            if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
                $r = $e->getResponse();
                $error = $r->getBody() ?? $error;
            }

            Log::error('Pay.DirectWeChat refund error message#2: ' . $error);
            throw new \Exception('退款请求失败, 请检查日志 #2');
        }
    }

    /**
     * 上传文件 返回ID
     * @param $config
     * @param \Illuminate\Http\UploadedFile $file
     * @return string
     * @throws \Exception
     */
    function apply_upload($config, $file)
    {
        $client = $this->wechat_client($config);

        try {
            $media = new \WeChatPay\Util\MediaUtil(
                'temp.' . $file->getClientOriginalExtension(),
                new LazyOpenStream($file->getPathname(), 'rb'));

            // https://pay.weixin.qq.com/wiki/doc/apiv3/apis/chapter2_1_1.shtml
            $resp = $client
                ->chain('v3/merchant/media/upload')
                ->post([
                    'body' => $media->getStream(),
                    'headers' => [
                        'Accept' => 'application/json',
                        'content-type' => $media->getContentType(),
                    ]
                ]);

            $response = @json_decode($resp->getBody(), true);
            if (!$response || !isset($response['media_id'])) {
                Log::error('Pay.DirectWeChat upload error message#1: ' . $resp->getBody());
                throw new \Exception('上传文件失败, 请检查日志 #1');
            }
            return $response['media_id'];
        } catch (\Exception $e) {
            // 进行错误处理
            $error = $e->getMessage();

            if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
                $r = $e->getResponse();
                $error = $r->getBody() ?? $error;
            }

            Log::error('Pay.DirectWeChat upload error message#2: ' . $error);
            if (config('app.debug')) {
                throw $e;
            } else {
                throw new \Exception('上传文件失败, 请检查日志 #2');
            }
        }
    }


    /**
     * 二级商户进件
     * @param $config
     * @param string $out_request_no 服务商自定义的商户唯一编号
     * @param array $data 数据
     *
     * @return string
     * @throws \Exception
     */
    function apply($config, $out_request_no, $data)
    {
        $instance = $this->wechat_client($config);

        // 做一个匿名方法，供后续方便使用，$platformPublicKeyInstance 见初始化章节
        $encryptor = function (string $msg) {
            return \WeChatPay\Crypto\Rsa::encrypt($msg, $this->wechat_platformPublicKeyInstance);
        };

        try {
            //  // https://pay.weixin.qq.com/wiki/doc/apiv3_partner/apis/chapter7_1_1.shtml
            $resp = $instance
                ->chain('v3/ecommerce/applyments/')
                ->post([
                    'json' => [
                        'out_request_no' => $out_request_no, // 服务商自定义的商户唯一编号
                        'organization_type' => $data['organization_type'], // 小微商户，指无营业执照的个人商家。
                        'id_doc_type' => $data['id_doc_type'], // 主体为“小微/个人卖家”，可选择：身份证。
                        'id_card_info' => [
                            'id_card_copy' => $this->apply_upload($config, $data['id_card_info']['id_card_copy']), // 身份证人像面照片
                            'id_card_national' => $this->apply_upload($config, $data['id_card_info']['id_card_national']), // 身份证国徽面照片
                            'id_card_name' => $encryptor($data['id_card_info']['id_card_name']),
                            'id_card_number' => $encryptor($data['id_card_info']['id_card_number']),
                            'id_card_valid_time' => $data['id_card_info']['id_card_valid_time'],
                        ],
                        'need_account_info' => true,
                        'account_info' => [
                            'bank_account_type' => $data['account_info']['bank_account_type'], // 账户类型: 75-对私账户
                            'account_bank' => $data['account_info']['account_bank'], // 开户银行: https://pay.weixin.qq.com/wiki/doc/apiv3_partner/terms_definition/chapter1_1_3.shtml
                            'account_name' => $encryptor($data['account_info']['account_name']), // 开户名称
                            'bank_address_code' => $data['account_info']['bank_address_code'], // 开户银行省市编码
                            'bank_name' => $data['account_info']['bank_name'], // 开户银行全称
                            'account_number' => $encryptor($data['account_info']['account_number']), // 银行账户
                        ],

                        'contact_info' => [
                            'contact_type' => '65', // 1、主体为“小微/个人卖家 ”，可选择：65-经营者/法人。
                            'contact_name' => $encryptor($data['id_card_info']['id_card_name']),
                            'contact_id_card_number' => $encryptor($data['id_card_info']['id_card_number']), // 超级管理员身份证件号码
                            'mobile_phone' => $encryptor($data['contact_info']['mobile_phone']),  // 超级管理员手机
                        ],

                        // 店铺信息
                        'sales_scene_info' => [
                            'store_name' => $data['sales_scene_info']['store_name'], // 请填写店铺全称。
                            'store_url' => $data['sales_scene_info']['store_url'], // 店铺二维码or店铺链接二选一必填。
                        ],

                        'merchant_shortname' => $data['merchant_shortname'], // 商户简称
                    ],
                    'headers' => [
                        // $platformCertificateSerial 见初始化章节
                        'Wechatpay-Serial' => $this->wechat_platformCertificateSerial,
                    ]]);

            $response = @json_decode($resp->getBody(), true);
            //  {"applyment_id":2000002239320568,"out_request_no":"202112261904463446"}
            if (!$response || !isset($response['applyment_id'])) {
                Log::error('Pay.DirectWeChat apply error message#1: ' . $resp->getBody());
                throw new \Exception('进件失败, 请检查日志 #1');
            }

            return (string)$response['applyment_id'];

        } catch (\Exception $e) {
            // 进行错误处理
            $error = $e->getMessage();

            if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
                $r = $e->getResponse();
                $error = $r->getBody() ?? $error;
            }

            Log::error('Pay.DirectWeChat apply error message#2: ' . $error);
            if (config('app.debug')) {
                throw $e;
            } else {
                throw new \Exception('进件失败, 请检查日志 #2');
            }
        }

    }

    /**
     * 进件查询
     * @param array $config
     * @param string $request_no [系统内]本地唯一商户ID
     * @param string $applyment_id [微信内]微信支付申请单号
     * @return mixed
     * @throws \Exception
     */
    function apply_query($config, $request_no = null, $applyment_id = null)
    {
        $instance = $this->wechat_client($config);

        try {
            // 查询申请状态API
            // https://pay.weixin.qq.com/wiki/doc/apiv3_partner/apis/chapter7_1_2.shtml
            $resp = $instance
                ->chain('v3/ecommerce/applyments/' . $applyment_id)
                ->get();

            $response = @json_decode($resp->getBody(), true);
            //  {"applyment_id":2000002239320568,"out_request_no":"202112261904463446"}
            if (!$response || !isset($response['applyment_id'])) {
                Log::error('Pay.DirectWeChat apply_query error message#1: ' . $resp->getBody());
                throw new \Exception('查询进件状态失败, 请检查日志 #1');
            }

            /*
            CHECKING：资料校验中
            ACCOUNT_NEED_VERIFY：待账户验证
            AUDITING：审核中
            REJECTED：已驳回
            NEED_SIGN：待签约
            FINISH：完成
            FROZEN：已冻结
            */
            // Log::debug('apply_query', $response);

            return $response;

        } catch (\Exception $e) {
            // 进行错误处理
            $error = $e->getMessage();

            if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
                $r = $e->getResponse();
                $error = $r->getBody() ?? $error;
            }

            Log::error('Pay.DirectWeChat apply_query error message#2: ' . $error);
            throw new \Exception('查询进件状态失败, 请检查日志 #2');
        }
    }

}