<?php
// 设置响应头
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// 设置时区为亚洲/上海
date_default_timezone_set('Asia/Shanghai');

// 处理预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 获取请求方法
$method = $_SERVER['REQUEST_METHOD'];

// 获取请求路径
$path = isset($_GET['path']) ? $_GET['path'] : '';

// 获取项目根目录
$rootDir = '/www/wwwroot/fileserves/wendang';

// 检查是否是搜索请求
if (isset($_GET['search'])) {
    handleSearch($_GET['search'], $rootDir);
} else {
    // 根据请求方法处理不同操作
    switch ($method) {
        case 'GET':
        case 'HEAD':  // 添加对HEAD请求的支持
            handleGet($path, $rootDir);
            break;
        case 'POST':
            handlePost($path, $rootDir);
            break;
        case 'PUT':
            handlePut($path, $rootDir);
            break;
        case 'DELETE':
            handleDelete($path, $rootDir);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed: ' . $method]);
            break;
    }
}

// 处理GET请求（文件列表、文件内容）
function handleGet($path, $rootDir) {
    $fullPath = $rootDir . '/' . $path;
    
    // 安全检查：确保路径在根目录内
    if (!isPathInRoot($fullPath, $rootDir)) {
        http_response_code(403);
        echo json_encode(['error' => '拒绝访问']);
        return;
    }
    
    // 如果是文件，则根据Accept头决定返回方式
    if (is_file($fullPath)) {
        // 检查是否有缩略图参数
        $isThumbnail = isset($_GET['thumbnail']);
        
        // 如果请求缩略图
        if ($isThumbnail) {
            // 检查是否为图片文件
            $mimeType = mime_content_type($fullPath);
            if (strpos($mimeType, 'image/') === 0) {
                generateThumbnail($fullPath);
                return;
            } else {
                // 如果不是图片文件，返回404
                http_response_code(404);
                echo json_encode(['error' => '文件不是图片格式']);
                return;
            }
        }
        
        // 检查Accept头，判断客户端是否希望接收JSON格式的数据
        $acceptHeader = isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '';
        $wantsJson = strpos($acceptHeader, 'application/json') !== false;
        
        // 检查是否有content参数
        $hasContentParam = isset($_GET['content']);
        
        // 如果有content参数或Accept头要求JSON，则返回内容或base64编码
        if ($hasContentParam || $wantsJson) {
            // 检查文件大小（限制为50MB）
            $fileSize = filesize($fullPath);
            $maxFileSize = 50 * 1024 * 1024; // 50MB
            
            if ($fileSize > $maxFileSize) {
                http_response_code(400);
                echo json_encode(['error' => '文件过大，无法预览。请直接下载文件。']);
                return;
            }
            
            // 检查文件类型
            $mimeType = mime_content_type($fullPath);
            
            // 对于文本文件，返回内容
            if (strpos($mimeType, 'text/') === 0 || 
                $mimeType === 'application/json' || 
                $mimeType === 'application/javascript' ||
                $mimeType === 'application/xml' ||
                $mimeType === 'application/pdf') {
                // 设置正确的Content-Type头
                header('Content-Type: ' . $mimeType . '; charset=utf-8');
                // 对于大文本文件，使用流式读取
                if ($fileSize > 10 * 1024 * 1024) { // 10MB
                    readfile($fullPath);
                } else {
                    echo file_get_contents($fullPath);
                }
                return;
            } else {
                // 对于二进制文件，返回base64编码
                $content = base64_encode(file_get_contents($fullPath));
                echo json_encode(['content' => $content, 'encoding' => 'base64']);
                return;
            }
        } else {
            // 否则直接提供文件下载
            $fileName = basename($fullPath);
            $mimeType = mime_content_type($fullPath);
            
            // 设置下载头
            header('Content-Type: ' . $mimeType);
            header('Content-Disposition: inline; filename="' . $fileName . '"');
            header('Content-Length: ' . filesize($fullPath));
            
            // 输出文件内容
            readfile($fullPath);
            return;
        }
    }
    
    // 返回目录内容或文件信息
    if (is_dir($fullPath)) {
        $files = [];
        $items = scandir($fullPath);
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $itemPath = $fullPath . '/' . $item;
            $relativePath = $path ? $path . '/' . $item : $item;
            
            $fileInfo = [
                'name' => $item,
                'isDirectory' => is_dir($itemPath),
                'size' => is_file($itemPath) ? filesize($itemPath) : 0,
                'modifiedTime' => date('Y-m-d\TH:i:s', filemtime($itemPath)),
                'path' => $relativePath
            ];
            
            // 获取文件MIME类型
            if (is_file($itemPath)) {
                $fileInfo['type'] = mime_content_type($itemPath);
            }
            
            $files[] = $fileInfo;
        }
        
        echo json_encode($files, JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'File or directory not found'], JSON_UNESCAPED_UNICODE);
    }
}

// 处理POST请求（上传文件、新建文件夹）
function handlePost($path, $rootDir) {
    $fullPath = $rootDir . '/' . $path;
    
    // 安全检查：确保路径在根目录内
    if (!isPathInRoot($fullPath, $rootDir)) {
        http_response_code(403);
        echo json_encode(['error' => '拒绝访问'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 获取请求数据
    $input = json_decode(file_get_contents('php://input'), true);
    
    // 新建文件夹
    if (isset($input['action']) && $input['action'] === 'createFolder') {
        $folderName = $input['name'];
        $folderPath = $fullPath . '/' . $folderName;
        
        // 检查文件夹名称是否合法
        if (!preg_match('/^[^\/\\:*?"<>|]+$/', $folderName)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid folder name'], JSON_UNESCAPED_UNICODE);
            return;
        }
        
        if (mkdir($folderPath, 0755, true)) {
            echo json_encode(['success' => true, 'message' => 'Folder created successfully'], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo json_encode(['error' => '创建文件夹失败'], JSON_UNESCAPED_UNICODE);
        }
        return;
    }
    
    // 上传文件
    if (isset($_FILES['file'])) {
        $uploadPath = $fullPath . '/' . $_FILES['file']['name'];
        
        // 检查文件是否已存在
        if (file_exists($uploadPath)) {
            http_response_code(409);
            echo json_encode(['error' => '文件已存在'], JSON_UNESCAPED_UNICODE);
            return;
        }
        
        if (move_uploaded_file($_FILES['file']['tmp_name'], $uploadPath)) {
            echo json_encode(['success' => true, 'message' => 'File uploaded successfully'], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo json_encode(['error' => '文件上传失败'], JSON_UNESCAPED_UNICODE);
        }
        return;
    }
    
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request'], JSON_UNESCAPED_UNICODE);
}

// 处理PUT请求（重命名文件/文件夹）
function handlePut($path, $rootDir) {
    $fullPath = $rootDir . '/' . $path;
    
    // 安全检查：确保路径在根目录内
    if (!isPathInRoot($fullPath, $rootDir)) {
        http_response_code(403);
        echo json_encode(['error' => '拒绝访问'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 获取请求数据
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (isset($input['action']) && $input['action'] === 'rename') {
        $newName = $input['newName'];
        $dir = dirname($fullPath);
        $newPath = $dir . '/' . $newName;
        
        // 检查新名称是否合法
        if (!preg_match('/^[^\/\\:*?"<>|]+$/', $newName)) {
            http_response_code(400);
            echo json_encode(['error' => '文件名无效'], JSON_UNESCAPED_UNICODE);
            return;
        }
        
        // 安全检查：确保新路径在根目录内
        if (!isPathInRoot($newPath, $rootDir)) {
            http_response_code(403);
            echo json_encode(['error' => '拒绝访问'], JSON_UNESCAPED_UNICODE);
            return;
        }
        
        // 检查目标文件/文件夹是否已存在
        if (file_exists($newPath)) {
            http_response_code(409);
            echo json_encode(['error' => '文件或文件夹已存在'], JSON_UNESCAPED_UNICODE);
            return;
        }
        
        if (rename($fullPath, $newPath)) {
            echo json_encode(['success' => true, 'message' => 'Renamed successfully'], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo json_encode(['error' => '重命名失败'], JSON_UNESCAPED_UNICODE);
        }
        return;
    }
    
    // 处理移动文件/文件夹
    if (isset($input['action']) && $input['action'] === 'move') {
        $targetPath = $input['targetPath'];
        // 如果目标路径为空，则移动到根目录
        if (empty($targetPath)) {
            $newPath = $rootDir . '/' . basename($fullPath);
        } else {
            $newPath = $rootDir . '/' . $targetPath . '/' . basename($fullPath);
        }
        
        // 安全检查：确保目标路径在根目录内
        if (!isPathInRoot($newPath, $rootDir)) {
            http_response_code(403);
            echo json_encode(['error' => '拒绝访问'], JSON_UNESCAPED_UNICODE);
            return;
        }
        
        // 检查目标文件/文件夹是否已存在
        if (file_exists($newPath)) {
            http_response_code(409);
            echo json_encode(['error' => '文件或文件夹已存在'], JSON_UNESCAPED_UNICODE);
            return;
        }
        
        // 确保目标目录存在（除非是移动到根目录）
        if (!empty($targetPath) && !is_dir($rootDir . '/' . $targetPath)) {
            http_response_code(400);
            echo json_encode(['error' => '目标目录不存在'], JSON_UNESCAPED_UNICODE);
            return;
        }
        
        if (rename($fullPath, $newPath)) {
            echo json_encode(['success' => true, 'message' => 'Moved successfully'], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo json_encode(['error' => '移动失败'], JSON_UNESCAPED_UNICODE);
        }
        return;
    }
    
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request'], JSON_UNESCAPED_UNICODE);
}

// 处理DELETE请求（删除文件/文件夹）
function handleDelete($path, $rootDir) {
    $fullPath = $rootDir . '/' . $path;
    
    // 安全检查：确保路径在根目录内
    if (!isPathInRoot($fullPath, $rootDir)) {
        http_response_code(403);
        echo json_encode(['error' => '拒绝访问'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if (!file_exists($fullPath)) {
        http_response_code(404);
        echo json_encode(['error' => 'File or directory not found'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if (is_file($fullPath)) {
        // 检查文件权限
        if (!is_writable($fullPath)) {
            http_response_code(500);
            echo json_encode(['error' => '文件没有写入权限，无法删除'], JSON_UNESCAPED_UNICODE);
            return;
        }
        
        if (unlink($fullPath)) {
            echo json_encode(['success' => true, 'message' => 'File deleted successfully'], JSON_UNESCAPED_UNICODE);
        } else {
            // 获取更详细的错误信息
            $error = error_get_last();
            $errorMsg = $error ? $error['message'] : '未知错误';
            http_response_code(500);
            echo json_encode(['error' => '删除文件失败: ' . $errorMsg], JSON_UNESCAPED_UNICODE);
        }
    } else if (is_dir($fullPath)) {
        // 检查目录权限
        if (!is_writable($fullPath)) {
            http_response_code(500);
            echo json_encode(['error' => '目录没有写入权限，无法删除'], JSON_UNESCAPED_UNICODE);
            return;
        }
        
        if (deleteDirectory($fullPath)) {
            echo json_encode(['success' => true, 'message' => 'Directory deleted successfully'], JSON_UNESCAPED_UNICODE);
        } else {
            // 获取更详细的错误信息
            $error = error_get_last();
            $errorMsg = $error ? $error['message'] : '未知错误';
            http_response_code(500);
            echo json_encode(['error' => '删除目录失败: ' . $errorMsg], JSON_UNESCAPED_UNICODE);
        }
    }
}

// 递归删除目录
function deleteDirectory($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    
    $files = array_diff(scandir($dir), ['.', '..']);
    
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            if (!deleteDirectory($path)) {
                return false;
            }
        } else {
            // 检查文件权限
            if (!is_writable($path)) {
                return false;
            }
            if (!unlink($path)) {
                return false;
            }
        }
    }
    
    // 检查目录权限
    if (!is_writable($dir)) {
        return false;
    }
    
    return rmdir($dir);
}

// 检查路径是否在根目录内
function isPathInRoot($path, $root) {
    // 规范化路径
    $realPath = realpath($path);
    $realRoot = realpath($root);
    
    // 如果realpath失败，尝试手动规范化路径
    if ($realPath === false) {
        // 解析相对路径
        $realPath = $path;
        if (substr($realPath, 0, 1) !== '/') {
            $realPath = $root . '/' . $realPath;
        }
        // 移除相对路径部分
        $realPath = str_replace('/./', '/', $realPath);
        while (strpos($realPath, '/../')) {
            $realPath = preg_replace('#/[^/]+/\.\./#', '/', $realPath, 1);
        }
    }
    
    if ($realRoot === false) {
        $realRoot = $root;
    }
    
    // 确保路径以根目录路径开头
    return strpos($realPath, $realRoot) === 0;
}

// 处理搜索请求
function handleSearch($query, $rootDir) {
    // 安全检查：确保查询不为空
    if (empty($query)) {
        http_response_code(400);
        echo json_encode(['error' => '搜索关键词不能为空']);
        return;
    }
    
    // 搜索文件
    $results = searchFiles($rootDir, $query);
    
    // 返回搜索结果
    echo json_encode($results, JSON_UNESCAPED_UNICODE);
}

// 递归搜索文件
function searchFiles($dir, $query) {
    $results = [];
    
    // 打开目录
    $handle = opendir($dir);
    if ($handle === false) {
        return $results;
    }
    
    // 遍历目录中的文件和子目录
    while (($item = readdir($handle)) !== false) {
        // 跳过当前目录和父目录
        if ($item === '.' || $item === '..') {
            continue;
        }
        
        $fullPath = $dir . '/' . $item;
        $relativePath = str_replace('/var/www/html/', '', $fullPath);
        
        // 检查文件名是否匹配查询
        if (stripos($item, $query) !== false) {
            // 获取文件信息
            $fileInfo = [
                'name' => $item,
                'isDirectory' => is_dir($fullPath),
                'size' => is_file($fullPath) ? filesize($fullPath) : 0,
                'modifiedTime' => date('Y-m-d\TH:i:s', filemtime($fullPath)),
                'path' => $relativePath
            ];
            
            // 获取文件MIME类型
            if (is_file($fullPath)) {
                $fileInfo['type'] = mime_content_type($fullPath);
            }
            
            $results[] = $fileInfo;
        }
        
        // 如果是目录，递归搜索
        if (is_dir($fullPath)) {
            $subResults = searchFiles($fullPath, $query);
            $results = array_merge($results, $subResults);
        }
    }
    
    closedir($handle);
    return $results;
}

// 生成图片缩略图
function generateThumbnail($imagePath) {
    // 获取图片信息
    $imageInfo = getimagesize($imagePath);
    
    if ($imageInfo === false) {
        http_response_code(400);
        echo json_encode(['error' => '无法读取图片信息']);
        return;
    }
    
    $mimeType = $imageInfo['mime'];
    
    // 设置缩略图尺寸
    $thumbWidth = 200;
    $thumbHeight = 200;
    
    // 优先使用GD库生成缩略图
    if (extension_loaded('gd')) {
        try {
            generateThumbnailGD($imagePath, $imageInfo, $thumbWidth, $thumbHeight);
            return;
        } catch (Exception $e) {
            // 如果GD库失败，继续尝试ImageMagick
            error_log('GD缩略图生成失败: ' . $e->getMessage());
        }
    }
    
    // 如果GD不可用，尝试使用ImageMagick
    if (function_exists('exec')) {
        try {
            generateThumbnailImageMagick($imagePath, $thumbWidth, $thumbHeight);
            return;
        } catch (Exception $e) {
            error_log('ImageMagick缩略图生成失败: ' . $e->getMessage());
        }
    }
    
    // 如果以上方法都失败，返回原始图片
    header('Content-Type: ' . $mimeType);
    header('Cache-Control: public, max-age=86400');
    readfile($imagePath);
}

// 使用GD库生成缩略图
function generateThumbnailGD($imagePath, $imageInfo, $thumbWidth, $thumbHeight) {
    // 根据图片类型创建图像资源
    switch ($imageInfo[2]) {
        case IMAGETYPE_JPEG:
            $srcImage = imagecreatefromjpeg($imagePath);
            break;
        case IMAGETYPE_PNG:
            $srcImage = imagecreatefrompng($imagePath);
            break;
        case IMAGETYPE_GIF:
            $srcImage = imagecreatefromgif($imagePath);
            break;
        case IMAGETYPE_BMP:
            $srcImage = imagecreatefrombmp($imagePath);
            break;
        case IMAGETYPE_WEBP:
            $srcImage = imagecreatefromwebp($imagePath);
            break;
        default:
            throw new Exception('不支持的图片格式');
    }
    
    if (!$srcImage) {
        throw new Exception('无法创建图像资源');
    }
    
    // 获取原图尺寸
    $srcWidth = imagesx($srcImage);
    $srcHeight = imagesy($srcImage);
    
    // 计算缩略图尺寸（保持宽高比）
    if ($srcWidth > $srcHeight) {
        $thumbHeight = intval($srcHeight * $thumbWidth / $srcWidth);
    } else {
        $thumbWidth = intval($srcWidth * $thumbHeight / $srcHeight);
    }
    
    // 创建缩略图画布
    $thumbImage = imagecreatetruecolor($thumbWidth, $thumbHeight);
    
    // 保持PNG和GIF的透明度
    if ($imageInfo[2] == IMAGETYPE_PNG || $imageInfo[2] == IMAGETYPE_GIF) {
        imagealphablending($thumbImage, false);
        imagesavealpha($thumbImage, true);
        $transparent = imagecolorallocatealpha($thumbImage, 255, 255, 255, 127);
        imagefilledrectangle($thumbImage, 0, 0, $thumbWidth, $thumbHeight, $transparent);
    }
    
    // 调整图片尺寸
    imagecopyresampled($thumbImage, $srcImage, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $srcWidth, $srcHeight);
    
    // 设置响应头
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=86400'); // 缓存1天
    
    // 输出JPEG格式的缩略图（更好的压缩率）
    imagejpeg($thumbImage, null, 80);
    
    // 释放内存
    imagedestroy($srcImage);
    imagedestroy($thumbImage);
}

// 使用ImageMagick生成缩略图
function generateThumbnailImageMagick($imagePath, $thumbWidth, $thumbHeight) {
    // 创建临时文件路径
    $tempThumbPath = tempnam(sys_get_temp_dir(), 'thumb_') . '.jpg';
    
    // 使用convert命令生成缩略图
    $cmd = "convert " . escapeshellarg($imagePath) . " -resize {$thumbWidth}x{$thumbHeight} -quality 80 " . escapeshellarg($tempThumbPath);
    exec($cmd, $output, $returnCode);
    
    if ($returnCode !== 0) {
        // 如果convert命令失败，尝试使用magick命令
        $cmd = "magick " . escapeshellarg($imagePath) . " -resize {$thumbWidth}x{$thumbHeight} -quality 80 " . escapeshellarg($tempThumbPath);
        exec($cmd, $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new Exception('无法生成缩略图');
        }
    }
    
    // 读取生成的缩略图
    $thumbData = file_get_contents($tempThumbPath);
    
    // 删除临时文件
    unlink($tempThumbPath);
    
    if ($thumbData === false) {
        throw new Exception('无法读取缩略图数据');
    }
    
    // 设置响应头
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=86400'); // 缓存1天
    
    // 输出缩略图
    echo $thumbData;
}
?>