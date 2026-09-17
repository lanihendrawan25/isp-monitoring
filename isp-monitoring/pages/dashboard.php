<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/auth.php';
requireLogin();

$tickets      = getTickets();
$openTickets  = array_filter($tickets, fn($t) => $t['status'] === 'open');
$closedTickets= array_filter($tickets, fn($t) => $t['status'] === 'closed');
$buckets      = bucketCounts($tickets);

$cs = getPingStats();

$offline = db()->query("SELECT * FROM clients WHERE is_online = 0 AND monitor_mode <> 'noping' AND TRIM(ip_address) <> '' ORDER BY name LIMIT 10")->fetchAll();

$avgDowntime = 0;
if ($closedTickets) {
    $total = 0;
    foreach ($closedTickets as $t) { $total += $t['hours']; }
    $avgDowntime = $total / count($closedTickets);
}
$title = 'Dashboard';
include __DIR__ . '/../inc/header.php';
?>

<div class="page-head">
    <h2>Dashboard</h2>
    <p class="muted">Rangkuman gangguan &amp; pemantauan jaringan, <?= date('d M Y H:i') ?> WIB</p>
</div>

<div class="cards">
    <a class="card stat" href="tickets.php?f=all">
        <div class="stat-label">Total Tiket</div>
        <div class="stat-value"><?= count($tickets) ?></div>
    </a>
    <a class="card stat stat-open" href="tickets.php?f=open">
        <div class="stat-label">Tiket Terbuka</div>
        <div class="stat-value"><?= count($openTickets) ?></div>
    </a>
    <a class="card stat stat-closed" href="tickets.php?f=closed">
        <div class="stat-label">Tiket Ditutup</div>
        <div class="stat-value"><?= count($closedTickets) ?></div>
    </a>
    <a class="card stat" href="ping.php">
        <div class="stat-label">Jumlah All Client</div>
        <div class="stat-value"><?= (int)$cs['all'] ?></div>
    </a>
    <a class="card stat stat-ok" href="ping.php?filter=online">
        <div class="stat-label">Client Online</div>
        <div class="stat-value"><?= (int)$cs['online'] ?></div>
    </a>
    <a class="card stat stat-offline" href="ping.php?filter=offline">
        <div class="stat-label">Client Offline</div>
        <div class="stat-value"><?= (int)$cs['offline'] ?></div>
    </a>
    <a class="card stat" href="ping.php?filter=noping">
        <div class="stat-label">Client No-Ping</div>
        <div class="stat-value"><?= (int)$cs['noping'] ?></div>
    </a>
    <a class="card stat" href="tickets.php">
        <div class="stat-label">Rata-rata Downtime Tiket Ditutup</div>
        <div class="stat-value"><?= e(formatDuration($avgDowntime)) ?></div>
    </a>
</div>

<div class="card">
    <h3>Monitoring Ping</h3>
    <p class="muted">Status koneksi client yang dipantau ICMP. Total dipantau <strong><?= (int)$cs['mon'] ?></strong> · Online <strong><?= (int)$cs['online'] ?></strong> · Offline <strong><?= (int)$cs['offline'] ?></strong> · No-Ping <strong><?= (int)$cs['noping'] ?></strong>.</p>
    <div class="head-actions">
        <form method="post" action="ping.php" class="inline">
            <button type="submit" name="ping_all" value="1" class="btn btn-primary">Ping Semua Sekarang</button>
        </form>
        <a class="btn btn-secondary" href="ping.php">Buka Monitoring Ping &raquo;</a>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <h3>Durasi Downtime</h3>
        <table class="table">
            <tr><td><a href="tickets.php?f=under8">8 Jam kebawah</a></td><td class="right"><?= $buckets['under8'] ?> tiket</td></tr>
            <tr><td><a href="tickets.php?f=under12">12 Jam kebawah</a></td><td class="right"><?= $buckets['under12'] ?> tiket</td></tr>
            <tr><td><a href="tickets.php?f=under24">24 Jam kebawah</a></td><td class="right"><?= $buckets['under24'] ?> tiket</td></tr>
            <tr><td><a href="tickets.php?f=over24">Di atas 24 Jam</a></td><td class="right"><?= $buckets['over24'] ?> tiket</td></tr>
        </table>
    </div>

    <div class="card">
        <h3>Tiket Terbuka Terbaru</h3>
        <?php if (!$openTickets): ?>
            <p class="muted">Tidak ada tiket terbuka.</p>
        <?php else: ?>
            <?php
            $recent = array_slice($openTickets, 0, 6);
            ?>
            <table class="table">
                <tr><th>ID</th><th>Subjek</th><th>Mulai</th><th>Durasi</th></tr>
                <?php foreach ($recent as $t): ?>
                    <tr>
                        <td><a href="ticket_detail.php?id=<?= (int)$t['id'] ?>">#<?= (int)$t['id'] ?></a></td>
                        <td><?= e($t['subject']) ?></td>
                        <td><?= e(formatDateTime($t['start_dt'])) ?></td>
                        <td><span class="tag <?= $t['bucket'] === 'over24' ? 'tag-danger' : 'tag-warn' ?>"><?= e(formatDuration($t['hours'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <h3>Client Offline Saat Ini</h3>
        <?php if (!$offline): ?>
            <p class="muted">Semua client online.</p>
        <?php else: ?>
            <table class="table">
                <tr><th>Nama</th><th>IP</th><th>Ping Terakhir</th></tr>
                <?php foreach ($offline as $c): ?>
                    <tr>
                        <td><?= e($c['name']) ?></td>
                        <td><code><?= e($c['ip_address']) ?></code></td>
                        <td><?= e(formatDateTime($c['last_ping'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
        <p><a class="btn btn-primary" href="ping.php">Buka Monitoring Ping &raquo;</a></p>
    </div>

    <div class="card">
        <h3>Aksi Cepat</h3>
        <p><a class="btn btn-primary" href="ticket_create.php">+ Buat Tiket Gangguan</a></p>
        <p><a class="btn btn-secondary" href="tickets.php">Semua Tiket</a></p>
        <p><a class="btn btn-secondary" href="kpi.php">Lihat KPI Saya</a></p>
        <?php if (!isAdmin()): ?>
            <p class="muted">KPI menampilkan performa penyelesaian hanya untuk akun Anda sendiri.</p>
        <?php endif; ?>
    </div>
</div>

<?php if (isAdmin()): ?>
<div class="card">
    <h3>KPI Semua Karyawan</h3>
    <p class="muted">Ringkasan performa penyelesaian setiap akun (khusus admin).</p>
    <div class="table-wrap">
        <table class="table table-wide">
            <thead>
                <tr>
                    <th>Nama</th>
                    <th>Peran</th>
                    <th>Tiket Ditutup</th>
                    <th class="hide-below-1200">Rata-rata Waktu Tutup</th>
                    <th>Client Dipulihkan</th>
                    <th class="hide-below-1200">Rata-rata Pemulihan</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (getKpiByUser() as $k): ?>
                    <tr>
                        <td><?= e($k['name']) ?> <span class="muted">(<?= e($k['username']) ?>)</span></td>
                        <td><span class="tag <?= $k['role'] === 'admin' ? 'tag-admin' : 'tag-muted' ?>"><?= e(ucfirst($k['role'])) ?></span></td>
                        <td><?= (int)$k['closed'] ?></td>
                        <td class="hide-below-1200"><?= $k['closed'] ? e(formatDuration($k['avg_close_h'])) : '-' ?></td>
                        <td><?= (int)$k['recoveries'] ?></td>
                        <td class="hide-below-1200"><?= $k['recoveries'] ? e(formatDuration($k['avg_recover_h'])) : '-' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../inc/footer.php'; ?>