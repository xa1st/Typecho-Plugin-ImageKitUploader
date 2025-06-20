<?php
namespace TypechoPlugin\ImageKitUploader;

use Typecho\Plugin\PluginInterface;
use Typecho\Plugin\Exception;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Password;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Http\Client;
use Typecho\Common;
use Utils\Helper;
use Widget\Upload;

// 防止直接运行
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 轻量重构版本，去掉官方SDK中多余功能，精简至只有2个API，打造轻量级上传插件
 * 
 * @package ImageKitUploader
 * @author 猫东东
 * @version 2.0.0
 * @link https://github.com/xa1st/Typecho-Plugin-ImageKitUploader
 */
class Plugin implements PluginInterface
{

    /**
     * 激活插件方法，如果激活失败，直接抛出异常
     * 
     * @return void
     * @throws Exception
     */
    public static function activate() {
        // 挂载上传文件钩子
        \Typecho\Plugin::factory('Widget_Upload')->uploadHandle = [__CLASS__, 'uploadHandle'];
        \Typecho\Plugin::factory('Widget_Upload')->modifyHandle = [__CLASS__, 'modifyHandle'];
        \Typecho\Plugin::factory('Widget_Upload')->deleteHandle = [__CLASS__, 'deleteHandle'];
        \Typecho\Plugin::factory('Widget_Upload')->attachmentHandle = [__CLASS__, 'attachmentHandle'];
        
        return _t('插件已启用，请前往设置页面配置您的ImageKit参数');
    }
    

    /**
     * 禁用插件方法
     * 
     * @return void
     */
    public static function deactivate() {
        return _t('插件已禁用');
    }
    

    /**
     * 获取插件配置面板
     * 
     * @param Form $form 配置面板
     * @return void
     */
    public static function config(Form $form) {
        // ImageKit.io 私钥
        $privateKey = new Password(
            'privateKey', 
            null, 
            '', 
            _t('私钥(Private Key)'), 
            _t('输入您的ImageKit私钥，可以在 ImageKit.io 控制台获取')
        );
        $form->addInput($privateKey->addRule('required', _t('私钥不能为空')));
            
        // 上传目录
        $uploadPath = new Text(
            'uploadPath', 
            null, 
            'typecho', 
            _t('上传路径前缀'), 
            _t('图片上传到ImageKit的路径前缀，例如：typecho，则上传路径为 typecho/年/月/文件名')
        );
        $form->addInput($uploadPath);
            
        // 自定义域名
        $customDomain = new Text(
            'customDomain', 
            null, 
            '', 
            _t('自定义域名'), 
            _t('设置自定义域名，例如：https://cdn.example.com（可选），ImageKit的免费用户无此功能，请留空')
        );
        $form->addInput($customDomain);

        $timeOut = new Text(
            'timeOut',
            NULL,
            '30',
            _t('超时时间（秒）'),
            _t('上传文件超时时间，单位为秒，默认为30秒。')
        );
        $form->addInput($timeOut->addRule('required', _t('请求超时时间')));
            
        // 是否使用原始文件名
        $useOriginFileName = new Radio(
            'useOriginFileName',
            ['0' => _t('否'), '1' => _t('是')],
            '0',
            _t('是否使用原始文件名'),
            _t('选择"是"则使用原始文件名，选择"否"则使用随机文件名')
        );
        $form->addInput($useOriginFileName);
    }
    

    /**
     * 个人用户的配置面板
     * 
     * @param Form $form
     * @return void
     */
    public static function personalConfig(Form $form) {
        // 暂时不需要个人配置
    }
    

    /**
     * 上传文件处理函数
     * 
     * @param array $file 上传的文件
     * @param bool $modify 是否为修改操作
     * @return array|bool
     */
    public static function uploadHandle($file, $modify = false) {
        // 文件为空则直接返回
        if (empty($file['name'])) return false;
        // 获取扩展名
        $ext = self::getSafeName($file['name']);
        // 验证可上传文件类型
        if (!Upload::checkFileType($ext))  throw new Exception('不允许上传的文件类型');
        // 获取插件配置
        $options = Helper::options()->plugin('ImageKitUploader');
        // 如果是新增 
        if (!$modify) {
            // 完全路径
            $filePath = ($options->uploadPath ? "{$options->uploadPath}/" : '') . date('Y/m');
            // 生成文件名
            $fileName = $options->useOriginFileName == '1' ? self::sanitizeFileName($file['name']) : sprintf('%u', crc32(uniqid())) . '.' . $ext;
        } else {
            // 旧路径
            $filePath = ltrim(dirname($file['path']), '/');
            // 旧文件名
            $fileName = basename($file['path']);
        }
        // 上传到ImageKit.io
        $uploaded = self::uploadToImageKit($file['tmp_name'], $filePath, $fileName, $options);
        // 上传失败抛出错误
        if (!$uploaded) throw new Exception('上传失败');
        // 返回结果
        return [
            'name' => $uploaded['name'], 
            'path' => $uploaded['filePath'],
            'size' => $uploaded['size'], 
            'type' => str_replace('image/', '', $file['type']),
            'mime' => @Common::mimeContentType($file['tmp_name']),
            'fileid' => $uploaded['fileId'],
            'url' => $options->customDomain ? ($options->customDomain . $uploaded['filePath']) : $uploaded['url'],
        ];
    }

    /**
     * 修改文件处理函数
     * 
     * @param array $content 旧的文件
     * @param string $file 新的文件
     * @return array|bool
     */
    public static function modifyHandle($content, $file) {
        // 把旧文件的路径给新文件
        $file['path'] = $content['attachment']->path;
        // 再上传新文件
        return self::uploadHandle($file, true);
    }

    /**
     * 删除文件
     * 
     * @param array $content 内容数组
     * @return bool
     */
    public static function deleteHandle(array $content) {
        if (empty($content['attachment']->fileid)) return false;
        // 获取插件配置
        $options = Helper::options()->plugin('ImageKitUploader');
        // 检查必需的配置
        if (empty($options->privateKey)) throw new Exception('ImageKitUploader: 缺少私钥配置');
        // 准备请求
        $client = Client::get();
        $client->setHeader('Authorization', 'Basic ' . base64_encode($options->privateKey . ':'))
               ->setHeader('Content-Type', 'application/json')
               ->setData(json_encode(['fileIds' => [$content['attachment']->fileid]]))
               ->setMethod(Client::METHOD_POST)
               ->send('https://imagekit.io/api/v1/files/batch/deleteByFileIds');
        $status = $client->getResponseStatus();
        // 如果删除不成功，则抛出错误
        if ($status < 200 || $status > 300) throw new Exception('ImageKitUploader: 删除文件失败: ' . $client->getResponseBody());
        // 删除成功直接返回true
        return true;
    }

    /**
    * 获取实际文件网址
    * 
    * @param array $content 文件相关信息
    * @return string
    */
    public static function attachmentHandle(array $content) {
        // 直接返回URL就可以了
        return $content['attachment']->url ?? '';
    }
    
    /**
     * 上传文件到ImageKit.io
     * 
     * @param string $filePath 本地临时文件路径
     * @param string $uploadPath ImageKit上的文件路径
     * @return bool
     */
    private static function uploadToImageKit($filePath, $uploadPath, $uploadName, $options = []) {
        // 检查配置
        if (empty($options->privateKey)) throw new Exception('ImageKitUploader: 缺少必需的配置信息');
        // 检查文件是否存在
        if (!file_exists($filePath) || !is_readable($filePath)) throw new Exception('ImageKitUploader: 文件不存在或不可读: ' . $filePath);     
        // 开始上传文件
        try {
            // 创建一个临时边界标识用于multipart表单
            $boundary = '----' . md5(uniqid(time()));
            // 构建multipart表单数据
            // 添加文件名
            $data = "--" . $boundary . "\r\n";
            $data .= 'Content-Disposition: form-data; name="fileName"' . "\r\n\r\n";
            $data .= $uploadName . "\r\n";
            
            // 添加文件夹
            $data .= "--" . $boundary . "\r\n";
            $data .= 'Content-Disposition: form-data; name="folder"' . "\r\n\r\n";
            $data .= $uploadPath . "\r\n";
            
            // 添加useUniqueFileName参数
            $data .= "--" . $boundary . "\r\n";
            $data .= 'Content-Disposition: form-data; name="useUniqueFileName"' . "\r\n\r\n";
            $data .= "false\r\n";
            
            // 获取文件内容
            $fileContent = file_get_contents($filePath);
            if ($fileContent === false) throw new Exception('ImageKitUploader: 无法读取文件内容: ' . $filePath);
            $data .= "--" . $boundary . "\r\n";
            $data .= 'Content-Disposition: form-data; name="file"; filename="' . basename($filePath) . '"' . "\r\n";
            $data .= 'Content-Type: application/octet-stream' . "\r\n\r\n";
            $data .= $fileContent . "\r\n";
            $data .= "--" . $boundary . "--\r\n";
            
            // 设置HTTP客户端
            $client = Client::get();
            $client->setHeader('Authorization', 'Basic ' . base64_encode($options->privateKey . ':'))
                   ->setHeader('Content-Type', 'multipart/form-data; boundary=' . $boundary)
                   ->setHeader('Content-Length', strlen($data))
                   ->setTimeout(intval($options->timeout) <= 0 ? 30 : $options->timeout)
                   ->setData($data)
                   ->setMethod(Client::METHOD_POST)
                   ->send('https://upload.imagekit.io/api/v1/files/upload');
            $status = $client->getResponseStatus();
            // 抛出错误
            if ($status < 200 || $status > 300) throw new Exception ('ImageKitUploader: 上传失败，HTTP状态码: ' . $status . ', 响应: ' . $client->getResponseBody());
            // 返回响应
            return json_decode($client->getResponseBody(), true);
        } catch (Exception $e) {
            throw new Exception('ImageKitUploader: 上传异常: ' . $e->getMessage());
        }
    }
    /**
     * 获取安全的文件名
     * 
     * @param string $name 文件名
     * @return string
     */
    private static function getSafeName(string $name): string {
        $name = str_replace(['"', '<', '>'], '', $name);
        $name = str_replace('\\', '/', $name);
        $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return $ext;
    }

    /**
     * 净化文件名，移除特殊字符
     * 
     * @param string $fileName 原始文件名
     * @return string 净化后的文件名
     */
    private static function sanitizeFileName($fileName) {
        // 获取扩展名
        $ext = self::getSafeName($fileName);
        // 获取文件名（不含扩展名）
        $name = basename($fileName, '.' . $ext);
        // 移除特殊字符
        $name = preg_replace('/[^\w\-]/', '_', $name);
        // 重新组合文件名
        return $name . '.' . $ext;
    }
}