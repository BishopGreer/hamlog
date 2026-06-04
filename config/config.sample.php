<?php
// HamLog configuration
// Copy config.sample.php to config.php and fill in your values.

// Version is defined in version.php (git-tracked). Include it first so updates
// are reflected without touching this file.
require_once __DIR__ . '/../version.php';

define('DB_HOST', 'localhost');
define('DB_NAME', 'hamlog');
define('DB_USER', 'hamlog');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

define('BASE_URL', '');          // e.g. '/hamlog' if not at web root
define('UPLOAD_PATH', __DIR__ . '/../uploads/');
define('SESSION_NAME', 'hamlog_session');

// Callsign lookup service: 'qrz', 'hamqth', or 'none'
define('CALLSIGN_LOOKUP', 'qrz');

// Debug mode — disable in production
define('DEBUG', false);
