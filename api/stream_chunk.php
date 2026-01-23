<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

$auth = new Auth();

// 🔐 Auth check
if (!$auth->getCurrentUser()) {
    http_response_code(401);
    exit;
}

// Params
$videoId = $_GET['video_id'] ?? '';
$track = $_GET['track'] ?? '';
$index = intval($_GET['index'] ?? -1);
$index++;

// Validation
if (
    empty($videoId) ||
    ($track !== 'video' && $track !== 'audio') ||
    $index < 1
) {
    http_response_code(400);
    exit;
}

// Path traversal protection
if (str_contains($videoId, '..') || str_contains($videoId, '/')) {
    http_response_code(403);
    exit;
}

// Map track → DASH stream id
$streamId = ($track === 'video') ? 0 : 1;

// Build filename (NO legacy extensions)
$chunkFile = sprintf(
    '%s%s/chunk-stream%d-%05d.enc',
    ENCRYPTED_DIR,
    $videoId,
    $streamId,
    $index
);

// File existence
if (!is_file($chunkFile)) {
    http_response_code(404);
    exit;
}

// Headers (opaque binary transport)
header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($chunkFile));
header('Content-Disposition: attachment; filename="' . basename($chunkFile) . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

// Stream encrypted bytes
readfile($chunkFile);
exit;
?>