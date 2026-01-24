<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

$auth = new Auth();
if (!$auth->getCurrentUser()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$videoId = $_GET['id'] ?? '';

if (!$videoId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Video ID required']);
    exit;
}
$info = getVideoInfo($videoId);



if (!$info) {
    http_response_code(404);
    echo json_encode(['error' => 'Video not found']);
    exit;
}

header('Content-Type: application/json');
echo json_encode($info);
