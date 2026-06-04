<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

session_start_hamlog();
$user = require_login();

$sid = (int)($_POST['station_id'] ?? 0);
if ($sid && user_can_access_station($user['id'], $sid)) {
    $_SESSION['active_station'] = $sid;
}
$ref = $_SERVER['HTTP_REFERER'] ?? BASE_URL . '/dashboard.php';
header('Location: ' . $ref);
exit;
