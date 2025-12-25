<?php
/*
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace TypechoPlugin\TypechoCosPlugin;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Hidden;
use Typecho\Widget\Helper\Form\Element\Select;
use Typecho\Widget\Helper\Form\Element\Checkbox;
use Widget\Options;
use Utils\Helper;


if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

//插件名
if (!defined('pluginName')) {
    define('pluginName', 'TypechoCosPlugin');
}

/**
 * 实现网站静态资源存储到腾讯云COS，有效降低本地存储负载，提升用户体验。
 * @package 腾讯云对象存储（COS）插件
 * @author 腾讯云对象存储（COS）
 * @version 1.0.4
 * @link https://github.com/Tencent-Cloud-Plugins/tencentcloud-typecho-plugin-cos
 * @dependence 1.1.2-*
 * @date 2022-10-24
 */

class Plugin implements PluginInterface
{
    #上传文件目录
    const UPLOAD_DIR = 'usr/uploads';

    /**
     * @description: 激活插件方法,如果激活失败,直接抛出异常
     * @return {*}
     */
    public static function activate()
    {
        \Typecho\Plugin::factory('Widget_Upload')->uploadHandle = array(pluginName . '_Plugin', 'uploadHandle');
        \Typecho\Plugin::factory('Widget_Upload')->modifyHandle = array(pluginName . '_Plugin', 'modifyHandle');
        \Typecho\Plugin::factory('Widget_Upload')->deleteHandle = array(pluginName . '_Plugin', 'deleteHandle');
        \Typecho\Plugin::factory('Widget_Upload')->attachmentHandle = array(pluginName . '_Plugin', 'attachmentHandle');
        \Typecho\Plugin::factory('Widget_Upload')->attachmentDataHandle = array(pluginName . '_Plugin', 'attachmentDataHandle');

        \Typecho\Plugin::factory('Widget_Archive')->beforeRender = array(pluginName . '_Plugin', 'Widget_Archive_beforeRender');
        return _t('COS插件已激活，正确设置后才可正常使用哦~');
    }

    /**
     * @description: 禁用插件方法,如果禁用失败,直接抛出异常
     * @return {*}
     */
    public static function deactivate()
    {
        return _t('插件已禁用，可能会影响文章图片/附件哦~');
    }

    /**
     * @description: 查询极智压缩配置
     * @return {*}
     */
    public static function getImageSlim()
    {
        try {
            $opt = Options::alloc()->plugin(pluginName);

            $cosClient = self::CosInit($opt);

            $bucket = $opt->bucket;
            $region = $opt->region;

            if (!$bucket) return '未设置存储桶';
            if (!$region) return '未设置地域';

            try {
                $headResult = $cosClient->headBucket(array(
                    'Bucket' => $bucket,
                ));
            } catch (\Exception $e) {
                if ($e->getExceptionCode() === 'NoSuchBucket') {
                    return '存储桶不存在，请检查存储桶和地域配置';
                } else {
                    return '调用 HeadBucket 存储桶失败';
                }
            }

            if (isset($headResult['BucketArch'])) {
                return "存储桶 $bucket 是 OFS 桶，暂不支持图片极智压缩";
            }

            try {
                $slimResult = $cosClient->getImageSlim(array(
                    'Bucket' => $bucket,
                ));
                return $slimResult;
            } catch (\Exception $e) {
                if ($e->getExceptionCode() === 'UpsAppNotExist') {
                    return "存储桶 $bucket 未绑定数据万象，若要开启极智压缩，请先 <a href=\"https://console.cloud.tencent.com/ci/bucket\" target=\"_blank\">绑定数据万象服务</a>";
                } else if ($e->getExceptionCode() === 'RegionUnsupport') {
                    return "存储桶所在地域 $region 暂不支持图片极智压缩";
                } else {
                    return "存储桶极智压缩配置获取失败";
                }
            }
        } catch (\Exception $exception) {
            return '';
        }
    }

    /**
     * @description: 设置极智压缩配置
     * @return {*}
     */
    public static function setImageSlim($config)
    {
        try {
            $opt = Options::alloc()->plugin(pluginName);
            $cosClient = self::CosInit($opt);

            $bucket = $opt->bucket;
            $region = $opt->region;

            if (!$bucket) return '未设置存储桶';
            if (!$region) return '未设置地域';

            unset($config['image_slim_tips']);

            try {
                $headResult = $cosClient->headBucket(array(
                    'Bucket' => $bucket,
                ));
            } catch (\Exception $e) {
                if ($e->getExceptionCode() === 'NoSuchBucket') {
                    return "存储桶不存在，请检查存储桶和地域配置";
                } else {
                    return $e->getExceptionCode() . ', ' . $e->getMessage();
                }
            }

            if (isset($headResult['BucketArch'])) {
                return "存储桶 $bucket 是 OFS 桶，暂不支持图片极智压缩";
            }

            // 生成 SlimMode
            $newSlimModeArr = array();
            $autoMode = false;

            if (in_array('on', $config['image_slim_auto'])) {
                $autoMode = true;
                $newSlimModeArr[] = 'Auto';
            }

            if (in_array('on', $config['image_slim_api'])) {
                if ($autoMode) {
                    return '「自动压缩模式」和「API调用模式」不能同时开启，请二选一）';
                }
                $newSlimModeArr[] = 'API';
            }

            $suffix = $config['image_slim_suffix'];
            $newSlimMode = join(',', $newSlimModeArr);

            if ($newSlimMode) {
                if ($autoMode && !$suffix) {
                    return '未选择图片格式（开启了自动极智压缩，必须选择图片格式）';
                }
                try {
                    $cosClient->openImageSlim(array(
                        'Bucket' => $bucket,
                        'SlimMode' => $newSlimMode,
                        'Suffixs' => array(
                            'Suffix' => $suffix,
                        ),
                    ));
                } catch (\Exception $e) {
                    return $e->getExceptionCode() . ', ' . $e->getMessage();
                }
            } else {
                try {
                    $cosClient->closeImageSlim(array(
                        'Bucket' => $bucket,
                    ));
                } catch (\Exception $e) {
                    return $e->getExceptionCode() . ', ' . $e->getMessage();
                }
            }
        } catch (\Exception $exception) {
            return '';
        }
        return '';
    }

    /**
     * @description: 获取插件配置面板
     * @param {Form} $form
     * @return {*}
     */
    public static function config(Form $form)
    {
        ?>
        <link rel="stylesheet" href="<?php echo Helper::options()->rootUrl . '/usr/plugins/' . pluginName; ?>/statics/css/joe.config.min.css">
        <script src="<?php echo  Helper::options()->rootUrl . '/usr/plugins/' . pluginName; ?>/statics/js/joe.config.min.js"></script>
        <div class="joe_config">
            <div>
                <div class="joe_config__aside">
                    <div class="logo">腾讯云COS</div>
                    <ul class="tabs">
                        <li class="item" data-current="joe_notice">使用说明</li>
                        <li class="item" data-current="joe_base">基础设置</li>
                        <li class="item" data-current="joe_advanced">高级设置</li>
                        <li class="item" data-current="joe_slim">极智压缩</li>
                    </ul>
                </div>
            </div>
            <span id="joe_version" style="display: none;"></span>
            <div class="joe_config__notice">
                <p class="title">使用说明</p>
                <ol>
                    <?php
                    if (self::compareVersion('5.6.1') < 0) {
                        $notice = '<li style="color:red; font-weight:800;">本插件要求php版本号>=5.6，否则可能无法正常运行！！！<br></li>';
                        echo $notice;
                    } ?>
                    <li>插件基于腾讯云cos-php-sdk-v5开发，若发现插件不可用，请到 <a target="_blank" href="https://github.com/Tencent-Cloud-Plugins/tencentcloud-typecho-plugin-cos">GitHub发布地址</a> 检查是否有更新，或者提交Issues<br></li>
                    <li>插件会验证配置的正确性，如填写错误会报错<br></li>
                    <li>插件会自动替换之前文件的链接，若启用插件前已上传文件，为保证正常显示，请自行将其上传至COS相同路径<br></li>
                    <li>禁用插件会恢复为本地路径，为保证正常显示，请自行将数据从COS下载至相同路径<br></li>
                    <li>重新更改插件中关于COS桶的配置需要先禁用插件，再重新设置<br></li>
                </ol>
            </div>
            <?php
            $secid = new Text('secid', NULL, '', _t('SecretId(必需)'), _t('腾讯云控制台 <a target="_blank" href="https://console.cloud.tencent.com/capi">个人API密钥</a> 获取 SecretId'));
            $secid->setAttribute('class', 'joe_content joe_base');
            $secid->addRule('required', _t('SecretId不能为空！'));

            $sekey = new Text('sekey', NULL, '', _t('SecretKey(必需)'), _t('腾讯云控制台 <a target="_blank" href="https://console.cloud.tencent.com/capi">个人API密钥</a> 获取 SecretKey'));
            $sekey->setAttribute('class', 'joe_content joe_base');
            $sekey->addRule('required', _t('SecretKey不能为空！'));

            $region = new Select(
                'region',
                array(
                    'ap-beijing' => _t('北京'),
                    'ap-beijing-1' => _t('天津'),
                    'ap-nanjing' => _t('南京'),
                    'ap-shanghai' => _t('上海'),
                    'ap-guangzhou' => _t('广州'),
                    'ap-chengdu' => _t('成都'),
                    'ap-chongqing' => _t('重庆'),
                    'ap-shenzhen-fsi' => _t('深圳金融'),
                    'ap-shanghai-fsi' => _t('上海金融'),
                    'ap-beijing-fsi' => _t('北京金融'),
                    'ap-hongkong' => _t('香港'),
                    'ap-singapore' => _t('新加坡'),
                    'ap-mumbai' => _t('孟买'),
                    'ap-jakarta' => _t('雅加达'),
                    'ap-seoul' => _t('首尔'),
                    'ap-bangkok' => _t('曼谷'),
                    'ap-tokyo' => _t('东京'),
                    'na-toronto' => _t('多伦多'),
                    'na-siliconvalley' => _t('硅谷（美西）'),
                    'na-ashburn' => _t('弗吉尼亚（美东）'),
                    'sa-saopaulo' => _t('圣保罗'),
                    'eu-frankfurt' => _t('法兰克福'),
                    'eu-moscow' => _t('莫斯科'),
                ),
                'ap-beijing-1',
                _t('所属地域(必需)')
            );
            $region->setAttribute('class', 'joe_content joe_base');

            $bucket = new Text('bucket', NULL, '', _t('存储桶名称(必需)'), _t('格式为 BucketName-Appid ，如 typecho-12345678 ,可在 <a target="_blank" href="https://console.cloud.tencent.com/cos/bucket">腾讯云控制台</a> 获取'));
            $bucket->setAttribute('class', 'joe_content joe_base');
            $bucket->addRule('required', _t('存储桶名称不能为空！'));


            $path = new Text('path', NULL, 'usr/uploads', _t('对象存储路径(必需)'), _t('默认为 usr/uploads，建议不要修改（无需以/开头）'));
            $path->setAttribute('class', 'joe_content joe_base');

            $domain = new Text(
                'domain',
                NULL,
                '',
                _t('访问域名（若配置错误无法正常访问）'),
                _t('留空则使用默认域名，如：https://images-sh-123456789.cos.ap-shanghai.myqcloud.com<br>
        可使用自定义源站域名，例如：cos.example.com（请注意不要以 http:// 或 https:// 开头，仅填写域名即可，默认以https访问）,如需配置可参考 <a target="_blank" href="https://cloud.tencent.com/document/product/436/36638">官方文档</a><br>
        可使用自定义CDN域名，例如：cos.example.com（请注意不要以 http:// 或 https:// 开头，仅填写域名即可，默认以https访问）,如需配置可参考 <a target="_blank" href="https://cloud.tencent.com/document/product/436/36637">官方文档</a>
        ')
            );
            $domain->setAttribute('class', 'joe_content joe_advanced');

            $cdn_domain = new Text(
                'cdn_domain',
                NULL,
                '',
                _t('访问域名，CDN域名，减少COS费用开支')
            );
            $cdn_domain->setAttribute('class', 'joe_content joe_advanced');

            $sign = new Select('sign', array(
                'open' => _t('开启(建议)'),
                'close' => _t('关闭'),
            ), 'open', _t('使用签名链接'), _t('生成带有签名的对象链接，有效期10分钟，可有效防盗链，建议开启<br>须将存储桶对应路径的访问权限设置为<b>私有读写</b>才真正有效，可参考 <a target="_blank" href="https://cloud.tencent.com/document/product/436/13327">官方文档</a>'));
            $sign->setAttribute('class', 'joe_content joe_advanced');

            $remote_sync = new Select('remote_sync', array(
                'open' => _t('开启'),
                'close' => _t('关闭'),
            ), 'open', _t('本地删除同步删除COS文件'), _t('在文件管理删除文件时，是否同步删除COS上的对应文件'));
            $remote_sync->setAttribute('class', 'joe_content joe_advanced');

            $local = new Select('local', array(
                'open' => _t('开启'),
                'close' => _t('关闭'),
            ), 'close', _t('在本地保存'), _t('在本地保存一份副本，会占用本地存储空间'));
            $local->setAttribute('class', 'joe_content joe_advanced');

            $local_sync = new Select('local_sync', array(
                'open' => _t('开启'),
                'close' => _t('关闭'),
            ), 'close', _t('删除时同步删除本地备份'), _t('在文件管理删除文件时，是否同步删除本地备份的对应文件（须开启“在本地保存”）'));
            $local_sync->setAttribute('class', 'joe_content joe_advanced');

            $form->addInput($secid);
            $form->addInput($sekey);
            $form->addInput($region);
            $form->addInput($bucket);
            $form->addInput($path);
            $form->addInput($domain);
            $form->addInput($cdn_domain);
            $form->addInput($sign);
            $form->addInput($remote_sync);
            $form->addInput($local);
            $form->addInput($local_sync);

            /** 极智压缩配置项 */
            $slimResult = self::getImageSlim();
            $slimError = is_string($slimResult) ? $slimResult : '';
            $slimClass = $slimError ? ' image_slim_hidden' : $slimError;
            $slimErrorHtml = $slimError ? ('<span style="color:red;font-weight:800">' . $slimError . '</span><br/>') : '';
            $slimTipsHtml = $slimErrorHtml . '<span>
                    <span>数据万象-图片极智压缩支持图片访问时无需参数自动压缩或通过处理参数<span style="color:orange"> imageSlim </span>主动压缩，支持JPG、PNG、GIF格式，压缩后不会改变图片格式。</span><br/>
                    <span>API使用示例： https://test-1250000000.cos.ap-beijing.myqcloud.com/sample.jpg<span style="color:orange">?imageSlim</span></span><br/>
                    <span><span>有关极智压缩的更多操作及计费信息，请查看</span> <a href="https://cloud.tencent.com/document/product/436/49259" target="_blank"> 图片极智压缩概述 </a></span>
                </span>';
            $image_slim_tips = new Hidden(
                'image_slim_tips',
                array(),
                $slimError,
                _t('极智压缩介绍'),
                _t($slimTipsHtml)
            );
            $image_slim_tips->setAttribute('class', 'joe_content joe_slim');
            $form->addInput($image_slim_tips);

            $slimMode = isset($slimResult['SlimMode']) ? $slimResult['SlimMode'] : '';
            $slimModeArr = $slimMode ? explode(',', $slimMode) : array();
            $slimAutoOpened = in_array('Auto', $slimModeArr) ? 'on' : 'off';
            $slimApiOpened = in_array('API', $slimModeArr) ? 'on' : 'off';
            $image_slim_auto = new Checkbox(
                'image_slim_auto',
                array(
                    'on' => _t('开启自动压缩'),
                ),
                array($slimAutoOpened),
                _t('自动压缩（推荐）'),
                _t('无需添加任何额外的参数，在正常访问以下格式图片时将自动进行压缩。')
            );
            $image_slim_auto->value(array($slimAutoOpened));
            $image_slim_auto->setAttribute('class', 'joe_content joe_slim image_slim_auto ' . $slimClass);

            $slimSuffixArr = isset($slimResult['Suffixs']) && isset($slimResult['Suffixs']['Suffix']) ? $slimResult['Suffixs']['Suffix'] : array();
            $image_slim_suffix = new Checkbox(
                'image_slim_suffix',
                array(
                    'jpg' => _t('JPG/JPEG'),
                    'png' => _t('PNG'),
                    'gif' => _t('GIF'),
                ),
                $slimSuffixArr,
                _t('图片格式'),
                _t('开启自动压缩，需要选择生效的图片格式')
            );
            $image_slim_suffix->value($slimSuffixArr);
            $image_slim_suffix->setAttribute('class', 'joe_content joe_slim image_slim_suffix ' . $slimClass);

            $image_slim_api = new Checkbox(
                'image_slim_api',
                array(
                    'on' => _t('开启 API 调用'),
                ),
                array($slimApiOpened),
                _t('通过 API 调用'),
                _t('访问图片时，在图片链接后添加极智压缩参数<span class="tea-text-warning"> imageSlim </span>，即可访问到压缩后的图片，具体请参考<a href="https://cloud.tencent.com/document/product/460/94856" target="_blank"> 图片极智压缩接口说明 </a>。')
            );
            $image_slim_api->value(array($slimApiOpened));
            $image_slim_api->setAttribute('class', 'joe_content joe_slim image_slim_api ' . $slimClass);

            $form->addInput($image_slim_auto);
            $form->addInput($image_slim_suffix);
            $form->addInput($image_slim_api);
            ?>
            <style>
                .joe_slim a {
                    text-decoration: underline;
                }
                .image_slim_hidden {
                    display: none!important;
                }
            </style>
            <script>
                window.onload = function() {
                    // 重置极智压缩的选项
                    var slimError = '<?=$slimError?>';
                    var autoMode = <?=$slimAutoOpened === 'on'?>;
                    var suffix = <?=json_encode($slimSuffixArr)?>;
                    var apiMode = <?=$slimApiOpened === 'on'?>;
                    $('[name=image_slim_tips]').val(slimError);
                    $('#image_slim_auto-on').prop('checked', autoMode);
                    $('#image_slim_api-on').prop('checked', apiMode);
                    $('.image_slim_suffix input[type=checkbox]').prop('checked', false);
                    if (suffix) {
                        suffix.forEach(function (ext) {
                            $('#image_slim_suffix-' + ext).prop('checked', true)
                        });
                    }
                }
            </script>
        <?php
    }


    /**
     * @description: 个人用户的配置面板
     * @param {Form} $form
     * @return {*}
     */
    public static function personalConfig(Form $form)
    {
    }

    /**
     * @description: 自定义配置修改
     * @param array $config 配置信息
     * @param bool $is_init 是否初始化
     * @return {*}
     */
    public static function configHandle($config, $is_init)
    {
        if (!$is_init) {
            try {
                $opt = (object)$config;
                $exist = self::doesBucketExist($opt);
                if (!$exist) {
                    throw new \Typecho\Plugin\Exception('存储桶不存在！请返回检查SecretId、SecretKey、存储桶等信息是否正确！');
                }
            } catch (\Exception $e) {
                throw new \Typecho\Plugin\Exception(_t($e->getMessage()));
            }

            $slimError = self::setImageSlim($config);
            if ($slimError) throw new \Typecho\Plugin\Exception(_t($slimError));
        }

        Helper::configPlugin(pluginName, $config);

        if (isset($config['image_slim_tips']) && !$config['image_slim_tips']) {

        }

    }

    /**
     * @description: 上传文件处理函数
     * @param {array} $file
     * @return {*}
     */
    public static function uploadHandle(array $file)
    {
        if (empty($file['name'])) {
            return false;
        }
        #获取扩展名
        $ext = self::getSafeName($file['name']);
        #判定是否是允许的文件类型
        if (!\Widget\Upload::checkFileType($ext) || \Typecho\Common::isAppEngine()) {
            return false;
        }
        #获取设置参数
        $opt = Options::alloc()->plugin(pluginName);

        #获取文件名
        $date = new \Typecho\Date($opt->gmtTime);
        $fileDir = self::getUploadDir() . '/' . $date->year . '/' . $date->month;
        $fileName = sprintf('%u', crc32(uniqid())) . '.' . $ext;
        $path = $fileDir . '/' . $fileName;
        #获得上传文件
        $uploadfile = self::getUploadFile($file);
        #如果没有临时文件，则退出
        if (!isset($uploadfile)) {
            return false;
        }

        /* 上传到COS */
        #初始化COS
        $cosClient = self::CosInit();
        try {
            #判断是否存在重名文件，重名则重新生成
            $times = 10;
            while ($times > 0 && self::doesObjectExist($path)) {
                $fileName = sprintf('%u', crc32(uniqid($times--))) . '.' . $ext;
                $path = $fileDir . '/' . $fileName;
            }

            $cosClient->upload(
                $opt->bucket,
                $path,
                fopen($uploadfile, 'rb'),
                array(
                    "ACL" => 'public-read',
                    'CacheControl' => 'private'
                )
            );
        } catch (\Exception $e) {
            echo "$e\n";
            return false;
        }

        if (!isset($file['size'])) {
            $fileInfo = $cosClient->headObject(array('Bucket' => $opt->bucket, 'Key' => $path))->toArray();
            $file['size'] = $fileInfo['ContentLength'];
        }

        if ($opt->local == 'open' && self::makeUploadDir($fileDir)) {
            #本地存储一份
            @move_uploaded_file($uploadfile, $path);
        }

        #返回相对存储路径
        return array(
            'name' => $file['name'],
            'path' => $path,
            'size' => $file['size'],
            'type' => $ext,
            'mime' => @\Typecho\Common::mimeContentType($path)
        );
    }

    /**
     * @description: 修改文件处理函数
     * @param {array} $content 旧文件
     * @param {array} $file 新文件
     * @return {*}
     */
    public static function modifyHandle(array $content, array $file)
    {
        if (empty($file['name'])) {
            return false;
        }

        #获取扩展名
        $ext = self::getSafeName($file['name']);
        #判定是否是允许的文件类型
        if ($content['attachment']->type != $ext || \Typecho\Common::isAppEngine()) {
            return false;
        }
        #获取设置参数
        $opt = Options::alloc()->plugin(pluginName);
        #获取文件路径
        $path = $content['attachment']->path;
        #获得上传文件
        $uploadfile = self::getUploadFile($file);
        #如果没有临时文件，则退出
        if (!isset($uploadfile)) {
            return false;
        }

        /* 上传到COS */
        $cosClient = self::CosInit();
        try {
            $cosClient->upload(
                $opt->bucket,
                $path,
                fopen($uploadfile, 'rb'),
                array(
                    "ACL" => 'public-read',
                    'CacheControl' => 'private'
                )
            );
        } catch (\Exception $e) {
            echo "$e\n";
            return false;
        }

        if (!isset($file['size'])) {
            $fileInfo = $cosClient->headObject(array('Bucket' => $opt->bucket, 'Key' => $path))->toArray();
            $file['size'] = $fileInfo['ContentLength'];
        }

        if ($opt->local == 'open') {
            #本地存储一份
            @move_uploaded_file($uploadfile, $path);
        }


        #返回相对存储路径
        return array(
            'name' => $content['attachment']->name,
            'path' => $content['attachment']->path,
            'size' => $file['size'],
            'type' => $content['attachment']->type,
            'mime' => $content['attachment']->mime
        );
    }

    /**
     * @description: 删除文件
     * @param {array} $content
     * @return {*}
     */
    public static function deleteHandle(array $content)
    {
        #获取设置参数
        $opt = Options::alloc()->plugin(pluginName);
        #删除本地文件
        if ($opt->local == 'open' && $opt->local_sync=='open') {
            @unlink($content['attachment']->path);
        }
        # 开启同步删除COS文件则执行
        if ($opt->remote_sync=='open') {
            #初始化COS
            $cosClient = self::CosInit();
            try {
                $cosClient->deleteObject(array(
                    #bucket的命名规则为{name}-{appid} ，此处填写的存储桶名称必须为此格式
                    'Bucket' => $opt->bucket,
                    'Key' => $content['attachment']->path
                ));
            } catch (\Exception $e) {
                echo "$e\n";
                return false;
            }
        }
        return true;
    }

    /**
     * @description: 获取对象访问Url
     * @param {array} $content
     * @return {*}
     */
    public static function attachmentHandle(array $content)
    {
        #获取设置参数
        $opt = Options::alloc()->plugin(pluginName);
        $cosClient = self::CosInit();
        if ($opt->sign == 'open') {
            return $cosClient->getObjectUrl($opt->bucket, $content['attachment']->path, '+60 minutes');
        }

        if (!empty($opt->cdn_domain)) {
            return $opt->cdn_domain . $content['attachment']->path;
        }

        return $cosClient->getObjectUrlWithoutSign($opt->bucket, $content['attachment']->path);
    }

    /**
     * @description: 获取对象访问Url
     * @param array $content
     * @return {*}
     */
    public static function attachmentDataHandle($content)
    {
        #获取设置参数
        $opt = Options::alloc()->plugin(pluginName);
        $cosClient = self::CosInit();
        if ($opt->sign == 'open') {
            return $cosClient->getObjectUrl($opt->bucket, $content['attachment']->path, '+60 minutes');
        }
        return $cosClient->getObjectUrlWithoutSign($opt->bucket, $content['attachment']->path);
    }

    /**
     * @description: 更新正文中的对象网址钩子
     * @return {*}
     */
    public static function Widget_Archive_beforeRender()
    {
        ob_start(__CLASS__ . '::beforeRender');
    }

    /**
     * @description: 更新正文中的对象网址（获得新的sign）
     * @param string $text 页面html
     * @return string
     */
    public static function beforeRender($text)
    {
        $opt = Options::alloc()->plugin(pluginName);
        $cosClient = self::CosInit();
        if ($opt->sign == 'open') {
            return preg_replace_callback(
                '/https?:\/\/[-A-Za-z0-9+&@#\/\%?=~_|!:,.;]+[-A-Za-z0-9+&@#\/\%=~_|]/i',
                function ($matches) use ($opt, $cosClient) {
                    $url = $matches[0];
                    if (strpos($url, self::getDomain()) !== false) {
                        $expTime = explode('q-key-time%3D', $url);
                        if (sizeof($expTime) > 1) {
                            @$expTime = explode('%26q', $expTime[1])[0];
                            @$expTime = explode('%3B', $expTime)[1];
                            #未过期，不更新
                            if ($expTime > time()) {
                                return $url;
                            }
                        }
                        $path = str_replace(self::getDomain(), '', $url);
                        $url = $cosClient->getObjectUrl($opt->bucket, explode('?', $path)[0], '+10 minutes');
                    }
                    return $url;
                },
                $text
            );
        }
        return $text;
    }

    /**
     * @description: COS初始化
     * @param object $options 设置信息
     * @return {*}
     */
    public static function CosInit($options = '')
    {
        if (!$options) {
            $options = Options::alloc()->plugin(pluginName);
        }
        if (self::compareVersion('7.2.5') < 0) {
            require_once 'phar://' . __DIR__ . '/phar/cos-sdk-v5-6.phar/vendor/autoload.php';;
        } else {
            require_once 'phar://' . __DIR__ . '/phar/cos-sdk-v5-7.phar/vendor/autoload.php';;
        }
        //增加自定义域名功能
        if (!empty($options->domain)){
            return new \Qcloud\Cos\Client(array(
                'domain' => $options->domain,
                'schema' => 'http', #协议头部，默认为https
                'credentials' => array(
                    'secretId' => $options->secid,
                    'secretKey' => $options->sekey
                ),
                'userAgent' => 'typecho/1.2.0;tencentcloud-typecho-plugin-cos/1.0.2;cos-php-sdk-v5/2.0.8'
            ));
        }

        return new \Qcloud\Cos\Client(array(
            'region' => $options->region,
            'schema' => 'http', #协议头部，默认为https
            'credentials' => array(
                'secretId' => $options->secid,
                'secretKey' => $options->sekey
            ),
            'userAgent' => 'typecho/1.2.0;tencentcloud-typecho-plugin-cos/1.0.2;cos-php-sdk-v5/2.0.8'
        ));
    }

    /**
     * @description: 判断存储桶是否存在
     * @param {*} $opt
     * @return {*}
     */
    public static function doesBucketExist($opt)
    {
        #初始化COS
        $cosClient = self::CosInit($opt);
        try {
            $result = $cosClient->doesBucketExist(
                $opt->bucket
            );
            if (!$result) {
                return false;
            }
        } catch (\Exception $e) {
            return false;
        }
        return true;
    }

    /**
     * @description: 判断对象是否已存在
     * @param {*} $key
     * @return {*}
     */
    public static function doesObjectExist($key)
    {
        #获取设置参数
        $opt = Options::alloc()->plugin(pluginName);
        #初始化COS
        $cosClient = self::CosInit();
        try {
            $result = $cosClient->doesObjectExist(
                $opt->bucket,
                $key
            );
            if ($result) {
                return true;
            }
        } catch (\Exception $e) {
            return true;
        }
        return false;
    }

    /**
     * @description: 创建上传路径
     * @param {string} $path
     * @return {*}
     */
    private static function makeUploadDir(string $path)
    {
        $path = preg_replace("/\\\+/", '/', $path);
        $current = rtrim($path, '/');
        $last = $current;

        while (!is_dir($current) && false !== strpos($path, '/')) {
            $last = $current;
            $current = dirname($current);
        }

        if ($last == $current) {
            return true;
        }

        if (!@mkdir($last, 0755)) {
            return false;
        }

        return self::makeUploadDir($path);
    }

    /**
     * @description: 获取文件上传目录
     * @return {*}
     */
    private static function getUploadDir()
    {
        $opt = Options::alloc()->plugin(pluginName);
        if ($opt->path) {
            return $opt->path;
        } else if (defined('__TYPECHO_UPLOAD_DIR__')) {
            return __TYPECHO_UPLOAD_DIR__;
        } else {
            return self::UPLOAD_DIR;
        }
    }

    /**
     * @description: 获取上传文件信息
     * @param array $file 上传的文件
     * @return {*}
     */
    private static function getUploadFile($file)
    {
        return isset($file['tmp_name']) ? $file['tmp_name'] : (isset($file['bytes']) ? $file['bytes'] : (isset($file['bits']) ? $file['bits'] : ''));
    }

    /**
     * @description: 获取访问域名
     * @return string
     */
    private static function getDomain()
    {
        $opt = Options::alloc()->plugin(pluginName);
        $domain = $opt->domain;
        if (empty($domain))  $domain = 'https://' . $opt->bucket . '.cos.' . $opt->region . '.myqcloud.com';
        return $domain;
    }

    /**
     * @description: 获取安全的文件名
     * @param string $file
     * @return string
     */
    private static function getSafeName(&$file)
    {
        $file = str_replace(array('"', '<', '>'), '', $file);
        $file = str_replace('\\', '/', $file);
        $file = false === strpos($file, '/') ? ('a' . $file) : str_replace('/', '/a', $file);
        $info = pathinfo($file);
        $file = substr($info['basename'], 1);
        return isset($info['extension']) ? strtolower($info['extension']) : '';
    }

    /**
     * @description: 比较php版本
     * @param string $test 要比较的版本号，如7.2.5
     * @return int 当前版本大于比较版本:1 当前版本小于比较版本:-1 当前版本等于比较版本:0
     */
    private static function compareVersion($test)
    {
        #echo PHP_VERSION;
        $currentVersion = explode('.', PHP_VERSION);
        $testVersion = explode('.', $test);
        $ret = 0;
        for ($i = 0; $i < sizeof($currentVersion); $i++) {
            if ($currentVersion[$i] == $testVersion[$i]) {
                continue;
            }
            if ($currentVersion[$i] > $testVersion[$i]) {
                $ret = 1;
                break;
            } else {
                $ret = -1;
                break;
            }
        }
        return $ret;
    }
}