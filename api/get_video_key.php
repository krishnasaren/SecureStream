<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/encryption.php';


header('Content-Type: application/json');


$referer = $_SERVER['HTTP_REFERER'] ?? '';

if (!$referer) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not Allowed']);
    exit;
}

if (parse_url($referer, PHP_URL_HOST) !== parse_url('//' . $_SERVER['HTTP_HOST'], PHP_URL_HOST)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid Requests']);
    exit;
}

// Initialize auth and encryption
$auth = new Auth();
$encryption = new VideoEncryption();

// Check authentication
if (!$auth->getCurrentUser()) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Get video ID
$videoId = $_GET['id'] ?? $_POST['id'] ?? '';
if (empty($videoId)) {
    echo json_encode(['success' => false, 'error' => 'Video ID required']);
    exit;
}

// Validate video ID
if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $videoId)) {
    echo json_encode(['success' => false, 'error' => 'Invalid video ID']);
    exit;
}

// Check if video exists
$videoInfo = getVideoInfo($videoId);
if (!$videoInfo) {
    echo json_encode(['success' => false, 'error' => 'Video not found']);
    exit;
}

// Get video key
$keyData = $encryption->getVideoKey($videoId);

if ($keyData['success']) {
    echo json_encode([
        'success' => true,
        'video_id' => $videoId,
        'key' => $keyData['key'],
        'iv' => $keyData['iv'],
        'algorithm' => $keyData['algorithm']
    ]);
} else {
    echo json_encode(['success' => false, 'error' => $keyData['error']]);
}
?>