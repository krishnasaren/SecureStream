<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';



$auth = new Auth();
if (!$auth->getCurrentUser()) {
    http_response_code(401);
    exit;
}

$videoId = $_GET['video_id'] ?? '';
$track = $_GET['track'] ?? '';
$quality = $_GET['quality'] ?? '360p';

if (
    empty($videoId) ||
    ($track !== 'video' && $track !== 'audio') ||
    str_contains($videoId, '..') ||
    str_contains($videoId, '/')
) {
    http_response_code(400);
    exit;
}

$streamId = ($track === 'video') ? 0 : 1;

// Look for init segment in quality folder
$file = ENCRYPTED_DIR . $videoId . "/{$quality}/init-stream{$streamId}.m4s";
$initEncFile= ENCRYPTED_DIR . $videoId . "/{$quality}/init-stream{$streamId}.enc";

// Fallback to root if quality folder doesn't exist
if (!is_file($file)) {
    $file = ENCRYPTED_DIR . $videoId . "/init-stream{$streamId}.m4s";
    $initEncFile= ENCRYPTED_DIR . $videoId . "/init-stream{$streamId}.enc";
}

if (!is_file($file)) {
    http_response_code(404);
    exit;
}

header('Content-Type: video/mp4');
header('Content-Length: ' . filesize($file));
header('Cache-Control: public, max-age=31536000'); // Init segments can be cached
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="' . basename($initEncFile) . '"');

readfile($file);
