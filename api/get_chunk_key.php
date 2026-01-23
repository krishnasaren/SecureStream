<?php
/**
 * ============================================
 * API: Get Ephemeral Chunk Key
 * File: api/get_chunk_key.php
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

$videoId = $_GET['video_id'] ?? '';
$chunkIndex = isset($_GET['chunk_index']) ? intval($_GET['chunk_index']) : -1;
$sessionToken = $_GET['session_token'] ?? '';

if (empty($videoId) || $chunkIndex < 0 || empty($sessionToken)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

// Generate ephemeral key
$manager = new SecurePlaybackManager();
$result = $manager->generateEphemeralKey($videoId, $chunkIndex, $sessionToken);

echo json_encode($result);
exit;
?>