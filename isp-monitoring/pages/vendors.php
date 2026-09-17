<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/auth.php';
requireLogin();

$db  = db();
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');
    if ($action === 'save') {
        $id    = (int)post('id');
        $name  = post('name');
        $cp    = post('contact_person');
        $phone = post('phone');
        $email = post('email');
        $desc  = post('description');
        if ($name === '') {
            $err = 'Nama vendor wajib diisi.';
        } elseif ($id > 0) {
            $db->prepare('UPDATE vendors SET name=?, contact_person=?, phone=?, email=?, description=? WHERE id=?')
               ->execute([$name, $cp, $phone, $email, $desc, $id]);
            $msg = 'Vendor diperbarui.';
        } else {
            $db->prepare('INSERT INTO vendors (name, contact_person, phone, email, description) VALUES (?,?,?,?,?)')
               ->execute([$name, $cp, $phone, $email, $desc]);
            $msg = 'Vendor ditambahkan.';
        }
    } elseif ($action === 'delete') {
        $id = (int)post('id');
        $used = $db->prepare('SELECT COUNT(*) AS n FROM tickets WHERE vendor_id = ?');
        $used->execute([$id]);
        if ((int)$used->fetch()['n'] > 0) {
            $err = 'Vendor tidak bisa dihapus karena sudah dipakai di tiket.';
        } else {
            $db->prepare('DELETE FROM vendors WHERE id = ?')->execute([$id]);
            $msg = 'Vendor dihapus.';
        }
    }
}

$editId  = (int)get('edit');
$editing = null;
if ($editId > 0) {
    $st = $db->prepare('SELECT * FROM vendors WHERE id = ?');
    $st->execute([$editId]);
    $editing = $st->fetch();
}

$vendors = $db->query('SELECT v.*, (SELECT COUNT(*) FROM tickets t WHERE t.vendor_id = v.id) AS used FROM vendors v ORDER BY v.name')->fetchAll();

$title = 'Vendor';
include __DIR__ . '/../inc/header.php';
?>

<div class="page-head">
    <h2>Data Vendor</h2>
</div>

<?php if ($msg): ?><div class="alert alert-ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

<div class="card">
    <h3><?= $editing ? 'Edit Vendor' : 'Tambah Vendor' ?></h3>
    <form method="post" class="grid-form">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
        <div class="form-group">
            <label>Nama Vendor *</label>
            <input type="text" name="name" class="form-control" required value="<?= e($editing['name'] ?? '') ?>" placeholder="cth: PT Fiber Nusantara">
        </div>
        <div class="form-group">
            <label>Kontak Person</label>
            <input type="text" name="contact_person" class="form-control" value="<?= e($editing['contact_person'] ?? '') ?>" placeholder="Nama PIC">
        </div>
        <div class="form-group">
            <label>Telepon</label>
            <input type="text" name="phone" class="form-control" value="<?= e($editing['phone'] ?? '') ?>" placeholder="0812xxxx">
        </div>
        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" class="form-control" value="<?= e($editing['email'] ?? '') ?>" placeholder="noc@vendor.co.id">
        </div>
        <div class="form-group">
            <label>Keterangan</label>
            <input type="text" name="description" class="form-control" value="<?= e($editing['description'] ?? '') ?>" placeholder="Layanan / cakupan">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $editing ? 'Simpan Perubahan' : 'Tambah Vendor' ?></button>
            <?php if ($editing): ?><a class="btn btn-secondary" href="vendors.php">Batal</a><?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <h3>Daftar Vendor (<?= count($vendors) ?>)</h3>
    <div class="table-wrap">
        <table class="table table-wide">
            <thead>
                <tr>
                    <th>Nama Vendor</th>
                    <th>Kontak</th>
                    <th>Telepon</th>
                    <th>Email</th>
                    <th>Keterangan</th>
                    <th>Dipakai di Tiket</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$vendors): ?>
                    <tr><td colspan="7" class="center muted">Belum ada vendor.</td></tr>
                <?php endif; ?>
                <?php foreach ($vendors as $v): ?>
                    <tr>
                        <td><strong><?= e($v['name']) ?></strong></td>
                        <td><?= e($v['contact_person'] ?: '-') ?></td>
                        <td><?= e($v['phone'] ?: '-') ?></td>
                        <td><?= e($v['email'] ?: '-') ?></td>
                        <td><?= e($v['description'] ?: '-') ?></td>
                        <td><?= (int)$v['used'] ?> tiket</td>
                        <td class="nowrap">
                            <a class="btn btn-sm btn-secondary" href="vendors.php?edit=<?= (int)$v['id'] ?>">Edit</a>
                            <form method="post" class="inline" onsubmit="return confirm('Hapus vendor <?= e($v['name']) ?>?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Hapus</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../inc/footer.php'; ?>