<?php

namespace Gateway\Pay\Example;

use Gateway\Pay\ApiInterface;

class Api implements ApiInterface
{
    private $url_notify = '';
    private $url_return = '';

    public function __construct($id)
    {
        $this->url_notify = SYS_URL_API . '/pay/notify/' . $id;
        $this->url_return = SYS_URL . '/pay/return/' . $id;
    }


    /**
     * 提交支付
     * @param array $config 支付渠道配置
     * @param string $order_no 本系统的订单号
     * @param string $subject 商品名称
     * @param string $body 商品介绍
     * @param int $amount_cent 金额/分
     */
    function goPay($config, $order_no, $subject, $body, $amount_cent)
    {
        $payway = strtolower($config['payway']);
        // wechat qq alipay
        // 跳转支付页面
        header('Location: http://example.com/order?out_trade_no=' . $order_no . '&subject=' . $subject . '&total_fee=' . $amount_cent);
        exit;
    }

    /**
     * 验证支付是否成功 <br>
     * $config['isNotify'] = true 则为支付成功后后台通知消息 <br>
     * $config['out_trade_no'] = 'xx' 则可能为二维码查询页面异步查询 <br>
     * 其余由情况为 支付成功后前台回调
     * @param array $config 支付渠道配置
     * @param callable $successCallback 成功回调 (系统单号,交易金额/分,支付渠道单号)
     * @return true|string true 验证通过  string 失败原因
     */
    function verify($config, $successCallback)
    {
        $isNotify = isset($config['isNotify']) && $config['isNotify'];

        // 如果是异步通知
        if ($isNotify) {
            // 这里是支付后一步回调步骤
            // 在这里需要做一些校验(如签名校验等), 确保通知的合法性
            if (verify_notify()) {
                echo 'success';
                $order_no = $_REQUEST[''];  // 发卡系统内交易单号
                $total_fee = $_REQUEST['']; // 实际支付金额, 单位, 分
                $pay_trade_no = $_REQUEST['']; // 支付系统内订单号/流水号
                $successCallback($order_no, $total_fee, $pay_trade_no); // 成功后记得调用此函数处理订单
                return true;
            } else {
                \Log::error('这里可以记录一些出错信息, 内容保存在 /storage/logs 内');
                echo 'error';
                return false;
            }
        }


        // 这里传递了发卡系统内交易单号, 有两种可能来到此步骤
        // 1. 用户提交订单后未支付, 重新发起支付, 支付前需要校验是否已经支付
        // 2. 此支付方式支持二维码扫码等方式, 二维码页面轮训请求是否支付
        if (!empty($config['out_trade_no'])) {
            // 通过主动查询方式查询交易是否成功
            $order = query_order($config['out_trade_no']);
            if ($order['status'] === 'SUCCESS') {
                $order_no = $order[''];  // 发卡系统内交易单号
                $total_fee = $order['']; // 实际支付金额, 单位, 分
                $pay_trade_no = $order['']; // 支付系统内订单号/流水号
                $successCallback($order_no, $total_fee, $pay_trade_no); // 成功后记得调用此函数处理订单
                return true;
            } else {
                \Log::error('这里可以记录一些出错信息, 内容保存在 /storage/logs 内');
                return false;
            }
        }

        // 这里是支付后返回的页面调用的步骤, 一般返回页面也会带有支付后的参数
        // 因此如果做了校验(如签名校验等), 一样可以认为我们支付成功了
        if (verify_return()) {
            $order_no = $_REQUEST[''];  // 发卡系统内交易单号
            $total_fee = $_REQUEST['']; // 实际支付金额, 单位, 分
            $pay_trade_no = $_REQUEST['']; // 支付系统内订单号/流水号
            $successCallback($order_no, $total_fee, $pay_trade_no); // 成功后记得调用此函数处理订单
            return true;
        } else {
            \Log::error('这里可以记录一些出错信息, 内容保存在 /storage/logs 内');
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
     * @throws \Throwable
     */
    function refund($config, $order_no, $pay_trade_no, $amount_cent)
    {
        return "此支付渠道不支持自动退款, 请手动操作"; // 返回字符串代表失败原因
    }
}