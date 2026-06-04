<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

session_start_hamlog();
$user = require_admin();
$pdo  = db();

define('GITHUB_REPO', 'BishopGreer/hamlog');
define('GITHUB_API',  'https://api.github.com/repos/' . GITHUB_REPO);

$web_root   = realpath($_SERVER['DOCUMENT_ROOT']);
$is_git     = $web_root && is_dir($web_root . '/.git');
$exec_ok    = function_exists('exec') && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))));

// ── Run update ────────────────────────────────────────────────────────────────
$update_log = '';
$update_ok  = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_update'])) {
    if (!$is_git || !$exec_ok) {
        flash('error', 'Auto-update is not available — see setup instructions below.');
        redirect('/admin/update.php');
    }
    $cmd = 'cd ' . escapeshellarg($web_root) . ' && git fetch origin && git reset --hard origin/main 2>&1';
    exec($cmd, $out, $code);
    $update_log = implode("\n", $out);
    $update_ok  = ($code === 0);
    if ($update_ok) {
        flash('success', 'Update applied. Reload the page to see the new version.');
        redirect('/admin/update.php');
    }
}

// ── Fetch latest release from GitHub ─────────────────────────────────────────
$latest      = null;
$fetch_error = '';
$api_ctx = stream_context_create(['http' => [
    'method'        => 'GET',
    'header'        => "User-Agent: HamLog/" . HAMLOG_VERSION . "\r\nAccept: application/vnd.github.v3+json\r\n",
    'timeout'       => 8,
    'ignore_errors' => true,
]]);
$api_resp = @file_get_contents(GITHUB_API . '/releases/latest', false, $api_ctx);
if ($api_resp !== false) {
    $data = json_decode($api_resp, true);
    if (!empty($data['tag_name'])) {
        $latest = $data;
    } elseif (!empty($data['message'])) {
        $fetch_error = 'GitHub: ' . htmlspecialchars($data['message']);
    }
} else {
    $fetch_error = 'Could not reach the GitHub API. Check server outbound connectivity.';
}

// ── Version comparison ────────────────────────────────────────────────────────
$current_ver      = ltrim(HAMLOG_VERSION, 'v');
$latest_ver       = $latest ? ltrim($latest['tag_name'], 'v') : null;
$update_available = $latest_ver && version_compare($latest_ver, $current_ver, '>');
$up_to_date       = $latest_ver && version_compare($latest_ver, $current_ver, '<=');

// ── Local git log ─────────────────────────────────────────────────────────────
$local_log = [];
if ($is_git && $exec_ok) {
    exec('cd ' . escapeshellarg($web_root) . ' && git log --oneline -10 2>&1', $local_log);
}

// ── Release list ─────────────────────────────────────────────────────────────
$releases      = [];
$rel_resp = @file_get_contents(GITHUB_API . '/releases?per_page=10', false, $api_ctx);
if ($rel_resp !== false) {
    $releases = json_decode($rel_resp, true) ?: [];
}

$page_title = 'Admin — Updates';
include __DIR__ . '/../includes/header.php';
?>

<div class="mb-3">
  <h4 class="mb-2 text-success"><i class="bi bi-gear"></i> Administration</h4>
  <ul class="nav nav-pills">
    <li class="nav-item"><a class="nav-link" href="index.php?tab=settings"><i class="bi bi-sliders"></i> Settings</a></li>
    <li class="nav-item"><a class="nav-link" href="index.php?tab=users"><i class="bi bi-people"></i> Users</a></li>
    <li class="nav-item"><a class="nav-link" href="index.php?tab=stats"><i class="bi bi-bar-chart"></i> Stats</a></li>
    <li class="nav-item"><a class="nav-link active" href="update.php"><i class="bi bi-cloud-download"></i> Updates</a></li>
  </ul>
</div>

<?php if ($update_log && !$update_ok): ?>
<div class="alert alert-danger">
  <strong>Update failed.</strong><br>
  <pre class="mb-0 mt-2" style="font-size:.8rem"><?= htmlspecialchars($update_log) ?></pre>
</div>
<?php endif; ?>

<!-- Version status card -->
<div class="row g-4 mb-4">
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-info-circle"></i> Version Status</div>
      <div class="card-body">
        <dl class="row mb-0">
          <dt class="col-sm-5">Installed version</dt>
          <dd class="col-sm-7">
            <code>v<?= htmlspecialchars($current_ver) ?></code>
          </dd>

          <dt class="col-sm-5">Latest release</dt>
          <dd class="col-sm-7">
            <?php if ($fetch_error): ?>
            <span class="text-warning"><i class="bi bi-exclamation-triangle"></i> <?= $fetch_error ?></span>
            <?php elseif ($latest): ?>
            <a href="<?= htmlspecialchars($latest['html_url']) ?>" target="_blank" class="text-success">
              v<?= htmlspecialchars($latest_ver) ?>
            </a>
            <span class="text-muted small ms-1">(<?= htmlspecialchars(substr($latest['published_at'], 0, 10)) ?>)</span>
            <?php else: ?>
            <span class="text-muted">Checking…</span>
            <?php endif; ?>
          </dd>

          <dt class="col-sm-5">Status</dt>
          <dd class="col-sm-7">
            <?php if ($update_available): ?>
            <span class="badge bg-warning text-dark"><i class="bi bi-arrow-up-circle"></i> Update available</span>
            <?php elseif ($up_to_date): ?>
            <span class="badge bg-success"><i class="bi bi-check-circle"></i> Up to date</span>
            <?php else: ?>
            <span class="badge bg-secondary">Unknown</span>
            <?php endif; ?>
          </dd>

          <dt class="col-sm-5">GitHub repo</dt>
          <dd class="col-sm-7">
            <a href="https://github.com/<?= GITHUB_REPO ?>" target="_blank" class="text-success">
              <i class="bi bi-github"></i> <?= GITHUB_REPO ?>
            </a>
          </dd>
        </dl>
      </div>
    </div>
  </div>

  <?php if ($update_available && $latest): ?>
  <div class="col-md-6">
    <div class="card h-100 border-warning">
      <div class="card-header" style="background:#2c1f00;border-color:#5a4a00;color:#ffdd88">
        <i class="bi bi-arrow-up-circle"></i> v<?= htmlspecialchars($latest_ver) ?> Available
      </div>
      <div class="card-body">
        <?php if (!empty($latest['body'])): ?>
        <p class="small text-muted mb-3" style="white-space:pre-line"><?= htmlspecialchars(substr($latest['body'], 0, 600)) ?></p>
        <?php endif; ?>

        <?php if ($is_git && $exec_ok): ?>
        <form method="post" onsubmit="return confirm('Apply update v<?= htmlspecialchars($latest_ver) ?>? This will run git reset --hard origin/main. Back up your database first.')">
          <button type="submit" name="do_update" value="1" class="btn btn-warning text-dark">
            <i class="bi bi-cloud-download"></i> Apply Update Now
          </button>
        </form>
        <p class="text-muted small mt-2 mb-0">
          Runs <code>git fetch origin && git reset --hard origin/main</code> in the web root.
          Your <code>config/config.php</code> is safe — it is gitignored.
        </p>
        <?php else: ?>
        <a href="https://github.com/<?= GITHUB_REPO ?>/releases/tag/<?= urlencode($latest['tag_name']) ?>"
           target="_blank" class="btn btn-warning text-dark">
          <i class="bi bi-download"></i> Download Release
        </a>
        <p class="text-muted small mt-2 mb-0">
          Auto-update requires the web root to be a git clone.
          See the <strong>Setup</strong> panel below.
        </p>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php elseif ($up_to_date): ?>
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-check-circle text-success"></i> Up to Date</div>
      <div class="card-body d-flex align-items-center justify-content-center">
        <div class="text-center py-3">
          <div style="font-size:3rem;color:#00cc44"><i class="bi bi-check-circle-fill"></i></div>
          <p class="text-muted mt-2 mb-0">HamLog is running the latest version.</p>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<div class="row g-4">
  <!-- Release history -->
  <div class="col-md-6">
    <div class="card">
      <div class="card-header"><i class="bi bi-tag"></i> Release History</div>
      <div class="card-body p-0" style="max-height:300px;overflow-y:auto">
        <?php if (empty($releases)): ?>
        <p class="text-muted text-center py-3">No releases found.</p>
        <?php else: ?>
        <table class="table table-sm mb-0">
          <thead><tr><th>Version</th><th>Date</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($releases as $rel): ?>
            <tr>
              <td>
                <code><?= htmlspecialchars($rel['tag_name']) ?></code>
                <?php if (ltrim($rel['tag_name'],'v') === $current_ver): ?>
                <span class="badge bg-success ms-1" style="font-size:.65rem">installed</span>
                <?php endif; ?>
              </td>
              <td class="text-muted" style="font-size:.8rem"><?= htmlspecialchars(substr($rel['published_at'],0,10)) ?></td>
              <td>
                <a href="<?= htmlspecialchars($rel['html_url']) ?>" target="_blank"
                   class="btn btn-outline-success btn-sm py-0">
                  <i class="bi bi-github"></i>
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Local git log -->
  <div class="col-md-6">
    <div class="card">
      <div class="card-header"><i class="bi bi-clock-history"></i> Installed Commits</div>
      <div class="card-body p-0" style="max-height:300px;overflow-y:auto">
        <?php if (!$is_git): ?>
        <div class="p-3 text-muted small">
          <i class="bi bi-exclamation-circle text-warning"></i>
          The web root is not a git repository — cannot show commit history.
        </div>
        <?php elseif (!$exec_ok): ?>
        <div class="p-3 text-muted small">
          <i class="bi bi-exclamation-circle text-warning"></i>
          <code>exec()</code> is disabled on this server.
        </div>
        <?php elseif (empty($local_log)): ?>
        <p class="text-muted text-center py-3">No commits found.</p>
        <?php else: ?>
        <table class="table table-sm mb-0">
          <tbody>
            <?php foreach ($local_log as $line): ?>
            <?php [$sha, $msg] = explode(' ', $line, 2) + ['',''] ?>
            <tr>
              <td style="width:6em"><code class="small"><?= htmlspecialchars($sha) ?></code></td>
              <td class="small"><?= htmlspecialchars($msg) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Setup instructions -->
  <?php if (!$is_git || !$exec_ok): ?>
  <div class="col-12">
    <div class="card border-secondary">
      <div class="card-header" style="color:#ffdd88"><i class="bi bi-terminal"></i> Setup Auto-Update</div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          For one-click updates, the web root must be a git clone of
          <a href="https://github.com/<?= GITHUB_REPO ?>" target="_blank" class="text-success">github.com/<?= GITHUB_REPO ?></a>
          and PHP must be able to run <code>exec()</code>.
        </p>

        <?php if (!$is_git): ?>
        <p class="mb-2 small"><strong>1. Convert the web root to a git clone</strong> (run as root on the server):</p>
        <pre class="p-3 rounded small" style="background:#0c1c0c;color:#c8d8c8">cd <?= htmlspecialchars($web_root) ?>

# Back up live config (it is gitignored but let's be safe)
cp config/config.php /root/hamlog-config.php.bak

# Init and fetch
git init
git remote add origin https://github.com/<?= GITHUB_REPO ?>.git
git fetch origin
git reset --hard origin/main

# Restore live config
cp /root/hamlog-config.php.bak config/config.php
chown root:www-data config/config.php
chmod 640 config/config.php</pre>
        <?php endif; ?>

        <?php if (!$exec_ok): ?>
        <p class="mb-2 small mt-3"><strong>Enable <code>exec()</code></strong> — remove it from <code>disable_functions</code> in your PHP-FPM pool config:</p>
        <pre class="p-3 rounded small" style="background:#0c1c0c;color:#c8d8c8">; /etc/php/8.4/fpm/pool.d/log.cqprideaugusta.us.conf
; Remove exec from disable_functions, then restart:
systemctl restart php8.4-fpm</pre>
        <?php endif; ?>

        <p class="text-muted small mt-3 mb-0">
          After setup, refresh this page — the <strong>Apply Update Now</strong> button will appear
          whenever a new release is published.
        </p>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
