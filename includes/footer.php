</div><!-- /container-fluid -->

<footer class="footer mt-auto py-2 bg-dark border-top border-secondary">
  <div class="container-fluid d-flex justify-content-between align-items-center">
    <span class="text-muted small"><i class="bi bi-broadcast"></i> HamLog <?= HAMLOG_VERSION ?></span>
    <span class="text-muted small" id="utc-clock">UTC: --:--:--</span>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/hamlog.js"></script>
<script>
function updateUTCClock() {
    const now = new Date();
    const h = String(now.getUTCHours()).padStart(2,'0');
    const m = String(now.getUTCMinutes()).padStart(2,'0');
    const s = String(now.getUTCSeconds()).padStart(2,'0');
    document.getElementById('utc-clock').textContent = 'UTC: ' + h + ':' + m + ':' + s + 'z';
}
setInterval(updateUTCClock, 1000);
updateUTCClock();
</script>
</body>
</html>
