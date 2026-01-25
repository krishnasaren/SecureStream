<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

// Enable CORS for development
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
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

// Initialize auth
$auth = new Auth();

// Check authentication
if (!$auth->getCurrentUser()) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if (!hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'CSRF validation failed']);
    exit;
}

// Get all videos
$videos = getAllVideos();

// Format response
$videoList = [];
foreach ($videos as $video) {
    $videoList[] = [
        'id' => $video['id'],
        'title' => $video['title'],
        'description' => $video['description'],
        'thumbnail' => $video['thumbnail'],
        'chunks' => $video['chunks'],
        'created_at' => $video['created_at']
    ];
}

echo json_encode([
    'success' => true,
    'count' => count($videoList),
    'videos' => $videoList
]);
?>