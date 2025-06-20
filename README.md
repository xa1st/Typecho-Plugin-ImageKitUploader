# ImageKit Uploader for Typecho

这是一个用于Typecho的ImageKit.io附件上传插件，该插件经过轻量级重构，去掉了官方SDK中的多余功能，精简至只有必要的API，打造轻量级高效的上传插件。

## 功能特点

- 支持将图片等各类附件上传至ImageKit.io云存储
- 支持自定义域名（可选，仅付费版ImageKit账户可用）
- 支持自定义文件夹路径
- 可选使用原始文件名或随机文件名
- 完全支持Typecho 1.x和PHP 8.x
- 轻量级设计，去除冗余功能，仅保留核心上传和删除功能

## 安装方法

1. 下载本插件，并解压
2. 将插件文件夹命名为`ImageKitUploader`（注意大小写）
3. 上传至网站的`/usr/plugins/`目录
4. 在Typecho后台启用插件

## 配置说明

插件启用后，需要在插件配置页面填写以下信息：

1. **私钥(Private Key)**: 您的ImageKit账户私钥，可以在ImageKit.io控制台获取
2. **上传路径前缀**: 文件在ImageKit中的存储路径前缀，默认为`typecho`
3. **自定义域名**: 如果您为ImageKit配置了自定义域名，可以在这里填写（仅ImageKit付费账户可用，免费用户请留空）
4. **超时时间**: 上传文件超时时间，单位为秒，默认为30秒
5. **是否使用原始文件名**: 选择是否保留上传文件的原始文件名，选择"否"则使用随机文件名

## 获取ImageKit配置信息

1. 注册并登录[ImageKit.io](https://imagekit.io/)
2. 在控制台中找到"Developer Options"
3. 在此页面可以找到您的Private Key

## 常见问题

### 上传失败怎么办？

- 检查您的ImageKit私钥是否正确填写
- 确认您的ImageKit账户是否有效
- 检查PHP是否支持cURL扩展
- 查看PHP错误日志获取更详细的错误信息

### 如何使用自定义域名？

1. 在ImageKit控制台配置您的自定义域名（需付费账户）
2. 在插件设置中填写您的自定义域名（包含http://或https://前缀）

## 注意事项

- 本插件需要PHP支持cURL扩展
- 上传超大文件可能会受到PHP配置限制，请适当调整`php.ini`中的`upload_max_filesize`和`post_max_size`
- 本插件为轻量级设计，不包含预签名URL等高级功能

## 更新日志

### 2.0.0 (当前版本)
- 轻量级重构，去掉官方SDK中多余功能
- 精简至只有核心上传和删除API
- 优化代码结构，提高性能
- 增加超时配置选项
- 改进错误处理

### 1.2.0
- 修复返回路径错误的bug

### 1.1.0
- 升级到typecho1.x 版本插件

### 1.0.0 (初始版本)
- 基本功能实现
- 支持图片和其他类型文件上传
- 支持自定义配置

## 许可证

本插件采用MIT许可证。

## 作者

猫东东 (Xa1st)

## 项目链接

[https://github.com/xa1st/Typecho-Plugin-ImageKitUploader](https://github.com/xa1st/Typecho-Plugin-ImageKitUploader)
