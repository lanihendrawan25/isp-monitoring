<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/auth.php';
requireLogin();

$vendors = getVendors();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject  = post('subject');
    $desc     = post('description');
    $vendorId = (int)post('vendor_id');

    $names  = $_POST['client_name'] ?? [];
    $ips    = $_POST['client_ip'] ?? [];
    $dts    = $_POST['downtime_start'] ?? [];
    $modes  = $_POST['monitor_mode'] ?? [];
    if (!is_array($names)) $names = [];

    $rows = [];
    foreach ($names as $i => $nm) {
        $nm = trim((string)$nm);
        $ip = trim((string)($ips[$i] ?? ''));
        $dt = trim((string)($dts[$i] ?? ''));
        $mode = trim((string)($modes[$i] ?? 'ping'));
        if (!in_array($mode, ['ping', 'noping', 'll'], true)) $mode = 'ping';
        if ($ip === '') $mode = 'noping';
        if ($nm === '') continue;
        $rows[] = ['name' => $nm, 'ip' => $ip, 'dt' => $dt, 'mode' => $mode];
    }

    if ($subject === '') {
        $error = 'Subjek gangguan wajib diisi.';
    } elseif (!$rows) {
        $error = 'Isi minimal satu nama client yang terimbas.';
    }

    if (!$error) {
        $db = db();
        try {
            $db->beginTransaction();
            $insT = $db->prepare('INSERT INTO tickets (subject, description, vendor_id, status, created_by) VALUES (?,?,?,?,?)');
            $insT->execute([$subject, $desc, $vendorId > 0 ? $vendorId : null, 'open', currentUser()['id']]);
            $ticketId = (int)$db->lastInsertId();

            $insTC = $db->prepare('INSERT INTO ticket_clients (ticket_id, client_id, client_name, ip_address, monitor_mode, downtime_start, status) VALUES (?,?,?,?,?,?,?)');
            foreach ($rows as $r) {
                $cid = resolveOrCreateClient($r['name'], $r['ip'], $r['mode']);
                $dt = $r['dt'] !== '' ? str_replace('T', ' ', $r['dt']) : nowDT();
                $dt = date('Y-m-d H:i:s', strtotime($dt));
                $insTC->execute([$ticketId, $cid, $r['name'], $r['ip'], $r['mode'], $dt, 'affected']);
            }

            $db->prepare('INSERT INTO ticket_updates (ticket_id, user_id, message) VALUES (?,?,?)')
               ->execute([$ticketId, currentUser()['id'], 'Tiket dibuat.']);

            $db->commit();
            redirect('ticket_detail.php?id=' . $ticketId);
        } catch (Exception $ex) {
            if ($db->inTransaction()) $db->rollBack();
            $error = 'Gagal menyimpan: ' . $ex->getMessage();
        }
    }
}

$title = 'Buat Tiket Gangguan';
include __DIR__ . '/../inc/header.php';
?>

<div class="page-head">
    <h2>Buat Tiket Gangguan</h2>
</div>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<form method="post" id="ticketForm">
    <div class="card">
        <div class="form-group">
            <label>Subjek Gangguan *</label>
            <input type="text" name="subject" class="form-control" required value="<?= e($_POST['subject'] ?? '') ?>" placeholder="cth: Fiber cut jalur utama">
        </div>
        <div class="grid-2">
            <div class="form-group">
                <label>Vendor (opsional)</label>
                <select name="vendor_id" class="form-control">
                    <option value="0">-- Tanpa Vendor --</option>
                    <?php foreach ($vendors as $v): ?>
                        <option value="<?= (int)$v['id'] ?>" <?= (int)($_POST['vendor_id'] ?? 0) === (int)$v['id'] ? 'selected' : '' ?>>
                            <?= e($v['name']) ?><?= $v['contact_person'] ? ' — ' . e($v['contact_person']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!$vendors): ?><small class="muted">Belum ada vendor. <a href="vendors.php">Tambah vendor &raquo;</a></small><?php endif; ?>
            </div>
        </div>
        <div class="form-group">
            <label>Deskripsi</label>
            <textarea name="description" class="form-control" rows="4" placeholder="Uraian gangguan, penyebab, dst."><?= e($_POST['description'] ?? '') ?></textarea>
        </div>
    </div>

    <div class="card">
        <h3>Client yang Terimbas</h3>
        <p class="muted">Ketik nama client (wajib) dan IP bila ada (opsional). Mode monitoring: <strong>Ping</strong> dipantau ICMP, <strong>No-ping</strong> tidak dipantau, <strong>Client LL</strong> client Leased Line. Client otomatis didaftarkan agar ikut terpantau.</p>
        <div class="head-actions">
            <button type="button" class="btn btn-sm btn-secondary" id="btnAddRow">+ Tambah Client</button>
            <button type="button" class="btn btn-sm btn-ghost" id="btnSyncDT">Terapkan waktu yang sama ke semua</button>
        </div>
        <div class="table-wrap">
            <table class="table table-wide" id="affectTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Nama Client / Perangkat *</th>
                        <th>IP / Range (opsional)</th>
                        <th>Mode Monitoring</th>
                        <th>Mulai Downtime</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="affectBody">
                    <tr class="affect-row">
                        <td class="row-no">1</td>
                        <td><input type="text" name="client_name[]" class="form-control f-name" placeholder="cth: Pelanggan A / ODP-01"></td>
                        <td><input type="text" name="client_ip[]" class="form-control f-ip" placeholder="cth: 192.168.1.10"></td>
                        <td>
                            <select name="monitor_mode[]" class="form-control f-mode">
                                <option value="ping">Ping</option>
                                <option value="noping">No-ping</option>
                                <option value="ll">Client LL</option>
                            </select>
                        </td>
                        <td><input type="datetime-local" name="downtime_start[]" class="form-control"></td>
                        <td><button type="button" class="btn btn-sm btn-danger btn-del-row">Hapus</button></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <p><button type="submit" class="btn btn-primary">Simpan Tiket</button>
    <a class="btn btn-secondary" href="tickets.php">Batal</a></p>
</form>

<?php include __DIR__ . '/../inc/footer.php'; ?>