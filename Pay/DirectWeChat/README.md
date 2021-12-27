
## 说明

此支付方式采用官方直清方式, 由支付宝/微信直接结算给个人, 订单金额不会计入商户余额, 每个商户需要后台独立进件开户。

手续费通过支付宝/微信官方分账接口直接计入服务商账户下。

支付宝需要开通 [直付通](https://opendocs.alipay.com/open/00faww)

微信需要开通 [电商收付通](https://pay.weixin.qq.com/wiki/doc/apiv3_partner/open/pay/chapter3_3_0.shtml)

## 微信配置说明
### 驱动: DirectWeChat

### 支付方式支持
- NATIVE: 微信Native (网页扫码)
- JSAPI: 微信JSAPI (微信内, 公众号跳转)
> 微信内置浏览器必须使用JSAPI, 扫码无法使用, 因此系统自动适配, 后台只填写NATIVE即可

### 配置JSON:

1. 微信参考 https://pay.weixin.qq.com/wiki/doc/apiv3_partner/open/pay/chapter3_3_1.shtml

2. JSON所需参数如下:
    - app_id: 服务商申请的公众号appid, 示例值：wx8888888888888888
    - app_secret: 服务商申请的公众号secret, 示例值：******
    - merchant_id: 获取微信服务商ID, 示例值：1230000109
    - api_key: APIv3 密钥
    - cert_serial: 第一步获取的商户API证书序列号
    - cert_private_key: 第一步获取的 apiclient_key.pem 用记事本打开删掉第一行和最后一行, 剩余内容删除换行符
    