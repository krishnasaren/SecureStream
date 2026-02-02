<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

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

$auth = new Auth();
if (!$auth->getCurrentUser()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$videoId = $_POST['video_id'] ?? '';

if (empty($videoId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Video ID required']);
    exit;
}

if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'CSRF validation failed']);
    exit;
}

//$_SESSION['init_csrf_token'] = $_SESSION['csrf_token'];
//$_SESSION['csrf_token'] = bin2hex(random_bytes(32));


$videoInfo = getVideoInfo($videoId);

if (!$videoInfo) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Video not found']);
    exit;
}

echo json_encode([
    'success' => true,
    'video_id' => $videoId,
    'qualities' => $videoInfo['qualities'] ?? ['360p'],
    'audio_tracks' => $videoInfo['audio_tracks'] ?? [],
    'subtitle_tracks' => $videoInfo['subtitle_tracks'] ?? [],
    'has_multi_quality' => $videoInfo['has_multi_quality'] ?? false,
    'has_multi_audio' => $videoInfo['has_multi_audio'] ?? false,
    'has_subtitles' => $videoInfo['has_subtitles'] ?? false,
    'chunk_count' => $videoInfo['chunk_count'],
    'chunk_size_seconds' => $videoInfo['chunk_size_seconds'],
    //'type' => $randomNumber = random_int(1, 100) % 2 === 0 ? 'VOD' : 'LIVE',
    'type' => 'VOD',
]);
?>