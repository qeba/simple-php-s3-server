# PHP S3 Server


[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)

[ENGLISH](./README-en.md)

一个轻量级的 PHP 实现的 S3 兼容对象存储服务器，使用本地文件系统作为存储后端。

## 功能特性

- ✅ S3 对象处理相关 API 兼容（PUT/GET/DELETE/POST）
- ✅ 支持分片上传（Multipart Upload）
- ✅ 无需数据库，纯文件系统存储
- ✅ 简单的 AWS V4 兼容签名认证
- ✅ 轻量级，单文件部署



## 太长不看版说明

只需在虚拟主机上新建一个网站，要把Github仓库内 index.php 放入这个网站根目录，修改 index.php 开头的密码配置，配置好路由重写，即可开始使用。
endpoint 就是你的网站域名
access_key 就是你配置的密码
secret_key 随便填，对本项目无意义
region 随便填，对本项目无意义

假如一个对象的 bucket="music" key="hello.mp3"
将被储存为 ./data/music/hello.mp3

你还可以搭配上 Cloudflare 的 CDN ，又快又稳。


## 快速开始

### 环境要求

- PHP 8.0+
- Apache/Nginx（需启用 mod_rewrite）


### 安装

1. 配置好一个网站

2. 下载 `index.php` 到你的网站根目录：

3. 创建数据目录
在网站根目录下创建 data 文件夹

4. 配置路由重写（以 DA面板 为例）：
```网站根目录下 创建 .htaccess 写入以下命令
<IfModule mod_rewrite.c>
    RewriteEngine On

    # 如果请求的不是真实存在的文件或目录
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d

    # 将所有请求重定向到 index.php
    RewriteRule ^(.*)$ index.php [L,QSA]
</IfModule>

```
> 其它的虚拟主机，请询问AI如何配置规则重写，将全部的路由都重定向到 index.php

### 配置

编辑 `index.php` 顶部配置项：
```php
define('ALLOWED_ACCESS_KEYS', ['改为你想要的访问密钥']);  
// 允许的访问密钥，在第三方OSS工具中使用时只需要填写 access-key 即可，其它的 region 和 secret-key 随意填写

```



### 开始尝试使用吧

#### 示例：在 Minio 中使用

```python
oss_client = Minio("https://your-domain", access_key="改为你想要的访问密钥", secret_key="*", secure=True)

```
