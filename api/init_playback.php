<?php
/**
 * ============================================
 * API: Initialize Secure Playback Session
 * File: api/init_playback.php
 * ============================================
 */

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/secure_drm.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->getCurrentUser()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'CSRF validation failed']);
    exit;
}

$videoId = $_POST['video_id'] ?? '';
if (empty($videoId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Video ID required']);
    exit;
}

// Verify video exists
$videoInfo = getVideoInfo($videoId);
if (!$videoInfo) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Video not found']);
    exit;
}

// Create secure playback session
$manager = new SecurePlaybackManager();
$result = $manager->createPlaybackSession($videoId, $auth->getCurrentUser());

echo json_encode($result);
exit;
?>


