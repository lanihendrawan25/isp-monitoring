<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/auth.php';
requireLogin();

$id = (int)get('id');
if ($id <= 0) redirect('tickets.php');

$db  = db();
$uid = (int)currentUser()['id'];

function loadTicket(PDO $db, int $id)
{
    $st = $db->prepare('SELECT t.*, u.name AS created_by_name, u2.name AS closed_by_name, v.name AS vendor_name
                        FROM tickets t
                        LEFT JOIN users u  ON u.id  = t.created_by
                        LEFT JOIN users u2 ON u2.id = t.closed_by
                        LEFT JOIN vendors v ON v.id = t.vendor_id
                        WHERE t.id = ?');
    $st->execute([$id]);
    return $st->fetch();
}

$ticket = loadTicket($db, $id);
if (!$ticket) redirect('tickets.php');

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['recover'])) {
        $tcId = (int)$_POST['recover'];
        $db->prepare("UPDATE ticket_clients SET status='recovered', downtime_end=?, closed_by=?, closed_at=?
                      WHERE id=? AND ticket_id=? AND status='affected'")
           ->execute([nowDT(), $uid, nowDT(), $tcId, $id]);
        $msg = 'Client ditandai pulih (recovered).';
    }

    if (isset($_POST['revert']) && $ticket['status'] === 'open') {
        $tcId = (int)$_POST['revert'];
        $db->prepare("UPDATE ticket_clients SET status='affected', downtime_end=NULL, closed_by=NULL, closed_at=NULL
                      WHERE id=? AND ticket_id=? AND status='recovered'")
           ->execute([$tcId, $id]);
        $db->prepare('INSERT INTO ticket_updates (ticket_id, user_id, message) VALUES (?,?,?)')
           ->execute([$id, $uid, 'Status client dikembalikan menjadi TERIMBAS.']);
        $msg = 'Client dikembalikan menjadi terimbas.';
    }

    if (isset($_POST['close'])) {
        $db->prepare("UPDATE ticket_clients SET status='recovered', downtime_end=?, closed_by=?, closed_at=?
                      WHERE ticket_id=? AND status='affected'")
           ->execute([nowDT(), $uid, nowDT(), $id]);
        $db->prepare("UPDATE tickets SET status='closed', closed_by=?, closed_at=? WHERE id=?")
           ->execute([$uid, nowDT(), $id]);
        $removed = cleanupAutoClientsForTicket($id);
        $db->prepare('INSERT INTO ticket_updates (ticket_id, user_id, message) VALUES (?,?,?)')
           ->execute([$id, $uid, 'Tiket DITUTUP. Seluruh client yang masih terimbas otomatis ditandai pulih.'
               . ($removed > 0 ? ' ' . $removed . ' client otomatis dihapus dari monitoring ping.' : '')]);
        $msg = 'Tiket #' . $id . ' ditutup.' . ($removed > 0 ? ' ' . $removed . ' IP otomatis dihapus dari monitoring.' : '');
    }

    $action = post('action');

    if ($action === 'reopen') {
        $db->prepare("UPDATE tickets SET status='open', closed_by=NULL, closed_at=NULL WHERE id=?")
           ->execute([$id]);
        $db->prepare("UPDATE ticket_clients SET status='affected', downtime_end=NULL, closed_by=NULL, closed_at=NULL
                      WHERE ticket_id=? AND status='recovered'")
           ->execute([$id]);
        // Pulihkan client otomatis yang terhapus saat tiket ditutup agar kembali terpantau.
        $rows = $db->prepare('SELECT id, client_name, ip_address, monitor_mode FROM ticket_clients WHERE ticket_id=? AND client_id IS NULL');
        $rows->execute([$id]);
        foreach ($rows->fetchAll() as $r) {
            $cid = resolveOrCreateClient((string)$r['client_name'], (string)$r['ip_address'], (string)$r['monitor_mode']);
            $db->prepare('UPDATE ticket_clients SET client_id=? WHERE id=?')->execute([$cid, (int)$r['id']]);
        }
        $db->prepare('INSERT INTO ticket_updates (ticket_id, user_id, message) VALUES (?,?,?)')
           ->execute([$id, $uid, 'Tiket DIBUKA KEMBALI. Client yang pulih dikembalikan menjadi terimbas.']);
        $msg = 'Tiket #' . $id . ' dibuka kembali.';
    }

    if ($action === 'delete_ticket') {
        $orph = $db->prepare("SELECT DISTINCT tc.client_id FROM ticket_clients tc
                              JOIN clients c ON c.id = tc.client_id
                              WHERE tc.ticket_id = ? AND c.description = 'Dibuat otomatis dari tiket'");
        $orph->execute([$id]);
        $cids = array_column($orph->fetchAll(), 'client_id');
        $db->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        $n = 0;
        foreach ($cids as $cid) {
            $chk = $db->prepare('SELECT COUNT(*) AS n FROM ticket_clients WHERE client_id = ?');
            $chk->execute([(int)$cid]);
            if ((int)$chk->fetch()['n'] === 0) {
                $db->prepare('DELETE FROM clients WHERE id = ?')->execute([(int)$cid]);
                $n++;
            }
        }
        redirect('tickets.php');
    }

    if ($action === 'add_update') {
        $text = post('message');
        $attachment = null;
        $upErr = '';
        if (!empty($_FILES['photo']['name'])) {
            $f = $_FILES['photo'];
            if ($f['error'] !== UPLOAD_ERR_OK) {
                $upErr = 'Gagal mengunggah foto (kode ' . (int)$f['error'] . ').';
            } elseif ($f['size'] > 5 * 1024 * 1024) {
                $upErr = 'Ukuran foto melebihi 5 MB.';
            } else {
                $info = @getimagesize($f['tmp_name']);
                $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
                if (!$info || !isset($allowed[$info[2]])) {
                    $upErr = 'Format foto harus JPG, PNG, GIF, atau WEBP.';
                } else {
                    $dir = __DIR__ . '/../uploads';
                    if (!is_dir($dir)) @mkdir($dir, 0777, true);
                    $fname = 't' . $id . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$info[2]];
                    if (move_uploaded_file($f['tmp_name'], $dir . '/' . $fname)) {
                        $attachment = 'uploads/' . $fname;
                    } else {
                        $upErr = 'Gagal menyimpan foto.';
                    }
                }
            }
        }
        if ($upErr !== '') {
            $msg = $upErr;
        } elseif ($text === '' && $attachment === null) {
            $msg = 'Isi update atau lampirkan foto.';
        } else {
            $db->prepare('INSERT INTO ticket_updates (ticket_id, user_id, message, attachment) VALUES (?,?,?,?)')
               ->execute([$id, $uid, $text, $attachment]);
            $msg = 'Update berhasil ditambahkan.';
        }
    }

    if ($action === 'remove_client' && $ticket['status'] === 'open') {
        $tcId = (int)post('tc_id');
        $q = $db->prepare('SELECT * FROM ticket_clients WHERE id = ? AND ticket_id = ?');
        $q->execute([$tcId, $id]);
        if ($row = $q->fetch()) {
            $db->prepare('DELETE FROM ticket_clients WHERE id = ?')->execute([$tcId]);
            if (!empty($row['client_id'])) {
                $cis = $db->prepare("SELECT c.description,
                        (SELECT COUNT(*) FROM ticket_clients tc WHERE tc.client_id = c.id) AS n
                     FROM clients c WHERE c.id = ?");
                $cis->execute([(int)$row['client_id']]);
                if (($cr = $cis->fetch()) && $cr['description'] === 'Dibuat otomatis dari tiket' && (int)$cr['n'] === 0) {
                    $db->prepare('DELETE FROM clients WHERE id = ?')->execute([(int)$row['client_id']]);
                }
            }
            $db->prepare('INSERT INTO ticket_updates (ticket_id, user_id, message) VALUES (?,?,?)')
               ->execute([$id, $uid, 'Client terimbas dihapus: ' . $row['client_name']]);
            $msg = 'Client terimbas dihapus.';
        }
    }

    if ($action === 'edit_ticket') {
        $subject  = post('subject');
        $desc     = post('description');
        $vendorId = (int)post('vendor_id');
        if ($subject === '') {
            $msg = 'Subjek tiket tidak boleh kosong.';
        } else {
            $db->prepare('UPDATE tickets SET subject=?, description=?, vendor_id=? WHERE id=?')
               ->execute([$subject, $desc, $vendorId > 0 ? $vendorId : null, $id]);
            $db->prepare('INSERT INTO ticket_updates (ticket_id, user_id, message) VALUES (?,?,?)')
               ->execute([$id, $uid, 'Detail tiket diperbarui.']);
            $msg = 'Detail tiket diperbarui.';
        }
    }

    if ($action === 'add_client') {
        $name = post('client_name');
        $ip   = post('client_ip');
        $dt   = post('downtime_start');
        $mode = post('monitor_mode', 'ping');
        if (!in_array($mode, ['ping', 'noping', 'll'], true)) $mode = 'ping';
        if ($name === '' && $ip === '') {
            $msg = 'Isi nama client atau IP terlebih dahulu.';
        } else {
            $cid = resolveOrCreateClient($name, $ip, $mode);
            if ($name === '' || $ip === '') {
                $cs = $db->prepare('SELECT name, ip_address FROM clients WHERE id = ?');
                $cs->execute([$cid]);
                if ($cc = $cs->fetch()) {
                    if ($name === '') $name = $cc['name'];
                    if ($ip === '')   $ip   = $cc['ip_address'];
                }
            }
            $dt  = $dt !== '' ? date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', $dt))) : nowDT();
            $db->prepare("INSERT INTO ticket_clients (ticket_id, client_id, client_name, ip_address, monitor_mode, downtime_start, status) VALUES (?,?,?,?,?,?,'affected')")
               ->execute([$id, $cid, $name, $ip, $mode, $dt]);
            $db->prepare('INSERT INTO ticket_updates (ticket_id, user_id, message) VALUES (?,?,?)')
               ->execute([$id, $uid, 'Client terimbas ditambahkan: ' . ($name !== '' ? $name : $ip) . ' (' . monitorModeLabel($mode) . ')']);
            $msg = 'Client terimbas ditambahkan.';
        }
    }

    $ticket = loadTicket($db, $id);
}

$st = $db->prepare("SELECT tc.*,
                       COALESCE(tc.client_name, c.name) AS client_name,
                       COALESCE(tc.ip_address, c.ip_address) AS ip_address,
                       u.name AS closed_by_name
                    FROM ticket_clients tc
                    LEFT JOIN clients c ON c.id = tc.client_id
                    LEFT JOIN users u ON u.id = tc.closed_by
                    WHERE tc.ticket_id = ?
                    ORDER BY tc.id ASC");
$st->execute([$id]);
$affected = $st->fetchAll();

$st = $db->prepare('SELECT tu.*, u.name AS user_name FROM ticket_updates tu
                    LEFT JOIN users u ON u.id = tu.user_id
                    WHERE tu.ticket_id = ? ORDER BY tu.id ASC');
$st->execute([$id]);
$updates = $st->fetchAll();

$vendors = getVendors();

$startDt = $ticket['created_at'];
$endDt   = $ticket['closed_at'] ?: nowDT();
if ($affected) {
    $startDt = min(array_map(fn($a) => $a['downtime_start'], $affected));
    $endDt   = max(array_map(fn($a) => $a['downtime_end'] ?: nowDT(), $affected));
}
$hours  = max(0, (strtotime($endDt) - strtotime($startDt)) / 3600.0);
$bucket = bucketOf($hours);
$isOpen = $ticket['status'] === 'open';

$title = 'Detail Tiket #' . $id;
include __DIR__ . '/../inc/header.php';
?>

<div class="page-head">
    <h2>Detail Tiket #<?= (int)$ticket['id'] ?></h2>
    <div class="head-actions">
        <a class="btn btn-secondary" href="tickets.php">Kembali</a>
    </div>
</div>

<?php if ($msg): ?><div class="alert alert-ok"><?= e($msg) ?></div><?php endif; ?>

<div class="grid-2">
    <div class="card">
        <h3><?= e($ticket['subject']) ?></h3>
        <p class="muted">Dibuat oleh <strong><?= e($ticket['created_by_name']) ?></strong> pada <?= e(formatDateTime($ticket['created_at'])) ?></p>
        <div class="status-box">
            <?php if ($isOpen): ?>
                <span class="badge badge-open">Terbuka</span>
            <?php else: ?>
                <span class="badge badge-closed">Ditutup</span>
            <?php endif; ?>
            <?php if ($ticket['vendor_id']): ?>
                <span class="tag tag-vendor">Vendor: <?= e($ticket['vendor_name']) ?></span>
            <?php endif; ?>
            <span class="tag <?= $bucket === 'over24' ? 'tag-danger' : ($bucket === 'under8' ? 'tag-ok' : 'tag-warn') ?>">Durasi: <?= e(formatDuration($hours)) ?></span>
        </div>
        <?php if ($ticket['description']): ?>
            <p><?= nl2br(e($ticket['description'])) ?></p>
        <?php endif; ?>
        <table class="table mt">
            <tr><th>Vendor</th><td><?= e($ticket['vendor_name'] ?: '-') ?></td></tr>
            <tr><th>Mulai Gangguan</th><td><?= e(formatDateTime($startDt)) ?></td></tr>
            <tr><th>Akhir Gangguan</th><td><?= e(formatDateTime($endDt)) ?></td></tr>
            <tr><th>Ditutup Oleh</th><td><?= e($ticket['closed_by_name'] ?: '-') ?></td></tr>
            <tr><th>Waktu Ditutup</th><td><?= e($ticket['closed_at'] ? formatDateTime($ticket['closed_at']) : '-') ?></td></tr>
        </table>
        <?php if ($isOpen): ?>
            <details class="mt">
                <summary class="details-toggle">Edit Detail Tiket</summary>
                <form method="post" class="details-panel">
                    <input type="hidden" name="action" value="edit_ticket">
                    <div class="form-group">
                        <label>Subjek</label>
                        <input type="text" name="subject" class="form-control" required value="<?= e($ticket['subject']) ?>">
                    </div>
                    <div class="form-group">
                        <label>Vendor</label>
                        <select name="vendor_id" class="form-control">
                            <option value="0">-- Tanpa Vendor --</option>
                            <?php foreach ($vendors as $v): ?>
                                <option value="<?= (int)$v['id'] ?>" <?= (int)$ticket['vendor_id'] === (int)$v['id'] ? 'selected' : '' ?>><?= e($v['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Deskripsi</label>
                        <textarea name="description" class="form-control" rows="3"><?= e($ticket['description']) ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Simpan Perubahan</button>
                </form>
            </details>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Tindakan</h3>
        <?php if ($isOpen): ?>
            <form method="post" onsubmit="return confirm('Tutup tiket #<?= (int)$ticket['id'] ?>? Client yang masih terimbas otomatis ditandai pulih.');">
                <button type="submit" name="close" value="1" class="btn btn-success btn-block">Tutup Tiket Sekarang</button>
            </form>
        <?php else: ?>
            <p class="muted">Tiket telah ditutup pada <?= e(formatDateTime($ticket['closed_at'])) ?>.</p>
            <form method="post" onsubmit="return confirm('Buka kembali tiket #<?= (int)$ticket['id'] ?>? Client yang pulih dikembalikan menjadi terimbas.');">
                <input type="hidden" name="action" value="reopen">
                <button type="submit" class="btn btn-primary btn-block">Open Kembali</button>
            </form>
        <?php endif; ?>

        <form method="post" class="mt" onsubmit="return confirm('HAPUS tiket #<?= (int)$ticket['id'] ?> permanen? Tindakan ini tidak bisa dibatalkan.');">
                <input type="hidden" name="action" value="delete_ticket">
                <button type="submit" class="btn btn-danger btn-block">Delete Tiket</button>
            </form>
    </div>
</div>

<div class="card">
    <h3>Client Terimbas (<?= count($affected) ?>)</h3>
    <div class="table-wrap">
        <table class="table table-wide">
            <thead>
                <tr>
                    <th>Client</th>
                    <th>IP / Range</th>
                    <th>Mode</th>
                    <th>Mulai Downtime</th>
                    <th class="hide-below-1000">Selesai Downtime</th>
                    <th>Durasi</th>
                    <th>Status</th>
                    <th class="hide-below-1200">Pulih Oleh</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$affected): ?>
                    <tr><td colspan="9" class="center muted">Tidak ada client.</td></tr>
                <?php endif; ?>
                <?php foreach ($affected as $a): ?>
                    <?php
                    $aEnd    = $a['downtime_end'] ?: nowDT();
                    $aHours  = max(0, (strtotime($aEnd) - strtotime($a['downtime_start'])) / 3600.0);
                    $aBucket = bucketOf($aHours);
                    $isAffected = $a['status'] === 'affected';
                    ?>
                    <tr>
                        <td><?= e($a['client_name']) ?></td>
                        <td><code><?= e($a['ip_address'] ?: '-') ?></code></td>
                        <td><span class="tag <?= monitorModeTagClass($a['monitor_mode']) ?>"><?= e(monitorModeLabel($a['monitor_mode'])) ?></span></td>
                        <td><?= e(formatDateTime($a['downtime_start'])) ?></td>
                        <td class="hide-below-1000"><?= e($a['downtime_end'] ? formatDateTime($a['downtime_end']) : '-') ?></td>
                        <td><span class="tag <?= $aBucket === 'over24' ? 'tag-danger' : ($aBucket === 'under8' ? 'tag-ok' : 'tag-warn') ?>"><?= e(formatDuration($aHours)) ?></span></td>
                        <td>
                            <?php if ($isAffected): ?>
                                <span class="badge badge-open">Terimbas</span>
                            <?php else: ?>
                                <span class="badge badge-closed">Pulih</span>
                            <?php endif; ?>
                        </td>
                        <td class="hide-below-1200"><?= $isAffected ? '-' : e($a['closed_by_name'] . ' · ' . formatDateTime($a['closed_at'])) ?></td>
                        <td>
                            <?php if ($isOpen && $isAffected): ?>
                                <form method="post" class="inline" onsubmit="return confirm('Tandai client ini pulih?');">
                                    <button type="submit" name="recover" value="<?= (int)$a['id'] ?>" class="btn btn-sm btn-success">Recover</button>
                                </form>
                            <?php elseif ($isOpen && !$isAffected): ?>
                                <form method="post" class="inline" onsubmit="return confirm('Kembalikan client ini menjadi TERIMBAS?');">
                                    <button type="submit" name="revert" value="<?= (int)$a['id'] ?>" class="btn btn-sm btn-secondary">Terimbas Kembali</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($isOpen): ?>
                                <form method="post" class="inline" onsubmit="return confirm('Hapus client <?= e($a['client_name']) ?> dari tiket ini?');">
                                    <input type="hidden" name="action" value="remove_client">
                                    <input type="hidden" name="tc_id" value="<?= (int)$a['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Hapus</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($isOpen): ?>
        <details class="mt">
            <summary class="details-toggle">+ Tambah Client Terimbas</summary>
            <form method="post" class="grid-form details-panel">
                <input type="hidden" name="action" value="add_client">
                <div class="form-group">
                    <label>Nama Client / Perangkat</label>
                    <input type="text" name="client_name" class="form-control" placeholder="cth: Pelanggan B">
                </div>
                <div class="form-group">
                    <label>IP / Range (opsional)</label>
                    <input type="text" name="client_ip" class="form-control" placeholder="192.168.1.40">
                </div>
                <div class="form-group">
                    <label>Mode Monitoring</label>
                    <select name="monitor_mode" class="form-control">
                        <option value="ping">Ping</option>
                        <option value="noping">No-ping</option>
                        <option value="ll">Client LL</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Mulai Downtime</label>
                    <input type="datetime-local" name="downtime_start" class="form-control">
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Tambah</button>
                </div>
            </form>
        </details>
    <?php endif; ?>
</div>

<div class="card">
    <h3>Update / Riwayat Penanganan (<?= count($updates) ?>)</h3>
    <?php if (!$updates): ?>
        <p class="muted">Belum ada update.</p>
    <?php else: ?>
        <ul class="feed">
            <?php foreach ($updates as $u): ?>
                <li class="feed-item">
                    <div class="feed-meta"><strong><?= e($u['user_name'] ?: 'Sistem') ?></strong> · <?= e(formatDateTime($u['created_at'])) ?></div>
                    <?php if ($u['message'] !== ''): ?>
                        <div class="feed-msg"><?= nl2br(e($u['message'])) ?></div>
                    <?php endif; ?>
                    <?php if ($u['attachment']): ?>
                        <div class="feed-photo">
                            <a href="../<?= e($u['attachment']) ?>" target="_blank" rel="noopener">
                                <img src="../<?= e($u['attachment']) ?>" alt="Lampiran foto" loading="lazy">
                            </a>
                        </div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="mt">
        <input type="hidden" name="action" value="add_update">
        <div class="form-group">
            <label>Tambah Update</label>
            <textarea name="message" class="form-control" rows="3" placeholder="cth: Tim vendor sedang pengecekan di lokasi, ETA 30 menit."></textarea>
        </div>
        <div class="form-group">
            <label>Lampiran Foto (opsional, maks 5 MB)</label>
            <input type="file" name="photo" accept="image/jpeg,image/png,image/gif,image/webp" class="form-control">
        </div>
        <button type="submit" class="btn btn-primary">Kirim Update</button>
    </form>
</div>

<?php include __DIR__ . '/../inc/footer.php'; ?>