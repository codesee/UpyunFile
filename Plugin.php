<?php
/**
 * Typecho又拍云文件管理
 * 
 * @package UpyunFile
 * @author codesee
 * @version 0.6.0
 * @link http://pengzhiyong.com
 * @date 2014-01-15
 */

class UpyunFile_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     * 
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Upload')->uploadHandle = array('UpyunFile_Plugin', 'uploadHandle');
        Typecho_Plugin::factory('Widget_Upload')->modifyHandle = array('UpyunFile_Plugin', 'modifyHandle');
        Typecho_Plugin::factory('Widget_Upload')->deleteHandle = array('UpyunFile_Plugin', 'deleteHandle');
        Typecho_Plugin::factory('Widget_Upload')->attachmentHandle = array('UpyunFile_Plugin', 'attachmentHandle');
        Typecho_Plugin::factory('Widget_Upload')->attachmentDataHandle = array('UpyunFile_Plugin', 'attachmentDataHandle');
		return _t('插件已经激活，请正确设置插件');
    }
    
    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     * 
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate(){
		return _t('插件已被禁用');
	}
    
    /**
     * 获取插件配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
		$upyundomain = new Typecho_Widget_Helper_Form_Element_Text('upyundomain', NULL, 'http://', _t('绑定域名：'), _t('该绑定域名为绑定Upyun空间的域名，由Upyun提供，注意以http://开头，最后不要加/'));
		$form->addInput($upyundomain->addRule('required',_t('您必须填写绑定域名，它是由Upyun提供'))
		->addRule('url', _t('您输入的域名格式错误')));
		
		$upyunpathmode = new Typecho_Widget_Helper_Form_Element_Radio(
            'mode',
            array('typecho' => _t('Typecho结构(/usr/uploads/年/月/文件名)'),'simple' => _t('精简结构(/年/月/文件名)')),
            'typecho',
            _t('目录结构模式'),
            _t('默认为Typecho结构模式')
        );
		
        $upyunhost = new Typecho_Widget_Helper_Form_Element_Text('upyunhost', NULL, NULL, _t('空间名：'));
		$upyunhost->input->setAttribute('class','mini');
		$form->addInput($upyunhost->addRule('required',_t('您必须填写空间名，它是由Upyun提供')));
		
        $upyunuser = new Typecho_Widget_Helper_Form_Element_Text('upyunuser', NULL, NULL, _t('操作员：'));
		$upyunuser->input->setAttribute('class','mini');
		$form->addInput($upyunuser->addRule('required',_t('您必须填写操作员，它是由Upyun提供')));

        $upyunpwd = new Typecho_Widget_Helper_Form_Element_Password('upyunpwd', NULL, NULL, _t('密码：'));
		$form->addInput($upyunpwd->addRule('required',_t('您必须填写密码，它是由Upyun提供'))
		->addRule(array('UpyunFile_Plugin', 'validate'), _t('验证不通过')));
    }
    
    /**
     * 个人用户的配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form){}
    
    /**
     * 上传文件处理函数
     *
     * @access public
     * @param array $file 上传的文件
     * @return mixed
     */
    public static function uploadHandle($file)
    {
        if (empty($file['name'])) {
            return false;
        }

        $fileName = preg_split("(\/|\\|:)", $file['name']);
        $file['name'] = array_pop($fileName);
        
        //获取扩展名
        $ext = '';
        $part = explode('.', $file['name']);
        if (($length = count($part)) > 1) {
            $ext = strtolower($part[$length - 1]);
        }

        if (!Widget_Upload::checkFileType($ext)) {
            return false;
        }

        $options = Typecho_Widget::widget('Widget_Options');
        $date = new Typecho_Date($options->gmtTime);
		
        //构建路径 /year/month/
        $path = '/' . $date->year . '/' . $date->month;
        $settings = $options->plugin('UpyunFile');
		
		if($settings->mode == 'typecho'){
			$path = '/usr/uploads' . $$path;
		}
		
        //获取文件名及文件路径
        $fileName = sprintf('%u', crc32(uniqid())) . '.' . $ext;
		$path = $path . '/' . $fileName;
		
		$uploadfile = isset($file['tmp_name']) ? $file['tmp_name'] : (isset($file['bits']) ? $file['bits'] : FALSE);	
		if ($uploadfile == FALSE) {	
			return false;	
		}
		else{
			//上传文件
			$upyun = self::upyunInit();
			$fh = fopen($uploadfile , 'r');
			$upyun->writeFile($path, $fh ,TRUE);
			fclose($fh);
		}
		
		if (!isset($file['size'])){
			$fileInfo = $upyun->getFileInfo($path);
			$file['size'] = $fileInfo['x-upyun-file-size'];
		}

        //返回相对存储路径
        return array(
            'name' => $file['name'],
            'path' => $path,
            'size' => $file['size'],
            'type' => $ext,
            'mime' => Typecho_Common::mimeContentType($path)
        );
    }

    /**
     * 修改文件处理函数
     *
     * @access public
     * @param array $content 老文件
     * @param array $file 新上传的文件
     * @return mixed
     */
    public static function modifyHandle($content, $file)
    {
        if (empty($file['name'])) {
            return false;
        }

        $fileName = preg_split("(\/|\\|:)", $file['name']);
        $file['name'] = array_pop($fileName);
        
        //获取扩展名
        $ext = '';
        $part = explode('.', $file['name']);
        if (($length = count($part)) > 1) {
            $ext = strtolower($part[$length - 1]);
        }

        if ($content['attachment']->type != $ext) {
            return false;
        }

        //获取文件路径
        $path = $content['attachment']->path;
		
		$uploadfile = isset($file['tmp_name']) ? $file['tmp_name'] : (isset($file['bits']) ? $file['bits'] : FALSE);	
		if ($uploadfile == FALSE) {	
			return false;	
		}
		else{
			//修改文件
			$upyun = self::upyunInit();
			$fh = fopen($uploadfile , 'r');
			$upyun->writeFile($path, $fh ,TRUE);
			fclose($fh);
		}
		
		if (!isset($file['size'])){
			$fileInfo = $upyun->getFileInfo($path);
			$file['size'] = $fileInfo['x-upyun-file-size'];
		}

        //返回相对存储路径
        return array(
            'name' => $content['attachment']->name,
            'path' => $content['attachment']->path,
            'size' => $file['size'],
            'type' => $content['attachment']->type,
            'mime' => $content['attachment']->mime
        );
    }

    /**
     * 删除文件
     *
     * @access public
     * @param array $content 文件相关信息
     * @return string
     */
    public static function deleteHandle(array $content)
    {
        $upyun = self::upyunInit();
		$path = $content['attachment']->path;

        return $upyun->delete($path);
    }

    /**
     * 获取实际文件绝对访问路径
     *
     * @access public
     * @param array $content 文件相关信息
     * @return string
     */
    public static function attachmentHandle(array $content)
    {
        $domain = Helper::options()->plugin('UpyunFile')->upyundomain;
        return Typecho_Common::url($content['attachment']->path, $domain);
    }

    /**
     * 获取实际文件数据
     *
     * @access public
     * @param array $content
     * @return string
     */
    public static function attachmentDataHandle(array $content)
    {
        $upyun = self::upyunInit();
        return $upyun->getFileInfo($content['attachment']->path);
    }

	/**
     * 验证Upyun签名
     * 
     * @access public
     * 
     * @return boolean
     */
	public static function validate()
	{
		$host = Typecho_Request::getInstance()->upyunhost;
		$user = Typecho_Request::getInstance()->upyunuser;
		$pwd = Typecho_Request::getInstance()->upyunpwd;
		$hostUsage = 0;
		
		try{
			require_once 'SDK/upyun.class.php';
			$upyun = new UpYun($host, $user, $pwd);
			$hostUsage = (int)$upyun->getFolderUsage('/');
		}
		catch(Exception $e){
			$hostUsage = -1;
		}
		
		return $hostUsage >= 0;
	}
	
    /**
     * Upyun初始化
     *
     * @access public
     * @return object
     */
    public static function upyunInit()
    {
        $options = Typecho_Widget::widget('Widget_Options')->plugin('UpyunFile');
        require_once 'SDK/upyun.class.php';
        return new UpYun($options->upyunhost, $options->upyunuser, $options->upyunpwd);
    }
}
