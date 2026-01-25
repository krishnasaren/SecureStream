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
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$videoId = $_POST['id'] ?? '';

if (!$videoId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Video ID required']);
    exit;
}

if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'CSRF validation failed']);
    exit;
}

$info = getVideoInfo($videoId);



if (!$info) {
    http_response_code(404);
    echo json_encode(['success' => false,'error' => 'Video not found']);
    exit;
}


echo json_encode($info);
