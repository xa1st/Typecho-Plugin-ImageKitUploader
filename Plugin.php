<?php
/**
 * 将Typecho的附件上传至ImageKit.io云存储
 * 
 * @package ImageKitUploader
 * @author 猫东东
 * @version 1.0.1
 * @link https://github.com/xa1st
 */
class ImageKitUploader_Plugin implements Typecho_Plugin_Interface
{
  /**
   * 激活插件方法，如果激活失败，直接抛出异常
   * 
   * @return void
   * @throws Typecho_Plugin_Exception
   */
  public static function activate() {
    // 挂载上传文件钩子
    Typecho_Plugin::factory('Widget_Upload')->uploadHandle = ['ImageKitUploader_Plugin', 'uploadHandle'];
    Typecho_Plugin::factory('Widget_Upload')->modifyHandle = ['ImageKitUploader_Plugin', 'modifyHandle'];
    Typecho_Plugin::factory('Widget_Upload')->deleteHandle = ['ImageKitUploader_Plugin', 'deleteHandle'];
    Typecho_Plugin::factory('Widget_Upload')->attachmentHandle = ['ImageKitUploader_Plugin', 'attachmentHandle'];
    
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
   * @param Typecho_Widget_Helper_Form $form 配置面板
   * @return void
   */
  public static function config(Typecho_Widget_Helper_Form $form) {
    // ImageKit.io 私钥
    $privateKey = new Typecho_Widget_Helper_Form_Element_Password(
      'privateKey', 
      null, 
      '', 
      _t('私钥(Private Key)'), 
      _t('输入您的ImageKit私钥，可以在 ImageKit.io 控制台获取')
    );
    $form->addInput($privateKey->addRule('required', _t('私钥不能为空')));
        
    // ImageKit.io 公钥
    $publicKey = new Typecho_Widget_Helper_Form_Element_Text(
      'publicKey', 
      null, 
      '', 
      _t('公钥(Public Key)'), 
      _t('输入您的ImageKit公钥，可以在 ImageKit.io 控制台获取')
    );
    $form->addInput($publicKey->addRule('required', _t('公钥不能为空')));
        
    // ImageKit.io Endpoint URL
    $endpointUrl = new Typecho_Widget_Helper_Form_Element_Text(
      'endpointUrl', 
      null, 
      '', 
      _t('URL端点(Endpoint URL)'), 
      _t('输入您的ImageKit端点URL，示例: https://ik.imagekit.io/your_account')
    );
    $form->addInput($endpointUrl->addRule('required', _t('端点URL不能为空')));
        
    // 上传目录
    $uploadPath = new Typecho_Widget_Helper_Form_Element_Text(
      'uploadPath', 
      null, 
      'typecho', 
      _t('上传路径前缀'), 
      _t('图片上传到ImageKit的路径前缀，例如：typecho，则上传路径为 typecho/年/月/文件名')
    );
    $form->addInput($uploadPath);
        
    // 自定义域名
    $customDomain = new Typecho_Widget_Helper_Form_Element_Text(
      'customDomain', 
      null, 
      '', 
      _t('自定义域名'), 
      _t('设置自定义域名，例如：https://cdn.example.com（可选），ImageKit的免费用户无此功能，请留空，')
    );
    $form->addInput($customDomain);
        
    // 是否使用原始文件名
    $useOriginFileName = new Typecho_Widget_Helper_Form_Element_Radio(
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
   * @param Typecho_Widget_Helper_Form $form
   * @return void
   */
  public static function personalConfig(Typecho_Widget_Helper_Form $form) {
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
    $ext = self::getExtension($file['name']);
    if (!self::checkFileType($ext)) return false;
       
    // 判断是否为图片类型
    if (in_array($ext, ['gif', 'jpg', 'jpeg', 'png', 'bmp', 'webp'])) {
      // 处理图片上传
      return self::uploadFile($file);
    } else {
      // 处理非图片类型文件上传
      return self::uploadFile($file);
    }
  }
    
  /**
   * 修改文件处理函数
   * 
   * @param array $content 旧的文件
   * @param array $file 新的文件
   * @return array|bool
   */
  public static function modifyHandle($content, $file) {
    if (empty($file['name'])) return false;
    // 删除旧文件
    self::deleteFile($content['attachment']->path);
    // 上传新文件
    return self::uploadHandle($file);
  }
    
  /**
   * 删除文件
   * 
   * @param string $path 文件路径
   * @return bool
   */
  public static function deleteHandle(array $content): bool {
    self::deleteFile($content['attachment']->path);
    return true;
  }
    
  /**
   * 获取实际附件地址
   * 
   * @param array $content 内容数组
   * @return string
   */
  public static function attachmentHandle(array $content): string {
    $options = Helper::options();
    $pluginOptions = $options->plugin('ImageKitUploader');
        
    // 自定义域名
    if (!empty($pluginOptions->customDomain)) {
      $url = rtrim($pluginOptions->customDomain, '/');
      return $url . '/' . $content['attachment']->path;
    }
        
    // 使用默认域名
    $url = rtrim($pluginOptions->endpointUrl, '/');
    return $url . '/' . $content['attachment']->path;
  }
    
  /**
   * 上传文件
   * 
   * @param array $file 上传的文件
   * @return array|bool
   */
  private static function uploadFile($file) {
    if (empty($file['name'])) return false;
       
    // 获取插件配置
    $options = Helper::options();
    $pluginOptions = $options->plugin('ImageKitUploader');
        
    // 获取文件扩展名
    $ext = self::getExtension($file['name']);
        
    // 生成保存路径
    $date = new Typecho_Date();
    $path = $pluginOptions->uploadPath . '/' . $date->year . '/' . $date->month;
        
    // 生成文件名
    $fileName = $pluginOptions->useOriginFileName == '1' ? 
    self::sanitizeFileName($file['name']) : 
    sprintf('%s.%s', md5(uniqid() . mt_rand()), $ext);
        
    // 完整的文件路径
    $uploadPath = $path . '/' . $fileName;
      
    // 上传到ImageKit.io
    $uploaded = self::uploadToImageKit($file['tmp_name'], $uploadPath);
       
    if (!$uploaded) return false;
        
    // 构建附件结构
    $attachment = [
      'name' => $file['name'],
      'path' => $uploadPath,
      'size' => $file['size'],
      'type' => $ext,
      'mime' => $file['type'] ?? self::getMimeType($ext)
    ];
    return $attachment;
  }
    
  /**
   * 删除指定路径的文件
   * 
   * @param string $path 文件路径
   * @return bool
   */
  private static function deleteFile($path) {
    if (empty($path)) return false;
        
    // 获取插件配置
    $options = Helper::options();
    $pluginOptions = $options->plugin('ImageKitUploader');
        
    try {
      // 构建删除请求
      $auth = base64_encode($pluginOptions->privateKey . ':');
      $timestamp = time();
            
      // 生成签名
      $urlPath = "/files/remove";  // ImageKit API endpoint for file deletion
      $dataToSign = $timestamp . $urlPath;
      $signature = hash_hmac('sha1', $dataToSign, $pluginOptions->privateKey);
            
      // 准备请求
      $client = Typecho_Http_Client::get();
      $client->setHeader('Authorization', 'Basic ' . $auth)
             ->setHeader('X-Signature', $signature)
             ->setHeader('X-Timestamp', $timestamp)
             ->setHeader('Content-Type', 'application/json');
      
      $response = $client->setData(json_encode(['fileIds' => [$path]]))
                         ->send('https://api.imagekit.io/v1/files/remove', Typecho_Http_Client::METHOD_POST);
      
      $status = $client->getResponseStatus();
      return $status >= 200 && $status < 300;
    } catch (Exception $e) {
      Typecho_Widget::widget('Widget_Notice')->set(_t('文件删除失败：' . $e->getMessage()), 'error');
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
    $pluginOptions = Helper::options()->plugin('ImageKitUploader');
    // 检查配置
    if (empty($pluginOptions->privateKey) || empty($pluginOptions->publicKey) || empty($pluginOptions->endpointUrl)) {
      Typecho_Widget::widget('Widget_Notice')->set(_t('请先配置ImageKit插件参数'), 'error');
      return false;
    }
        
    try {
      // 构建上传所需认证信息
      $auth = base64_encode($pluginOptions->privateKey . ':');
      
      // 构建文件上传参数
      $fileName = basename($uploadPath);
      $folder = dirname($uploadPath);
      
      // 创建一个临时边界标识用于multipart表单
      $boundary = '----' . md5(uniqid(time()));
      
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
      $data .= file_get_contents($filePath) . "\r\n";
      $data .= "--" . $boundary . "--\r\n";
      
      // 设置HTTP客户端
      $client = Typecho_Http_Client::get();
      $client->setHeader('Authorization', 'Basic ' . $auth)
             ->setHeader('Content-Type', 'multipart/form-data; boundary=' . $boundary)
             ->setHeader('Content-Length', strlen($data));
      
      // 发送请求
      $response = $client->setData($data)
                         ->send('https://upload.imagekit.io/api/v1/files/upload', Typecho_Http_Client::METHOD_POST);
      
      $status = $client->getResponseStatus();
      
      // 检查响应
      if ($status < 200 || $status >= 300) {
        $error = json_decode($response, true);
        $errorMessage = isset($error['message']) ? $error['message'] : '未知错误';
        Typecho_Widget::widget('Widget_Notice')->set(_t('文件上传失败：' . $errorMessage), 'error');
        return false;
      }
      
      return true;  
    } catch (Exception $e) {
      Typecho_Widget::widget('Widget_Notice')->set(_t('文件上传失败：' . $e->getMessage()), 'error');
      return false;
    }
  }
    
  /**
   * 获取文件扩展名
   * 
   * @param string $fileName 文件名
   * @return string
   */
  private static function getExtension($fileName) {
    $parts = explode('.', $fileName);
    return strtolower(end($parts));
  }
    
  /**
   * 检查文件类型是否允许上传
   * 
   * @param string $ext 扩展名
   * @return bool
   */
  private static function checkFileType($ext) {
    $allowedTypes = [
      'gif', 'jpg', 'jpeg', 'png', 'bmp', 'webp',
      'zip', 'rar', 'pdf', 'doc', 'docx', 'xls', 'xlsx',
      'ppt', 'pptx', 'txt', 'mp3', 'mp4', 'wmv', 'avi'
    ];
        
    return in_array($ext, $allowedTypes);
  }
    
  /**
   * 获取MIME类型
   * 
   * @param string $ext 扩展名
   * @return string
   */
  private static function getMimeType($ext) {
    $mimeTypes = [
      'gif' => 'image/gif',
      'jpg' => 'image/jpeg',
      'jpeg' => 'image/jpeg',
      'png' => 'image/png',
      'bmp' => 'image/bmp',
      'webp' => 'image/webp',
      'zip' => 'application/zip',
      'rar' => 'application/x-rar-compressed',
      'pdf' => 'application/pdf',
      'doc' => 'application/msword',
      'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'xls' => 'application/vnd.ms-excel',
      'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'ppt' => 'application/vnd.ms-powerpoint',
      'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
      'txt' => 'text/plain',
      'mp3' => 'audio/mpeg',
      'mp4' => 'video/mp4',
      'wmv' => 'video/x-ms-wmv',
      'avi' => 'video/x-msvideo'
    ];
    return $mimeTypes[$ext] ?? 'application/octet-stream';
  }
    
  /**
    * 净化文件名，移除特殊字符
    * 
    * @param string $fileName 原始文件名
    * @return string 净化后的文件名
    */
  private static function sanitizeFileName($fileName) {
    // 获取扩展名
    $ext = self::getExtension($fileName);
    // 获取文件名（不含扩展名）
    $name = basename($fileName, '.' . $ext);
    // 移除特殊字符
    $name = preg_replace('/[^\w\-]/', '_', $name);
    // 重新组合文件名
    return $name . '.' . $ext;
  }
}