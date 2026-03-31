<?php
// file-manager.php - Enhanced secure version
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Configure for production
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Session-Token');

// Enhanced Security Configuration
define('UPLOAD_PASSWORD_HASH', hash('sha256', 'Snara!2026' . 'filemanager_salt_2024'));
define('ADMIN_PASSWORD_HASH', hash('sha256', 'Aniket!2026' . 'filemanager_salt_2024'));

define('MAX_FILE_SIZE', 20 * 1024 * 1024); // 20MB
define('ALLOWED_EXTENSIONS', ['php', 'html', 'htm', 'css', 'js', 'json', 'xml', 'txt', 'md', 'log', 'ini',
                              'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'ico', 'bmp',
                              'zip', 'rar', 'gz', 'tar', 'tgz', 'pdf', 'doc', 'docx', 'xls', 'xlsx']);

// Session management (simple file-based for demo - use database in production)
function validateSession($token) {
    if (empty($token)) return false;
    
    $sessionFile = sys_get_temp_dir() . '/fm_session_' . hash('sha256', $token);
    if (file_exists($sessionFile)) {
        $sessionData = json_decode(file_get_contents($sessionFile), true);
        if ($sessionData && $sessionData['expires'] > time()) {
            return true;
        }
        unlink($sessionFile); // Clean expired session
    }
    return false;
}

function createSession($token) {
    $sessionFile = sys_get_temp_dir() . '/fm_session_' . hash('sha256', $token);
    $sessionData = [
        'token' => $token,
        'created' => time(),
        'expires' => time() + 3600 // 1 hour
    ];
    file_put_contents($sessionFile, json_encode($sessionData));
}

// Get request data
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? $_POST['action'] ?? '';
$path = $_GET['path'] ?? $input['path'] ?? $_POST['path'] ?? '';

// Security validation
$sessionToken = $_POST['session_token'] ?? $input['session_token'] ?? $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';
$hashedPassword = $_POST['hashed_password'] ?? $input['hashed_password'] ?? '';

// Legacy support for direct password (less secure)
$directPassword = $_GET['password'] ?? $input['password'] ?? $_POST['password'] ?? '';
if ($directPassword && !$hashedPassword) {
    $hashedPassword = hash('sha256', $directPassword . 'filemanager_salt_2024');
}

function checkSecurity($action, $hashedPassword, $sessionToken) {
    // Validate session token first
    if (!validateSession($sessionToken)) {
        return false;
    }
    
    // Check password permissions
    if (in_array($action, ['upload', 'rename', 'move', 'mkdir', 'save'])) {
        return $hashedPassword === UPLOAD_PASSWORD_HASH || $hashedPassword === ADMIN_PASSWORD_HASH;
    } elseif (in_array($action, ['delete'])) {
        return $hashedPassword === ADMIN_PASSWORD_HASH;
    } elseif (in_array($action, ['list', 'read', 'download', 'test'])) {
        return $hashedPassword === UPLOAD_PASSWORD_HASH || $hashedPassword === ADMIN_PASSWORD_HASH;
    }
    return false;
}

// Response helper
function jsonResponse($success, $data = null, $message = '') {
    echo json_encode(['success' => $success, 'data' => $data, 'message' => $message]);
    exit;
}

// Path sanitization
function sanitizePath($path) {
    if (empty($path) || $path === '.' || $path === '/') return '.';
    $path = str_replace(['\\', '//'], '/', trim($path, '/'));
    if (strpos($path, '..') !== false) {
        error_log("Security Alert: Directory traversal attempt: " . $path);
        return '.';
    }
    return $path;
}

function getFileExtension($filename) {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

function isAllowedFile($filename) {
    $ext = getFileExtension($filename);
    return is_dir($filename) || in_array($ext, ALLOWED_EXTENSIONS);
}

// Special handling for session creation (no session required for this)
if ($action === 'create_session') {
    $token = $_POST['token'] ?? '';
    if ($token) {
        createSession($token);
        jsonResponse(true, null, 'Session created');
    } else {
        jsonResponse(false, null, 'Invalid token');
    }
}

// Security check for all other actions
if (!checkSecurity($action, $hashedPassword, $sessionToken)) {
    jsonResponse(false, null, 'Authentication failed or insufficient permissions');
}

$sanitizedPath = sanitizePath($path);

switch ($action) {
    case 'list':
        $targetPath = $sanitizedPath;
        if (!file_exists($targetPath) || !is_dir($targetPath)) {
            jsonResponse(false, null, "Invalid path: " . $targetPath);
        }

        $files = [];
        $items = scandir($targetPath);
        if ($items === false) {
            jsonResponse(false, null, "Cannot read directory");
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $fullPath = $targetPath . DIRECTORY_SEPARATOR . $item;
            $isDir = is_dir($fullPath);

            if (!$isDir && !isAllowedFile($fullPath)) continue;

            $files[] = [
                'name' => $item,
                'type' => $isDir ? 'directory' : 'file',
                'size' => $isDir ? 0 : (file_exists($fullPath) ? filesize($fullPath) : 0),
                'modified' => file_exists($fullPath) ? filemtime($fullPath) : 0,
                'extension' => $isDir ? null : getFileExtension($item),
                'path' => ($targetPath === '.' ? '' : $targetPath . DIRECTORY_SEPARATOR) . $item
            ];
        }

        usort($files, function($a, $b) {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'directory' ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        jsonResponse(true, $files);
        break;

    case 'read':
        $fileName = $_POST['file'] ?? $input['file'] ?? '';
        $fullPath = $sanitizedPath . DIRECTORY_SEPARATOR . $fileName;
        $fullPath = sanitizePath($fullPath);

        if (!file_exists($fullPath) || is_dir($fullPath)) {
            jsonResponse(false, null, 'File not found or is a directory');
        }

        if (!isAllowedFile($fullPath)) {
            jsonResponse(false, null, 'File type not allowed');
        }

        $content = file_get_contents($fullPath);
        if ($content === false) {
            jsonResponse(false, null, 'Failed to read file');
        }
        jsonResponse(true, base64_encode($content));
        break;

    case 'download':
        $fileName = $_POST['file'] ?? $_GET['file'] ?? $input['file'] ?? '';
        $fullPath = $sanitizedPath . DIRECTORY_SEPARATOR . $fileName;
        $fullPath = sanitizePath($fullPath);

        if (!file_exists($fullPath) || is_dir($fullPath)) {
            jsonResponse(false, null, 'File not found or is a directory');
        }

        if (!isAllowedFile($fullPath)) {
            jsonResponse(false, null, 'File type not allowed');
        }

        // Force download with proper headers
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($fullPath) . '"');
        header('Content-Length: ' . filesize($fullPath));
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        
        // Clear any previous output
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        readfile($fullPath);
        exit;
        break;

    case 'upload':
        if (!isset($_FILES['file'])) {
            jsonResponse(false, null, 'No file uploaded');
        }

        $file = $_FILES['file'];
        $targetPath = rtrim($sanitizedPath, '/\\') . DIRECTORY_SEPARATOR;
        $targetFile = $targetPath . basename($file['name']);

        if ($file['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(false, null, 'Upload error: ' . $file['error']);
        }

        if ($file['size'] > MAX_FILE_SIZE) {
            jsonResponse(false, null, 'File too large (max ' . (MAX_FILE_SIZE / 1024 / 1024) . 'MB)');
        }

        if (!isAllowedFile($file['name'])) {
            jsonResponse(false, null, 'File type not allowed');
        }

        $dirToCreate = dirname($targetFile);
        if (!is_dir($dirToCreate) && !mkdir($dirToCreate, 0755, true)) {
            jsonResponse(false, null, 'Failed to create directory');
        }

        if (move_uploaded_file($file['tmp_name'], $targetFile)) {
            jsonResponse(true, null, 'File uploaded successfully');
        } else {
            jsonResponse(false, null, 'Upload failed');
        }
        break;

    case 'delete':
        $fileName = $_POST['file'] ?? $input['file'] ?? '';
        $fullPath = $sanitizedPath . DIRECTORY_SEPARATOR . $fileName;
        $fullPath = sanitizePath($fullPath);

        if (!file_exists($fullPath)) {
            jsonResponse(false, null, 'File not found');
        }

        if (is_dir($fullPath)) {
            $it = new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS);
            $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getRealPath());
                } else {
                    unlink($file->getRealPath());
                }
            }
            rmdir($fullPath) ? jsonResponse(true, null, 'Directory deleted') : jsonResponse(false, null, 'Failed to delete directory');
        } else {
            unlink($fullPath) ? jsonResponse(true, null, 'File deleted') : jsonResponse(false, null, 'Failed to delete file');
        }
        break;

    case 'rename':
        $oldName = $_POST['oldPath'] ?? $input['oldPath'] ?? '';
        $newName = $_POST['newPath'] ?? $input['newPath'] ?? '';
        $oldPath = $sanitizedPath . DIRECTORY_SEPARATOR . $oldName;
        $newPath = $sanitizedPath . DIRECTORY_SEPARATOR . $newName;
        $oldPath = sanitizePath($oldPath);
        $newPath = sanitizePath($newPath);

        if (!file_exists($oldPath)) {
            jsonResponse(false, null, 'Source not found');
        }
        if (file_exists($newPath)) {
            jsonResponse(false, null, 'Target already exists');
        }

        rename($oldPath, $newPath) ? jsonResponse(true, null, 'Renamed successfully') : jsonResponse(false, null, 'Rename failed');
        break;

    case 'mkdir':
        $newFolder = $_POST['path'] ?? $input['path'] ?? '';
        $newPath = $sanitizedPath . DIRECTORY_SEPARATOR . $newFolder;
        $newPath = sanitizePath($newPath);

        if (file_exists($newPath)) {
            jsonResponse(false, null, 'Directory already exists');
        }

        mkdir($newPath, 0755, true) ? jsonResponse(true, null, 'Directory created') : jsonResponse(false, null, 'Failed to create directory');
        break;

    case 'save':
        $fileName = $_POST['file'] ?? $input['file'] ?? '';
        $content = base64_decode($_POST['content'] ?? $input['content'] ?? '');
        $fullPath = $sanitizedPath . DIRECTORY_SEPARATOR . $fileName;
        $fullPath = sanitizePath($fullPath);

        if (!isAllowedFile($fileName)) {
            jsonResponse(false, null, 'File type not allowed');
        }

        $dir = dirname($fullPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            jsonResponse(false, null, 'Failed to create directory');
        }

        file_put_contents($fullPath, $content) !== false ? jsonResponse(true, null, 'File saved') : jsonResponse(false, null, 'Save failed');
        break;

    case 'test':
        $info = [
            'php_version' => PHP_VERSION,
            'current_dir' => getcwd(),
            'path_used' => $sanitizedPath,
            'path_exists' => file_exists($sanitizedPath),
            'is_writable' => is_writable($sanitizedPath),
            'session_valid' => validateSession($sessionToken),
            'security_mode' => 'Enhanced with session tokens'
        ];
        jsonResponse(true, $info, 'Connection test successful');
        break;

    default:
        jsonResponse(false, null, 'Invalid action');
}
?>