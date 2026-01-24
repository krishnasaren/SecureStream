<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/secure_drm.php';


header('Content-Type: application/json');

// 🔐 Only admin allowed
$auth = new Auth();
$isAdmin = $auth->isAdmin();

if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$videoId = $_POST['video_id'] ?? '';

if (!$videoId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Video ID required']);
    exit;
}

if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'CSRF validation failed']);
    exit;
}

try {
    deleteVideoCompletely($videoId);

    echo json_encode([
        'success' => true,
        'message' => "Video $videoId deleted successfully"
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
function deleteVideoCompletely(string $videoId): void
{
    // Paths
    $encryptedDir = ENCRYPTED_DIR . $videoId . '/';
    $keysDir = KEYS_DIR . $videoId . '/';
    $thumbFile = THUMBNAILS_DIR . $videoId . '.jpg';

    // 1️⃣ Delete encrypted DASH files
    deleteDirectory($encryptedDir);

    // 2️⃣ Delete encryption keys
    deleteDirectory($keysDir);

    // 3️⃣ Delete original uploaded file(s)
    $originalFiles = glob(ORIGINAL_DIR . $videoId . '_*');
    foreach ($originalFiles as $file) {
        @unlink($file);
    }

    // 4️⃣ Delete thumbnail
    if (file_exists($thumbFile)) {
        @unlink($thumbFile);
    }

    // 5️⃣ Invalidate playback sessions
    SecurePlaybackManager::invalidatePlaybackSessions($videoId);
    

    // 6️⃣ Clear PHP file cache
    clearstatcache(true);

    // 7️⃣ Optional: log deletion
    error_log("Video deleted: $videoId");
}

function deleteDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $file) {
        if ($file->isDir()) {
            @rmdir($file->getRealPath());
        } else {
            @unlink($file->getRealPath());
        }
    }

    @rmdir($dir);
}
exit;
?>


