<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

// Check if installed
try {
    $installed = db_setting('installed');
} catch (PDOException) {
    $installed = '';
}

if (!$installed) {
    header('Location: ' . BASE_URL . '/install/index.php');
    exit;
}

require_once __DIR__ . '/includes/auth.php';
$user = current_user();
if ($user) {
    header('Location: ' . BASE_URL . '/dashboard.php');
} else {
    header('Location: ' . BASE_URL . '/login.php');
}
exit;
