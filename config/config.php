<?php
// HamLog configuration
// Copy config.sample.php to config.php and fill in your values.

define('DB_HOST', 'localhost');
define('DB_NAME', 'hamlog');
define('DB_USER', 'hamlog');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

define('HAMLOG_VERSION', '1.0.0');
define('BASE_URL', '');          // e.g. '/hamlog' if not at web root
define('UPLOAD_PATH', __DIR__ . '/../uploads/');
define('SESSION_NAME', 'hamlog_session');

// Callsign lookup service: 'qrz', 'hamqth', or 'none'
define('CALLSIGN_LOOKUP', 'qrz');

// Debug mode — disable in production
define('DEBUG', false);
