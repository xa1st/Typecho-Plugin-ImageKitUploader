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
use Typecho\Date;
use Utils\Helper;
use Widget\Upload;

// 防止直接运行
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 将Typecho的附件上传至ImageKit.io云存储
 * 
 * @package ImageKitUploader
 * @author 猫东东
 * @version 1.2.0
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
            
        // ImageKit.io 公钥
        $publicKey = new Text(
            'publicKey', 
            null, 
            '', 
            _t('公钥(Public Key)'), 
            _t('输入您的ImageKit公钥，可以在 ImageKit.io 控制台获取')
        );
        $form->addInput($publicKey->addRule('required', _t('公钥不能为空')));
            
        // ImageKit.io Endpoint URL
        $endpointUrl = new Text(
            'endpointUrl', 
            null, 
            '', 
            _t('URL端点(Endpoint URL)'), 
            _t('输入您的ImageKit端点URL，示例: https://ik.imagekit.io/your_account')
        );
        $form->addInput($endpointUrl->addRule('required', _t('端点URL不能为空')));
            
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
     * @return array|bool
     */
    public static function uploadHandle($file) {
        if (empty($file['name'])) return false;
        // 获取扩展名
        $ext = self::getSafeName($file['name']);
        // 验证可上传文件类型
        if (!Upload::checkFileType($ext)) return false;
           
        // 上传文件（统一处理）
        return self::uploadFile($file);
    }
    

    /**
     * 修改文件处理函数
     * 
     * @param array $content 旧的文件
     * @param string $file 新的文件
     * @return array|bool
     */
    public static function modifyHandle($content, $file) {
        // 如果不存在附件，直接返回
        if (!isset($content['attachment'])) return false;
        // 获取扩展名
        $ext = self::getSafeName($content['name']);
        $fileName = sprintf('%u', crc32(uniqid())) . '.' . $ext;
        
        // 获取插件配置
        $options = Helper::options()->plugin('ImageKitUploader');
        $date = new Date();
        $filePath = $date->year . '/' . $date->month . '/';
        $path = $options->uploadPath . '/' . $filePath;
        
        // 上传文件
        $result = self::uploadToImageKit($file, $path . $fileName);
        if (!$result) return false;
        
        // 删除旧文件
        self::deleteFile($content['attachment']->path);
        
        // 返回新文件信息
        return [
            'name' => $content['name'], 
            'path' => $path . $fileName, 
            'size' => $content['size'], 
            'type' => $content['type'], 
            'mime' => $content['mime']
        ];
    }
    

    /**
     * 删除文件
     * 
     * @param array $content 内容数组
     * @return bool
     */
    public static function deleteHandle(array $content) {
        if (!isset($content['attachment'])) return false;
        return self::deleteFile($content['attachment']->path);
    }
    

    /**
     * 获取实际附件地址
     * 
     * @param array $content 内容数组
     * @return string
     */
    public static function attachmentHandle(array $content) {
        // 获取插件配置
        $options = Helper::options()->plugin('ImageKitUploader');
            
        // 自定义域名
        if (!empty($options->customDomain)) {
            $url = rtrim($options->customDomain, '/');
            return $url . '/' . ltrim($content['attachment']->path, '/');
        }
            
        // 使用默认域名
        $url = rtrim($options->endpointUrl, '/');
        return $url . '/' . ltrim($content['attachment']->path, '/');
    }
    

    /**
     * 上传文件
     * 
     * @param array $file 上传的文件
     * @return array|bool
     */
    private static function uploadFile($file) {
        // 文件名为空就直接返回
        if (empty($file['name'])) return false;
        // 获取插件配置
        $options = Helper::options()->plugin('ImageKitUploader');
        // 获取文件扩展名
        $ext = self::getSafeName($file['name']);
        // 生成保存路径
        $date = new Date();
        $filePath = $date->year . '/' . $date->month . '/';
        $path = $options->uploadPath . '/' . $filePath;
            
        // 生成文件名
        $fileName = $options->useOriginFileName == '1' ? 
            self::sanitizeFileName($file['name']) : 
            sprintf('%u', crc32(uniqid())) . '.' . $ext;
            
        // 上传到ImageKit.io
        $uploaded = self::uploadToImageKit($file['tmp_name'], $path . $fileName);
           
        if (!$uploaded) return false;

        // 构建附件结构
        return [
            'name' => $file['name'],
            'path' => $path . $fileName,
            'size' => $file['size'],
            'type' => $file['type'],
            'mime' => @Common::mimeContentType($file['tmp_name'])
        ];
    }
    

    /**
     * 删除指定路径的文件
     * 
     * @param string $path 文件路径
     * @return bool
     */
    private static function deleteFile($path) {
        if (empty($path)) return true;
            
        // 获取插件配置
        $options = Helper::options()->plugin('ImageKitUploader');
        
        // 检查必需的配置
        if (empty($options->privateKey)) {
            error_log('ImageKitUploader: 缺少私钥配置');
            return false;
        }
            
        try {
            // 构建删除请求
            $auth = base64_encode($options->privateKey . ':');
            
            // 准备请求
            $client = Client::get();
            $client->setHeader('Authorization', 'Basic ' . $auth)
                   ->setHeader('Content-Type', 'application/json')
                   ->setData(json_encode(['fileIds' => [$path]]))
                   ->setMethod(Client::METHOD_POST)
                   ->send('https://api.imagekit.io/v1/files/batch/delete');
            
            $status = $client->getResponseStatus();
            if ($status >= 200 && $status < 300) {
                return true;
            } else {
                error_log('ImageKitUploader: 删除失败，HTTP状态码: ' . $status . ', 响应: ' . $client->getResponseBody());
                return false;
            }
        } catch (Exception $e) {
            error_log('ImageKitUploader: 删除异常: ' . $e->getMessage());
            return false;
        }
    }
    

    /**
     * 上传文件到ImageKit.io
     * 
     * @param string $filePath 本地临时文件路径
     * @param string $uploadPath ImageKit上的文件路径
     * @return bool
     */
    private static function uploadToImageKit($filePath, $uploadPath) {
        // 获取插件配置
        $options = Helper::options()->plugin('ImageKitUploader');
        
        // 检查配置
        if (empty($options->privateKey) || empty($options->publicKey) || empty($options->endpointUrl)) {
            error_log('ImageKitUploader: 缺少必需的配置信息');
            return false;
        }
        
        // 检查文件是否存在
        if (!file_exists($filePath) || !is_readable($filePath)) {
            error_log('ImageKitUploader: 文件不存在或不可读: ' . $filePath);
            return false;
        }
            
        try {
            // 构建上传所需认证信息
            $auth = base64_encode($options->privateKey . ':');
            
            // 构建文件上传参数
            $fileName = basename($uploadPath);
            $folder = dirname($uploadPath);
            
            // 创建一个临时边界标识用于multipart表单
            $boundary = '----' . md5(uniqid(time()));
            
            // 获取文件内容
            $fileContent = file_get_contents($filePath);
            if ($fileContent === false) {
                error_log('ImageKitUploader: 无法读取文件内容: ' . $filePath);
                return false;
            }
            
            // 构建multipart表单数据
            $data = '';
            // 添加文件名
            $data .= "--" . $boundary . "\r\n";
            $data .= 'Content-Disposition: form-data; name="fileName"' . "\r\n\r\n";
            $data .= $fileName . "\r\n";
            
            // 添加文件夹
            $data .= "--" . $boundary . "\r\n";
            $data .= 'Content-Disposition: form-data; name="folder"' . "\r\n\r\n";
            $data .= $folder . "\r\n";
            
            // 添加useUniqueFileName参数
            $data .= "--" . $boundary . "\r\n";
            $data .= 'Content-Disposition: form-data; name="useUniqueFileName"' . "\r\n\r\n";
            $data .= "false\r\n";
            
            // 添加文件数据
            $data .= "--" . $boundary . "\r\n";
            $data .= 'Content-Disposition: form-data; name="file"; filename="' . basename($filePath) . '"' . "\r\n";
            $data .= 'Content-Type: application/octet-stream' . "\r\n\r\n";
            $data .= $fileContent . "\r\n";
            $data .= "--" . $boundary . "--\r\n";
            
            // 设置HTTP客户端
            $client = Client::get();
            $client->setHeader('Authorization', 'Basic ' . $auth)
                   ->setHeader('Content-Type', 'multipart/form-data; boundary=' . $boundary)
                   ->setHeader('Content-Length', strlen($data))
                   ->setData($data)
                   ->setMethod(Client::METHOD_POST)
                   ->send('https://upload.imagekit.io/api/v1/files/upload');
            
            $status = $client->getResponseStatus();
            
            // 检查响应
            if ($status >= 200 && $status < 300) {
                return true;
            } else {
                error_log('ImageKitUploader: 上传失败，HTTP状态码: ' . $status . ', 响应: ' . $client->getResponseBody());
                return false;
            }
        } catch (Exception $e) {
            error_log('ImageKitUploader: 上传异常: ' . $e->getMessage());
            return false;
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
