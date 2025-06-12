# ImageKitUploader 插件使用说明

这是一个用于Typecho的ImageKit.io附件上传插件，使用此插件可以将Typecho博客的附件上传到ImageKit.io云存储服务上。

## 功能特点

- 支持将图片等各类附件上传至ImageKit.io云存储
- 支持自定义域名
- 支持自定义文件夹路径
- 可选使用原始文件名或随机文件名
- 完全支持PHP 8.x

## 安装方法

1. 下载本插件，并解压
2. 将插件文件夹命名为`ImageKitUploader`（注意大小写）
3. 上传至网站的`/usr/plugins/`目录
4. 在Typecho后台启用插件

## 配置说明

插件启用后，需要在插件配置页面填写以下信息：

1. **私钥(Private Key)**: 您的ImageKit账户私钥
2. **公钥(Public Key)**: 您的ImageKit账户公钥
3. **URL端点(Endpoint URL)**: 您的ImageKit URL端点，通常格式为`https://ik.imagekit.io/your_account`
4. **上传路径前缀**: 文件在ImageKit中的存储路径前缀，默认为`typecho`
5. **自定义域名**: 如果您为ImageKit配置了自定义域名，可以在这里填写
6. **是否使用原始文件名**: 选择是否保留上传文件的原始文件名

## 获取ImageKit配置信息

1. 注册并登录[ImageKit.io](https://imagekit.io/)
2. 在控制台中找到"Developer Options"
3. 在此页面可以找到您的Private Key、Public Key和URL Endpoint

## 常见问题

### 上传失败怎么办？

- 检查您的ImageKit配置信息是否正确
- 确认您的ImageKit账户是否有效
- 查看PHP错误日志获取更详细的错误信息

### 如何使用自定义域名？

1. 在ImageKit控制台配置您的自定义域名
2. 在插件设置中填写您的自定义域名（包含http://或https://前缀）

## 注意事项

- 本插件需要PHP支持cURL扩展
- 建议使用PHP 7.0或更高版本
- 上传超大文件可能会受到PHP配置限制，请适当调整`php.ini`中的`upload_max_filesize`和`post_max_size`

## 更新日志

### 1.1.0 (初始版本)
- 升级到typecho1.x 版本插件

### 1.0.0 (初始版本)
- 基本功能实现
- 支持图片和其他类型文件上传
- 支持自定义配置

## 许可证

本插件采用MIT许可证。
