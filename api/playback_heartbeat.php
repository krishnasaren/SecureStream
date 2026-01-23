<?php
/**
 * ============================================
 * API: Heartbeat (Keep Session Alive)
 * File: api/playback_heartbeat.php
 * ============================================
 */

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/secure_drm.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->getCurrentUser()) {
    http_response_code(401);
    echo json_encode(['success' => false]);
    exit;
}

$sessionToken = $_POST['token'] ?? '';
$currentTime = isset($_POST['current_time']) ? floatval($_POST['current_time']) : 0;

if (empty($sessionToken)) {
    http_response_code(400);
    echo json_encode(['success' => false]);
    exit;
}

$manager = new SecurePlaybackManager();
$validation = $manager->validateSession($sessionToken);

if ($validation['valid']) {
    // Update session activity
    $_SESSION['playback_sessions'][$sessionToken]['last_activity'] = time();
    $_SESSION['playback_sessions'][$sessionToken]['current_position'] = $currentTime;

    echo json_encode([
        'success' => true,
        'time_remaining' => $validation['session']['expires_at'] - time()
    ]);
} else {
    echo json_encode(['success' => false, 'error' => $validation['error']]);
}
exit;
?>