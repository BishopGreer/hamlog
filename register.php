<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

session_start_hamlog();
if (current_user()) { redirect('/dashboard.php'); }
if (db_setting('allow_registration') !== '1') { redirect('/login.php'); }

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';
    $callsign = strtoupper(trim($_POST['callsign'] ?? ''));

    if (strlen($username) < 3)   $errors[] = 'Username must be at least 3 characters.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';
    if (strlen($password) < 8)   $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirm)  $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        $uid = register_user($username, $email, $password, $callsign);
        if ($uid) {
            // Create default station if callsign provided
            if ($callsign) {
                $pdo = db();
                $st = $pdo->prepare('INSERT INTO stations (owner_id, callsign, name) VALUES (?,?,?)');
                $st->execute([$uid, $callsign, $callsign]);
                $sid = (int)$pdo->lastInsertId();
                $st = $pdo->prepare('INSERT INTO logbooks (station_id, name, is_default) VALUES (?,?,1)');
                $st->execute([$sid, 'Main Log']);
            }
            login($username, $password);
            flash('success', 'Welcome to HamLog! Your account has been created.');
            redirect('/dashboard.php');
        } else {
            $errors[] = 'Username or email already in use.';
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Register — HamLog</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/hamlog.css">
</head>
<body class="d-flex align-items-center justify-content-center py-4" style="min-height:100vh">
<div class="card" style="width:100%;max-width:480px">
  <div class="card-header text-center py-3">
    <div style="font-size:2rem"><i class="bi bi-broadcast text-success"></i></div>
    <div class="fw-bold fs-4">Create Account</div>
  </div>
  <div class="card-body p-4">
    <?php foreach ($errors as $e): ?>
    <div class="alert alert-danger py-2"><?= h($e) ?></div>
    <?php endforeach; ?>
    <form method="post">
      <div class="mb-3">
        <label class="form-label">Username</label>
        <input type="text" name="username" class="form-control" required value="<?= h($_POST['username'] ?? '') ?>">
      </div>
      <div class="mb-3">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" required value="<?= h($_POST['email'] ?? '') ?>">
      </div>
      <div class="mb-3">
        <label class="form-label">Callsign <span class="text-muted">(optional — you can add it later)</span></label>
        <input type="text" name="callsign" class="form-control" style="text-transform:uppercase"
               value="<?= h($_POST['callsign'] ?? '') ?>" placeholder="e.g. W1AW">
      </div>
      <div class="mb-3">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Confirm Password</label>
        <input type="password" name="confirm" class="form-control" required>
      </div>
      <button type="submit" class="btn btn-success w-100">
        <i class="bi bi-person-plus"></i> Create Account
      </button>
    </form>
    <div class="text-center mt-3 text-muted small">
      Already have an account? <a href="<?= BASE_URL ?>/login.php" class="text-success">Sign In</a>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
