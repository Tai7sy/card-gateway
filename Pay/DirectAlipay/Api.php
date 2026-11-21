<?php

namespace Gateway\Pay\DirectAlipay;

use Alipay\Exception\AlipayErrorResponseException;
use Alipay\Request\AlipayRequest;
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

    /** @var \Alipay\AopClient */
    private $alipay_client = null;

    public function __construct($id)
    {
        $this->pay_id = $id;
        $this->url_notify = SYS_URL_API . '/pay/notify/' . $id;
        $this->url_return = SYS_URL . '/pay/return/' . $id;
    }

    private function alipay_client($config)
    {
        if ($this->alipay_client === null) {
            $keyPair = \Alipay\Key\AlipayKeyPair::create(
                "-----BEGIN RSA PRIVATE KEY-----\n" . wordwrap($config['merchant_private_key'], 64, "\n", true) . "\n-----END RSA PRIVATE KEY-----",
                "-----BEGIN PUBLIC KEY-----\n" . wordwrap($config['alipay_public_key'], 64, "\n", true) . "\n-----END PUBLIC KEY-----"
            );
            $this->alipay_client = new \Alipay\AopClient($config['app_id'], $keyPair);
        }
        return $this->alipay_client;
    }

    private function exec(AlipayRequest $request)
    {
        $params = $this->alipay_client->build($request);
        $response = $this->alipay_client->request($params);

        if ($response->isSuccess() === false) {
            $error = $response->getError();

            $code = $error['code'] ?? 0;
            $message = $error['msg'] ?? '';
            if (isset($error['sub_code']))
                $message .= ', ' . $error['sub_code'] . ', ';
            if (isset($error['sub_msg']))
                $message .= $error['sub_msg'];

            // Log::debug('Pay.DirectAlipay.query exec error:', $error);
            throw new \Exception($message, $code);
        }

        return $response->getData();
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
        $amount = sprintf('%.2f', $amount_cent / 100); //支付宝元为单位
        $client = $this->alipay_client($config);

        $biz_content = [
            'out_trade_no' => $out_trade_no, // 商户网站唯一订单号 这里一定要保证唯一 支付宝那里没有校验
            'total_amount' => $amount, // 订单总金额，单位为元，精确到小数点后两位，取值范围 [0.01,100000000]
            'subject' => $subject, // 商品的标题 / 交易标题 / 订单标题 / 订单关键字等

            'sub_merchant' => [
                'merchant_id' => $config['sub_merchant_id'], // 二级商户ID
            ],
            'settle_info' => [
                // 二级商户结算目标账户信息
                'settle_detail_infos' => [
                    [
                        'trans_in_type' => 'defaultSettle',
                        'amount' => $amount,
                    ]
                ]
            ],
        ];

        if ($config['payway'] === 'mobile') {

            if (!Helper::is_mobile()) {
                $pay_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://{$_SERVER['HTTP_HOST']}" . '/pay/' . $out_trade_no;
                header('location: /qrcode/pay/' . $out_trade_no . '/aliqr?url=' . urlencode($pay_url));
                exit;
            }

            $biz_content['product_code'] = 'QUICK_WAP_WAY';
            $request = \Alipay\AlipayRequestFactory::create('alipay.trade.wap.pay', [
                'return_url' => $this->url_return,
                'notify_url' => $this->url_notify,
                'biz_content' => $biz_content
            ]);
            $result = $client->pageExecuteUrl($request);

            header('location: ' . $result);

        } elseif ($config['payway'] === 'f2f') {
            $request = \Alipay\AlipayRequestFactory::create('alipay.trade.precreate', [
                'notify_url' => $this->url_notify,
                'biz_content' => $biz_content,
            ]);
            $result = $this->exec($request);
            header('location: /qrcode/pay/' . $out_trade_no . '/aliqr?url=' . urlencode($result['qr_code']));
        } elseif ($config['payway'] === 'pc') {

            $biz_content['product_code'] = 'FAST_INSTANT_TRADE_PAY';
            $request = \Alipay\AlipayRequestFactory::create('alipay.trade.page.pay', [
                'return_url' => $this->url_return,
                'notify_url' => $this->url_notify,
                'biz_content' => $biz_content,
            ]);
            $result = $client->pageExecuteUrl($request);
            header('location: ' . $result);
        } else {
            throw new \Exception('不支持的支付方式:' . $config['payway']);
        }

        exit;
    }

    /**
     * 请求分账, 返回成功 or 抛出异常
     * @param array $config
     * @param \App\Order $order
     * @return true
     * @throws \Exception
     */
    function profit_sharing($config, $order)
    {
        $client = $this->alipay_client($config);

        if (!isset($config['sub_merchant_id'])) {
            $config = array_merge($config, $order->pay_sub->$config);
        }

        // 用户支付成功，支付宝不主动发起结算，订单需要先结算一下才可以进行分账等操作
        // https://opendocs.alipay.com/open/direct-payment/gkvknf
        try {
            $request = \Alipay\AlipayRequestFactory::create('alipay.trade.settle.confirm', [
                'biz_content' => [
                    'out_request_no' => 'confirm_' . $order->order_no,
                    'trade_no' => $order->pay_trade_no,
                    'settle_info' => [
                        'settle_detail_infos' => [
                            [
                                'amount' => sprintf('%.2f', $order->paid / 100),
                                'trans_in_type' => 'defaultSettle'
                            ]
                        ]
                    ],
                    'extend_params' => [
                        'royalty_freeze' => 'true', // 是否进行资金冻结，用于后续分账，true表示冻结，false或不传表示不冻结
                    ]
                ]
            ]);
            $response = $this->exec($request);
            Log::debug('Pay.DirectAlipay settle.confirm: ' . json_encode($response));

            if (!is_array($response) || !isset($response['settle_amount'])) {
                Log::error('Pay.DirectAlipay settle.confirm error message#1: ' . json_encode($response));
                throw new \Exception('确认结算失败, 请检查日志 #1');
            }

        } catch (\Exception $e) {
            // 进行错误处理
            $error = $e->getCode() . ', ' . $e->getMessage();

            if (strpos($error, 'ACQ.ILLEGAL_SETTLE_STATE') !== FALSE) {
                // 已经结算过
            } else {
                $order->pay_sub_profit_status = \App\Order::PAY_SUB_PROFIT_STATUS_FAILED;
                $order->pay_sub_profit_info = array_merge($order->pay_sub_profit_info ?? [], [
                    'error' => $error
                ]);

                Log::error('Pay.DirectAlipay settle.confirm error message#2: ' . $error);
                throw new \Exception('确认结算失败, 请检查日志 #2');
            }

        }

        try {

            // 查询是否已经分账过
            // https://opendocs.alipay.com/open/02o6e0
            if ($order->pay_sub_profit_info && isset($order->pay_sub_profit_info['settle_no'])) {
                try {
                    $request = \Alipay\AlipayRequestFactory::create('alipay.trade.order.settle.query', [
                        'biz_content' => [
                            'settle_no' => $order->pay_sub_profit_info['settle_no'],
                        ]
                    ]);
                    $response = $this->exec($request);
                    Log::debug('Pay.DirectAlipay profit_sharing.query', ['order_no' => $order->order_no, '$response' => $response]);

                    if (is_array($response) && isset($response['royalty_detail_list']) && count($response['royalty_detail_list'])) {
                        $royalty_detail = $response['royalty_detail_list'][0];
                        // 分账状态，SUCCESS成功，FAIL失败，PROCESSING处理中
                        if ($royalty_detail['state'] === 'SUCCESS') {
                            $order->pay_sub_profit_status = \App\Order::PAY_SUB_PROFIT_STATUS_FINISH;

                            if (is_array($order->pay_sub_profit_info) && isset($order->pay_sub_profit_info['error'])) {
                                $temp = $order->pay_sub_profit_info;
                                unset($temp['error']);
                                $order->pay_sub_profit_info = $temp;
                            }

                        } elseif ($royalty_detail['state'] === 'PROCESSING') {
                            $order->pay_sub_profit_status = \App\Order::PAY_SUB_PROFIT_STATUS_ING;
                        } else {
                            $order->pay_sub_profit_status = \App\Order::PAY_SUB_PROFIT_STATUS_FAILED;
                            $order->pay_sub_profit_info = array_merge($order->pay_sub_profit_info ?? [], [
                                'error' => $royalty_detail['error_code'] . ':' . $royalty_detail['error_desc']
                            ]);
                        }

                        return true;
                    }

                } catch (\Exception $e) {
                    // 进行错误处理
                    $error = $e->getCode() . ', ' . $e->getMessage();
                    if (strpos($error, 'RESOURCE_NOT_EXISTS') !== FALSE) {
                        // 记录不存在 = 没有分账过, 正常情况
                    } else {
                        Log::debug('Pay.DirectAlipay profit_sharing.query exception: ' . $error);
                    }
                }
            }

            // 开始分账
            // https://opendocs.alipay.com/open/028xqz
            $request = \Alipay\AlipayRequestFactory::create('alipay.trade.order.settle', [
                'biz_content' => [
                    'out_request_no' => 'fee_' . $order->order_no,
                    'trade_no' => $order->pay_trade_no,

                    'royalty_parameters' => [
                        [
                            // 'royalty_type' => 'transfer', // 普通分账为：transfer;
                            // 'trans_out_type' => 'userId', // 支出方账户类型。
                            // 'trans_out' => '',

                            'trans_in_type' => 'userId',  // 收入方账户类型。
                            'trans_in' => $config['user_id'], // 收入方的支付宝账号对应的支付宝唯一用户号，以2088开头的纯16位数字。
                            'amount' => sprintf('%.2f', $order->fee / 100), // 分账的金额，单位为元
                            'desc' => '订单手续费: ' . $order->order_no,
                            'royalty_scene' => '平台服务费',
                        ]
                    ],

                    'extend_params' => [
                        'royalty_finish' => 'true', // 代表该交易分账是否完结
                    ],

                    'royalty_mode' => 'sync', // 同步模式
                ]
            ]);
            $response = $this->exec($request);
            Log::debug('Pay.DirectAlipay profit_sharing', ['order_no' => $order->order_no, '$response' => $response]);

            if (!is_array($response) || !isset($response['trade_no'])) {
                Log::error('Pay.DirectAlipay profit_sharing error message#1: ' . json_encode($response));
                throw new \Exception('分账请求失败, 请检查日志 #1');
            }

            if ($response['msg'] === 'Success') {
                $order->pay_sub_profit_status = \App\Order::PAY_SUB_PROFIT_STATUS_ING;
                $order->pay_sub_profit_info = array_merge($order->pay_sub_profit_info ?? [], [
                    'settle_no' => $response['settle_no'], // 支付宝分账单号，可以根据该单号查询单次分账请求执行结果
                ]);
            }

            return true;

        } catch (\Exception $e) {
            // 进行错误处理
            $error = $e->getCode() . ', ' . $e->getMessage();
            $order->pay_sub_profit_info = array_merge($order->pay_sub_profit_info ?? [], [
                'error' => $error
            ]);

            Log::error('Pay.DirectAlipay profit_sharing error message#2: ' . $error);
            if (config('app.debug')) {
                throw $e;
            } else {
                throw new \Exception('分账请求失败, 请检查日志 #2');
            }
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
        $client = $this->alipay_client($config);

        if ($isNotify) {
            // post
            // {"gmt_create":"2019-03-03 11:25:52","charset":"UTF-8","seller_email":"jyhnetworks@gmail.com","subject":"20190303112547kNaB9",
            //  "sign":"xxx",
            //  "buyer_id":"2088912871498663","invoice_amount":"0.01","notify_id":"2019030300222112558098661047087964",
            //  "fund_bill_list":"[{\"amount\":\"0.01\",\"fundChannel\":\"ALIPAYACCOUNT\"}]",
            //  "notify_type":"trade_status_sync","trade_status":"TRADE_SUCCESS",
            //  "receipt_amount":"0.01","buyer_pay_amount":"0.01","app_id":"2019012163100265","sign_type":"RSA2","seller_id":"2088431220893143",
            //  "gmt_payment":"2019-03-03 11:25:58","notify_time":"2019-03-03 11:25:58","version":"1.0","out_trade_no":"20190303112547kNaB9",
            //  "total_amount":"0.01","trade_no":"2019030322001498661018630713","auth_app_id":"2019012163100265","buyer_logon_id":"312***@qq.com","point_amount":"0.00"}

            if ($client->verify($_POST)) {
                if ($_POST['trade_status'] === 'TRADE_SUCCESS') {

                    // $trade_no = $_POST['trade_no'];//支付宝交易号
                    // $total_fee = (int)round($_POST['total_amount'] * 100);
                    // $successCallback($_POST['out_trade_no'], $total_fee, $trade_no);

                    // re query, when appKey is leaked
                    $request = \Alipay\AlipayRequestFactory::create('alipay.trade.query', [
                        'biz_content' => [
                            'out_trade_no' => $_POST['out_trade_no'], // 商户网站唯一订单号
                        ],
                    ]);

                    $result = [];
                    try {
                        $result = $this->exec($request);
                    } catch (\Throwable $e) {
                        $error = $e->getCode() . ', ' . $e->getMessage();
                        if (strpos($error, '.TRADE_NOT_EXIST') !== FALSE) {
                            return false;
                        }
                        Log::error('Pay.DirectAlipay.query exception: ' . $error);
                    }

                    if (isset($result['trade_status']) && $result['trade_status'] === 'TRADE_SUCCESS') {
                        $trade_no = $result['trade_no'];//支付宝交易号
                        $total_fee = (int)round($result['total_amount'] * 100);
                        $successCallback($result['out_trade_no'], $total_fee, $trade_no);
                    }
                }
            } else {
                Log::error('Pay.DirectAlipay.goPay.verify Error: ' . json_encode($_POST));
            }
            echo 'success'; // 输出 `success`，否则支付宝服务器将会重复通知
            exit;
        }

        if (!empty($config['out_trade_no'])) {
            // payReturn(带订单号) or 当面付主动查询 or 查询页面点击支付 先查询一下
            $request = \Alipay\AlipayRequestFactory::create('alipay.trade.query', [
                'biz_content' => [
                    'out_trade_no' => $config['out_trade_no'], // 商户网站唯一订单号
                ],
            ]);
            try {
                $result = $this->exec($request);
            } catch (\Throwable $e) {
                $error = $e->getCode() . ', ' . $e->getMessage();
                if (strpos($error, '.TRADE_NOT_EXIST') !== FALSE) {
                    return false;
                }
                Log::error('Pay.DirectAlipay.query exception: ' . $error . ', json:' . json_encode($e));
                return false;
            }
            if ($result['trade_status'] === 'TRADE_SUCCESS') {
                $trade_no = $result['trade_no'];//支付宝交易号
                $total_fee = (int)round($result['total_amount'] * 100);
                $successCallback($result['out_trade_no'], $total_fee, $trade_no);
                return true;
            }
        } else {
            // PC or MOBILE 支付完返回的地址
            // http://127.0.0.4/pay/return/4?charset=UTF-8&
            // out_trade_no=20190303161401iqDUK&method=alipay.trade.page.pay.return&total_amount=0.01&
            // sign=xxx&trade_no=2019030322001498661018828681&auth_app_id=2019012163100265&version=1.0&app_id=2019012163100265&
            // sign_type=RSA2&seller_id=2088431220893143&timestamp=2019-03-03+16%3A14%3A48
            if (!isset($_GET['out_trade_no']) || !isset($_GET['total_amount'])) {
                return false;
            }
            $passed = $client->verify($_GET);
            if (!$passed) {
                Log::error('Pay.DirectAlipay.verify Error: 支付宝签名校验失败', ['$_GET' => $_GET]);
                return false;
            }

            // re query, when appKey is leaked
            $request = \Alipay\AlipayRequestFactory::create('alipay.trade.query', [
                'biz_content' => [
                    'out_trade_no' => $_GET['out_trade_no'], // 商户网站唯一订单号
                ],
            ]);
            try {
                $result = $this->exec($request);
            } catch (\Throwable $e) {
                $error = $e->getCode() . ', ' . $e->getMessage();
                if (strpos($error, '.TRADE_NOT_EXIST') !== FALSE) {
                    return false;
                }
                Log::error('Pay.DirectAlipay.query exception: ' . $error);
                return false;
            }
            if ($result['trade_status'] === 'TRADE_SUCCESS') {
                $trade_no = $result['trade_no'];//支付宝交易号
                $total_fee = (int)round($result['total_amount'] * 100);
                $successCallback($result['out_trade_no'], $total_fee, $trade_no);
                return true;
            }
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
        /** @var \App\Order $order */
        $order = \App\Order::whereOrderNo($order_no)->firstOrFail();

        // https://opendocs.alipay.com/open/direct-payment/vtd23a#%E9%80%80%E6%AC%BE%26%E9%80%80%E5%88%86%E8%B4%A6%26%E9%80%80%E8%A1%A5%E5%B7%AE
        $request = \Alipay\AlipayRequestFactory::create('alipay.trade.refund', [
            'biz_content' => [
                'out_trade_no' => $order_no, // 订单支付时传入的商户订单号
                'refund_amount' => sprintf('%.2f', $amount_cent / 100),
                'refund_reason' => '订单退款#' . $order_no,
                'refund_royalty_parameters' => [
                    [
                        'royalty_type' => 'transfer',
                        'trans_out_type' => 'userId',
                        'trans_out' => $config['user_id'],
                        'amount' => sprintf('%.2f', $order->fee / 100),
                        'desc' => '手续费退回#' . $order_no,
                    ]
                ]
            ]]);

        try {
            $this->alipay_client($config);
            $result = $this->exec($request);
            Log::debug('Pay.DirectAlipay refund: ' . json_encode($result));
            if (!isset($result['code']) || $result['code'] !== '10000') { // string 类型
                throw new \Exception($result['sub_msg'], $result['code']);
            }
            return true;
        } catch (\Throwable $e) {
            $error = $e->getCode() . ', ' . $e->getMessage();
            Log::error('Pay.DirectAlipay refund error message: ' . $error);
            return $e->getMessage();
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
        $client = $this->alipay_client($config);

        try {
            $request = \Alipay\AlipayRequestFactory::create('ant.merchant.expand.indirect.image.upload', [
                'image_type' => $file->getClientOriginalExtension(),
            ]);

            $params = $client->build($request);
            $params['image_content'] = new \CURLFile($file->getPathname(), $file->getClientMimeType(), $file->getClientOriginalName());
            $response = $client->request($params)->getData();


            if (!is_array($response) || !isset($response['image_id'])) {
                Log::error('Pay.DirectAlipay upload error message#1: ' . json_encode($response));
                throw new \Exception('上传文件失败, 请检查日志 #1');
            }
            return $response['image_id'];
        } catch (\Exception $e) {
            // 进行错误处理
            $error = $e->getCode() . ', ' . $e->getMessage();

            Log::error('Pay.DirectAlipay upload error message#2: ' . $error);
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
     * @param string $external_id 服务商自定义的商户唯一编号
     * @param array $data 数据
     *
     * @return string
     * @throws \Exception
     */
    function apply($config, $external_id, $data)
    {
        $client = $this->alipay_client($config);

        try {
            $query_info = [];
            $is_modify = false;
            try {
                $query_info = $this->apply_query($config, $external_id, null);
                $is_modify = true;
            } catch (\Throwable $e) {
            }

            if ($is_modify && $query_info['status'] == -1) {
                // 已经失败了
                throw new \Exception($query_info['reason']);
            }

            // Log::debug('Pay.DirectAlipay apply: $is_modify: ' . json_encode($is_modify));

            // ant.merchant.expand.indirect.zft.create(直付通二级商户创建)
            // https://opendocs.alipay.com/open/028xr0
            $request = \Alipay\AlipayRequestFactory::create($is_modify ? 'ant.merchant.expand.indirect.zft.modify' : 'ant.merchant.expand.indirect.zft.create', [
                'biz_content' => [
                    'external_id' => $external_id, // 服务商自定义的商户唯一编号
                    'name' => $data['name'], // 进件的二级商户名称。一般情况下要与证件的名称相同。
                    'alias_name' => $data['alias_name'], // 商户别名。支付宝账单中的商户名称会展示此处设置的别名
                    'merchant_type' => $data['merchant_type'], // 商户类型

                    // 商户类别码mcc，参见https://gw.alipayobjects.com/os/bmw-prod/b28421ce-0ddf-422f-9e9c-c2c3c7f30c73.xlsx
                    // 7413	网络虚拟	互联网服务	其他在线应用或综合类
                    'mcc' => '7413',
                    'cert_no' => $data['cert_no'],     // 商户证件编号
                    'cert_type' => $data['cert_type'], // 商户证件类型

                    // 'cert_image' => $data['cert_image'],
                    // 'legal_name' => $data['name'],          // 法人名称。非个人商户类型必填
                    // 'legal_cert_no' =>  $data['cert_no'],   // 法人身份证号。非个人商户类型必填

                    'legal_cert_front_image' => $data['legal_cert_front_image'], // 法人身份证正面url
                    'legal_cert_back_image' => $data['legal_cert_back_image'],   // 法人身份证反面url

                    'contact_infos' => [
                        [
                            'name' => $data['name'],
                            'mobile' => $data['contact_infos']['mobile'],
                        ]
                    ],

                    'service' => [
                        'wap支付', '电脑支付'
                    ],

                    'alipay_logon_id' => $data['alipay_logon_id'], // 结算支付宝账号。
                    'binding_alipay_logon_id' => $data['alipay_logon_id'], // 签约支付宝账户，用于协议确认，及后续二级商户增值产品服务签约时使用。

                    'sites' => [
                        [
                            'site_type' => '01',                        // 网站：01
                            'site_name' => $data['sites']['site_name'], // 站点名称
                            'site_url' => $data['sites']['site_url']    // 站点地址
                        ]
                    ],

                    // 默认结算规则。
                    'default_settle_rule' => [
                        'default_settle_type' => 'alipayAccount',
                        'default_settle_target' => $data['alipay_logon_id']
                    ]
                ]
            ]);
            $response = $this->exec($request);

            if (!is_array($response) || !isset($response['order_id'])) {
                Log::error('Pay.DirectAlipay apply error message#1: ' . json_encode($response));
                throw new \Exception('进件失败, 请检查日志 #1');
            }

            return (string)$response['order_id'];

        } catch (\Alipay\Exception\AlipayErrorResponseException $e) {
            // 进行错误处理
            $error = $e->getCode() . ', ' . $e->getMessage();

            Log::error('Pay.DirectAlipay apply error message#2: ' . $error);
            if (config('app.debug')) {
                throw $e;
            } else {
                throw new \Exception('进件失败, 请检查日志 #2');
            }
        }
    }


    /**
     * 直付通商户入驻进度查询
     * @param array $config
     * @param string $external_id [系统内] 进件申请时的外部商户id，与order_id二选一必填
     * @param string $order_id [支付宝内]申请单id。
     * @return array
     * @throws \Exception
     */
    function apply_query($config, $external_id = null, $order_id = null)
    {
        $client = $this->alipay_client($config);

        try {

            // ant.merchant.expand.indirect.zft.create(直付通二级商户创建)
            // https://opendocs.alipay.com/open/028xr0

            $data = [];
            if ($order_id !== null) {
                $data['order_id'] = $order_id;       // 申请单id。
            } else if ($external_id !== null) {
                $data['external_id'] = $external_id; // 进件申请时的外部商户id，与order_id二选一必填
            }
            // Log::debug('Pay.DirectAlipay apply_query, $request: ' . json_encode($data));

            $request = \Alipay\AlipayRequestFactory::create('ant.merchant.expand.indirect.zftorder.query', [
                'biz_content' => $data
            ]);
            $response = $this->exec($request);
            // Log::debug('Pay.DirectAlipay apply_query, $response: ' . json_encode($response));

            if (!is_array($response) || !isset($response['orders']) || !count($response['orders'])) {
                Log::error('Pay.DirectAlipay apply_query error message#1: ' . json_encode($response));
                throw new \Exception('查询进件状态失败, 请检查日志 #1');
            }

            $order = $response['orders'][0];

            /*
            CREATE：已发起二级商户确认、SKIP：无需确认、FAIL：签约失败、NOT_CONFIRM：商户未确认、FINISH签约完成
            */
            // Log::debug('Pay.DirectAlipay apply_query: ', $response);

            return $order;

        } catch (\Alipay\Exception\AlipayErrorResponseException $e) {
            // 进行错误处理
            $error = $e->getCode() . ', ' . $e->getMessage();
            if (strpos($error, '申请单不存在') !== FALSE) {
                throw new \Exception('申请单不存在');
            }

            Log::error('Pay.DirectAlipay apply_query error message#2: ' . $error);
            if (config('app.debug')) {
                throw $e;
            } else {
                throw new \Exception('查询进件状态失败, 请检查日志 #2');
            }
        }
    }

}