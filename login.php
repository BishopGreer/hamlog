<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

session_start_hamlog();
if (current_user()) { redirect('/dashboard.php'); }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (login($username, $password)) {
        redirect('/dashboard.php');
    }
    $error = 'Invalid username or password.';
}

$page_title = 'Login';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login — HamLog</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/hamlog.css">
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh">
<div class="card" style="width:100%;max-width:420px">
  <div class="card-header text-center py-3">
    <div style="font-size:2.5rem"><i class="bi bi-broadcast text-success"></i></div>
    <div class="fw-bold fs-4 mt-1">HamLog</div>
    <div class="text-muted small">Amateur Radio Logbook</div>
  </div>
  <div class="card-body p-4">
    <?php if ($error): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>
    <form method="post">
      <div class="mb-3">
        <label class="form-label">Username or Email</label>
        <input type="text" name="username" class="form-control" required autofocus
               value="<?= h($_POST['username'] ?? '') ?>">
      </div>
      <div class="mb-3">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" required>
      </div>
      <button type="submit" class="btn btn-success w-100">
        <i class="bi bi-box-arrow-in-right"></i> Sign In
      </button>
    </form>
    <?php if (db_setting('allow_registration') === '1'): ?>
    <div class="text-center mt-3 text-muted small">
      Don't have an account? <a href="<?= BASE_URL ?>/register.php" class="text-success">Register</a>
    </div>
    <?php endif; ?>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
