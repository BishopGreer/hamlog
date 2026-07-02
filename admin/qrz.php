<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/qrz.php';
require_once __DIR__ . '/../includes/hamqth.php';

session_start_hamlog();
$user = require_admin();
$pdo  = db();

// Save QRZ credentials
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_qrz'])) {
    $un = trim($_POST['qrz_username'] ?? '');
    qrz_save_setting($pdo, 'qrz_username', $un);
    $pw = $_POST['qrz_password'] ?? '';
    if ($pw !== '') qrz_save_setting($pdo, 'qrz_password', $pw);
    qrz_save_setting($pdo, 'qrz_session_key',   '');
    qrz_save_setting($pdo, 'qrz_session_time',  '0');
    qrz_save_setting($pdo, 'qrz_session_error', '');
    flash('success', 'QRZ credentials saved.');
    redirect('/admin/qrz.php');
}

// Save HamQTH credentials
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_hamqth'])) {
    $un = trim($_POST['hamqth_username'] ?? '');
    hamqth_save_setting($pdo, 'hamqth_username', $un);
    $pw = $_POST['hamqth_password'] ?? '';
    if ($pw !== '') hamqth_save_setting($pdo, 'hamqth_password', $pw);
    hamqth_save_setting($pdo, 'hamqth_session_key',   '');
    hamqth_save_setting($pdo, 'hamqth_session_time',  '0');
    hamqth_save_setting($pdo, 'hamqth_session_error', '');
    flash('success', 'HamQTH credentials saved.');
    redirect('/admin/qrz.php');
}

// Test QRZ connection
$test_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_qrz'])) {
    qrz_save_setting($pdo, 'qrz_session_key',  '');
    qrz_save_setting($pdo, 'qrz_session_time', '0');
    $key = qrz_login($pdo);
    $test_result = $key ? 'ok' : 'fail';
}

// Test HamQTH connection
$hamqth_test = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_hamqth'])) {
    hamqth_save_setting($pdo, 'hamqth_session_key',  '');
    hamqth_save_setting($pdo, 'hamqth_session_time', '0');
    $key = hamqth_login($pdo);
    $hamqth_test = $key ? 'ok' : 'fail';
}

$st_info  = qrz_session_status();
$hq_info  = hamqth_session_status();
$stations = get_user_stations($user['id']);

$page_title = 'Admin — QRZ / HamQTH';
include __DIR__ . '/../includes/header.php';
?>

<div class="mb-3">
  <h4 class="mb-2 text-success"><i class="bi bi-gear"></i> Administration</h4>
  <ul class="nav nav-pills">
    <li class="nav-item"><a class="nav-link" href="index.php?tab=settings"><i class="bi bi-sliders"></i> Settings</a></li>
    <li class="nav-item"><a class="nav-link" href="index.php?tab=users"><i class="bi bi-people"></i> Users</a></li>
    <li class="nav-item"><a class="nav-link" href="index.php?tab=stats"><i class="bi bi-bar-chart"></i> Stats</a></li>
    <li class="nav-item"><a class="nav-link active" href="qrz.php"><i class="bi bi-search"></i> QRZ / HamQTH</a></li>
    <li class="nav-item"><a class="nav-link" href="update.php"><i class="bi bi-cloud-download"></i> Updates</a></li>
  </ul>
</div>

<?php if ($test_result === 'ok'): ?>
<div class="alert alert-success"><i class="bi bi-check-circle"></i> QRZ login successful — session key obtained.</div>
<?php elseif ($test_result === 'fail'): ?>
<div class="alert alert-danger"><i class="bi bi-x-circle"></i> QRZ login failed.
  <?= $st_info['error'] ? '<br><code>' . h($st_info['error']) . '</code>' : '' ?>
</div>
<?php endif; ?>

<?php if ($hamqth_test === 'ok'): ?>
<div class="alert alert-success"><i class="bi bi-check-circle"></i> HamQTH login successful — session ID obtained.</div>
<?php elseif ($hamqth_test === 'fail'): ?>
<div class="alert alert-danger"><i class="bi bi-x-circle"></i> HamQTH login failed.
  <?= $hq_info['error'] ? '<br><code>' . h($hq_info['error']) . '</code>' : '' ?>
</div>
<?php endif; ?>

<div class="row g-4 mb-4">

  <!-- QRZ Credentials card -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-key"></i> QRZ Credentials</div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          Uses the <a href="https://www.qrz.com/page/current_spec.html" target="_blank" class="text-success">QRZ XML Logbook Data Service</a>.
          Full data requires an active <strong>QRZ Logbook Data subscription</strong>.
        </p>

        <dl class="row mb-3">
          <dt class="col-5 text-muted">Status</dt>
          <dd class="col-7">
            <?php if (!qrz_configured()): ?>
            <span class="badge bg-secondary">Not configured</span>
            <?php elseif ($st_info['fresh']): ?>
            <span class="badge bg-success"><i class="bi bi-check-circle"></i> Connected</span>
            <?php elseif ($st_info['error']): ?>
            <span class="badge bg-danger">Error</span>
            <?php else: ?>
            <span class="badge bg-warning text-dark">Session expired</span>
            <?php endif; ?>
          </dd>
          <?php if ($st_info['fresh']): ?>
          <dt class="col-5 text-muted">Session age</dt>
          <dd class="col-7 small text-muted"><?= (int)((time() - $st_info['age']) / 60) ?> min ago</dd>
          <?php if ($st_info['sub']): ?>
          <dt class="col-5 text-muted">Subscription</dt>
          <dd class="col-7 small text-muted"><?= h($st_info['sub']) ?></dd>
          <?php endif; ?>
          <?php endif; ?>
          <?php if ($st_info['error'] && !$st_info['fresh']): ?>
          <dt class="col-5 text-muted">Last error</dt>
          <dd class="col-7"><code class="small"><?= h($st_info['error']) ?></code></dd>
          <?php endif; ?>
        </dl>

        <form method="post">
          <div class="mb-3">
            <label class="form-label">QRZ Username</label>
            <input type="text" name="qrz_username" class="form-control"
                   value="<?= h(db_setting('qrz_username')) ?>" autocomplete="username">
          </div>
          <div class="mb-3">
            <label class="form-label">QRZ Password</label>
            <input type="password" name="qrz_password" class="form-control" autocomplete="current-password"
                   placeholder="<?= db_setting('qrz_password') ? '(saved — leave blank to keep)' : '' ?>">
          </div>
          <div class="d-flex gap-2">
            <button type="submit" name="save_qrz" value="1" class="btn btn-success">
              <i class="bi bi-check-lg"></i> Save
            </button>
            <?php if (qrz_configured()): ?>
            <button type="submit" name="test_qrz" value="1" class="btn btn-outline-success">
              <i class="bi bi-plug"></i> Test Connection
            </button>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- HamQTH Credentials card -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-key"></i> HamQTH Credentials</div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          HamQTH is a free callsign database used as a fallback when QRZ is unavailable or returns no data.
          Register at <a href="https://www.hamqth.com" target="_blank" class="text-success">hamqth.com</a>.
        </p>

        <dl class="row mb-3">
          <dt class="col-5 text-muted">Status</dt>
          <dd class="col-7">
            <?php if (!hamqth_configured()): ?>
            <span class="badge bg-secondary">Not configured</span>
            <?php elseif ($hq_info['fresh']): ?>
            <span class="badge bg-success"><i class="bi bi-check-circle"></i> Connected</span>
            <?php elseif ($hq_info['error']): ?>
            <span class="badge bg-danger">Error</span>
            <?php else: ?>
            <span class="badge bg-warning text-dark">Session expired</span>
            <?php endif; ?>
          </dd>
          <?php if ($hq_info['fresh']): ?>
          <dt class="col-5 text-muted">Session age</dt>
          <dd class="col-7 small text-muted"><?= (int)((time() - $hq_info['age']) / 60) ?> min ago</dd>
          <?php endif; ?>
          <?php if ($hq_info['error'] && !$hq_info['fresh']): ?>
          <dt class="col-5 text-muted">Last error</dt>
          <dd class="col-7"><code class="small"><?= h($hq_info['error']) ?></code></dd>
          <?php endif; ?>
        </dl>

        <form method="post">
          <div class="mb-3">
            <label class="form-label">HamQTH Username</label>
            <input type="text" name="hamqth_username" class="form-control"
                   value="<?= h(db_setting('hamqth_username')) ?>" autocomplete="username">
          </div>
          <div class="mb-3">
            <label class="form-label">HamQTH Password</label>
            <input type="password" name="hamqth_password" class="form-control" autocomplete="current-password"
                   placeholder="<?= db_setting('hamqth_password') ? '(saved — leave blank to keep)' : '' ?>">
          </div>
          <div class="d-flex gap-2">
            <button type="submit" name="save_hamqth" value="1" class="btn btn-success">
              <i class="bi bi-check-lg"></i> Save
            </button>
            <?php if (hamqth_configured()): ?>
            <button type="submit" name="test_hamqth" value="1" class="btn btn-outline-success">
              <i class="bi bi-plug"></i> Test Connection
            </button>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

</div>

<div class="row g-4">

  <!-- Bulk update card -->
  <div class="col-12">
    <div class="card">
      <div class="card-header"><i class="bi bi-cloud-arrow-down"></i> Bulk Callsign Update</div>
      <div class="card-body">
        <?php if (!qrz_configured() && !hamqth_configured()): ?>
        <p class="text-muted">Configure QRZ or HamQTH credentials above to enable bulk lookup.</p>
        <?php else: ?>
        <p class="text-muted small mb-3">
          Looks up each unique callsign in your log against QRZ and fills in missing fields
          (name, QTH, country, grid square, DXCC, CQ zone, ITU zone).
          Lookups are paced at ~5 per second to respect QRZ's rate limits.
        </p>

        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <label class="form-label">Station</label>
            <select id="bulk-station" class="form-select">
              <?php foreach ($stations as $s): ?>
              <option value="<?= $s['id'] ?>"><?= h($s['callsign']) ?><?= $s['is_club_station'] ? ' [Club]' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6 d-flex align-items-end">
            <div class="form-check">
              <input type="checkbox" class="form-check-input" id="bulk-force">
              <label class="form-check-label" for="bulk-force">
                Force update <span class="text-muted small">(overwrite existing values with QRZ data)</span>
              </label>
            </div>
          </div>
        </div>

        <div class="d-flex gap-2 mb-3">
          <button id="bulk-start" class="btn btn-success">
            <i class="bi bi-play-circle"></i> Start Bulk Update
          </button>
          <button id="bulk-stop" class="btn btn-outline-danger d-none">
            <i class="bi bi-stop-circle"></i> Stop
          </button>
          <span id="bulk-count" class="text-muted small align-self-center"></span>
        </div>

        <div id="bulk-progress-wrap" class="d-none mb-3">
          <div class="progress mb-1" style="height:8px">
            <div id="bulk-bar" class="progress-bar bg-success" style="width:0%"></div>
          </div>
          <div class="d-flex justify-content-between">
            <small id="bulk-status" class="text-muted"></small>
            <small id="bulk-pct" class="text-muted">0%</small>
          </div>
        </div>

        <div id="bulk-log" style="max-height:220px;overflow-y:auto;font-size:.78rem;font-family:monospace"></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<?php if (qrz_configured() || hamqth_configured()): ?>
<script>
const BASE = '<?= BASE_URL ?>';
let stopping = false;

document.getElementById('bulk-start').addEventListener('click', startBulk);
document.getElementById('bulk-stop').addEventListener('click', () => { stopping = true; });

async function startBulk() {
    const sid   = document.getElementById('bulk-station').value;
    const force = document.getElementById('bulk-force').checked ? 1 : 0;
    stopping = false;

    document.getElementById('bulk-start').disabled = true;
    document.getElementById('bulk-stop').classList.remove('d-none');
    document.getElementById('bulk-progress-wrap').classList.remove('d-none');
    document.getElementById('bulk-log').innerHTML = '';
    document.getElementById('bulk-count').textContent = 'Counting…';

    // Get total
    const cr = await fetch(`${BASE}/api/qrz_bulk_update.php?action=count&station_id=${sid}&force=${force}`);
    const cd = await cr.json();
    if (cd.error) { appendLog('error', cd.error); done(); return; }

    const total = cd.total;
    if (total === 0) {
        appendLog('info', 'Nothing to update — all callsigns already have name, country, and grid data.');
        done(); return;
    }
    document.getElementById('bulk-count').textContent = `${total} callsign${total !== 1 ? 's' : ''} to look up`;

    let offset = 0, totalUpdated = 0, totalErrors = 0, totalNotFound = 0;

    while (offset < total && !stopping) {
        const url = `${BASE}/api/qrz_bulk_update.php?action=update&station_id=${sid}&offset=${offset}&batch=5&force=${force}`;
        let resp, data;
        try {
            resp = await fetch(url);
            data = await resp.json();
        } catch(e) {
            appendLog('error', 'Network error — stopping.');
            break;
        }
        if (data.error) { appendLog('error', data.error); break; }

        offset        += data.processed;
        totalUpdated  += data.updated;
        totalErrors   += data.errors;
        totalNotFound += data.notfound;

        // Per-callsign log entries
        for (const r of (data.log || [])) {
            if (r.status === 'updated') {
                const src = r.source ? ` [${r.source}]` : '';
                appendLog('ok', `${r.call}${src} → ${r.name || '?'}${r.country ? ', ' + r.country : ''}`);
            } else if (r.status === 'notfound') {
                appendLog('warn', `${r.call} — not found`);
            } else {
                appendLog('error', `${r.call} — lookup error`);
            }
        }

        // Progress bar
        const pct = Math.min(100, Math.round(offset / total * 100));
        document.getElementById('bulk-bar').style.width = pct + '%';
        document.getElementById('bulk-pct').textContent = pct + '%';
        document.getElementById('bulk-status').textContent =
            `${offset} / ${total} processed — ${totalUpdated} updated`;

        if (data.processed < 5) break; // finished (last batch was short)
    }

    appendLog('info', `Done. Updated: ${totalUpdated}  Not found: ${totalNotFound}  Errors: ${totalErrors}`);
    done();
}

function done() {
    document.getElementById('bulk-start').disabled = false;
    document.getElementById('bulk-stop').classList.add('d-none');
}

function appendLog(type, msg) {
    const el = document.getElementById('bulk-log');
    const color = type === 'ok' ? '#44ee66' : type === 'warn' ? '#ffdd44' : type === 'error' ? '#ee8888' : '#a8b8a8';
    el.innerHTML += `<div style="color:${color}">${msg}</div>`;
    el.scrollTop = el.scrollHeight;
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
