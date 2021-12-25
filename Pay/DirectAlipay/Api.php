<?php

namespace Gateway\Pay\DirectAlipay;

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

        var_dump($this->wechat_client($config));

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
        // 直接成功, 用于测试
        return true;
    }
}