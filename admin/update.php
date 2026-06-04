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

// ── Environment detection ─────────────────────────────────────────────────────
$web_root = realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT'];
$is_git   = $web_root && is_dir($web_root . '/.git');

// Check exec() availability (handles disable_functions at ini AND pool level)
$exec_ok = false;
if (function_exists('exec')) {
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    $exec_ok  = !in_array('exec', $disabled, true);
}

// Locate git binary — PHP exec() often runs with a stripped PATH
$git_bin = '';
foreach (['/usr/bin/git', '/usr/local/bin/git', '/opt/homebrew/bin/git', '/bin/git'] as $p) {
    if (file_exists($p) && is_executable($p)) { $git_bin = $p; break; }
}
if (!$git_bin && $exec_ok) {
    // last resort: ask the shell
    @exec('which git 2>/dev/null', $wb_out);
    $git_bin = trim($wb_out[0] ?? '');
}

// ── Installed version (read from FILE, not constant) ─────────────────────────
// config/config.php is gitignored so HAMLOG_VERSION there never changes after
// install. We read version.php directly from disk so git updates are reflected.
$current_ver = ltrim(HAMLOG_VERSION, 'v'); // fallback for non-git servers
$ver_file = $web_root . '/version.php';
if (file_exists($ver_file)) {
    $vc = @file_get_contents($ver_file);
    if ($vc && preg_match("/define\s*\(\s*['\"]HAMLOG_VERSION['\"],\s*['\"]([^'\"]+)['\"]/", $vc, $m)) {
        $current_ver = ltrim($m[1], 'v');
    }
}

// ── Run update ────────────────────────────────────────────────────────────────
$update_log  = '';
$update_ok   = null;
$update_done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_update'])) {
    if (!$is_git) {
        flash('error', 'The web root is not a git repository. See setup instructions below.');
        redirect('/admin/update.php');
    }
    if (!$exec_ok) {
        flash('error', 'exec() is disabled on this server — cannot run git commands.');
        redirect('/admin/update.php');
    }
    if (!$git_bin) {
        flash('error', 'git binary not found. Install git on the server.');
        redirect('/admin/update.php');
    }

    $out  = [];
    $code = -1;

    // Set HOME so git doesn't complain about missing user config
    $env  = 'HOME=/root ';
    $cmd  = $env
          . escapeshellarg($git_bin) . ' -C ' . escapeshellarg($web_root) . ' fetch origin 2>&1'
          . ' && ' . $env
          . escapeshellarg($git_bin) . ' -C ' . escapeshellarg($web_root) . ' reset --hard origin/main 2>&1';

    exec($cmd, $out, $code);
    $update_log  = implode("\n", $out);
    $update_ok   = ($code === 0);
    $update_done = true;

    if ($update_ok) {
        // Re-read version from file now that the update has run
        if (file_exists($ver_file)) {
            $vc2 = @file_get_contents($ver_file);
            if ($vc2 && preg_match("/define\s*\(\s*['\"]HAMLOG_VERSION['\"],\s*['\"]([^'\"]+)['\"]/", $vc2, $m2)) {
                $current_ver = ltrim($m2[1], 'v');
            }
        }
        flash('success', 'Update applied successfully — now running v' . $current_ver . '.');
        redirect('/admin/update.php');
    }
    // On failure: fall through and display $update_log below
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
        $fetch_error = 'GitHub API: ' . htmlspecialchars($data['message']);
    }
} else {
    $fetch_error = 'Could not reach the GitHub API. Check server outbound connectivity.';
}

// ── Version comparison ────────────────────────────────────────────────────────
$latest_ver       = $latest ? ltrim($latest['tag_name'], 'v') : null;
$update_available = $latest_ver && version_compare($latest_ver, $current_ver, '>');
$up_to_date       = $latest_ver && version_compare($latest_ver, $current_ver, '<=');

// ── Local git log ─────────────────────────────────────────────────────────────
$local_log = [];
if ($is_git && $exec_ok && $git_bin) {
    @exec(escapeshellarg($git_bin) . ' -C ' . escapeshellarg($web_root) . ' log --oneline -10 2>&1', $local_log);
}

// ── Release list ─────────────────────────────────────────────────────────────
$releases = [];
$rel_resp = @file_get_contents(GITHUB_API . '/releases?per_page=10', false, $api_ctx);
if ($rel_resp !== false) {
    $releases = json_decode($rel_resp, true) ?: [];
}

// ── Can auto-update? ──────────────────────────────────────────────────────────
$can_autoupdate = $is_git && $exec_ok && $git_bin;

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

<?php if ($update_done && !$update_ok): ?>
<div class="alert alert-danger">
  <strong><i class="bi bi-x-circle"></i> Update failed</strong> (exit code <?= htmlspecialchars((string)$code) ?>)<br>
  Command output:
  <pre class="mb-0 mt-2" style="font-size:.8rem;background:#1a0a0a;padding:.75rem;border-radius:4px;overflow-x:auto"><?= htmlspecialchars($update_log ?: '(no output — git binary may not be accessible by www-data)') ?></pre>
</div>
<?php endif; ?>

<!-- Version status -->
<div class="row g-4 mb-4">
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-info-circle"></i> Version Status</div>
      <div class="card-body">
        <dl class="row mb-0">
          <dt class="col-sm-5">Installed version</dt>
          <dd class="col-sm-7"><code>v<?= htmlspecialchars($current_ver) ?></code></dd>

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
            <span class="text-muted">—</span>
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

        <?php if ($can_autoupdate): ?>
        <form method="post"
              onsubmit="return confirm('Apply update to v<?= htmlspecialchars($latest_ver) ?>?\n\nThis runs:\n  git fetch origin\n  git reset --hard origin/main\n\nYour config/config.php (credentials) will not be touched.\nBack up your database before updating.')">
          <button type="submit" name="do_update" value="1" class="btn btn-warning text-dark fw-bold">
            <i class="bi bi-cloud-download"></i> Apply Update Now
          </button>
        </form>
        <p class="text-muted small mt-2 mb-0">
          Runs <code>git reset --hard origin/main</code> in the web root.
          <code>config/config.php</code> is gitignored and will not be changed.
        </p>
        <?php else: ?>
        <a href="https://github.com/<?= GITHUB_REPO ?>/releases/tag/<?= urlencode($latest['tag_name']) ?>"
           target="_blank" class="btn btn-warning text-dark fw-bold">
          <i class="bi bi-download"></i> Download Release
        </a>
        <p class="text-muted small mt-2 mb-0">
          Auto-update requires the web root to be a git clone with <code>exec()</code> available.
          See <strong>Setup</strong> below.
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
            <?php $rv = ltrim($rel['tag_name'], 'v'); ?>
            <tr>
              <td>
                <code><?= htmlspecialchars($rel['tag_name']) ?></code>
                <?php if ($rv === $current_ver): ?>
                <span class="badge bg-success ms-1" style="font-size:.65rem">installed</span>
                <?php endif; ?>
              </td>
              <td class="text-muted" style="font-size:.8rem"><?= htmlspecialchars(substr($rel['published_at'],0,10)) ?></td>
              <td>
                <a href="<?= htmlspecialchars($rel['html_url']) ?>" target="_blank"
                   class="btn btn-outline-success btn-sm py-0 px-1">
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

  <!-- Local git log / diagnostics -->
  <div class="col-md-6">
    <div class="card">
      <div class="card-header"><i class="bi bi-terminal"></i> Diagnostics</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <tbody>
            <tr>
              <td class="text-muted" style="width:45%">Web root</td>
              <td><code style="font-size:.8rem"><?= htmlspecialchars($web_root) ?></code></td>
            </tr>
            <tr>
              <td class="text-muted">Git repo</td>
              <td>
                <?php if ($is_git): ?>
                <span class="badge bg-success">Yes</span>
                <?php else: ?>
                <span class="badge bg-danger">No</span>
                <a href="#setup" class="text-warning small ms-1">Setup ↓</a>
                <?php endif; ?>
              </td>
            </tr>
            <tr>
              <td class="text-muted">exec() available</td>
              <td>
                <?php if ($exec_ok): ?>
                <span class="badge bg-success">Yes</span>
                <?php else: ?>
                <span class="badge bg-danger">Disabled</span>
                <a href="#setup" class="text-warning small ms-1">Setup ↓</a>
                <?php endif; ?>
              </td>
            </tr>
            <tr>
              <td class="text-muted">git binary</td>
              <td>
                <?php if ($git_bin): ?>
                <code style="font-size:.8rem"><?= htmlspecialchars($git_bin) ?></code>
                <?php else: ?>
                <span class="badge bg-danger">Not found</span>
                <?php endif; ?>
              </td>
            </tr>
            <tr>
              <td class="text-muted">PHP version</td>
              <td><code style="font-size:.8rem"><?= PHP_VERSION ?></code></td>
            </tr>
          </tbody>
        </table>
        <?php if (!empty($local_log)): ?>
        <div style="border-top:1px solid #1a2a1a;padding:.5rem .75rem">
          <div class="text-muted small mb-1">Installed commits:</div>
          <?php foreach ($local_log as $line): ?>
          <?php [$sha, $msg] = explode(' ', $line, 2) + ['','']; ?>
          <div style="font-size:.75rem;font-family:monospace">
            <span style="color:#7a9a7a"><?= htmlspecialchars($sha) ?></span>
            <span style="color:#c8d8c8"> <?= htmlspecialchars($msg) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Setup instructions (shown when auto-update not available) -->
  <?php if (!$can_autoupdate): ?>
  <div class="col-12" id="setup">
    <div class="card">
      <div class="card-header" style="color:#ffdd88"><i class="bi bi-tools"></i> Setup Auto-Update</div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          For one-click updates, the web root must be a git clone of
          <a href="https://github.com/<?= GITHUB_REPO ?>" target="_blank" class="text-success">github.com/<?= GITHUB_REPO ?></a>
          and <code>exec()</code> must be enabled.
        </p>

        <?php if (!$is_git): ?>
        <p class="mb-2 small"><strong>Convert the web root to a git clone</strong> (run as root on the server):</p>
        <pre class="p-3 rounded small" style="background:#0c1c0c;color:#c8d8c8;overflow-x:auto">cd <?= htmlspecialchars($web_root) ?>

# Back up live config (gitignored, but let's be safe)
cp config/config.php /root/hamlog-config.bak

# Initialise git and fetch
git init
git remote add origin https://github.com/<?= GITHUB_REPO ?>.git
git fetch origin main
git reset --hard origin/main

# Restore live credentials
cp /root/hamlog-config.bak config/config.php
chown root:www-data config/config.php
chmod 640 config/config.php</pre>
        <?php endif; ?>

        <?php if (!$exec_ok): ?>
        <p class="mb-2 small mt-3">
          <strong>Enable <code>exec()</code></strong> — edit your PHP-FPM pool config:
        </p>
        <pre class="p-3 rounded small" style="background:#0c1c0c;color:#c8d8c8">; Remove exec from disable_functions in the pool config, e.g.:
; /etc/php/8.4/fpm/pool.d/log.cqprideaugusta.us.conf
;
; Change:  php_admin_value[disable_functions] = exec, ...
; To:      php_admin_value[disable_functions] = ...   (remove exec)
;
; Then restart PHP-FPM:
systemctl restart php8.4-fpm</pre>
        <?php endif; ?>

        <p class="text-muted small mt-3 mb-0">
          Refresh this page after completing the steps — the <strong>Apply Update Now</strong>
          button appears automatically once everything is ready.
        </p>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
