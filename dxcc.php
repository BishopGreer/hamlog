<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

session_start_hamlog();
$user = require_login();
$pdo  = db();

$stations   = get_user_stations($user['id']);
$active_sid = (int)($_SESSION['active_station'] ?? ($stations[0]['id'] ?? 0));

// Worked DXCC entities for active station
$worked = [];
if ($active_sid) {
    $st = $pdo->prepare(
        "SELECT q.dxcc, d.name, d.prefix, d.continent, d.cqz,
                COUNT(*) as qso_count,
                SUM(q.lotw_qsl_rcvd IN ('Y','R')) as lotw_confirmed,
                SUM(q.eqsl_qsl_rcvd IN ('Y','R')) as eqsl_confirmed,
                SUM(q.qsl_rcvd IN ('Y','R')) as card_confirmed,
                MAX(q.date_on) as last_qso,
                GROUP_CONCAT(DISTINCT q.band ORDER BY q.band) as bands
         FROM qsos q
         LEFT JOIN dxcc_entities d ON d.adif = q.dxcc
         WHERE q.station_id = ? AND q.dxcc IS NOT NULL
         GROUP BY q.dxcc, d.name, d.prefix, d.continent, d.cqz
         ORDER BY d.name"
    );
    $st->execute([$active_sid]);
    $worked = $st->fetchAll();
}

// Stats
$total_worked    = count($worked);
$total_confirmed = count(array_filter($worked, fn($r) => $r['lotw_confirmed'] > 0 || $r['card_confirmed'] > 0));
$total_dxcc      = (int)$pdo->query('SELECT COUNT(*) FROM dxcc_entities WHERE deleted = 0')->fetchColumn();

// By continent
$by_cont = [];
foreach ($worked as $w) {
    $cont = $w['continent'] ?: 'UN';
    if (!isset($by_cont[$cont])) $by_cont[$cont] = ['worked'=>0,'confirmed'=>0];
    $by_cont[$cont]['worked']++;
    if ($w['lotw_confirmed'] || $w['card_confirmed']) $by_cont[$cont]['confirmed']++;
}
ksort($by_cont);

$tab = $_GET['tab'] ?? 'worked';
$page_title = 'DXCC Tracker';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h4 class="mb-0 text-success"><i class="bi bi-globe2"></i> DXCC Tracker</h4>
</div>

<!-- Summary stats -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-number"><?= $total_worked ?></div>
      <div class="stat-label">Entities Worked</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-number"><?= $total_confirmed ?></div>
      <div class="stat-label">Entities Confirmed</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-number"><?= $total_dxcc ?></div>
      <div class="stat-label">Total DXCC Entities</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-number"><?= $total_dxcc > 0 ? round($total_worked / $total_dxcc * 100) : 0 ?>%</div>
      <div class="stat-label">Progress</div>
    </div>
  </div>
</div>

<!-- Progress bar -->
<?php if ($total_dxcc > 0): ?>
<div class="mb-4">
  <div class="d-flex justify-content-between small text-muted mb-1">
    <span>DXCC Progress</span>
    <span><?= $total_worked ?> / <?= $total_dxcc ?></span>
  </div>
  <div class="progress" style="height:12px">
    <div class="progress-bar" style="width:<?= round($total_worked/$total_dxcc*100) ?>%"></div>
    <div class="progress-bar bg-info" style="width:<?= round(($total_confirmed-0)/$total_dxcc*100) ?>%;opacity:.5"></div>
  </div>
</div>
<?php endif; ?>

<div class="row g-4">
  <!-- By continent -->
  <div class="col-md-4">
    <div class="card">
      <div class="card-header"><i class="bi bi-map"></i> By Continent</div>
      <div class="card-body">
        <?php
        $cont_names = ['AF'=>'Africa','AN'=>'Antarctica','AS'=>'Asia','EU'=>'Europe','NA'=>'N. America','OC'=>'Oceania','SA'=>'S. America','UN'=>'Unknown'];
        foreach ($by_cont as $cont => $data): ?>
        <div class="d-flex align-items-center gap-2 mb-2">
          <span class="badge bg-secondary" style="min-width:30px"><?= h($cont) ?></span>
          <span class="text-muted small flex-grow-1"><?= $cont_names[$cont] ?? $cont ?></span>
          <span class="text-success fw-bold"><?= $data['worked'] ?></span>
          <span class="text-muted small">(<?= $data['confirmed'] ?> cfm)</span>
        </div>
        <?php endforeach; ?>
        <?php if (empty($by_cont)): ?>
        <p class="text-muted text-center mt-3">No DXCC data yet</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Entity table -->
  <div class="col-md-8">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-table"></i> Worked Entities</span>
        <span class="text-muted small"><?= $total_worked ?> entities</span>
      </div>
      <div class="card-body p-0" style="max-height:600px;overflow-y:auto">
        <table class="table table-hover table-striped table-sm mb-0">
          <thead style="position:sticky;top:0">
            <tr>
              <th>Prefix</th>
              <th>Entity</th>
              <th>Cont</th>
              <th>CQ</th>
              <th>QSOs</th>
              <th>Bands</th>
              <th>LoTW</th>
              <th>eQSL</th>
              <th>Card</th>
              <th>Last QSO</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($worked)): ?>
            <tr><td colspan="10" class="text-center py-3 text-muted">No worked entities yet. Log QSOs with DXCC entity numbers to track progress.</td></tr>
            <?php else: foreach ($worked as $w): ?>
            <tr>
              <td><span class="callsign" style="font-size:.8rem"><?= h($w['prefix'] ?? '') ?></span></td>
              <td><?= h($w['name'] ?? 'DXCC #' . $w['dxcc']) ?></td>
              <td><span class="badge bg-secondary"><?= h($w['continent'] ?? '') ?></span></td>
              <td class="text-muted"><?= h($w['cqz'] ?? '') ?></td>
              <td class="text-muted"><?= $w['qso_count'] ?></td>
              <td style="font-size:.75rem">
                <?php foreach (explode(',', $w['bands'] ?? '') as $b): if(trim($b)): ?>
                <span class="badge-band me-1"><?= h(trim($b)) ?></span>
                <?php endif; endforeach; ?>
              </td>
              <td><?= $w['lotw_confirmed'] ? '<span class="qsl-y">Y</span>' : '<span class="qsl-n">N</span>' ?></td>
              <td><?= $w['eqsl_confirmed'] ? '<span class="qsl-y">Y</span>' : '<span class="qsl-n">N</span>' ?></td>
              <td><?= $w['card_confirmed'] ? '<span class="qsl-y">Y</span>' : '<span class="qsl-n">N</span>' ?></td>
              <td class="text-muted" style="font-size:.8rem"><?= h($w['last_qso'] ?? '') ?></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
