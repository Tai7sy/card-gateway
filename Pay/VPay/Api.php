<?php

namespace Gateway\Pay\VPay;

use Gateway\Pay\ApiInterface;
use Illuminate\Support\Facades\Log;


/**
 *
 *
 * 测试通过的 VPay Gateway:
 * https://github.com/dreamncn/VPay
 *
 * payway: 1=支付宝 2=微信
 *
 * Class Api
 * @package Gateway\Pay\VPay
 */

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
        if (!isset($config['id'])) {
            throw new \Exception('请填写[id]');
        }
        if (!isset($config['key'])) {
            throw new \Exception('请填写[key]');
        }
        if (!isset($config['gateway'])) {
            throw new \Exception('请填写[gateway]');
        }
        $amount_yuan = sprintf('%.2f', $amount_cent / 100);
        $payway = $config['payway'];

        if ($payway == '1') {
            $template_file = "vpay_wechat";
        } else if ($payway == '2') {
            $template_file = "vpay_alipay";
        } else {
            throw new \Exception('支付渠道错误, 支付方式：1微信 2支付宝');
        }

        $return_url = SYS_URL . '/qrcode/pay/' . $out_trade_no . '/query';
        $params = array(
            'payId' => $out_trade_no,
            'type' => $payway,
            'price' => $amount_yuan,
            'returnUrl' => $return_url,
            'isHtml' => 0,
            'notifyUrl' => $this->url_notify
        );
        $params['sign'] = $this->getPostData($params, $config['key']);
        $response_raw = $this->curl_post($config['gateway'] . '/createOrder', $params);
        $response = @json_decode($response_raw, true);

        if (!$response || !isset($response['code'])) {
            Log::error('Pay.HLPay.goPay.order Error#1: ' . $response_raw);
            throw new \Exception('获取付款信息超时, 请刷新重试');
        }
        if ($response['code'] !== 1 || !isset($response['data']['payUrl'])) {
            Log::error('Pay.HLPay.goPay.order Error#2: ' . $response_raw);
            throw new \Exception($response['msg']);

        }

        header('location: /qrcode/pay/' . $out_trade_no . '/' . strtolower($template_file) .
            '?relprice=' . $response['data']['reallyPrice'] . '&url=' . urlencode($response['data']['payUrl']));

        die;

    }

    function verify($config, $successCallback)
    {
        $isNotify = isset($config['isNotify']) && $config['isNotify'];

        if ($isNotify) {

            $sign_params = array();
            foreach (array('payId', 'param', 'type', 'price', 'reallyPrice') as $param_name) {
                $sign_params[$param_name] = $_GET[$param_name];
            }
            if ($this->getSign($sign_params, $config['key']) !== $_GET['sign']) {
                Log::error('Pay.VmqPay.verify, sign error $post:' . json_encode($_GET));
                echo 'sign error';
                return false;
            }
            $out_trade_no = $_GET['payId'];
            $trade_no = '';
            $successCallback($out_trade_no, (int)round($_GET['price'] * 100), $trade_no);
            echo 'success';
            return true;

        } else {

            // 不支持主动查询?

            return false;
        }

    }

    private function getPostData($params, $sign_key)
    {
        unset($params['returnUrl']);
        unset($params['isHtml']);
        unset($params['notifyUrl']);
        $md5str = "";
        foreach ($params as $key => $val) {
            $md5str = $md5str . $val;
        }
        //echo($md5str . "key=" . $Md5key."<br>");
        $sign = md5($md5str . $sign_key);
        return $sign;
    }

    private function getSign($params, $sign_key)
    {
        $md5str = "";

        foreach ($params as $key => $val) {
            $md5str = $md5str . $val;
        }
        $sign = md5($md5str . $sign_key);
        return $sign;

    }

    private function curl_post($url, $post_data = '')
    {
        $headers['Accept'] = '*/*';
        $headers['Referer'] = $url;
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        $sendHeaders = array();
        foreach ($headers as $headerName => $headerVal) {
            $sendHeaders[] = $headerName . ': ' . $headerVal;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);

        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);

        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // 返回获取的输出文本流
        curl_setopt($ch, CURLOPT_HEADER, 1);         // 将头文件的信息作为数据流输出
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $sendHeaders);
        $response = curl_exec($ch);

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $body = substr($response, $headerSize);
        curl_close($ch);

        return $body;
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