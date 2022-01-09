
## 说明

此支付方式采用官方直清方式, 由支付宝/微信直接结算给个人, 订单金额不会计入商户余额, 每个商户需要后台独立进件开户。

手续费通过支付宝/微信官方额外的分账接口转到服务商账户中。

支付宝需要开通 [直付通](https://opendocs.alipay.com/open/00faww)

微信需要开通 [电商收付通](https://pay.weixin.qq.com/wiki/doc/apiv3_partner/open/pay/chapter3_3_0.shtml)

## 注意事项
1. 需要使用异步队列方式, 参考README.md中配置 `QUEUE_DRIVER=database` 或 `QUEUE_DRIVER=redis`
2. 支付宝直付通 常见问题详细参考: https://opendocs.alipay.com/open/direct-payment/bvxbhg
2. 微信电商收付通 常见问题详细参考: https://pay.weixin.qq.com/wiki/doc/apiv3/wxpay/ecommerce/guide/chapter11_2.shtml

## 微信配置说明
### 驱动: DirectAlipay

### 支付方式支持
- mobile: 支付宝手机端 (手机网站支付接口 2.0) (本接口PC端支持自动转为支付宝扫码支付)
- pc: 支付宝PC端

### 配置JSON:

1. 参考 https://opendocs.alipay.com/open/direct-payment/incr1f

2. JSON所需参数如下:
    - user_id: 平台支付宝账号对应的支付宝唯一用户号，以2088开头的纯16位数字。
    - app_id: 后台应用appid
    - merchant_private_key: 商户私钥
    - alipay_public_key: 支付宝公钥
    