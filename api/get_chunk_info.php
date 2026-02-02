<?php
/**
 * ============================================
 * API: Get Chunk Information
 * File: api/get_chunk_info.php
 * ============================================
 * 
 * Returns chunk mapping information for a specific quality
 */

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/secure_drm.php';

header('Content-Type: application/json');

// Get parameters
$videoId = $_POST['video_id'] ?? $_GET['video_id'] ?? '';
$quality = $_POST['quality'] ?? $_GET['quality'] ?? '360p';
$csrfToken = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';

// Validate CSRF token
if (!isset($_SESSION['csrf_token']) || $csrfToken !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// Validate parameters
if (empty($videoId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing video_id']);
    exit;
}

// Path traversal protection
if (str_contains($videoId, '..') || str_contains($videoId, '/')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid video ID']);
    exit;
}



try {
    $manager = new SecurePlaybackManager();

    // Get chunk information
    $result = $manager->getChunkInfo($videoId, $quality);

    if ($result['success']) {
        echo json_encode($result);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Video not found']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'message' => $e->getMessage()
    ]);
}
?>