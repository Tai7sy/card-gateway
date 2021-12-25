
## 说明

此支付方式采用官方直清方式, 由支付宝/微信直接结算给个人, 订单金额不会计入商户余额, 每个商户需要后台独立进件开户。

手续费通过支付宝/微信官方分账接口直接计入服务商账户下。

支付宝需要开通 [直付通](https://opendocs.alipay.com/open/00faww)

微信需要开通 [电商收付通](https://pay.weixin.qq.com/wiki/doc/apiv3_partner/open/pay/chapter3_3_0.shtml)

## 配置说明
### 驱动: DirectAlipay

### 支付方式支持:
- 1: 支付宝电脑端
- 2: 支付宝手机端

### 配置JSON:

1. xxx