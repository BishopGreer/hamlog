<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$_current_user = current_user();
$_page_title   = isset($page_title) ? h($page_title) . ' — HamLog' : 'HamLog';
$_stations     = $_current_user ? get_user_stations($_current_user['id']) : [];
$_active_sid   = $_SESSION['active_station'] ?? ($_stations[0]['id'] ?? null);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $_page_title ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/hamlog.css">
</head>
<body>

<?php if ($_current_user): ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark border-bottom border-success">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold text-success" href="<?= BASE_URL ?>/dashboard.php">
      <i class="bi bi-broadcast"></i> HamLog
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav me-auto">
        <li class="nav-item">
          <a class="nav-link<?= str_contains($_SERVER['PHP_SELF'] ?? '', 'dashboard') ? ' active' : '' ?>"
             href="<?= BASE_URL ?>/dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
        </li>
        <li class="nav-item">
          <a class="nav-link<?= str_contains($_SERVER['PHP_SELF'] ?? '', 'log.php') ? ' active' : '' ?>"
             href="<?= BASE_URL ?>/log.php"><i class="bi bi-pencil-square"></i> Log QSO</a>
        </li>
        <li class="nav-item">
          <a class="nav-link<?= str_contains($_SERVER['PHP_SELF'] ?? '', 'logbook') ? ' active' : '' ?>"
             href="<?= BASE_URL ?>/logbook.php"><i class="bi bi-journal-text"></i> Logbook</a>
        </li>
        <li class="nav-item">
          <a class="nav-link<?= str_contains($_SERVER['PHP_SELF'] ?? '', 'dxcc') ? ' active' : '' ?>"
             href="<?= BASE_URL ?>/dxcc.php"><i class="bi bi-globe2"></i> DXCC</a>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?= str_contains($_SERVER['PHP_SELF'] ?? '', 'upload') ? ' active' : '' ?>"
             href="#" data-bs-toggle="dropdown"><i class="bi bi-cloud-upload"></i> Upload</a>
          <ul class="dropdown-menu dropdown-menu-dark">
            <li><a class="dropdown-item" href="<?= BASE_URL ?>/adif.php"><i class="bi bi-file-earmark-arrow-up"></i> ADIF Import/Export</a></li>
            <li><a class="dropdown-item" href="<?= BASE_URL ?>/upload.php?service=lotw"><i class="bi bi-check2-circle"></i> LoTW</a></li>
            <li><a class="dropdown-item" href="<?= BASE_URL ?>/upload.php?service=eqsl"><i class="bi bi-envelope-check"></i> eQSL</a></li>
            <li><a class="dropdown-item" href="<?= BASE_URL ?>/upload.php?service=clublog"><i class="bi bi-bar-chart-line"></i> ClubLog</a></li>
          </ul>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?= str_contains($_SERVER['PHP_SELF'] ?? '', 'station') ? ' active' : '' ?>"
             href="#" data-bs-toggle="dropdown"><i class="bi bi-antenna"></i> Stations</a>
          <ul class="dropdown-menu dropdown-menu-dark">
            <li><a class="dropdown-item" href="<?= BASE_URL ?>/stations.php"><i class="bi bi-list-ul"></i> My Stations</a></li>
            <li><a class="dropdown-item" href="<?= BASE_URL ?>/stations.php?action=new"><i class="bi bi-plus-circle"></i> Add Station</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?= BASE_URL ?>/clubs.php"><i class="bi bi-people"></i> Club Logs</a></li>
          </ul>
        </li>
        <?php if ($_current_user['is_admin']): ?>
        <li class="nav-item">
          <a class="nav-link<?= str_contains($_SERVER['PHP_SELF'] ?? '', 'admin') ? ' active' : '' ?>"
             href="<?= BASE_URL ?>/admin/index.php"><i class="bi bi-gear"></i> Admin</a>
        </li>
        <?php endif; ?>
      </ul>

      <?php if (!empty($_stations)): ?>
      <form class="d-flex me-3" method="post" action="<?= BASE_URL ?>/api/set_station.php">
        <select class="form-select form-select-sm bg-dark text-success border-success" name="station_id"
                onchange="this.form.submit()" style="min-width:160px">
          <?php foreach ($_stations as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $s['id'] == $_active_sid ? 'selected' : '' ?>>
            <?= h($s['callsign']) ?><?= $s['is_club_station'] ? ' [Club]' : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php endif; ?>

      <ul class="navbar-nav">
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
            <i class="bi bi-person-circle"></i> <?= h($_current_user['callsign'] ?: $_current_user['username']) ?>
          </a>
          <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end">
            <li><a class="dropdown-item" href="<?= BASE_URL ?>/settings.php"><i class="bi bi-sliders"></i> Settings</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="<?= BASE_URL ?>/logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
          </ul>
        </li>
      </ul>
    </div>
  </div>
</nav>
<?php endif; ?>

<div class="container-fluid py-3">
<?php
foreach (get_flashes() as $f) {
    $cls = match($f['type']) { 'success' => 'success', 'error' => 'danger', 'warning' => 'warning', default => 'info' };
    echo '<div class="alert alert-' . $cls . ' alert-dismissible fade show" role="alert">'
       . h($f['msg'])
       . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}
?>
