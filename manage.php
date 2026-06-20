<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

// ==================== AJAX 上传处理 ====================
$options = Helper::options();
$db = Typecho_Db::get();
$prefix = $db->getPrefix();

$isAjaxUpload = isset($_GET['ajax_upload']) && $_GET['ajax_upload'] == 1;

if ($isAjaxUpload) {
    error_reporting(0);
    ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=utf-8');
    
    $currentAlbumId = isset($_GET['album_id']) ? intval($_GET['album_id']) : 0;
    
    // 获取相册对应的存储目录
    if ($currentAlbumId > 0) {
        $album = $db->fetchRow($db->select('name, folder')->from($prefix . 'albums')->where('id = ?', $currentAlbumId));
        if ($album) {
            if (!empty($album['folder'])) {
                $folderName = $album['folder'];
            } else {
                // 如果没有 folder 字段，生成并更新
                $folderName = MyPhotoAlbum_Plugin::generateFolderName($currentAlbumId, $album['name']);
                $db->query($db->update($prefix . 'albums')
                    ->rows(array('folder' => $folderName))
                    ->where('id = ?', $currentAlbumId));
            }
            $albumDir = __TYPECHO_ROOT_DIR__ . MyPhotoAlbum_Plugin::UPLOAD_DIR . '/' . $folderName;
        } else {
            $albumDir = __TYPECHO_ROOT_DIR__ . MyPhotoAlbum_Plugin::UPLOAD_DIR . '/uncategorized';
        }
    } else {
        $albumDir = __TYPECHO_ROOT_DIR__ . MyPhotoAlbum_Plugin::UPLOAD_DIR . '/uncategorized';
    }
    
    // 确保目录存在
    if (!is_dir($albumDir)) {
        mkdir($albumDir, 0755, true);
    }
    
    if (!isset($_FILES['photoFile']) || $_FILES['photoFile']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => '没有收到文件或上传错误']);
        exit;
    }
    
    $file = $_FILES['photoFile'];
    $compress = $options->plugin('MyPhotoAlbum')->compressSwitch;
    $quality = intval($options->plugin('MyPhotoAlbum')->compressQuality);
    
    $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($ext, $allowedExts)) {
        echo json_encode(['success' => false, 'error' => '不支持的文件类型']);
        exit;
    }
    
    $newFileName = date('YmdHis') . '_' . mt_rand(100, 999) . '.' . $ext;
    $targetPath = $albumDir . '/' . $newFileName;
    
    // 存储相对路径（用于数据库）
    $relativePath = str_replace(__TYPECHO_ROOT_DIR__, '', $targetPath);
    
    $uploadSuccess = false;
    
    try {
        if ($compress == '1' && in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            list($width, $height, $type) = @getimagesize($file['tmp_name']);
            if ($type == IMAGETYPE_JPEG) {
                $src = imagecreatefromjpeg($file['tmp_name']);
                if ($src) {
                    $uploadSuccess = imagejpeg($src, $targetPath, $quality);
                    imagedestroy($src);
                }
            } elseif ($type == IMAGETYPE_PNG) {
                $src = imagecreatefrompng($file['tmp_name']);
                if ($src) {
                    $pngQuality = floor((100 - $quality) / 10);
                    $uploadSuccess = imagepng($src, $targetPath, $pngQuality);
                    imagedestroy($src);
                }
            } else {
                $uploadSuccess = move_uploaded_file($file['tmp_name'], $targetPath);
            }
        } else {
            $uploadSuccess = move_uploaded_file($file['tmp_name'], $targetPath);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => '图片处理失败']);
        exit;
    }
    
    if ($uploadSuccess && file_exists($targetPath)) {
        try {
            $db->query($db->insert($prefix . 'photos')->rows(array(
                'album_id' => $currentAlbumId,
                'path' => $relativePath,
                'created' => time()
            )));
            echo json_encode(['success' => true, 'filename' => $newFileName]);
        } catch (Exception $e) {
            if (file_exists($targetPath)) @unlink($targetPath);
            echo json_encode(['success' => false, 'error' => '数据库写入失败']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => '文件保存失败，请检查目录权限']);
    }
    exit;
}

include 'header.php';
include 'menu.php';

// ==================== 数据库兼容性检查 ====================
// 检查并添加 hidden 字段（兼容旧版本数据库）
try {
    $db->query("SELECT hidden FROM `{$prefix}albums` LIMIT 1", false);
} catch (Exception $e) {
    try {
        $db->query("ALTER TABLE `{$prefix}albums` ADD COLUMN `hidden` tinyint(1) unsigned default 0");
    } catch (Exception $e2) {}
}

$uploadDir = __TYPECHO_ROOT_DIR__ . MyPhotoAlbum_Plugin::UPLOAD_DIR;
$uploadUrl = $options->siteUrl . ltrim(MyPhotoAlbum_Plugin::UPLOAD_DIR, '/') . '/';

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$panelPath = 'MyPhotoAlbum/manage.php';
$currentUrl = $options->adminUrl . 'extending.php?panel=' . $panelPath;

$currentAlbumId = isset($_GET['album_id']) ? intval($_GET['album_id']) : 0;

// ==================== 相册操作 ====================

if (isset($_POST['add_album'])) {
    $name = trim($_POST['album_name']);
    $description = trim($_POST['album_description']);
    $cover = trim($_POST['album_cover']);
    $sort = intval($_POST['album_sort']);
    $password = !empty($_POST['album_password']) ? trim($_POST['album_password']) : null;
    $hidden = isset($_POST['album_hidden']) ? intval($_POST['album_hidden']) : 0;
    
    if (!empty($name)) {
        // 先插入数据库获取ID
        $db->query("INSERT INTO `{$prefix}albums` (`name`, `description`, `cover`, `sort`, `password`, `hidden`, `created`) 
                    VALUES ('" . addslashes($name) . "', '" . addslashes($description) . "', '" . addslashes($cover) . "', 
                    " . intval($sort) . ", " . ($password ? "'" . addslashes($password) . "'" : "NULL") . ", 
                    " . $hidden . ", " . time() . ")");
        
        // 获取新插入的相册ID
        $newAlbumId = $db->fetchObject($db->query("SELECT LAST_INSERT_ID() as id"))->id;
        
        // 生成文件夹名（直接使用相册名称，仅过滤非法字符）
        $folderName = MyPhotoAlbum_Plugin::generateFolderName($newAlbumId, $name);
        
        // 更新数据库中的 folder 字段
        $db->query("UPDATE `{$prefix}albums` SET `folder` = '" . addslashes($folderName) . "' WHERE `id` = " . $newAlbumId);
        
        // 创建对应的文件夹
        $albumDir = __TYPECHO_ROOT_DIR__ . MyPhotoAlbum_Plugin::UPLOAD_DIR . '/' . $folderName;
        if (!is_dir($albumDir)) {
            mkdir($albumDir, 0755, true);
        }
        
        echo "<script>window.location.href='" . $currentUrl . "';</script>";
        exit;
    }
}

if (isset($_POST['edit_album'])) {
    $albumId = intval($_POST['album_id']);
    $name = trim($_POST['album_name']);
    $description = trim($_POST['album_description']);
    $cover = trim($_POST['album_cover']);
    $sort = intval($_POST['album_sort']);
    $password = !empty($_POST['album_password']) ? trim($_POST['album_password']) : null;
    $hidden = isset($_POST['album_hidden']) ? intval($_POST['album_hidden']) : 0;
    
    if (!empty($name)) {
        // 获取旧的相册信息
        $oldAlbum = $db->fetchRow($db->select('name, folder')->from($prefix . 'albums')->where('id = ?', $albumId));
        $oldName = $oldAlbum ? $oldAlbum['name'] : '';
        $oldFolder = $oldAlbum ? $oldAlbum['folder'] : '';
        
        // 生成新的文件夹名
        $newFolder = MyPhotoAlbum_Plugin::generateFolderName($albumId, $name);
        
        $db->query($db->update($prefix . 'albums')
            ->rows(array(
                'name' => $name, 
                'description' => $description, 
                'cover' => $cover, 
                'sort' => $sort,
                'password' => $password,
                'folder' => $newFolder,
                'hidden' => $hidden
            ))
            ->where('id = ?', $albumId));
        
        // 如果文件夹名变更，重命名文件夹
        if ($oldFolder !== $newFolder && !empty($oldFolder)) {
            $oldDir = __TYPECHO_ROOT_DIR__ . MyPhotoAlbum_Plugin::UPLOAD_DIR . '/' . $oldFolder;
            $newDir = __TYPECHO_ROOT_DIR__ . MyPhotoAlbum_Plugin::UPLOAD_DIR . '/' . $newFolder;
            
            if (is_dir($oldDir) && $oldDir !== $newDir) {
                if (is_dir($newDir)) {
                    // 合并
                    $files = glob($oldDir . '/*');
                    foreach ($files as $file) {
                        $filename = basename($file);
                        $destFile = $newDir . '/' . $filename;
                        if (!file_exists($destFile)) {
                            rename($file, $destFile);
                        }
                    }
                    $remaining = glob($oldDir . '/*');
                    if (empty($remaining)) {
                        rmdir($oldDir);
                    }
                } else {
                    rename($oldDir, $newDir);
                }
                
                // 更新数据库中图片的路径
                $oldPrefix = MyPhotoAlbum_Plugin::UPLOAD_DIR . '/' . $oldFolder . '/';
                $newPrefix = MyPhotoAlbum_Plugin::UPLOAD_DIR . '/' . $newFolder . '/';
                $db->query("UPDATE `{$prefix}photos` SET `path` = REPLACE(`path`, '{$oldPrefix}', '{$newPrefix}') WHERE `album_id` = {$albumId}");
            }
        }
        
        // 移除 alert，直接跳转刷新
        echo "<script>window.location.href='" . $currentUrl . "&album_id=" . $albumId . "';</script>";
        exit;
    }
}

if (isset($_GET['delete_album'])) {
    $albumId = intval($_GET['delete_album']);
    
    // 获取相册名称和文件夹名以删除文件夹
    $album = $db->fetchRow($db->select('name, folder')->from($prefix . 'albums')->where('id = ?', $albumId));
    
    // 获取所有图片路径并删除文件
    $rows = $db->fetchAll($db->select('path')->from($prefix . 'photos')->where('album_id = ?', $albumId));
    foreach ($rows as $row) {
        $filePath = __TYPECHO_ROOT_DIR__ . $row['path'];
        if (file_exists($filePath)) @unlink($filePath);
    }
    
    // 删除数据库记录
    $db->query($db->delete($prefix . 'photos')->where('album_id = ?', $albumId));
    $db->query($db->delete($prefix . 'albums')->where('id = ?', $albumId));
    
    // 删除相册文件夹
    if ($album && !empty($album['folder'])) {
        $albumDir = __TYPECHO_ROOT_DIR__ . MyPhotoAlbum_Plugin::UPLOAD_DIR . '/' . $album['folder'];
        if (is_dir($albumDir)) {
            // 递归删除文件夹
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($albumDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
            }
            rmdir($albumDir);
        }
    }
    
    echo "<script>window.location.href='" . $currentUrl . "';</script>";
    exit;
}

// ==================== 图片操作 ====================

if (isset($_GET['set_cover']) && isset($_GET['photo_id'])) {
    $albumId = intval($_GET['album_id']);
    $photoId = intval($_GET['photo_id']);
    
    $photo = $db->fetchRow($db->select('path')->from($prefix . 'photos')->where('id = ?', $photoId));
    if ($photo) {
        $db->query($db->update($prefix . 'albums')->rows(array('cover' => $photo['path']))->where('id = ?', $albumId));
        // 移除 alert，直接跳转刷新
        echo "<script>window.location.href='" . $currentUrl . "&album_id=" . $albumId . "';</script>";
        exit;
    }
}

if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $row = $db->fetchRow($db->select('path')->from($prefix . 'photos')->where('id = ?', $id));
    
    if ($row) {
        $filePath = __TYPECHO_ROOT_DIR__ . $row['path'];
        if (file_exists($filePath)) @unlink($filePath);
        $db->query($db->delete($prefix . 'photos')->where('id = ?', $id));
    }
    echo "<script>window.location.href='" . $currentUrl . "&album_id=" . $currentAlbumId . "';</script>";
    exit;
}

if (isset($_POST['batch_delete']) && isset($_POST['photo_ids']) && is_array($_POST['photo_ids'])) {
    $ids = array_map('intval', $_POST['photo_ids']);
    if (!empty($ids)) {
        $idsStr = implode(',', $ids);
        
        $rows = $db->fetchAll($db->select('id, path')->from($prefix . 'photos')->where('id IN (' . $idsStr . ')'));
        
        foreach ($rows as $row) {
            $filePath = __TYPECHO_ROOT_DIR__ . $row['path'];
            if (file_exists($filePath)) @unlink($filePath);
        }
        
        $db->query($db->delete($prefix . 'photos')->where('id IN (' . $idsStr . ')'));
        
        echo "<script>window.location.href='" . $currentUrl . "&album_id=" . $currentAlbumId . "';</script>";
    } else {
        echo "<script>alert('请先选择要删除的图片！');window.location.href='" . $currentUrl . "&album_id=" . $currentAlbumId . "';</script>";
    }
    exit;
}

$albums = $db->fetchAll($db->select()->from($prefix . 'albums')->order('sort', Typecho_Db::SORT_ASC));

$currentAlbum = null;
if ($currentAlbumId > 0) {
    $currentAlbum = $db->fetchRow($db->select()->from($prefix . 'albums')->where('id = ?', $currentAlbumId));
}

$photos = array();
if ($currentAlbumId > 0) {
    $photos = $db->fetchAll($db->select()->from($prefix . 'photos')->where('album_id = ?', $currentAlbumId)->order('created', Typecho_Db::SORT_DESC));
}
?>

<style>
/* 全局盒模型 - 确保所有元素自适应宽度 */
* {
    box-sizing: border-box;
}

*:before,
*:after {
    box-sizing: border-box;
}

/* 全局强制颜色样式 */
.album-info-card,
.album-info-card *,
.album-info-card .card-header h3,
.album-info-card .card-header p,
.album-info-card .info-label,
.album-info-card .info-value {
    color: #000000 !important;
}

.album-info-card .info-value input,
.album-info-card .info-value textarea,
.modal-content input,
.modal-content textarea {
    background-color: #ffffff !important;
    color: #000000 !important;
    border: 1px solid #dcdfe6 !important;
}

.upload-section,
.upload-section *,
.upload-section h4,
.upload-section .file-list,
.upload-section .file-item,
.upload-section .file-name,
.upload-section .file-size,
.upload-section .upload-status {
    color: #000000 !important;
}

.file-list > div:first-child {
    margin-bottom: 8px !important;
}

.album-info-card .btn-save {
    color: #ffffff !important;
}

.btn-clear {
    background: #ff8c00 !important;
    color: #ffffff !important;
    border: none;
}
.btn-clear:hover {
    background: #e67e00 !important;
}

.btn-upload {
    color: #ffffff !important;
}

.batch-bar,
.batch-bar *,
.batch-bar span,
.batch-bar strong {
    color: #000000 !important;
}

/* 预览模态框 */
.preview-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.9);
    z-index: 10000;
    display: flex;
    justify-content: center;
    align-items: center;
    cursor: pointer;
}
.preview-modal img {
    max-width: 90%;
    max-height: 90%;
    object-fit: contain;
}
.preview-modal .close {
    position: absolute;
    top: 20px;
    right: 30px;
    color: white;
    font-size: 40px;
    cursor: pointer;
}

/* 相册管理样式 - 新建相册按钮独立一行 */
.albums-header {
    margin-bottom: 20px;
    width: 100%;
}

@media (max-width: 576px) {
    .albums-header button {
        width: 100%;
    }
}

/* 选择文件按钮 - 绿色样式 */
.btn-select-file {
    background: #28a745 !important;
    color: #ffffff !important;
    border: none;
    padding: 8px 20px;
    border-radius: 10px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    display: inline-block;
}
.btn-select-file:hover {
    background: #218838 !important;
}

/* 全选/取消选择按钮样式 - 黑色椭圆背景 */
.batch-bar .batch-link {
    background: #333333 !important;
    color: #ffffff !important;
    padding: 4px 16px !important;
    border-radius: 20px !important;
    text-decoration: none !important;
    font-size: 12px !important;
    display: inline-block;
    cursor: pointer;
}
.batch-bar .batch-link:hover {
    background: #555555 !important;
}

/* 批量删除按钮 - 红色背景，圆角20px */
.batch-delete-btn {
    background: #ff4d4f !important;
    color: #ffffff !important;
    padding: 6.6px 16px !important;
    border-radius: 20px !important;
    border: none;
    font-size: 12px !important;
    cursor: pointer;
    display: inline-block;
}
.batch-delete-btn:hover {
    background: #ff7875 !important;
}


/* 相册网格 - 完全自适应 */
.albums-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.album-card {
    position: relative;
    aspect-ratio: 4 / 6;
    background: #f0f0f0;
    border-radius: 12px;
    overflow: hidden;
    cursor: pointer;
    transition: transform 0.2s, box-shadow 0.2s;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
.album-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 4px 16px rgba(0,0,0,0.15);
}
.album-card.active {
    border: 3px solid #0071e3;
}
.album-cover {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.album-overlay {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    background: rgba(0, 0, 0, 0.75);
    padding: 12px;
    height: auto;
    min-height: 50px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}
.album-overlay .album-name {
    color: white !important;
}
.album-name {
    font-size: 14px;
    font-weight: bold;
    margin-bottom: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.album-stats {
    position: absolute;
    top: 8px;
    right: 8px;
    background: rgba(0,0,0,0.6);
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 11px;
    color: white;
}
.album-actions {
    position: absolute;
    top: 8px;
    left: 8px;
    display: flex;
    gap: 5px;
    opacity: 0;
    transition: opacity 0.2s;
}
.album-card:hover .album-actions {
    opacity: 1;
}
.album-actions a {
    background: rgba(0,0,0,0.6);
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    text-decoration: none;
}
.album-actions a:hover {
    background: #ff4d4f;
}
.album-actions a.edit-btn:hover {
    background: #0071e3;
}

.album-detail-header {
    margin-bottom: 25px;
    width: 100%;
}
.back-link {
    display: inline-block;
    margin-bottom: 20px;
    text-decoration: none;
    font-size: 14px;
}
.back-link:hover {
    text-decoration: underline;
}

.album-info-card {
    background: #ffffff;
    border: 1px solid #e8e8e8;
    border-radius: 20px;
    padding: 0;
    margin-bottom: 25px;
    width: 100%;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}
.album-info-card:hover {
    box-shadow: 0 8px 24px rgba(0,0,0,0.1);
}
.album-info-card .card-header {
    padding: 20px 24px 0 24px;
    border-bottom: 1px solid #f0f0f0;
}
.album-info-card .card-header h3 {
    margin: 0 0 8px 0;
    font-size: 18px;
    font-weight: 600;
}
.album-info-card .card-header p {
    margin: 0 0 12px 0;
    font-size: 13px;
}
.album-info-card .card-body {
    padding: 20px 24px;
}
.album-info-card .info-row {
    display: flex;
    margin-bottom: 18px;
    align-items: flex-start;
}
.album-info-card .info-label {
    width: 100px;
    font-weight: 600;
    font-size: 14px;
    padding-top: 8px;
    flex-shrink: 0;
}
.album-info-card .info-value {
    flex: 1;
    font-size: 14px;
    padding-top: 8px;
}
.album-info-card .info-value input,
.album-info-card .info-value textarea {
    width: 100%;
    padding: 8px 12px;
    border-radius: 8px;
    font-size: 14px;
}
.album-info-card .info-value input:focus,
.album-info-card .info-value textarea:focus {
    outline: none;
    border-color: #0071e3;
}
.album-info-card .info-value textarea {
    min-height: 80px;
    resize: vertical;
}
.album-info-card .info-value input[type="number"] {
    width: 150px;
}
.album-info-card .info-value input[type="checkbox"] {
    width: auto;
    margin-right: 10px;
}
.album-info-card .info-value .toggle-label {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
}
.album-info-card .info-value .toggle-label input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
}
.album-info-card .info-value .toggle-label .toggle-text {
    font-size: 13px;
    color: #666;
}
.album-info-card .card-actions {
    padding: 16px 24px 24px 24px;
    background: #fafbfc;
    border-top: 1px solid #f0f0f0;
    display: flex;
    gap: 12px;
}
.album-info-card .btn-save {
    background: #0071e3;
    border: none;
    padding: 8px 24px;
    border-radius: 10px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
}
.album-info-card .btn-save:hover {
    background: #005bb5;
}

.upload-section {
    background: #f9f9f9;
    border: 1px solid #e0e0e0;
    border-radius: 20px;
    padding: 20px;
    margin-bottom: 25px;
    width: 100%;
}
.upload-section h4 {
    margin: 0 0 15px 0;
    font-size: 15px;
    font-weight: 600;
}
.file-input-wrapper {
    margin-bottom: 15px;
    display: flex;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
}
.file-list {
    margin: 15px 0;
    background: #ffffff;
    border: 1px solid #e0e0e0;
    border-radius: 12px;
    padding: 12px;
    max-height: 400px;
    overflow-y: auto;
}
.file-list .files-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.file-list .file-item {
    display: flex;
    flex-direction: column;
    padding: 12px 16px;
    background: #fafafa;
    border: 1px solid #e8e8e8;
    border-radius: 8px;
}
.file-list .file-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.file-list .file-name {
    font-family: monospace;
    font-size: 13px;
    word-break: break-all;
    flex: 1;
}
.file-list .file-size {
    font-size: 11px;
    margin-left: 15px;
}
.file-list .file-progress {
    width: 100%;
    height: 4px;
    background: #e8e8e8;
    border-radius: 2px;
    overflow: hidden;
    margin-top: 8px;
}
.file-list .file-progress-fill {
    width: 0%;
    height: 100%;
    background: #00a854;
    transition: width 0.3s;
}
.file-list .file-progress-fill.uploaded {
    background: #00a854;
}
.file-list .file-progress-fill.error {
    background: #ff4d4f;
}
.file-list.grid-layout .files-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}
.file-list.grid-layout .file-card {
    background: #fafafa;
    border: 1px solid #e8e8e8;
    border-radius: 10px;
    padding: 10px 12px;
}
.file-list.grid-layout .file-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}
.file-list.grid-layout .file-name {
    font-size: 12px;
    font-family: monospace;
    word-break: break-all;
    flex: 1;
}
.file-list.grid-layout .file-size {
    font-size: 11px;
    margin-left: 8px;
}
.file-list.grid-layout .file-progress {
    width: 100%;
    height: 4px;
    background: #e8e8e8;
    border-radius: 2px;
    overflow: hidden;
}
.upload-buttons {
    display: flex;
    gap: 10px;
    margin-top: 15px;
}
.upload-buttons button {
    cursor: pointer;
    padding: 8px 24px;
    border-radius: 6px;
    font-size: 14px;
    border: none;
}
.btn-upload {
    background: #0071e3;
}
.btn-upload:disabled {
    background: #ccc;
    cursor: not-allowed;
}
.upload-status {
    margin-top: 12px;
    font-size: 13px;
    text-align: center;
}

.photos-section {
    width: 100%;
}

/* 图片网格 - 完全自适应 */
.photos-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 16px;
    width: 100%;
}

.photo-card {
    position: relative;
    width: 100%;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 8px;
    background: #fff;
    cursor: pointer;
    transition: all 0.2s;
}
.photo-card.selected {
    border-color: #ff8c00;
    box-shadow: 0 0 0 2px rgba(255,140,0,0.7);
    background: #fff7e6;
}
.photo-card.current-cover {
    border-color: #ff9800;
    box-shadow: 0 0 0 2px rgba(255,152,0,0.3);
}
.photo-preview {
    width: 100%;
    aspect-ratio: 1 / 1;
    overflow: hidden;
    border-radius: 4px;
}
.photo-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    pointer-events: none;
}
.cover-badge {
    position: absolute;
    top: 5px;
    right: 5px;
    background: #ff9800;
    color: white;
    font-size: 10px;
    padding: 2px 6px;
    border-radius: 4px;
    z-index: 2;
}
.photo-actions {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin-top: 8px;
    padding-top: 6px;
    border-top: 1px solid #f0f0f0;
}
.photo-actions a {
    font-size: 11px;
    text-decoration: none;
}
.photo-actions a.delete-link {
    color: #ff4d4f;
}
.photo-actions a.preview-link {
    color: #0071e3;
}

.batch-bar {
    background: #fff6e5;
    border: 1px solid #ffd591;
    border-radius: 12px;
    padding: 12px 15px;
    margin: 0 0 15px 0;
    display: flex;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
}
.empty-tip {
    text-align: center;
    padding: 60px 20px;
}
.modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    display: flex;
    justify-content: center;
    align-items: center;
}
.modal-content {
    background: #fff;
    border-radius: 20px;
    padding: 24px;
    width: 500px;
    max-width: 90%;
}
.modal-content input,
.modal-content textarea {
    width: 100%;
    margin-bottom: 15px;
    padding: 8px 12px;
    border-radius: 8px;
}
.modal-buttons {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

/* 禁止图片卡片被框选 */
.photo-card,
.photo-card * {
    user-select: none;
    -webkit-user-select: none;
    -moz-user-select: none;
    -ms-user-select: none;
}

.photo-preview img {
    pointer-events: none;
    user-select: none;
    -webkit-user-select: none;
    -moz-user-select: none;
    -ms-user-select: none;
}

/* ========== 响应式断点 ========== */

@media (max-width: 1200px) {
    .photo-card .photo-actions a {
        font-size: 10px;
    }
}

@media (max-width: 992px) {
    .body.container {
        padding: 0 15px;
    }
    
    .albums-grid {
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
        gap: 15px;
    }
    
    .photos-grid {
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
        gap: 15px;
    }
    
    .album-info-card .info-row {
        flex-direction: column;
    }
    
    .album-info-card .info-label {
        width: 100%;
        padding-bottom: 5px;
    }
    
    .album-info-card .info-value {
        width: 100%;
    }
}

@media (max-width: 768px) {
    .body.container {
        padding: 0 12px;
    }
    
    .albums-grid {
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
        gap: 12px;
    }
    
    .photos-grid {
        grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
        gap: 12px;
    }
    
    .album-info-card .card-header,
    .album-info-card .card-body,
    .album-info-card .card-actions {
        padding: 15px;
    }
    
    .album-info-card .info-row {
        flex-direction: column;
    }
    
    .album-info-card .info-label {
        width: 100%;
        padding-bottom: 5px;
    }
    
    .album-info-card .info-value {
        width: 100%;
    }
    
    .album-info-card .info-value input[type="number"] {
        width: 100% !important;
    }
    
    .album-info-card .card-actions {
        flex-direction: column;
    }
    
    .album-info-card .card-actions button {
        width: 100%;
    }
    
    .upload-section {
        padding: 15px;
    }
    
    .file-list.grid-layout .files-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
    }
}

@media (max-width: 576px) {
    .body.container {
        padding: 0 10px;
    }
    
    .albums-grid {
        grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
        gap: 10px;
    }
    
    .photos-grid {
        grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
        gap: 10px;
    }
    
    .photo-card {
        padding: 5px;
    }
    
    .cover-badge {
        font-size: 8px;
        padding: 1px 4px;
    }
    
    .photo-actions {
        flex-wrap: wrap;
        gap: 5px;
    }
    
    .photo-actions a {
        font-size: 9px;
    }
    
    .batch-bar {
        padding: 10px;
        gap: 10px;
        flex-direction: column;
        align-items: stretch;
        text-align: center;
    }
    
    .batch-bar span,
    .batch-bar a,
    .batch-bar button {
        font-size: 12px;
    }
    
    .file-input-wrapper {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .upload-buttons {
        flex-direction: column;
    }
    
    .upload-buttons button {
        width: 100%;
    }
    
    .file-list.grid-layout .files-grid {
        grid-template-columns: 1fr;
        gap: 8px;
    }
}

@media (max-width: 400px) {
    .albums-grid {
        grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
        gap: 8px;
    }
    
    .photos-grid {
        grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
        gap: 8px;
    }
    
    .photo-card {
        padding: 4px;
    }
    
    .album-name {
        font-size: 12px;
    }
}
</style>

<div class="main">
    <div class="body container">
        <div class="typecho-page-title"><h2>我的相册</h2></div>

<?php if ($currentAlbumId > 0 && $currentAlbum): ?>
        
        <div class="album-detail-header">
            <a href="<?php echo $currentUrl; ?>" class="back-link">← 返回相册列表</a>
        </div>
        
        <div class="album-info-card">
            <form method="post">
                <input type="hidden" name="album_id" value="<?php echo $currentAlbum['id']; ?>">
                <div class="card-header">
                    <h3><?php echo htmlspecialchars($currentAlbum['name']); ?></h3>
                    <p>管理相册的基本信息和封面设置</p>
                </div>
                <div class="card-body">
                    <div class="info-row">
                        <div class="info-label">相册名称：</div>
                        <div class="info-value"><input type="text" name="album_name" value="<?php echo htmlspecialchars($currentAlbum['name']); ?>" required></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">相册简介：</div>
                        <div class="info-value"><textarea name="album_description"><?php echo htmlspecialchars($currentAlbum['description']); ?></textarea></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">自定义封面：</div>
                        <div class="info-value"><input type="text" name="album_cover" value="<?php echo htmlspecialchars($currentAlbum['cover']); ?>" placeholder="留空则自动使用相册第一张图片"></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">排列序号：</div>
                        <div class="info-value"><input type="number" name="album_sort" value="<?php echo intval($currentAlbum['sort']); ?>" style="width:150px;"></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">访问密码：</div>
                        <div class="info-value">
                            <input type="text" name="album_password" value="<?php echo htmlspecialchars($currentAlbum['password'] ?? ''); ?>" placeholder="留空表示无密码保护">
                            <span style="font-size: 12px; margin-left: 8px;">设置密码后，前端访问需输入密码</span>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">隐藏相册：</div>
                        <div class="info-value">
                            <label class="toggle-label">
                                <input type="checkbox" name="album_hidden" value="1" <?php echo isset($currentAlbum['hidden']) && $currentAlbum['hidden'] == 1 ? 'checked' : ''; ?>>
                                <span class="toggle-text">开启后，此相册将不会在独立页面的相册列表中显示</span>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="card-actions">
                    <button type="submit" name="edit_album" class="btn-save">保存修改</button>
                </div>
            </form>
        </div>
        
        <div class="upload-section">
            <h4> 上传图片</h4>
            <div class="file-input-wrapper">
                <button type="button" class="btn-select-file" id="selectFileBtn">选择文件</button>
                <span class="file-count-info" id="fileCountInfo" style="color:#ff8c00;">未选择任何文件</span>
                <input type="file" id="fileInput" multiple accept="image/*" style="display:none;">
            </div>
            <div class="file-list" id="fileList" style="display:none;">
                <div style="background:#f5f5f5; padding:10px 12px; font-weight:bold; font-size:12px; border-radius:10px;"> 待上传文件列表</div>
                <div id="selectedFiles" style="margin-top:0;"></div>
            </div>
            <div class="upload-buttons">
                <button type="button" class="btn-upload" id="startUploadBtn" style="display:none;">开始上传</button>
                <button type="button" class="btn-clear" id="clearFilesBtn" style="display:none;">清空列表</button>
            </div>
            <div class="upload-status" id="uploadStatus"></div>
        </div>
        
        <div class="photos-section">
            <?php if (count($photos) > 0): ?>
            
            <form method="post" id="batchForm">
                <input type="hidden" name="batch_delete" value="1">
                <div class="batch-bar" id="batchBar" style="display:none;">
                   <span>已选中 <strong id="selectedCount">0</strong> 张图片</span>
                    <a href="javascript:void(0)" id="selectAllBtn" class="batch-link">全选</a>
                    <a href="javascript:void(0)" id="cancelSelectBtn" class="batch-link">取消选择</a>
                    <button type="button" class="batch-delete-btn" id="batchDeleteBtn">批量删除</button>
                </div>
                
                <div class="photos-grid" id="photosGrid">
                    <?php foreach($photos as $photo): 
                        $isCurrentCover = ($currentAlbum['cover'] == $photo['path']);
                    ?>
                    <div class="photo-card <?php echo $isCurrentCover ? 'current-cover' : ''; ?>" data-id="<?php echo $photo['id']; ?>">
                        <?php if ($isCurrentCover): ?>
                        <div class="cover-badge">封面</div>
                        <?php endif; ?>
                        <div class="photo-preview">
                            <img src="<?php echo $options->siteUrl . ltrim($photo['path'], '/'); ?>" alt="<?php echo htmlspecialchars(basename($photo['path'])); ?>">
                        </div>
                        <div class="photo-actions">
                            <a href="#" class="preview-link" data-photo-url="<?php echo $options->siteUrl . ltrim($photo['path'], '/'); ?>"> 预览</a>
                            <a href="#" class="set-cover-link" data-photo-id="<?php echo $photo['id']; ?>" data-album-id="<?php echo $currentAlbumId; ?>">设为封面</a>
                            <a href="#" class="delete-link" data-photo-id="<?php echo $photo['id']; ?>" data-album-id="<?php echo $currentAlbumId; ?>">删除</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </form>
            
            <script>
            (function() {
                var photoCards = document.querySelectorAll('.photo-card');
                var batchBar = document.getElementById('batchBar');
                var selectedCountSpan = document.getElementById('selectedCount');
                var selectAllBtn = document.getElementById('selectAllBtn');
                var cancelSelectBtn = document.getElementById('cancelSelectBtn');
                var selectedIds = [];
                
                // 记录最后点击的图片索引
                var lastClickedIndex = -1;
                
                function updateSelectionUI() {
                    selectedIds = [];
                    document.querySelectorAll('.photo-card').forEach(function(card) {
                        if (card.classList.contains('selected')) {
                            var id = card.getAttribute('data-id');
                            if (id) selectedIds.push(id);
                        }
                    });
                    var count = selectedIds.length;
                    if (selectedCountSpan) selectedCountSpan.textContent = count;
                    if (batchBar) batchBar.style.display = count > 0 ? 'flex' : 'none';
                }
                
                function togglePhoto(card) {
                    if (card.classList.contains('selected')) {
                        card.classList.remove('selected');
                    } else {
                        card.classList.add('selected');
                    }
                    updateSelectionUI();
                }
                
                // 绑定点击事件
                photoCards.forEach(function(card, index) {
                    card.addEventListener('click', function(e) {
                        // 防止点击内部链接时触发
                        if (e.target.tagName === 'A' || e.target.closest('a')) {
                            return;
                        }
                        
                        // Shift 键：范围选择
                        if (e.shiftKey && lastClickedIndex !== -1 && lastClickedIndex !== index) {
                            e.preventDefault();
                            var start = Math.min(lastClickedIndex, index);
                            var end = Math.max(lastClickedIndex, index);
                            
                            for (var i = start; i <= end; i++) {
                                var currentCard = photoCards[i];
                                if (!currentCard.classList.contains('selected')) {
                                    currentCard.classList.add('selected');
                                }
                            }
                            updateSelectionUI();
                            lastClickedIndex = index;
                        }
                        // Ctrl/Cmd 键：多选
                        else if (e.ctrlKey || e.metaKey) {
                            togglePhoto(card);
                            lastClickedIndex = index;
                        }
                        // 普通点击：单选
                        else {
                            if (!card.classList.contains('selected')) {
                                photoCards.forEach(function(c) {
                                    if (c.classList.contains('selected')) {
                                        c.classList.remove('selected');
                                    }
                                });
                                card.classList.add('selected');
                            } else {
                                card.classList.remove('selected');
                            }
                            updateSelectionUI();
                            lastClickedIndex = index;
                        }
                    });
                });
                
                // 全选按钮
                if (selectAllBtn) {
                    selectAllBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        var allSelected = photoCards.length === selectedIds.length;
                        photoCards.forEach(function(card) {
                            if (allSelected) {
                                card.classList.remove('selected');
                            } else {
                                card.classList.add('selected');
                            }
                        });
                        updateSelectionUI();
                        selectAllBtn.textContent = allSelected ? '全选' : '取消全选';
                        lastClickedIndex = -1;
                    });
                }
                
                // 取消选择按钮
                if (cancelSelectBtn) {
                    cancelSelectBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        photoCards.forEach(function(card) {
                            card.classList.remove('selected');
                        });
                        if (selectAllBtn) selectAllBtn.textContent = '全选';
                        updateSelectionUI();
                        lastClickedIndex = -1;
                    });
                }
                
                updateSelectionUI();
                
                window.getSelectedPhotoIds = function() {
                    return selectedIds;
                };
            })();
            
            // ===== 统一确认弹窗函数（兼容 AB-Admin 和原生环境） =====
            function showConfirm(message) {
                return new Promise(function(resolve) {
                    var AB = window.AdminBeautify;
                    // AB v2.1.33+ 公开 API
                    if (AB && typeof AB.confirm === 'function') {
                        AB.confirm(message).then(resolve);
                        return;
                    }
                    // 降级：原生 confirm
                    resolve(confirm(message));
                });
            }
            
            // ===== 批量删除 =====
            document.getElementById('batchDeleteBtn').addEventListener('click', function(e) {
                e.preventDefault();
                var ids = window.getSelectedPhotoIds();
                if (ids.length === 0) {
                    alert('请先选择要删除的图片！');
                    return;
                }
                showConfirm('确定删除选中的 ' + ids.length + ' 张图片吗？\n此操作不可撤销！').then(function(ok) {
                    if (!ok) return;
                    var form = document.getElementById('batchForm');
                    var oldInputs = form.querySelectorAll('input[name="photo_ids[]"]');
                    oldInputs.forEach(function(input) { input.remove(); });
                    
                    ids.forEach(function(id) {
                        var input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'photo_ids[]';
                        input.value = id;
                        form.appendChild(input);
                    });
                    
                    form.submit();
                });
            });
            
            // ===== 单张图片删除 =====
            document.querySelectorAll('.delete-link').forEach(function(link) {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    var photoId = this.getAttribute('data-photo-id');
                    var albumId = this.getAttribute('data-album-id');
                    var deleteUrl = '<?php echo $currentUrl; ?>&delete=' + photoId + '&album_id=' + albumId;
                    
                    showConfirm('确定删除这张图片吗？\n此操作不可撤销！').then(function(ok) {
                        if (ok) {
                            window.location.href = deleteUrl;
                        }
                    });
                });
            });
            
            // ===== 设为封面（无弹窗，直接跳转） =====
            document.querySelectorAll('.set-cover-link').forEach(function(link) {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    var photoId = this.getAttribute('data-photo-id');
                    var albumId = this.getAttribute('data-album-id');
                    var coverUrl = '<?php echo $currentUrl; ?>&set_cover=1&photo_id=' + photoId + '&album_id=' + albumId;
                    
                    // 直接跳转，无需确认弹窗
                    window.location.href = coverUrl;
                });
            });
            
            // ===== 删除整个相册 =====
            document.getElementById('deleteAlbumBtn').addEventListener('click', function(e) {
                e.preventDefault();
                var deleteUrl = '<?php echo $currentUrl; ?>&delete_album=<?php echo $currentAlbumId; ?>';
                
                showConfirm('确定删除整个相册吗？\n所有图片将被永久删除！').then(function(ok) {
                    if (ok) {
                        window.location.href = deleteUrl;
                    }
                });
            });
            
            // 预览功能
            document.querySelectorAll('.preview-link').forEach(function(link) {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    var imgUrl = this.getAttribute('data-photo-url');
                    var modal = document.createElement('div');
                    modal.className = 'preview-modal';
                    modal.innerHTML = '<span class="close">&times;</span><img src="' + imgUrl + '">';
                    document.body.appendChild(modal);
                    modal.addEventListener('click', function(e) {
                        if (e.target === modal || e.target.className === 'close') {
                            modal.remove();
                        }
                    });
                });
            });
            </script>
            
            <?php else: ?>
            <div class="empty-tip">
                <p>此相册暂无图片，请上传图片~</p>
            </div>
            <?php endif; ?>
        </div>

<?php else: ?>
        
        <div class="albums-header">
            <button class="btn btn-primary" onclick="openAddAlbumForm()">+ 新建相册</button>
        </div>
        
        <?php if (count($albums) > 0): ?>
        <div class="albums-grid">
            <?php foreach ($albums as $album): 
                $photoCount = $db->fetchRow($db->select('COUNT(*) as num')->from($prefix . 'photos')->where('album_id = ?', $album['id']));
                // 使用 getAlbumCover 获取封面
                $coverUrl = MyPhotoAlbum_Plugin::getAlbumCover($album['cover']);
                if (empty($album['cover']) || $album['cover'] == '') {
                    // 尝试从相册文件夹获取第一张图片作为封面
                    if (!empty($album['folder'])) {
                        $albumStoragePath = MyPhotoAlbum_Plugin::UPLOAD_DIR . '/' . $album['folder'];
                        $albumFullPath = __TYPECHO_ROOT_DIR__ . $albumStoragePath;
                        if (is_dir($albumFullPath)) {
                            $firstPhoto = glob($albumFullPath . '/*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE);
                            if (!empty($firstPhoto)) {
                                $coverUrl = $options->siteUrl . ltrim($albumStoragePath, '/') . '/' . basename($firstPhoto[0]);
                            }
                        }
                    }
                }
            ?>
            <div class="album-card" onclick="location.href='<?php echo $currentUrl; ?>&album_id=<?php echo $album['id']; ?>'">
                <img class="album-cover" src="<?php echo $coverUrl; ?>" onerror="this.src='https://placehold.co/400x600/f0f0f0/999?text=No+Image'">
                <div class="album-overlay">
                    <div class="album-name"><?php echo htmlspecialchars($album['name']); ?></div>
                </div>
                <div class="album-stats">  <?php echo $photoCount['num']; ?></div>
                <div class="album-actions">
                    <a href="#" class="edit-btn" onclick="event.stopPropagation(); location.href='<?php echo $currentUrl; ?>&album_id=<?php echo $album['id']; ?>'">编辑</a>
                    <a href="#" class="delete-album-link" data-album-id="<?php echo $album['id']; ?>" onclick="event.stopPropagation();">删除</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <script>
        // ===== 相册列表页的删除确认（兼容 AB-Admin） =====
        function showConfirm(message) {
            return new Promise(function(resolve) {
                var AB = window.AdminBeautify;
                if (AB && typeof AB.confirm === 'function') {
                    AB.confirm(message).then(resolve);
                    return;
                }
                resolve(confirm(message));
            });
        }
        
        document.querySelectorAll('.delete-album-link').forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var albumId = this.getAttribute('data-album-id');
                var deleteUrl = '<?php echo $currentUrl; ?>&delete_album=' + albumId;
                
                showConfirm('删除相册会同时删除里面的所有图片，确定吗？\n此操作不可撤销！').then(function(ok) {
                    if (ok) {
                        window.location.href = deleteUrl;
                    }
                });
            });
        });
        </script>
        
        <?php else: ?>
        <div class="empty-tip">
            <p>暂无相册，点击上方按钮创建第一个相册~</p>
        </div>
        <?php endif; ?>
        
<div id="addAlbumModal" class="modal" style="display:none;">
    <div class="modal-content">
        <h3>新建相册</h3>
        <form method="post">
            <input type="text" name="album_name" placeholder="相册名称 *" required>
            <textarea name="album_description" placeholder="相册简介（可选）"></textarea>
            <input type="text" name="album_cover" placeholder="自定义封面URL（可选）">
            <input type="number" name="album_sort" placeholder="排序" value="0">
            <input type="text" name="album_password" placeholder="访问密码（可选）">
            <div style="margin: 0 0 15px 0; padding: 0; display: flex; align-items: center; gap: 8px;">
                <input type="checkbox" name="album_hidden" value="1" style="margin: 0; padding: 0; width: 16px; height: 16px; flex-shrink: 0; cursor: pointer;">
                <span style="font-size: 13px; color: #666; line-height: 1; cursor: pointer;">不将相册显示到独立页</span>
            </div>
            <div class="modal-buttons">
                <button type="button" class="btn" onclick="closeAddAlbumModal()">取消</button>
                <button type="submit" name="add_album" class="btn btn-primary">创建</button>
            </div>
        </form>
    </div>
</div>
        
        <script>
        function openAddAlbumForm() {
            document.getElementById('addAlbumModal').style.display = 'flex';
        }
        function closeAddAlbumModal() {
            document.getElementById('addAlbumModal').style.display = 'none';
        }
        document.getElementById('addAlbumModal')?.addEventListener('click', function(e) {
            if (e.target === this) closeAddAlbumModal();
        });
        </script>
        
<?php endif; ?>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var fileInput = document.getElementById('fileInput');
    var selectFileBtn = document.getElementById('selectFileBtn');
    if (!fileInput || !selectFileBtn) return;
    
    // 点击绿色按钮触发文件选择
    selectFileBtn.addEventListener('click', function() {
        fileInput.click();
    });
    
    var fileListDiv = document.getElementById('fileList');
    var selectedFilesDiv = document.getElementById('selectedFiles');
    var startUploadBtn = document.getElementById('startUploadBtn');
    var clearFilesBtn = document.getElementById('clearFilesBtn');
    var uploadStatus = document.getElementById('uploadStatus');
    var fileCountInfo = document.getElementById('fileCountInfo');

    var selectedFiles = [];
    var currentLayout = 'list';

    function updateFileCountInfo() {
        var count = selectedFiles.length;
        if (count === 0) {
            fileCountInfo.textContent = '未选择任何文件';
        } else {
            fileCountInfo.textContent = '已选择 ' + count + ' 个文件';
        }
    }

    function switchLayout(fileCount) {
        if (fileCount < 3) {
            if (currentLayout !== 'list') {
                fileListDiv.classList.remove('grid-layout');
                fileListDiv.classList.add('list-layout');
                currentLayout = 'list';
            }
        } else {
            if (currentLayout !== 'grid') {
                fileListDiv.classList.remove('list-layout');
                fileListDiv.classList.add('grid-layout');
                currentLayout = 'grid';
            }
        }
    }

    function renderFileList() {
        selectedFilesDiv.innerHTML = '';
        
        if (selectedFiles.length === 0) {
            fileListDiv.style.display = 'none';
            startUploadBtn.style.display = 'none';
            clearFilesBtn.style.display = 'none';
            updateFileCountInfo();
            return;
        }
        
        switchLayout(selectedFiles.length);
        
        if (currentLayout === 'list') {
            var filesList = document.createElement('div');
            filesList.className = 'files-list';
            
            for (var i = 0; i < selectedFiles.length; i++) {
                var file = selectedFiles[i];
                var displayName = file.name.length > 50 ? file.name.substring(0, 47) + '...' : file.name;
                
                var fileItem = document.createElement('div');
                fileItem.className = 'file-item';
                fileItem.id = 'file_item_' + i;
                fileItem.innerHTML = '<div class="file-info">' +
                    '<span class="file-name" title="' + file.name + '"> ' + displayName + '</span>' +
                    '<span class="file-size">' + (file.size / 1024).toFixed(1) + ' KB</span>' +
                    '</div>' +
                    '<div class="file-progress">' +
                    '<div class="file-progress-fill" id="progress_' + i + '"></div>' +
                    '</div>';
                filesList.appendChild(fileItem);
            }
            selectedFilesDiv.appendChild(filesList);
        } else {
            var filesGrid = document.createElement('div');
            filesGrid.className = 'files-grid';
            
            for (var i = 0; i < selectedFiles.length; i++) {
                var file = selectedFiles[i];
                var displayName = file.name.length > 30 ? file.name.substring(0, 27) + '...' : file.name;
                
                var fileCard = document.createElement('div');
                fileCard.className = 'file-card';
                fileCard.id = 'file_card_' + i;
                fileCard.innerHTML = '<div class="file-info">' +
                    '<span class="file-name" title="' + file.name + '"> ' + displayName + '</span>' +
                    '<span class="file-size">' + (file.size / 1024).toFixed(1) + ' KB</span>' +
                    '</div>' +
                    '<div class="file-progress">' +
                    '<div class="file-progress-fill" id="progress_' + i + '"></div>' +
                    '</div>';
                filesGrid.appendChild(fileCard);
            }
            selectedFilesDiv.appendChild(filesGrid);
        }
        
        fileListDiv.style.display = 'block';
        startUploadBtn.style.display = 'inline-block';
        clearFilesBtn.style.display = 'inline-block';
        uploadStatus.innerHTML = '';
        updateFileCountInfo();
    }

    function updateFileProgress(index, success) {
        var progressFill = document.getElementById('progress_' + index);
        if (!progressFill) return;
        progressFill.style.width = '100%';
        if (success) {
            progressFill.classList.add('uploaded');
        } else {
            progressFill.classList.add('error');
        }
    }

    fileInput.addEventListener('change', function(e) {
        selectedFiles = Array.from(e.target.files);
        renderFileList();
    });

    clearFilesBtn.addEventListener('click', function() {
        fileInput.value = '';
        selectedFiles = [];
        fileListDiv.style.display = 'none';
        startUploadBtn.style.display = 'none';
        clearFilesBtn.style.display = 'none';
        uploadStatus.innerHTML = '';
        currentLayout = 'list';
        fileListDiv.classList.remove('grid-layout', 'list-layout');
        updateFileCountInfo();
    });

    startUploadBtn.addEventListener('click', async function() {
        if (selectedFiles.length === 0) return;
        
        startUploadBtn.disabled = true;
        clearFilesBtn.disabled = true;
        fileInput.disabled = true;
        uploadStatus.innerHTML = '正在上传...';
        
        var successCount = 0;
        var failCount = 0;
        
        for (var i = 0; i < selectedFiles.length; i++) {
            var file = selectedFiles[i];
            var formData = new FormData();
            formData.append('photoFile', file);
            
            try {
                var response = await fetch(window.location.href + '&ajax_upload=1&album_id=<?php echo $currentAlbumId; ?>', {
                    method: 'POST',
                    body: formData
                });
                var result = await response.json();
                
                if (result.success) {
                    successCount++;
                    updateFileProgress(i, true);
                    uploadStatus.innerHTML = '上传进度：' + (i + 1) + '/' + selectedFiles.length + ' 成功:' + successCount + ' 失败:' + failCount;
                } else {
                    failCount++;
                    updateFileProgress(i, false);
                    uploadStatus.innerHTML = '上传进度：' + (i + 1) + '/' + selectedFiles.length + ' 成功:' + successCount + ' 失败:' + failCount + ' - ' + (result.error || '上传失败');
                }
            } catch (err) {
                failCount++;
                updateFileProgress(i, false);
                uploadStatus.innerHTML = '上传进度：' + (i + 1) + '/' + selectedFiles.length + ' 成功:' + successCount + ' 失败:' + failCount + ' - 网络错误';
            }
        }
        
        if (failCount === 0) {
            uploadStatus.innerHTML = '✅ 上传完成！成功 ' + successCount + ' 张图片，页面即将刷新...';
            setTimeout(function() { location.reload(); }, 1500);
        } else {
            uploadStatus.innerHTML = '⚠️ 上传完成！成功 ' + successCount + ' 张，失败 ' + failCount + ' 张';
            startUploadBtn.disabled = false;
            clearFilesBtn.disabled = false;
            fileInput.disabled = false;
        }
    });
});
</script>

<?php include 'footer.php'; ?>