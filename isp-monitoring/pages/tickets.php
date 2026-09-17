<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/auth.php';
requireLogin();

$tickets = getTickets();
$counts  = bucketCounts($tickets);

$buckets = [
    'under8'  => '8 Jam kebawah',
    'under12' => '12 Jam kebawah',
    'under24' => '24 Jam kebawah',
    'over24'  => 'Di atas 24 Jam',
];

$f = get('f', 'all');
$filterOptions = ['all' => 'Semua Filter', 'open' => 'Terbuka', 'closed' => 'Ditutup'] + $buckets;
if (!isset($filterOptions[$f])) {
    $f = 'all';
}

$filtered = $tickets;
if ($f === 'open' || $f === 'closed') {
    $filtered = array_values(array_filter($filtered, fn($t) => $t['status'] === $f));
} elseif (isset($buckets[$f])) {
    $filtered = array_values(array_filter($filtered, fn($t) => $t['bucket'] === $f));
}

$statusCounts = ['open' => 0, 'closed' => 0];
foreach ($tickets as $t) {
    if (isset($statusCounts[$t['status']])) $statusCounts[$t['status']]++;
}

$title = 'Tiket Gangguan';
include __DIR__ . '/../inc/header.php';
?>

<div class="page-head">
    <h2>Tiket Gangguan</h2>
    <div class="head-actions">
        <a class="btn btn-primary" href="ticket_create.php">+ Buat Tiket</a>
        <a class="btn btn-success" href="export_gangguan.php<?= $f !== 'all' ? '?f=' . e($f) : '' ?>">Export Excel</a>
        <a class="btn btn-secondary" href="export_gangguan_pdf.php<?= $f !== 'all' ? '?f=' . e($f) : '' ?>" target="_blank" rel="noopener">Export PDF</a>
    </div>
</div>

<div class="filterbar">
    <div class="btn-group">
        <?php foreach ($filterOptions as $k => $label):
            if ($k === 'all')                $n = count($tickets);
            elseif (isset($statusCounts[$k])) $n = $statusCounts[$k];
            else                              $n = $counts[$k] ?? 0;
            $active = $f === $k ? 'btn-primary' : 'btn-secondary';
            ?>
            <a class="btn btn-sm <?= $active ?>" href="tickets.php?f=<?= e($k) ?>"><?= e($label) ?> (<?= (int)$n ?>)</a>
        <?php endforeach; ?>
    </div>
</div>

<div class="ticket-list">
    <?php if (!$filtered): ?>
        <div class="card center muted">Tidak ada tiket.</div>
    <?php else: ?>
        <?php foreach ($filtered as $t): ?>
            <?php
            $isOpen = $t['status'] === 'open';
            $total  = (int)$t['total_clients'];
            $recN   = (int)$t['recovered_clients'];
            $allRec = $total > 0 && $recN >= $total;
            ?>
            <div class="ticket-item <?= $isOpen ? 'ticket-open' : '' ?>">
                <div class="ticket-head">
                    <div class="ticket-title">
                        <span class="ticket-id">#<?= (int)$t['id'] ?></span>
                        <a class="ticket-subject" href="ticket_detail.php?id=<?= (int)$t['id'] ?>"><?= e($t['subject']) ?></a>
                    </div>
                    <div class="ticket-status">
                        <span class="tag <?= $t['bucket'] === 'over24' ? 'tag-danger' : ($t['bucket'] === 'under8' ? 'tag-ok' : 'tag-warn') ?>">Durasi: <?= e(formatDuration($t['hours'])) ?></span>
                        <?php if ($isOpen): ?>
                            <span class="badge badge-open">Terbuka</span>
                        <?php else: ?>
                            <span class="badge badge-closed">Ditutup</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="ticket-meta">
                    <?php if ($total > 0): ?>
                        <span>Client: <span class="tag <?= $allRec ? 'tag-ok' : 'tag-warn' ?>"><?= $recN ?>/<?= $total ?> pulih</span></span>
                    <?php endif; ?>
                    <?php if ($t['vendor_name']): ?>
                        <span>Vendor: <span class="tag tag-vendor"><?= e($t['vendor_name']) ?></span></span>
                    <?php endif; ?>
                    <span>Awal: <?= e(formatDateTime($t['start_dt'])) ?></span>
                    <span>Akhir: <?= e(formatDateTime($t['end_dt'])) ?></span>
                    <span>Dibuka: <?= e($t['created_by_name'] ?? '-') ?></span>
                    <?php if (!$isOpen): ?>
                        <span>Ditutup: <?= e($t['closed_by_name'] ?? '-') ?></span>
                    <?php endif; ?>
                    <a class="btn btn-sm btn-secondary" href="ticket_detail.php?id=<?= (int)$t['id'] ?>">Detail</a>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../inc/footer.php'; ?>