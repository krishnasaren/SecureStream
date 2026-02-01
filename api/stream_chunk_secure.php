<?php
/**
 * ============================================
 * API: Stream Secure Chunk (DRM-Protected with Dynamic Chunk Handling)
 * File: api/stream_chunk_secure.php
 * ============================================
 * 
 * Enhanced version with dynamic chunk handling for different stream counts
 */

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/secure_drm.php';

// Get all parameters
$videoId = $_GET['video_id'] ?? '';
$track = $_GET['track'] ?? '';
$index = isset($_GET['index']) ? intval($_GET['index']) : -1;
$sessionToken = $_GET['session_token'] ?? '';
$quality = $_GET['quality'] ?? '360p';
$audioTrack = isset($_GET['audio_track']) ? intval($_GET['audio_track']) : 0;

// Basic validation
if (
    empty($videoId) ||
    ($track !== 'video' && $track !== 'audio') ||
    $index < 0 ||
    empty($sessionToken)
) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing or invalid parameters']);
    exit;
}

// Path traversal protection
if (str_contains($videoId, '..') || str_contains($videoId, '/')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid video ID']);
    exit;
}

// Validate quality parameter
$allowedQualities = ['144p', '240p', '360p', '480p', '720p', '1080p'];
if (!in_array($quality, $allowedQualities)) {
    $quality = '360p'; // Default to 360p
}

// Validate audio track
if ($audioTrack < 0) {
    $audioTrack = 0;
}

// Serve secure chunk with dynamic handling
try {
    $manager = new SecurePlaybackManager();

    // Use the new dynamic chunk serving method
    $manager->serveDynamicChunk(
        $videoId,
        $track,
        $index,
        $sessionToken,
        $quality,
        $audioTrack
    );

} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Internal server error',
        'message' => $e->getMessage()
    ]);
    exit;
}
?>