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
 * payway: 1微信 2支付宝
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

        // $return_url = SYS_URL . '/qrcode/pay/' . $out_trade_no . '/query';

        $params = array(
            'payId' => $out_trade_no,   // 发卡系统内订单号
            'price' => floatval($amount_yuan),    // 需要支付的金额, 保留两位有效数字  V免签内部签名用了floatval, 所以这里也转化一下
            'param' => '',              // 额外参数, 该参数允许置为空,如果需要传递数据请使用json编码，该参数在异步回调会被使用
            'type' => $payway,          // 支付方式 1微信 2支付宝
            'isHtml' => 0,              // 是否使用支付站点的UI
            'appid' => $config['id'],   // 应用ID
        );
        $post_data = $this->getPostData($params, $config['key']);
        $response_raw = $this->curl_post($config['gateway'] . '/CreateOrder', $post_data);
        $response = @json_decode($response_raw, true);

        if (!$response || !isset($response['code'])) {
            Log::error('Pay.VPay.goPay.order Error#1: ' . $response_raw);
            throw new \Exception('获取付款信息超时, 请刷新重试');
        }
        if ($response['code'] !== 0 || !isset($response['data']['data']) || !isset($response['data']['data']['payUrl'])) {
            Log::error('Pay.VPay.goPay.order Error#2: ' . $response_raw);
            throw new \Exception($response['msg']);
        }

        // 保存一下 V免签 系统内的订单号
        \App\Order::whereOrderNo($out_trade_no)->update(['pay_trade_no' => $response['data']['data']['orderId']]);

        header('location: /qrcode/pay/' . $out_trade_no . '/' . strtolower($template_file) .
            '?real_price=' . $response['data']['data']['reallyPrice'] . '&url=' . urlencode($response['data']['data']['payUrl']));

        die;

    }

    function verify($config, $successCallback)
    {
        $isNotify = isset($config['isNotify']) && $config['isNotify'];

        // 如果是异步通知
        if ($isNotify) {
            // 这里是支付后一步回调步骤
            // 在这里需要做一些校验(如签名校验等), 确保通知的合法性

            Log::debug('Pay.VPay.verify - notify', [ 'url' => request()->fullUrl(), '$_GET' => $_GET, '$_POST' => $_POST ]);
            
            $sign_params = array();
            foreach (array('payId', 'param', 'type', 'price', 'reallyPrice') as $param_name) {
                $sign_params[$param_name] = $_GET[$param_name];
            }
            if ($this->getSign($sign_params, $config['key']) !== $_GET['sign']) {
                Log::error('Pay.VPay.verify, sign error $_GET:' . json_encode($_GET));
                echo json_encode(array("state" => -1, "msg" => "sign error"));
                return false;
            }

            /** @var \App\Order $order */
            $order = \App\Order::whereOrderNo($_GET['payId'])->first(); // 为了查询 V免签内订单号

            $order_no = $_GET['payId'];  // 发卡系统内交易单号
            $total_fee = (int)round((float)$_GET['price'] * 100);  // 这里本来应该传入实际支付金额(单位, 分) 但是系统会校验实际金额是否等于订单金额导致失败, 因此直接传订单金额算了
            $pay_trade_no = $order->pay_trade_no; // V免签内订单号

            $successCallback($order_no, $total_fee, $pay_trade_no); // 成功后记得调用此函数处理订单
            echo json_encode(array("state" => 0, "msg" => "okok"));
            return true;

        } else {

            // 这里传递了发卡系统内交易单号, 有两种可能来到此步骤
            // 1. 用户提交订单后未支付, 重新发起支付, 支付前需要校验是否已经支付
            // 2. 此支付方式支持二维码扫码等方式, 二维码页面轮训请求是否支付
            if (!empty($config['out_trade_no'])) {
                // 通过主动查询方式查询交易是否成功

                /** @var \App\Order $order */
                $order = \App\Order::whereOrderNo($config['out_trade_no'])->first();

                $response_raw = $this->curl_post($config['gateway'] . '/GetOrder', 'orderId=' . $order->pay_trade_no);
                Log::debug('Pay.VPay.query result: ' . $response_raw);

                $response = @json_decode($response_raw, true);
                if (!$response || !isset($response['code'])) {
                    Log::error('Pay.VPay.query Error#1: ' . $response_raw);
                    return false;
                }
                if ($response['code'] !== 0 || !isset($response['data']) || !isset($response['data']['state'])) {
                    Log::error('Pay.VPay.query Error#2: ' . $response_raw);
                    return false;
                }
                /*
                   状态代码	状态名称	状态解释
                   -1	State_Over	订单超时或者被关闭
                   0	State_Wait	订单等待支付中
                   1	State_Ok	订单支付完成，尚未进行异步回调
                   2	State_Err	异步通知失败，异步回调服务器未能正确响应信息
                   3	State_Succ	远程服务器回调成功，订单完成确认
                */
                if ($response['data']['state'] === '1' ||  // 订单支付完成，尚未进行异步回调
                    $response['data']['state'] === '2' ||  // 异步通知失败，异步回调服务器未能正确响应信息
                    $response['data']['state'] === '3'     // 远程服务器回调成功，订单完成确认
                ) {
                    $order_no = $response['data']['payId'];  // 发卡系统内交易单号
                    $total_fee = (int)round((float)$response['data']['price'] * 100);     // 这里本来应该传入实际支付金额(单位, 分) 但是系统会校验实际金额是否等于订单金额导致失败, 因此直接传订单金额算了
                    $pay_trade_no = $response['data']['orderId'];    // V免签内订单号

                    $successCallback($order_no, $total_fee, $pay_trade_no); // 成功后记得调用此函数处理订单
                    return true;
                } else {
                    // 未支付
                    return false;
                }
            }
            

            // V免签都是扫码, 不会有下面跳转到 return_url 的可能性
            return false;
            /*
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
            */

        }
    }

    private function getPostData($params, $key)
    {
        ksort($params);
        $tmp = array();
        foreach ($params as $k => $v) {
            array_push($tmp, "$k=$v");
        }
        $params = implode('&', $tmp);
        $sign_data = $params . '&key=' . $key;

        return $params . '&sign=' . strtoupper(hash('sha256', $sign_data));
    }


    private function getSign($params, $key)
    {
        ksort($params);
        $tmp = array();
        foreach ($params as $k => $v) {
            array_push($tmp, "$k=$v");
        }
        $params = implode('&', $tmp);
        $sign_data = $params . '&key=' . $key;

        return strtoupper(hash('sha256', $sign_data));
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