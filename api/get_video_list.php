<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

// Enable CORS for development
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Content-Type: application/json');

// Initialize auth
$auth = new Auth();

// Check authentication
if (!$auth->getCurrentUser()) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
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