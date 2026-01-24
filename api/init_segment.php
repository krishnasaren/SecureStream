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

$file = ENCRYPTED_DIR . $videoId . "/init-stream{$streamId}.m4s";
$initEncFile = ENCRYPTED_DIR . $videoId . "/init-stream{$streamId}.enc";

if (!is_file($file)) {
    http_response_code(404);
    exit;
}



#---------------------to avoid by other video detector that this is a video file
//header('Content-Type: video/mp4');
header('Content-Length: ' . filesize($file));
header('Content-Disposition: attachment; filename="' . basename($initEncFile) . '"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

readfile($file);
