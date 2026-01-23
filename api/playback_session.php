<?php
require_once '../includes/auth.php';

session_start();

$auth = new Auth();
if (!$auth->getCurrentUser()) {
    http_response_code(403);
    exit;
}

$videoId = $_POST['video_id'] ?? '';
if (!$videoId)
    exit;

$_SESSION['playback'][$videoId] = [
    'token' => bin2hex(random_bytes(16)),
    'expires' => time() + 15 // 15 seconds
];

echo json_encode(['success' => true]);



?>