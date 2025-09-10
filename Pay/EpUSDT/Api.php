<?php
/**
 * 发卡系统对接 EpUSDT 类
 * @author Prk
 * @version 1.0.3
 */

namespace Gateway\Pay\EpUSDT;

use Gateway\Pay\ApiInterface;
use Illuminate\Support\Facades\Log;

class Api implements ApiInterface {

    // 👇  这里一般来说 5 秒足够
    //     当然，有部分两个服务器连接太差的情况
    //     可以酌情修改为 15
    //     超过这个时间一般就会提示失败
    //     最好不要太长，因为不是所有客户都原因等过久
    //     一般最大不超 15 秒，少数也有 60 秒的情况
    //     请酌情设置
    //     （单位：秒，作者：Prk）
    private $timeout = 5;
    private $url_notify = '';
    private $url_return = '';

    /**
     * 构造器
     * @author Prk
     */
    function __construct($id) {
        $this->url_notify = SYS_URL_API . '/pay/notify/' . $id;
        $this->url_return = SYS_URL . '/pay/return/' . $id;
    }

    /**
     * 去支付
     * @author Prk
     */
    public function goPay(
        $config,
        $out_trade_no,
        $subject,
        $body,
        $amount_cent
    ) {
        if (!isset($config['gateway'])) throw new \Exception('请填写支付网关地址');
        if (!isset($config['key'])) throw new \Exception('请填写密钥');
        $amount = sprintf('%.2f', $amount_cent / 100);
        $parameter = [
            'amount'        =>  (double)$amount,
            'notify_url'    =>  strval($this->url_notify),
            'order_id'      =>  strval($out_trade_no),
            'redirect_url'  =>  strval($this->url_return) . '/' . $out_trade_no
        ];
        $parameter['signature'] = $this->epusdtSign($parameter, $config['key']);
        $res = json_decode(
            $this->curl_request(
                $config['gateway'] . '/api/v1/order/create-transaction',
                $parameter,
                'POST'
            ), true
        );
        if (200 == intval($res['status_code']) &&
            'success' == $res['message']
        ) {
            if (isset($res['data']['payment_url']) &&
                !empty($res['data']['payment_url'])
            ) {
                header('Location: ' . $res['data']['payment_url']);
                exit;
            } else throw new \Exception('从支付接口获取支付地址失败');
        } else foreach([
            [
                400, '支付接口系统错误'
            ], [
                401, '支付接口签名认证错误'
            ], [
                10002, '支付交易已存在，请勿重复创建'
            ], [
                10003, '无可用钱包地址，无法发起支付'
            ], [
                10004, '支付金额有误, 无法满足最小支付单位'
            ], [
                10005, '无可用金额通道'
            ], [
                10006, '汇率计算错误'
            ], [
                10007, '订单区块已处理'
            ], [
                10008, '支付接口订单不存在'
            ], [
                10009, '支付接口无法解析参数'
            ]
        ] as $row) throw new \Exception(
            $row[0] == intval($res['status_code'])
                ?
            $row[1]
                :
            '获取支付地址失败'
            );
    }

    /**
     * 验证是否支付
     * @author Prk
     */
    function verify(
        $config,
        $successCallback
    ) {
        $isNotify = isset($config['isNotify']) && $config['isNotify'];
        if ($isNotify) {
            function isJson($string) {
                @json_decode($string);
                return (json_last_error() == JSON_ERROR_NONE);
            }

            $can = file_get_contents('php://input');
            if (empty($can)) exit('这不对吧，兄弟');
            if (!isJson($can)) exit('这也不对吧，兄弟');

            $can = @json_decode($can, true);
            if (!is_array($can)) exit('这更不对吧，兄弟');

            if (!isset($can['signature'])) {
                exit('不包括签名 signature');
                return false;
            }

            $signature = $this->epusdtSign($can, $config['key']);
            if ($signature == $can['signature']) {
                if (2 == intval($can['status'])) $successCallback(
                    $can['order_id'],
                    (int)round($can['amount'] * 100),
                    $can['trade_id']
                );
                echo 'ok';
                return true;
            } else {
                \Log::error('请求参数: ' . json_encode($can));
                \Log::error('我方签名: ' . $signature);
                \Log::error('对方签名: ' . $can['signature']);
                echo 'error sign';
                return false;
            }
        } else {
            if (empty($config['out_trade_no'])) exit('未知的订单号');

            /*
                官方文档目前没有主动获取付款信息的相关接口！
                如果有了请通知我更新支付网关
                https://github.com/assimon/epusdt/blob/master/wiki/API.md
            */

            $order = \App\Order::where('order_no', $config['out_trade_no'])->first();
            if (!!$order && ('SUCCESS' === $order['status'] || 2 == intval($order['status']))) return true;
            exit(file_get_contents(__DIR__ . '/loading.html'));
        };

        return false;
    }

    /**
     * 发卡系统退款函数
     * @author Prk
     * 
     * @param $config
     * @param $order_no
     * @param $pay_trade_no
     * @param @amount_cent
     */
    function refund(
        $config,
        $order_no,
        $pay_trade_no,
        $amount_cent
    ) {
        // 数字货币你退你大爷款啊
        return '数字货币接口暂不支持退款';
    }

    /**
     * 使用 cUrl 发起网络请求
     * @author Prk
     * 
     * @param string $url 请求的地址
     * @param array $data 请求的数据
     * @param string $method 请求方式（GET POST PUT）
     * @param boolean $https 是否为 HTTPS 请求（忽略验证）
     */
    private function curl_request(
        string $url,
        array $data = [],
        string $method = 'POST'
    ) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL             =>  $url,
            CURLOPT_RETURNTRANSFER  =>  true,
            CURLOPT_TIMEOUT         =>  $this->timeout,
            CURLOPT_HTTPHEADER      =>  [
                'Content-Type: application/json'
            ],
            CURLOPT_SSL_VERIFYPEER  =>  false,
            CURLOPT_SSL_VERIFYHOST  =>  false
        ]);
        if ('POST' == strtoupper($method)) curl_setopt_array($ch, [
            CURLOPT_POSTFIELDS      =>  json_encode($data),
            CURLOPT_CUSTOMREQUEST   =>  'POST',
            CURLOPT_POST            =>  true
        ]);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    /**
     * 算签名
     * @author Prk
     * 
     * @param array $parameter 欲要算签名的数据的信息
     * @param string $signKey 商户密钥用作 MD5 加密 “盐”
     * 
     * @return string 加密后的签名字符串
     */
    private function epusdtSign(
        array $parameter,
        string $signKey
    ): string {
        ksort($parameter);
        reset($parameter);
        $sign = '';
        $urls = '';
        foreach ($parameter as $k => $v) {
            if ('' == $v) continue;
            if ('signature' == $k) continue;
            if ('' != $sign) {
                $sign .= '&';
                $urls .= '&';
            }
            $sign .= $k . '=' . $v;
            $urls .= $k . '=' . urlencode($v);
        }
        return md5($sign . $signKey);
    }

}
