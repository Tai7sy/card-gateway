# 发卡系统对接 epusdt 类

当前版本：`1.0.1`

作者：`Prk`


## 使用教程

将文件下载到如下目录：`/www/app/Library/Gateway/Pay/EpUSDT`

（`/www` 为网站所在目录，你需要在 `/www/app/Library/Gateway/Pay` 新建一个名为 `EpUSDT` 的文件夹）

```
/www/app/Library/Gateway/Pay/EpUSDT
```

文件地址：[https://github.com/Tai7sy/card-gateway/blob/master/Pay/EpUSDT/Api.php](https://github.com/Tai7sy/card-gateway/blob/master/Pay/EpUSDT/Api.php)

你也可以使用如下命令一键完成：

``` sh
cd /www # 这里改成你网站的相对路径
cd app/Library/Gateway/Pay/EpUSDT
wget https://raw.githubusercontent.com/Tai7sy/card-gateway/master/Pay/EpUSDT/Api.php
```

下载完成后打开网站后台，找到支付网关，请按照下述说明进行配置：

------

**名称**：_自己想一个就好_

**费率**：_根据需求设定费率_

启用：**√**_你必须要启用才可以使用啊！_

**驱动**：`EpUSDT`

**方式**：_随便写点什么_

备注：_由自己喜好设定_

------

配置部分非常重要，先选择 JSON 然后复制如下内容粘贴进去后，回到 Parse 修改。

（`gateway` 是支付网关地址，是你搭建 epusdt 的地址，`key` 是你在 epusdt 程序的环境变量中设置的对接密钥）

``` json
{
    "gateway": "https://example.com",
    "key": "abcd1234"
}
```
