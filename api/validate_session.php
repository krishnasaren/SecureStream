<?php
/**
 * ============================================
 * API: Validate Session
 * File: api/validate_session.php
 * ============================================
 */

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/secure_drm.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->getCurrentUser()) {
    http_response_code(401);
    echo json_encode(['valid' => false, 'error' => 'Not authenticated']);
    exit;
}

$sessionToken = $_GET['token'] ?? '';
if (empty($sessionToken)) {
    http_response_code(400);
    echo json_encode(['valid' => false, 'error' => 'Token required']);
    exit;
}

$manager = new SecurePlaybackManager();
$result = $manager->validateSession($sessionToken);

echo json_encode($result);
exit;
?>