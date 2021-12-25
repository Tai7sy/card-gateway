<?php

namespace Gateway\Pay\DirectWeChat;

use Gateway\Pay\ApiInterface;
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
                Log::error('DirectWeChat wechat_certs error: ' . $exception->getMessage());
                if ($exception instanceof \GuzzleHttp\Exception\RequestException && $exception->hasResponse()) {
                    /** @var \Psr\Http\Message\ResponseInterface $response */
                    $response = $exception->getResponse();
                    Log::error('DirectWeChat wechat_certs error message: ' . $response->getBody());
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

            if (intval($config['payway']) === 1) {
                // Native

                $resp = $instance
                    ->chain('v3/pay/partner/transactions/native')
                    ->post(['json' => [
                        'sp_appid' => $config['app_id'], // 服务商申请的公众号appid。示例值：wx8888888888888888
                        'sp_mchid' => $config['merchant_id'], // 服务商申请的公众号appid。示例值：1230000109
                        'sub_appid' => $config['sub_app_id'], //  二级商户在开放平台申请的应用appid。示例值：wxd678efh567hg6999
                        'sub_mchid' => $config['sub_merchant_id'], //  二级商户的商户号，由微信支付生成并下发。示例值：1900000109
                        'out_trade_no' => $out_trade_no,
                        'description' => $subject,
                        'notify_url' => $this->url_notify,
                        'amount' => [
                            'total' => $amount_cent,
                            'currency' => 'CNY'
                        ],
                    ]]);

                $response = @json_decode($resp->getBody(), true);
                if (!$response || !isset($response['code_url'])) {
                    Log::error('DirectWeChat goPay error message#1: ' . $resp->getBody());
                    throw new \Exception('支付请求失败, 请检查日志 #1');
                }

                header('Location: /qrcode/pay/' . $out_trade_no . '/wechat?url=' . urlencode($response['code_url']));

            } else if (intval($config['payway']) === 2) {
                // JSAPI
                throw new \Exception('暂不支持 JSAPI');
            } else {

                throw new \Exception('暂不支持支付方式: ' . $config['payway']);
            }

        } catch (\Exception $e) {
            // 进行错误处理
            $error = $e->getMessage();

            if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
                $r = $e->getResponse();
                $error = $r->getBody() ?? $error;
            }

            Log::error('DirectWeChat goPay error message#2: ' . $error);
            if (config('app.debug')) {
                throw new \Exception($error);
            } else {
                throw new \Exception('支付请求失败, 请检查日志 #2');
            }
        }

        exit;
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
            $merchantId = $config['merchant_id'];

            // 根据通知的平台证书序列号，查询本地平台证书文件，
            $certs = $this->wechat_certs($config);
            if (!is_array($certs) || !isset($certs[$inWechatpaySerial])) {
                Log::error('DirectWeChat Notify: cert not found: ' . $inWechatpaySerial);
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

                Log::error('debug: ', $inBodyResourceArray);
            }


            return false;

        } else {
            // 直接支付成功

            // 用于payReturn支付返回页面第二种情况(传递了out_trade_no), 或者重新发起支付之前检查一下, 或者二维码支付页面主动请求
            // 主动查询交易结果
            if (!empty($config['out_trade_no'])) {
                $order_no = @$config['out_trade_no'];  //商户订单号

                // 进行一些查询逻辑
                $check_ret = [
                    'code' => 0,
                    'total_fee' => sprintf('%.2f', \App\Order::whereOrderNo($order_no)->first()->paid / 100), // 元为单位
                    'transaction_id' => date('YmdHis')
                ];

                // 如果检查通过
                if (@$check_ret['code'] === 0) {
                    $total_fee = (int)round((float)$check_ret['total_fee'] * 100);
                    $pay_trade_no = $check_ret['transaction_id']; //支付流水号
                    $successCallback($order_no, $total_fee, $pay_trade_no);
                    return true;
                }
                return false;
            }


            // 这里可能是payReturn支付返回页面的第一种情况, 支付成功后直接返回, config里面没有out_trade_no
            // 这里的URL, $_GET 里面可能有订单参数用于校验订单是否成功(参考支付宝的AliAop逻辑)
            if (1) { // 进行一些校验逻辑, 如果检查通过
                $order_no = $_REQUEST['out_trade_no']; // 本系统内订单号
                $total_fee = (int)round((float)$_REQUEST['total_fee'] * 100);
                $pay_trade_no = $_REQUEST['transaction_id']; //支付流水号
                $successCallback($order_no, $total_fee, $pay_trade_no);
                return true;
            }

            return false;
        }
        return false;
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

        try {
            $resp = $instance
                ->chain('v3/ecommerce/refunds/apply')
                ->post(['json' => [
                    'sp_appid' => $config['app_id'], // 服务商申请的公众号appid。示例值：wx8888888888888888
                    'sp_mchid' => $config['merchant_id'], // 服务商申请的公众号appid。示例值：1230000109
                    'sub_appid' => $config['sub_app_id'], //  二级商户在开放平台申请的应用appid。示例值：wxd678efh567hg6999
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
                Log::error('DirectWeChat refund error message#1: ' . $resp->getBody());
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

            Log::error('DirectWeChat refund error message#2: ' . $error);
            throw new \Exception('退款请求失败, 请检查日志 #2');
        }
    }

    /**
     * 上传文件 返回ID
     * @param $config
     * @param $file_path
     * @return string
     * @throws \Exception
     */
    function apply_upload($config, $file_path)
    {
        $client = $this->wechat_client($config);

        try {
            $media = new \WeChatPay\Util\MediaUtil($file_path);
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
                Log::error('DirectWeChat upload error message#1: ' . $resp->getBody());
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

            Log::error('DirectWeChat upload error message#2: ' . $error);
            if (config('app.debug')) {
                throw new \Exception($error);
            } else {
                throw new \Exception('上传文件失败, 请检查日志 #2');
            }
        }
    }


    /**
     * 二级商户进件
     * @param $config
     * @param string $out_request_no 服务商自定义的商户唯一编号
     * @param string $id_card_name 身份证姓名
     * @param string $id_card_number 身份证号码
     * @param string $id_card_valid_time 身份证有效期限 2026-06-06
     * @param string $id_card_front_file_path 身份证人像面照片
     * @param string $id_card_national_file_path 身份证国徽面照片
     *
     * @throws \Exception
     */
    function apply($config, $out_request_no, $id_card_name, $id_card_number, $id_card_valid_time, $id_card_front_file_path, $id_card_national_file_path)
    {
        $instance = $this->wechat_client($config);

        // 做一个匿名方法，供后续方便使用，$platformPublicKeyInstance 见初始化章节
        $encryptor = static function (string $msg) {
            return \WeChatPay\Crypto\Rsa::encrypt($msg, $this->wechat_platformPublicKeyInstance);
        };

        try {
            $resp = $instance
                ->chain('v3/ecommerce/applyments/')
                ->post([
                    'json' => [
                        'out_request_no' => $out_request_no, // 服务商自定义的商户唯一编号
                        'organization_type' => '2401', // 小微商户，指无营业执照的个人商家。
                        'id_doc_type' => 'IDENTIFICATION_TYPE_MAINLAND_IDCARD', // 主体为“小微/个人卖家”，可选择：身份证。
                        'id_card_info' => [
                            'id_card_copy' => $this->apply_upload($config, $id_card_front_file_path), // 身份证人像面照片
                            'id_card_national' => $this->apply_upload($config, $id_card_national_file_path), // 身份证国徽面照片
                            'id_card_name' => $encryptor($id_card_name),
                            'id_card_number' => $encryptor($id_card_number),
                            'id_card_valid_time' => $id_card_valid_time,
                        ],
                        'need_account_info' => 'true',
                        'account_info' => [
                            'bank_account_type' => '75', // 账户类型: 75-对私账户
                            'account_bank' => 'dd', // 开户银行: https://pay.weixin.qq.com/wiki/doc/apiv3_partner/terms_definition/chapter1_1_3.shtml
                            'account_name' => $id_card_name, // 开户名称
                            'bank_address_code' => 'dd' // 开户银行省市编码
                        ],

                        'sp_appid' => $config['app_id'], // 服务商申请的公众号appid。示例值：wx8888888888888888
                        'sp_mchid' => $config['merchant_id'], // 服务商申请的公众号appid。示例值：1230000109
                        'sub_appid' => $config['sub_app_id'], //  二级商户在开放平台申请的应用appid。示例值：wxd678efh567hg6999
                        'sub_mchid' => $config['sub_merchant_id'], //  二级商户的商户号，由微信支付生成并下发。示例值：1900000109
                        'out_trade_no' => $out_trade_no,
                        'description' => $subject,
                        'notify_url' => $this->url_notify,
                        'amount' => [
                            'total' => $amount_cent,
                            'currency' => 'CNY'
                        ],
                    ],
                    'headers' => [
                        // $platformCertificateSerial 见初始化章节
                        'Wechatpay-Serial' => $this->wechat_platformCertificateSerial,
                    ]]);

            $response = @json_decode($resp->getBody(), true);
            if (!$response || !isset($response['code_url'])) {
                Log::error('DirectWeChat apply error message#1: ' . $resp->getBody());
                throw new \Exception('进件失败, 请检查日志 #1');
            }

        } catch (\Exception $e) {
            // 进行错误处理
            $error = $e->getMessage();

            if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
                $r = $e->getResponse();
                $error = $r->getBody() ?? $error;
            }

            Log::error('DirectWeChat apply error message#2: ' . $error);
            if (config('app.debug')) {
                throw new \Exception($error);
            } else {
                throw new \Exception('进件失败, 请检查日志 #2');
            }
        }

    }
}