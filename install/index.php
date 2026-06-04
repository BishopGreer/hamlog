<?php
// HamLog installer — only runs if not yet installed

$config_path = __DIR__ . '/../config/config.php';
$needs_config = !file_exists($config_path);

// Step tracking
$step = (int)($_POST['step'] ?? $_GET['step'] ?? 1);

$error = '';
$success = '';

if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Write config.php
    $db_host = trim($_POST['db_host'] ?? 'localhost');
    $db_name = trim($_POST['db_name'] ?? '');
    $db_user = trim($_POST['db_user'] ?? '');
    $db_pass = $_POST['db_pass'] ?? '';
    $base_url = rtrim(trim($_POST['base_url'] ?? ''), '/');

    if (empty($db_name) || empty($db_user)) {
        $error = 'Database name and user are required.';
        $step = 1;
    } else {
        // Test connection
        try {
            $pdo = new PDO("mysql:host=$db_host;charset=utf8mb4", $db_user, $db_pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$db_name`");
            // Run schema
            $sql = file_get_contents(__DIR__ . '/install.sql');
            foreach (explode(';', $sql) as $stmt) {
                $stmt = trim($stmt);
                if ($stmt) $pdo->exec($stmt);
            }
            // Write config
            $cfg = <<<PHP
<?php
define('DB_HOST', '$db_host');
define('DB_NAME', '$db_name');
define('DB_USER', '$db_user');
define('DB_PASS', '$db_pass');
define('DB_CHARSET', 'utf8mb4');

define('HAMLOG_VERSION', '1.0.0');
define('BASE_URL', '$base_url');
define('UPLOAD_PATH', __DIR__ . '/../uploads/');
define('SESSION_NAME', 'hamlog_session');

define('CALLSIGN_LOOKUP', 'qrz');
define('DEBUG', false);
PHP;
            file_put_contents($config_path, $cfg);
            // If users already exist, skip to done — this is a config restore
            $existing_users = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $step = $existing_users > 0 ? 4 : 3;
        } catch (PDOException $e) {
            $error = 'Database error: ' . htmlspecialchars($e->getMessage());
            $step = 1;
        }
    }
}

if ($step === 3 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_admin'])) {
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/auth.php';

    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $callsign = strtoupper(trim($_POST['callsign'] ?? ''));

    if (strlen($username) < 3 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
        $error = 'Username (min 3 chars), valid email, and password (min 8 chars) are required.';
    } else {
        $uid = register_user($username, $email, $password, $callsign);
        if ($uid) {
            if ($callsign) {
                $pdo = db();
                $st = $pdo->prepare('INSERT INTO stations (owner_id, callsign, name) VALUES (?,?,?)');
                $st->execute([$uid, $callsign, $callsign]);
                $sid = (int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO logbooks (station_id, name, is_default) VALUES (?,?,1)')->execute([$sid,'Main Log']);
            }
            $step = 4;
        } else {
            // User already exists (duplicate) — config restore scenario, just go to done
            require_once __DIR__ . '/../config/config.php';
            require_once __DIR__ . '/../config/database.php';
            $existing = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
            if ($existing > 0) {
                $step = 4;
            } else {
                $error = 'Could not create user. The username or email may already be in use — try different values.';
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>HamLog — Installation</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>
body { background:#0d0d0d; color:#d4d4d4; font-family:system-ui,sans-serif; }
.card { background:#111; border:1px solid #1e3a1e; }
.card-header { background:#0f1f0f; border-bottom:1px solid #1e3a1e; color:#00cc44; font-weight:600; }
.form-control,.form-select { background:#111; border-color:#2a3a2a; color:#d4d4d4; }
.form-control:focus,.form-select:focus { background:#141f14; border-color:#00cc44; color:#fff; box-shadow:0 0 0 .15rem rgba(0,204,68,.25); }
.form-label { color:#9fba9f; font-size:.875rem; }
.btn-success { background:#1a7a2a; border-color:#1a7a2a; }
.step-badge { display:inline-block; width:28px; height:28px; line-height:28px; text-align:center; border-radius:50%; background:#0c2c0c; color:#00cc44; border:1px solid #1a5a1a; font-weight:bold; font-size:.8rem; }
.step-badge.active { background:#00cc44; color:#000; }
.step-badge.done { background:#1a5a1a; }
.alert-danger { background:#2c0c0c; border-color:#5a1a1a; color:#dd7a7a; }
.alert-success { background:#0c2c0c; border-color:#1a5a1a; color:#7add7a; }
</style>
</head>
<body class="py-5">
<div class="container" style="max-width:600px">
  <div class="text-center mb-4">
    <div style="font-size:3rem;color:#00cc44">&#x1F4E1;</div>
    <h2 class="text-white">HamLog Installation</h2>
    <div class="d-flex justify-content-center gap-3 mt-3">
      <?php for ($i=1;$i<=4;$i++): ?>
      <div>
        <span class="step-badge <?= $i < $step ? 'done' : ($i === $step ? 'active' : '') ?>">
          <?= $i < $step ? '✓' : $i ?>
        </span>
        <div style="font-size:.7rem;color:#6a8a6a;margin-top:4px">
          <?= ['','DB','Schema','Admin','Done'][$i] ?>
        </div>
      </div>
      <?php endfor; ?>
    </div>
  </div>

  <?php if ($error): ?>
  <div class="alert alert-danger mb-3"><?= $error ?></div>
  <?php endif; ?>

  <?php if ($step === 1 || ($step === 2 && !empty($error))): ?>
  <!-- Step 1: Database -->
  <div class="card">
    <div class="card-header">Step 1 — Database Configuration</div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="step" value="2">
        <div class="mb-3">
          <label class="form-label">Database Host</label>
          <input type="text" name="db_host" class="form-control" value="localhost" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Database Name</label>
          <input type="text" name="db_name" class="form-control" placeholder="hamlog" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Database Username</label>
          <input type="text" name="db_user" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Database Password</label>
          <input type="password" name="db_pass" class="form-control">
        </div>
        <div class="mb-3">
          <label class="form-label">Base URL <small class="text-muted">(leave blank if at web root)</small></label>
          <input type="text" name="base_url" class="form-control" placeholder="/hamlog">
        </div>
        <button type="submit" class="btn btn-success w-100">
          Test Connection &amp; Install Schema →
        </button>
      </form>
    </div>
  </div>

  <?php elseif ($step === 3): ?>
  <!-- Step 3: Admin account -->
  <div class="card">
    <div class="card-header">Step 3 — Create Admin Account</div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="step" value="3">
        <div class="mb-3">
          <label class="form-label">Username</label>
          <input type="text" name="username" class="form-control" required autofocus>
        </div>
        <div class="mb-3">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Callsign <span class="text-muted">(optional)</span></label>
          <input type="text" name="callsign" class="form-control" style="text-transform:uppercase">
        </div>
        <div class="mb-3">
          <label class="form-label">Password</label>
          <input type="password" name="password" class="form-control" required>
        </div>
        <button type="submit" name="create_admin" value="1" class="btn btn-success w-100">
          Create Admin Account →
        </button>
      </form>
    </div>
  </div>

  <?php elseif ($step === 4): ?>
  <!-- Done -->
  <div class="card">
    <div class="card-header">Configuration Complete!</div>
    <div class="card-body text-center py-4">
      <div style="font-size:3rem;color:#00cc44">✓</div>
      <h5 class="mt-2 text-white">HamLog is ready.</h5>
      <p class="text-muted">
        Sign in with your existing account credentials.<br>
        Please delete or protect the <code>install/</code> directory when done.
      </p>
      <a href="<?= defined('BASE_URL') ? BASE_URL : '' ?>/login.php" class="btn btn-success btn-lg mt-2">
        Go to HamLog →
      </a>
    </div>
  </div>
  <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
