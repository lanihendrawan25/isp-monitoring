<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/auth.php';
requireLogin();

$uid = (int)currentUser()['id'];

$tickets = getTickets('closed');
$mine = array_values(array_filter($tickets, fn($t) => (int)$t['closed_by'] === $uid));

$totClosed      = count($mine);
$avgCloseMin    = 0;
$bucketMine     = bucketCounts($mine);
$recoveries     = 0;
$avgRecoverMin  = 0;

if ($totClosed > 0) {
    $sumMin = 0;
    foreach ($mine as $t) {
        $sumMin += (strtotime($t['closed_at']) - strtotime($t['created_at'])) / 60;
    }
    $avgCloseMin = $sumMin / $totClosed;
}

$st = db()->prepare("SELECT COUNT(*) AS n, COALESCE(AVG(TIMESTAMPDIFF(MINUTE, downtime_start, downtime_end)),0) AS avg_min
                     FROM ticket_clients WHERE closed_by = ?");
$st->execute([$uid]);
$rec = $st->fetch();
$recoveries    = (int)$rec['n'];
$avgRecoverMin = (float)$rec['avg_min'];

$title = 'KPI Saya';
include __DIR__ . '/../inc/header.php';
?>

<div class="page-head">
    <h2>KPI — <?= e(currentUser()['name']) ?></h2>
    <p class="muted">Statistik penyelesaian hanya untuk akun Anda. Data diperbarui otomatis saat tiket ditutup.</p>
</div>

<div class="cards">
    <div class="card stat stat-closed">
        <div class="stat-label">Tiket Ditutup</div>
        <div class="stat-value"><?= $totClosed ?></div>
    </div>
    <div class="card stat">
        <div class="stat-label">Rata-rata Waktu Tutup Tiket</div>
        <div class="stat-value"><?= e(formatDuration($avgCloseMin / 60)) ?></div>
    </div>
    <div class="card stat stat-ok">
        <div class="stat-label">Client Dipulihkan (Recover)</div>
        <div class="stat-value"><?= $recoveries ?></div>
    </div>
    <div class="card stat">
        <div class="stat-label">Rata-rata Durasi Pemulihan Client</div>
        <div class="stat-value"><?= e(formatDuration($avgRecoverMin / 60)) ?></div>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <h3>Penyelesaian Berdasarkan Durasi Downtime</h3>
        <table class="table">
            <tr><td><a href="tickets.php?f=under8">8 Jam kebawah</a></td><td class="right"><?= $bucketMine['under8'] ?> tiket</td></tr>
            <tr><td><a href="tickets.php?f=under12">12 Jam kebawah</a></td><td class="right"><?= $bucketMine['under12'] ?> tiket</td></tr>
            <tr><td><a href="tickets.php?f=under24">24 Jam kebawah</a></td><td class="right"><?= $bucketMine['under24'] ?> tiket</td></tr>
            <tr><td><a href="tickets.php?f=over24">Di atas 24 Jam</a></td><td class="right"><?= $bucketMine['over24'] ?> tiket</td></tr>
        </table>
    </div>

    <div class="card">
        <h3>Tiket yang Saya Tutup</h3>
        <?php if (!$mine): ?>
            <p class="muted">Belum ada tiket yang Anda tutup.</p>
        <?php else: ?>
            <table class="table">
                <tr><th>ID</th><th>Subjek</th><th>Durasi</th><th>Ditutup</th></tr>
                <?php foreach (array_slice($mine, 0, 10) as $t): ?>
                    <tr>
                        <td><a href="ticket_detail.php?id=<?= (int)$t['id'] ?>">#<?= (int)$t['id'] ?></a></td>
                        <td><?= e($t['subject']) ?></td>
                        <td><span class="tag <?= $t['bucket'] === 'over24' ? 'tag-danger' : ($t['bucket'] === 'under8' ? 'tag-ok' : 'tag-warn') ?>"><?= e(formatDuration($t['hours'])) ?></span></td>
                        <td><?= e(formatDateTime($t['closed_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../inc/footer.php'; ?>