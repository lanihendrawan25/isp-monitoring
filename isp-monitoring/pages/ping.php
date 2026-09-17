<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/auth.php';
requireLogin();

$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ping_all'])) {
    pingAllClients();
    $flash = 'Ping ICMP selesai dijalankan untuk semua client (' . nowDT() . ').';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_client'])) {
    if (!isAdmin()) {
        $flash = 'Hanya admin yang dapat menghapus client dari monitoring.';
    } else {
        db()->prepare('DELETE FROM clients WHERE id = ?')->execute([(int)$_POST['delete_client']]);
        $flash = 'Client dihapus dari monitoring ping.';
    }
} elseif (isset($_GET['auto']) && get('auto') === '1') {
    pingAllClients();
}

$clients = db()->query("SELECT c.*,
        (SELECT tc.ticket_id FROM ticket_clients tc JOIN tickets t ON t.id = tc.ticket_id
          WHERE tc.client_id = c.id ORDER BY (t.status = 'open') DESC, tc.id DESC LIMIT 1) AS last_ticket_id,
        (SELECT tc.status FROM ticket_clients tc JOIN tickets t ON t.id = tc.ticket_id
          WHERE tc.client_id = c.id ORDER BY (t.status = 'open') DESC, tc.id DESC LIMIT 1) AS last_ticket_status,
        (SELECT tc.monitor_mode FROM ticket_clients tc JOIN tickets t ON t.id = tc.ticket_id
          WHERE tc.client_id = c.id ORDER BY (t.status = 'open') DESC, tc.id DESC LIMIT 1) AS last_ticket_mode,
        (SELECT t.subject FROM ticket_clients tc JOIN tickets t ON t.id = tc.ticket_id
          WHERE tc.client_id = c.id ORDER BY (t.status = 'open') DESC, tc.id DESC LIMIT 1) AS last_ticket_subject
    FROM clients c ORDER BY c.name")->fetchAll();
$stats = getPingStats();
$offlineCount   = $stats['offline'];
$onlineCount    = $stats['online'];
$monitoredCount = $stats['mon'];
$nopingCount    = $stats['noping'];
$allCount       = $stats['all'];
$llCount        = $stats['ll'];

$filter = get('filter', 'all');
$filterOpts = ['all', 'online', 'offline', 'noping', 'll'];
if (!in_array($filter, $filterOpts, true)) $filter = 'all';
if ($filter !== 'all') {
    $clients = array_values(array_filter($clients, function ($c) use ($filter) {
        $mode   = trim((string)($c['last_ticket_mode'] ?? '')) !== '' ? $c['last_ticket_mode'] : $c['monitor_mode'];
        $noPing = $mode === 'noping' || trim((string)$c['ip_address']) === '';
        $online = (int)$c['is_online'] === 1;
        switch ($filter) {
            case 'online':  return !$noPing && $online;
            case 'offline': return !$noPing && !$online;
            case 'noping':  return $noPing;
            case 'll':      return $mode === 'll';
        }
        return true;
    }));
}

$title = 'Monitoring Ping';
$headExtra = '<meta http-equiv="refresh" content="30">';
include __DIR__ . '/../inc/header.php';
?>

<div class="page-head">
    <h2>Monitoring Ping (ICMP)</h2>
    <div class="head-actions">
        <form method="post" class="inline">
            <button type="submit" name="ping_all" value="1" class="btn btn-primary">Ping Semua Sekarang</button>
        </form>
    </div>
</div>

<?php if ($flash): ?><div class="alert alert-ok"><?= e($flash) ?></div><?php endif; ?>

<div class="filterbar">
    <div class="btn-group">
        <a class="btn btn-sm <?= $filter === 'all' ? 'btn-primary' : 'btn-secondary' ?>" href="ping.php">Semua Client (<?= $allCount ?>)</a>
        <a class="btn btn-sm <?= $filter === 'online' ? 'btn-primary' : 'btn-secondary' ?>" href="ping.php?filter=online">Online (<?= $onlineCount ?>)</a>
        <a class="btn btn-sm <?= $filter === 'offline' ? 'btn-primary' : 'btn-secondary' ?>" href="ping.php?filter=offline">Offline (<?= $offlineCount ?>)</a>
        <a class="btn btn-sm <?= $filter === 'noping' ? 'btn-primary' : 'btn-secondary' ?>" href="ping.php?filter=noping">No-Ping (<?= $nopingCount ?>)</a>
        <a class="btn btn-sm <?= $filter === 'll' ? 'btn-primary' : 'btn-secondary' ?>" href="ping.php?filter=ll">Client LL (<?= $llCount ?>)</a>
    </div>
</div>

<div class="cards">
    <div class="card stat stat-ok">
        <div class="stat-label">Client Online</div>
        <div class="stat-value"><?= $onlineCount ?></div>
    </div>
    <div class="card stat stat-offline">
        <div class="stat-label">Client Offline</div>
        <div class="stat-value"><?= $offlineCount ?></div>
    </div>
    <div class="card stat">
        <div class="stat-label">Jumlah All Client</div>
        <div class="stat-value"><?= $allCount ?></div>
    </div>
    <div class="card stat">
        <div class="stat-label">Client No-Ping</div>
        <div class="stat-value"><?= $nopingCount ?></div>
    </div>
    <div class="card stat">
        <div class="stat-label">Client LL</div>
        <div class="stat-value"><?= $llCount ?></div>
    </div>
    <div class="card stat">
        <div class="stat-label">Pembaruan Otomatis</div>
        <div class="stat-value" id="refreshCountdown">30s</div>
    </div>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table table-wide">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Nama Client</th>
                    <th>IP Address</th>
                    <th>Mode</th>
                    <th>Tiket</th>
                    <th>Subjek Gangguan</th>
                    <th class="hide-below-1200">Gangguan</th>
                    <th class="hide-below-1200">Respon</th>
                    <th class="hide-below-1200">Ping Terakhir</th>
                    <?php if (isAdmin()): ?><th class="hide-below-1000"></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (!$clients): ?>
                    <tr><td colspan="<?= isAdmin() ? 10 : 9 ?>" class="center muted">Belum ada client.</td></tr>
                <?php endif; ?>
                <?php foreach ($clients as $c): ?>
<?php
                    $mode   = trim((string)($c['last_ticket_mode'] ?? '')) !== '' ? $c['last_ticket_mode'] : $c['monitor_mode'];
                    $noPing = $mode === 'noping' || trim((string)$c['ip_address']) === '';
                    $online = (int)$c['is_online'] === 1;
                    $tid    = $c['last_ticket_id'];
                    $tstat  = $c['last_ticket_status'];
                    $tsubj  = $c['last_ticket_subject'];
                    ?>
                    <tr class="<?= (!$noPing && !$online) ? 'row-danger' : '' ?>">
                        <td>
                            <?php if ($noPing): ?>
                                <span class="muted">Tidak diping</span>
                            <?php elseif ($online): ?>
                                <span class="dot dot-on"></span> Online
                            <?php else: ?>
                                <span class="dot dot-off"></span> Offline
                            <?php endif; ?>
                        </td>
                        <td><?= $tid ? '<a href="ticket_detail.php?id=' . (int)$tid . '">' . e($c['name']) . '</a>' : e($c['name']) ?></td>
                        <td><code><?= e($c['ip_address'] ?: '-') ?></code></td>
                        <td><span class="tag <?= monitorModeTagClass($mode) ?>"><?= e(monitorModeLabel($mode)) ?></span></td>
                        <td><?= $tid ? '<a href="ticket_detail.php?id=' . (int)$tid . '">#' . (int)$tid . '</a>' : '<span class="muted">-</span>' ?></td>
                        <td><?= $tsubj ? ($tid ? '<a href="ticket_detail.php?id=' . (int)$tid . '">' . e($tsubj) . '</a>' : e($tsubj)) : '<span class="muted">-</span>' ?></td>
                        <td class="hide-below-1200">
                            <?php if (!$tid): ?>
                                <span class="muted">-</span>
                            <?php elseif ($tstat === 'recovered'): ?>
                                <span class="tag tag-ok">Pulih</span>
                            <?php else: ?>
                                <span class="badge badge-open">Terimbas</span>
                            <?php endif; ?>
                        </td>
                        <td class="hide-below-1200"><?= !$noPing && $online && $c['ping_response_ms'] !== null ? e($c['ping_response_ms']) . ' ms' : '-' ?></td>
                        <td class="hide-below-1200"><?= e($c['last_ping'] ? formatDateTime($c['last_ping']) : 'belum pernah') ?></td>
                        <?php if (isAdmin()): ?>
                        <td class="hide-below-1000">
                            <form method="post" class="inline" onsubmit="return confirm('Hapus client <?= e($c['name']) ?> dari monitoring?');">
                                <input type="hidden" name="delete_client" value="<?= (int)$c['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h3>Otomatisasi Monitoring</h3>
    <p class="muted">Untuk pemantauan otomatis setiap menit, jadwalkan lewat Windows Task Scheduler:</p>
    <pre>schtasks /Create /TN "ISPPingMonitor" /TR "C:\xampp\php\php.exe C:\xampp\htdocs\isp-monitoring\ping_cron.php" /SC MINUTE /MO 1 /F</pre>
    <p class="muted">Alternatif sederhana: buka halaman ini dengan <code>?auto=1</code> via browser (auto ping lalu tampil). Halaman ini me-refresh sendiri tiap 30 detik.</p>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var c = 30;
    var el = document.getElementById('refreshCountdown');
    var t = setInterval(function () {
        c--;
        if (el) el.textContent = c + 's';
        if (c <= 0) clearInterval(t);
    }, 1000);
});
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>