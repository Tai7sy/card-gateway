<?php

namespace Gateway\Pay\Alidirect;

use Gateway\Pay\ApiInterface;
use Illuminate\Support\Facades\Log;

class Api implements ApiInterface
{
    private $url_notify = '';
    private $url_return = '';
    private $pay_id;

    public function __construct($id)
    {
        $this->url_notify = SYS_URL_API . '/pay/notify/' . $id;
        $this->url_return = SYS_URL . '/pay/return/' . $id;
        $this->pay_id = $id;
    }

    /**
     * @param array $config
     * @param string $out_trade_no 外部订单号
     * @param string $subject
     * @param string $body
     * @param $amount_cent
     * @throws \Exception
     * @internal param int $amount 1 = 0.01元
     */
    function goPay($config, $out_trade_no, $subject, $body, $amount_cent)
    {
        /* By Mr.Point QQ:40386277 www.zfbjk.com */

        $pid = isset($config['pid']) ? $config['pid'] : "";

        /** @var \App\Order $result */
        $result = \App\Order::where('order_no', $out_trade_no)->first();
        $payAmount = sprintf('%.2f', $amount_cent / 100);
        $title = $result->id;

        switch ($config['payway']) {
            case 'alipay':
                $paytype = 'alipay';
                break;
            case 'weixin':
                $paytype = 'weixin';
                break;
            default:
                throw new \Exception('支付方式填写错误, alipay/weixin');
        }

        // 这里模板文件尽量复用之前的
        header("Location: /qrcode/pay/{$out_trade_no}/alidirect_{$paytype}?url={$pid}&title={$title}");
        exit;
    }

    function verify($config, $successCallback)
    {
        $isNotify = isset($config['isNotify']) ? $config['isNotify'] : "";

        $id = isset($config['id']) ? $config['id'] : "";
        $key = isset($config['key']) ? $config['key'] : "";


        if ($isNotify) {

            $tradeNo = isset($_POST['tradeNo']) ? $_POST['tradeNo'] : '';
            $Money = isset($_POST['Money']) ? $_POST['Money'] : 0;
            $title = isset($_POST['title']) ? $_POST['title'] : '';
            $memo = isset($_POST['memo']) ? $_POST['memo'] : '';
            $alipay_account = isset($_POST['alipay_account']) ? $_POST['alipay_account'] : '';
            $Gateway = isset($_POST['Gateway']) ? $_POST['Gateway'] : '';
            $Sign = isset($_POST['Sign']) ? $_POST['Sign'] : '';
            $orderid = isset($_POST['orderid']) ? $_POST['orderid'] : '';

            if ($orderid && is_numeric($orderid)) {
                $result = \App\Order::where('id', $orderid)->first();
                if ($result && $result->status == \App\Order::STATUS_SUCCESS) {
                    exit("success");
                }
                exit;
            }
            if (@strtoupper(md5($id . $key . $tradeNo . $Money . iconv("utf-8", "gb2312", $title) . iconv("utf-8", "gb2312", $memo))) != strtoupper($Sign)) {
                exit("Fail");
            } else {
                if (!is_numeric($title)) {
                    exit("FAIL");
                }

                /** @var \App\Order $result */
                $result = \App\Order::where('id', $title)->first();
                if (!$result) {
                    exit("IncorrectOrder");
                } elseif ($result->paid != $Money * 100) {
                    exit("fail");
                }
                $out_trade_no = $result->order_no;
                $total_fee = (int)round($Money * 100);
                $trade_no = $tradeNo;
                $successCallback($out_trade_no, $total_fee, $trade_no);

                if ($isNotify) {
                    echo 'success';
                }
                return true;
            }
        } else {

            // 不支持主动查询?
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
        return '此支付渠道不支持发起退款, 请手动操作';
    }
}