<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 相册插件，文章使用短代码引用相册 [MPG-相册名称] for Typecho 1.3
 * 
 * @package MyPhotoAlbum
 * @author Nicotine-2
 * @version 1.0.0
 * @link https://github.com/Nicotine-2/MyPhotoAlbum
 */
class MyPhotoAlbum_Plugin implements Typecho_Plugin_Interface
{
    // 定义统一的上传目录和URL（指向 /usr/uploads/MyPhotoAlbum）
    const UPLOAD_DIR = '/usr/uploads/MyPhotoAlbum';
    
    // 插件资源目录
    const ASSETS_DIR = '/usr/plugins/MyPhotoAlbum/assets';
    
    // 缓存相册名称到文件夹名的映射
    private static $albumFolderCache = [];
    
    // 短代码计数器，用于生成唯一ID
    private static $shortcodeCount = 0;
    
    public static function activate()
    {
        // 确保上传目录存在且有写入权限
        $uploadDir = __TYPECHO_ROOT_DIR__ . self::UPLOAD_DIR;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // 确保资源目录存在
        $assetsDir = __TYPECHO_ROOT_DIR__ . self::ASSETS_DIR;
        if (!is_dir($assetsDir)) {
            mkdir($assetsDir, 0755, true);
        }
        $assetsJsDir = $assetsDir . '/js';
        if (!is_dir($assetsJsDir)) {
            mkdir($assetsJsDir, 0755, true);
        }

        Helper::addPanel(1, 'MyPhotoAlbum/manage.php', '我的相册', '管理相册', 'administrator');
        Typecho_Plugin::factory('Widget_Theme')->fileHandle = array('MyPhotoAlbum_Plugin', 'fileHandle');
        Typecho_Plugin::factory('Widget_Themes_List')->listHandle = array('MyPhotoAlbum_Plugin', 'listHandle');
        
        // 注册文章内容解析钩子 - 支持短代码
        Typecho_Plugin::factory('Widget_Abstract_Contents')->contentEx = array('MyPhotoAlbum_Plugin', 'contentEx');
        Typecho_Plugin::factory('Widget_Abstract_Contents')->excerptEx = array('MyPhotoAlbum_Plugin', 'contentEx');
        
        // 注册头部输出钩子 - 用于加载短代码样式和脚本
        Typecho_Plugin::factory('Widget_Archive')->header = array('MyPhotoAlbum_Plugin', 'header');

        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        
        // 检查并更新 photos 表结构
        try {
            $db->query("SELECT album_id FROM `{$prefix}photos` LIMIT 1", false);
        } catch (Exception $e) {
            try {
                $db->query("ALTER TABLE `{$prefix}photos` ADD COLUMN `album_id` int(10) unsigned default 0");
            } catch (Exception $e2) {}
        }
        
        // 创建相册表
        $sql2 = "CREATE TABLE IF NOT EXISTS `{$prefix}albums` (
            `id` int(10) unsigned NOT NULL auto_increment,
            `name` varchar(100) NOT NULL,
            `folder` varchar(100) default NULL,
            `description` text,
            `cover` varchar(255) default NULL,
            `sort` int(10) unsigned default 0,
            `password` varchar(100) default NULL,
            `hidden` tinyint(1) unsigned default 0,
            `created` int(10) unsigned default NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        try {
            $db->query($sql2);
        } catch (Exception $e) {}
        
        // 检查并添加 folder 字段
        try {
            $db->query("SELECT folder FROM `{$prefix}albums` LIMIT 1", false);
        } catch (Exception $e) {
            try {
                $db->query("ALTER TABLE `{$prefix}albums` ADD COLUMN `folder` varchar(100) default NULL");
            } catch (Exception $e2) {}
        }
        
        // 检查并添加 hidden 字段
        try {
            $db->query("SELECT hidden FROM `{$prefix}albums` LIMIT 1", false);
        } catch (Exception $e) {
            try {
                $db->query("ALTER TABLE `{$prefix}albums` ADD COLUMN `hidden` tinyint(1) unsigned default 0");
            } catch (Exception $e2) {}
        }

        return _t('插件已启用，图片将保存在 /usr/uploads/MyPhotoAlbum 目录下。');
    }

    public static function deactivate()
    {
        Helper::removePanel(1, 'MyPhotoAlbum/manage.php');
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $compressSwitch = new Typecho_Widget_Helper_Form_Element_Radio('compressSwitch',
            array('0' => _t('关闭'), '1' => _t('开启')), '0', _t('开启图片压缩'), _t('上传时是否自动压缩图片'));
        $form->addInput($compressSwitch);

        $compressQuality = new Typecho_Widget_Helper_Form_Element_Text('compressQuality', NULL, '80', _t('压缩质量'), _t('1-100，数值越小体积越小，建议 75-85'));
        $form->addInput($compressQuality);
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    public static function fileHandle($file, $themeDir)
    {
        if ($file == 'page-photos.php') {
            return dirname(__FILE__) . '/' . $file;
        }
        return false;
    }

    public static function listHandle($templates, $theme)
    {
        $pluginTemplatePath = dirname(__FILE__) . '/page-photos.php';
        
        if (file_exists($pluginTemplatePath)) {
            $templateContent = file_get_contents($pluginTemplatePath);
            if (preg_match("/Template Name:([^\r\n]+)/i", $templateContent, $nameMatch)) {
                $templateName = trim($nameMatch[1]);
            } else {
                $templateName = '相册页面 (插件内置)';
            }
            $templates['page-photos.php'] = $templateName;
        }
        return $templates;
    }
    
    /**
     * 文章内容解析 - 处理短代码
     */
    public static function contentEx($text, $widget, $last)
    {
        $text = empty($last) ? $text : $last;
        
        // 收集所有卡片HTML
        $cardsHtml = '';
        $overlaysHtml = '';
        
        $result = preg_replace_callback('/\[MPG\-([^\]]+)\]/', function($m) use (&$cardsHtml, &$overlaysHtml) {
            $albumName = trim($m[1]);
            return self::renderShortcodeCard($albumName, $cardsHtml, $overlaysHtml);
        }, $text);
        
        // 如果有卡片，在短代码位置插入容器
        if (!empty($cardsHtml)) {
            $containerHtml = '<div class="mpg-card-wrapper">' . $cardsHtml . '</div>' . $overlaysHtml;
            $result = preg_replace('/<!-- MPG_CARD_PLACEHOLDER -->/', $containerHtml, $result, 1);
        }
        
        return $result;
    }
    
    /**
     * 渲染单个相册卡片
     */
    private static function renderShortcodeCard($albumName, &$cardsHtml, &$overlaysHtml)
    {
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        
        // 根据相册名称查找相册
        $album = $db->fetchRow($db->select()->from($prefix . 'albums')->where('name = ?', $albumName));
        if (!$album) {
            return '<div style="padding:20px;background:#fff3cd;border:1px solid #ffc107;border-radius:8px;color:#856404;display:inline-block;margin:4px;">⚠️ 相册 "' . htmlspecialchars($albumName) . '" 不存在</div>';
        }
        
        // 获取相册下的图片
        $photos = $db->fetchAll($db->select('path, id')->from($prefix . 'photos')->where('album_id = ?', $album['id'])->order('created', Typecho_Db::SORT_DESC));
        if (empty($photos)) {
            return '<div style="padding:20px;background:#e2e3e5;border:1px solid #d6d8db;border-radius:8px;color:#383d41;display:inline-block;margin:4px;"> 相册 "' . htmlspecialchars($albumName) . '" 暂无图片</div>';
        }
        
        // 生成唯一ID
        self::$shortcodeCount++;
        $containerId = 'mpg-' . self::$shortcodeCount . '-' . md5($albumName . time());
        $overlayId = $containerId . '-overlay';
        $gridId = $containerId . '-grid';
        
        // 获取封面图
        $coverUrl = self::getAlbumCover($album['cover']);
        if (empty($album['cover']) || $album['cover'] == '') {
            if (!empty($album['folder'])) {
                $albumFullPath = __TYPECHO_ROOT_DIR__ . self::UPLOAD_DIR . '/' . $album['folder'];
                if (is_dir($albumFullPath)) {
                    $firstPhoto = glob($albumFullPath . '/*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE);
                    if (!empty($firstPhoto)) {
                        $coverUrl = Helper::options()->siteUrl . ltrim(self::UPLOAD_DIR . '/' . $album['folder'] . '/' . basename($firstPhoto[0]), '/');
                    }
                }
            }
        }
        if (empty($coverUrl) || $coverUrl == Helper::options()->siteUrl . 'usr/plugins/MyPhotoAlbum/assets/default-cover.jpg') {
            $coverUrl = Helper::options()->siteUrl . 'usr/plugins/MyPhotoAlbum/assets/default-cover.jpg';
        }
        
        // ===== 关键修改：使用真实的 href，与 MyMusicAlbum 一致 =====
        // 构建图片列表HTML - 使用真实 href 和 rel/data-lightbox 属性让 iSeeBox 识别
        $photosHtml = '';
        $photoUrls = [];
        foreach ($photos as $photo) {
            $imgUrl = Helper::options()->siteUrl . ltrim($photo['path'], '/');
            $photoUrls[] = $imgUrl;
            // 与独立页相同的结构：真实 href + rel="lightbox-group" + data-lightbox
            $photosHtml .= '<div class="mpg-item"><a href="' . $imgUrl . '" class="mpg-slimbox" rel="lightbox-group" data-lightbox="' . $containerId . '" data-mpg-url="' . $imgUrl . '"><img src="' . $imgUrl . '" loading="lazy" decoding="async" alt="' . htmlspecialchars($albumName) . '"></a></div>';
        }
        
        // 将图片URL列表转为JSON用于JS
        $photoUrlsJson = json_encode($photoUrls);
        
        // 生成卡片HTML
        $cardsHtml .= <<<HTML
<div class="mpg-card" onclick="openMpgOverlay('{$overlayId}', '{$gridId}')">
    <div class="mpg-card-cover" style="background-image: url('{$coverUrl}');">
        <div class="mpg-card-mask">
            <span class="mpg-card-name">{$album['name']}</span>
        </div>
    </div>
</div>
HTML;
        
        // 生成遮罩层HTML
        $overlaysHtml .= <<<HTML
<div class="mpg-overlay" id="{$overlayId}" style="display:none;">
    <div class="mpg-overlay-content">
        <div class="mpg-overlay-header">
            <span class="mpg-overlay-title"> {$album['name']}</span>
            <button class="mpg-overlay-close" onclick="closeMpgOverlay('{$overlayId}')">✕ 关闭</button>
        </div>
        <div class="mpg-grid" id="{$gridId}" data-photos='{$photoUrlsJson}'>
            <div class="mpg-grid-sizer"></div>
            {$photosHtml}
        </div>
    </div>
</div>
HTML;
        
        // 返回占位标记
        return '<!-- MPG_CARD_PLACEHOLDER -->';
    }
    
    /**
     * 头部输出 - 加载短代码样式和脚本
     */
    public static function header()
    {
        static $loaded = false;
        if ($loaded) return;
        $loaded = true;
        
        $assetsUrl = self::getAssetsUrl();
        
        echo <<<HTML
<style>
/* ===== 相册卡片容器 ===== */
.mpg-card-wrapper {
    margin: 10px 0;
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    justify-content: flex-start;
}
.mpg-card {
    display: block;
    width: 256px;
    height: 384px;
    border-radius: 16px;
    overflow: hidden;
    cursor: pointer;
    background: var(--bg-elevated, #2a2a2e);
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    border: 1px solid var(--line, #e9ecef);
    flex-shrink: 0;
    transition: none;
}
.mpg-card-cover {
    width: 100%;
    height: 100%;
    background-size: cover !important;
    background-position: center !important;
    position: relative;
}
.mpg-card-mask {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 56px;
    background: rgba(0, 0, 0, 0.9);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 12px;
}
.mpg-card-name {
    color: #fff !important;
    font-size: 16px;
    font-weight: 600;
    text-align: center;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    width: 100%;
}

/* ===== 全屏遮罩层 ===== */
.mpg-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(30, 25, 20, 0.92);
    z-index: 99999;
    overflow-y: auto;
    padding: 20px;
    box-sizing: border-box;
    animation: mpgFadeIn 0.3s ease;
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,0.3) transparent;
}
.mpg-overlay::-webkit-scrollbar {
    width: 6px;
    background: transparent;
}
.mpg-overlay::-webkit-scrollbar-track {
    background: transparent;
}
.mpg-overlay::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,0.15);
    border-radius: 3px;
    transition: background 0.3s ease;
}
.mpg-overlay::-webkit-scrollbar-thumb:hover {
    background: rgba(255,255,255,0.35);
}
.mpg-overlay {
    scrollbar-color: transparent transparent;
    transition: scrollbar-color 0.3s ease;
}
.mpg-overlay:hover {
    scrollbar-color: rgba(255,255,255,0.3) transparent;
}

@keyframes mpgFadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
.mpg-overlay-content {
    max-width: 1240px;
    margin: 0 auto;
    padding: 10px 0 40px 0;
}
.mpg-overlay-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0 20px 0;
    color: #fff;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    margin-bottom: 20px;
}
.mpg-overlay-title {
    font-size: 20px;
    font-weight: 600;
    color: #fff;
}
.mpg-overlay-close {
    background: rgba(255,255,255,0.1);
    color: #fff;
    border: 1px solid rgba(255,255,255,0.15);
    padding: 8px 20px;
    border-radius: 30px;
    cursor: pointer;
    font-size: 14px;
    transition: background 0.2s ease;
}
.mpg-overlay-close:hover {
    background: rgba(255,255,255,0.2);
}

/* ===== 瀑布流 ===== */
.mpg-grid {
    position: relative;
    padding: 0;
}
.mpg-grid-sizer {
    width: 25%;
}
.mpg-item {
    width: 25%;
    padding: 8px;
    box-sizing: border-box;
    opacity: 0;
    transition: opacity 0.6s ease;
}
.mpg-item.fade {
    opacity: 1;
}
.mpg-item a {
    display: block;
    cursor: pointer;
}
.mpg-item img {
    width: 100%;
    border-radius: 10px;
    display: block;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}
.mpg-item img:hover {
    transform: scale(1.03);
    box-shadow: 0 8px 30px rgba(0,0,0,0.3);
}

@media (max-width: 1200px) {
    .mpg-grid-sizer, .mpg-item { width: 33.333%; }
}
@media (max-width: 768px) {
    .mpg-grid-sizer, .mpg-item { width: 50%; }
    .mpg-card { width: 200px; height: 300px; }
    .mpg-card-name { font-size: 14px; }
    .mpg-overlay { padding: 12px; }
    .mpg-overlay-title { font-size: 16px; }
    .mpg-item { padding: 5px; }
    .mpg-card-mask { height: 48px; }
    .mpg-card-wrapper { gap: 12px; }
}
@media (max-width: 480px) {
    .mpg-grid-sizer, .mpg-item { width: 100%; }
    .mpg-card { width: 160px; height: 240px; }
    .mpg-card-name { font-size: 13px; }
    .mpg-card-mask { height: 42px; }
    .mpg-overlay { padding: 8px; }
    .mpg-overlay-close { font-size: 12px; padding: 6px 14px; }
    .mpg-card-wrapper { gap: 10px; justify-content: center; }
}
</style>
HTML;
        
        // 加载 Masonry 和 imagesLoaded
        echo '<script src="' . $assetsUrl . 'js/imagesloaded.pkgd.min.js"></script>';
        echo '<script src="' . $assetsUrl . 'js/masonry.pkgd.min.js"></script>';
        
        echo <<<HTML
<script>
// ===== 打开全屏遮罩 =====
function openMpgOverlay(overlayId, gridId) {
    var overlay = document.getElementById(overlayId);
    if (!overlay) return;
    overlay.style.display = 'block';
    document.body.style.overflow = 'hidden';
    
    setTimeout(function() {
        initMpgMasonry(gridId);
        // 重新触发灯箱绑定
        reinitLightbox(gridId);
    }, 150);
}

// ===== 关闭全屏遮罩 =====
function closeMpgOverlay(overlayId) {
    var overlay = document.getElementById(overlayId);
    if (!overlay) return;
    overlay.style.display = 'none';
    document.body.style.overflow = '';
    // 关闭 iSeeBox
    if (window.iSeeBox && typeof window.iSeeBox.close === 'function') {
        window.iSeeBox.close();
    }
}

// ===== 点击遮罩背景关闭 =====
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('mpg-overlay')) {
        closeMpgOverlay(e.target.id);
    }
});

// ===== 初始化瀑布流 =====
function initMpgMasonry(gridId) {
    var grid = document.getElementById(gridId);
    if (!grid) return;
    
    var items = grid.querySelectorAll('.mpg-item');
    if (items.length === 0) return;
    
    if (grid._masonryInited) return;
    grid._masonryInited = true;
    
    imagesLoaded(grid, function() {
        var msnry = new Masonry(grid, {
            itemSelector: '.mpg-item',
            percentPosition: true,
            columnWidth: '.mpg-grid-sizer',
            gutter: 0,
            transitionDuration: '0.2s',
            initLayout: true
        });
        
        items.forEach(function(item, index) {
            setTimeout(function() {
                item.classList.add('fade');
            }, index * 60);
        });
        
        var resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                msnry.layout();
            }, 200);
        });
    });
}

// ===== 重新触发灯箱绑定 =====
function reinitLightbox(gridId) {
    var grid = document.getElementById(gridId);
    if (!grid) return;
    
    // 如果 iSeeBox 存在，调用其重新绑定方法
    var iSeeBoxInstance = window.iSeeBox || window._iSeeBox || (typeof iSeeBox !== 'undefined' ? iSeeBox : null);
    if (iSeeBoxInstance) {
        // 如果有 init 或 bind 方法，调用它
        if (typeof iSeeBoxInstance.init === 'function') {
            iSeeBoxInstance.init();
        } else if (typeof iSeeBoxInstance.bind === 'function') {
            iSeeBoxInstance.bind();
        } else if (typeof iSeeBoxInstance.rebind === 'function') {
            iSeeBoxInstance.rebind();
        } else if (typeof iSeeBoxInstance.scan === 'function') {
            iSeeBoxInstance.scan();
        }
    }
    
    // 尝试使用 jQuery slimbox 重新绑定
    if (typeof jQuery !== 'undefined' && typeof jQuery.fn.slimbox === 'function') {
        jQuery(grid).find('.mpg-item a.slimbox-item, .mpg-item a[rel="lightbox-group"]').slimbox({
            overlayOpacity: 0.75,
            overlayFadeDuration: 400,
            imageFadeDuration: 400,
            captionAnimationDuration: 400,
            loop: true,
            counterText: 'Image {x} of {y}'
        });
    }
}

// ===== 页面加载完成后初始化 =====
document.addEventListener('DOMContentLoaded', function() {
    // 在页面加载完成后，如果有已打开的遮罩，初始化灯箱
    document.querySelectorAll('.mpg-overlay[style*="display: block"]').forEach(function(overlay) {
        var grid = overlay.querySelector('.mpg-grid');
        if (grid) {
            setTimeout(function() {
                reinitLightbox(grid.id);
            }, 200);
        }
    });
});

// ===== ESC 键关闭 =====
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.mpg-overlay[style*="display: block"]').forEach(function(overlay) {
            closeMpgOverlay(overlay.id);
        });
        if (window.iSeeBox && typeof window.iSeeBox.close === 'function') {
            window.iSeeBox.close();
        }
    }
});
</script>
HTML;
    }
    
    /**
     * 获取插件资源URL
     */
    public static function getAssetsUrl()
    {
        return Helper::options()->siteUrl . 'usr/plugins/MyPhotoAlbum/assets/';
    }
    
    /**
     * 获取相册封面URL
     */
    public static function getAlbumCover($coverPath)
    {
        if (empty($coverPath)) {
            return Helper::options()->siteUrl . 'usr/plugins/MyPhotoAlbum/assets/default-cover.jpg';
        }
        if (strpos($coverPath, 'http') === 0) {
            return $coverPath;
        }
        return Helper::options()->siteUrl . ltrim($coverPath, '/');
    }
    
    /**
     * 获取相册对应的存储目录路径
     */
    public static function getAlbumStoragePath($albumId, $albumName = null, $folderName = null)
    {
        $baseDir = self::UPLOAD_DIR;
        
        if ($folderName) {
            return $baseDir . '/' . $folderName;
        }
        
        if (isset(self::$albumFolderCache[$albumId])) {
            return $baseDir . '/' . self::$albumFolderCache[$albumId];
        }
        
        if ($albumId > 0) {
            $db = Typecho_Db::get();
            $prefix = $db->getPrefix();
            $album = $db->fetchRow($db->select('name, folder')->from($prefix . 'albums')->where('id = ?', $albumId));
            if ($album) {
                if (!empty($album['folder'])) {
                    self::$albumFolderCache[$albumId] = $album['folder'];
                    return $baseDir . '/' . $album['folder'];
                }
                $folderName = self::generateFolderName($albumId, $album['name']);
                $db->query($db->update($prefix . 'albums')
                    ->rows(array('folder' => $folderName))
                    ->where('id = ?', $albumId));
                self::$albumFolderCache[$albumId] = $folderName;
                return $baseDir . '/' . $folderName;
            }
        }
        
        if ($albumName) {
            $folderName = self::generateFolderName($albumId, $albumName);
            return $baseDir . '/' . $folderName;
        }
        
        return $baseDir . '/uncategorized';
    }
    
    /**
     * 生成文件夹名
     */
    public static function generateFolderName($albumId, $name)
    {
        $name = trim($name);
        if (empty($name) && $albumId > 0) {
            return 'album_' . $albumId;
        }
        if (empty($name)) {
            return 'album_' . time();
        }
        $name = str_replace(['\\', '/', ':', '*', '?', '"', '<', '>', '|'], '_', $name);
        $name = trim($name, '_ ');
        if (empty($name)) {
            return $albumId > 0 ? 'album_' . $albumId : 'album_' . time();
        }
        return $name;
    }
    
    /**
     * 获取相册对应的存储目录完整路径
     */
    public static function getAlbumStorageFullPath($albumId, $albumName = null, $folderName = null)
    {
        return __TYPECHO_ROOT_DIR__ . self::getAlbumStoragePath($albumId, $albumName, $folderName);
    }
    
    /**
     * 获取相册对应的存储目录URL
     */
    public static function getAlbumStorageUrl($albumId, $albumName = null, $folderName = null)
    {
        return Helper::options()->siteUrl . ltrim(self::getAlbumStoragePath($albumId, $albumName, $folderName), '/');
    }
    
    /**
     * 确保相册文件夹存在
     */
    public static function ensureAlbumDirectory($albumId, $albumName = null, $folderName = null)
    {
        $dir = self::getAlbumStorageFullPath($albumId, $albumName, $folderName);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }
}
?>