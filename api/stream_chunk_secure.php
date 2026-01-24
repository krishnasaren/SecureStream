<?php
/**
 * ============================================
 * API: Stream Secure Chunk (DRM-Protected)
 * File: api/stream_chunk_secure.php
 * ============================================
 * 
 * This replaces the old stream_chunk.php
 */

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/secure_drm.php';

//commented out because of parallel chunk access in PHP which SESSION cannot handle, thread locking issue
/*$auth = new Auth();
if (!$auth->getCurrentUser()) {
    http_response_code(401);
    exit;
}*/

$videoId = $_GET['video_id'] ?? '';
$track = $_GET['track'] ?? '';
$index = isset($_GET['index']) ? intval($_GET['index']) : -1;
$sessionToken = $_GET['session_token'] ?? '';

// Validation
if (
    empty($videoId) ||
    ($track !== 'video' && $track !== 'audio') ||
    $index < 0 ||
    empty($sessionToken)
) {
    http_response_code(400);
    exit;
}

// Path traversal protection
if (str_contains($videoId, '..') || str_contains($videoId, '/')) {
    http_response_code(403);
    exit;
}

// Serve secure chunk with double encryption
$manager = new SecurePlaybackManager();
$manager->serveSecureChunk($videoId, $track, $index, $sessionToken);

// Method exits internally
?>