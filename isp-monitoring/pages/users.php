<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/auth.php';
requireLogin();

$db = db();
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'change_pass') {
        $old      = post('old_password');
        $new1     = post('new_password');
        $new2     = post('new_password2');
        $me       = currentUser();
        $st       = $db->prepare('SELECT password, name FROM users WHERE id = ?');
        $st->execute([$me['id']]);
        $row = $st->fetch();
        if (!password_verify($old, $row['password'])) {
            $err = 'Password lama salah.';
        } elseif (strlen($new1) < 6) {
            $err = 'Password baru minimal 6 karakter.';
        } elseif ($new1 !== $new2) {
            $err = 'Konfirmasi password baru tidak cocok.';
        } else {
            $db->prepare('UPDATE users SET password = ? WHERE id = ?')
               ->execute([password_hash($new1, PASSWORD_DEFAULT), $me['id']]);
            $msg = 'Password Anda berhasil diubah.';
        }
    } elseif ($action === 'add_user') {
        if (!isAdmin()) { $err = 'Hanya admin yang dapat menambah akun.'; }
        else {
            $username = post('username');
            $name     = post('name');
            $pass     = post('password');
            $role     = post('role') === 'admin' ? 'admin' : 'staff';
            if ($username === '' || $name === '' || $pass === '') {
                $err = 'Semua kolom wajib diisi.';
            } elseif (strlen($pass) < 6) {
                $err = 'Password minimal 6 karakter.';
            } else {
                try {
                    $db->prepare('INSERT INTO users (username, password, name, role) VALUES (?,?,?,?)')
                       ->execute([$username, password_hash($pass, PASSWORD_DEFAULT), $name, $role]);
                    $msg = "Akun <strong>$username</strong> ditambahkan.";
                } catch (Exception $ex) {
                    $err = 'Username sudah dipakai.';
                }
            }
        }
    } elseif ($action === 'reset_pass') {
        if (!isAdmin()) { $err = 'Hanya admin yang dapat mereset password.'; }
        else {
            $id  = (int)post('id');
            $pass = post('password');
            if (strlen($pass) < 6) {
                $err = 'Password baru minimal 6 karakter.';
            } else {
                $db->prepare('UPDATE users SET password = ? WHERE id = ?')
                   ->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
                $msg = 'Password akun berhasil direset.';
            }
        }
    }
}

$users = $db->query('SELECT u.*, 
    (SELECT COUNT(*) FROM tickets t WHERE t.closed_by = u.id) AS tickets_closed,
    (SELECT COUNT(*) FROM ticket_clients tc WHERE tc.closed_by = u.id) AS clients_recovered
    FROM users u ORDER BY u.role, u.name')->fetchAll();

$title = 'Pengguna';
include __DIR__ . '/../inc/header.php';
?>

<div class="page-head">
    <h2><?= isAdmin() ? 'Kelola Pengguna' : 'Akun Saya' ?></h2>
    <?php if (!isAdmin()): ?>
        <a class="btn btn-secondary" href="kpi.php">Lihat KPI Saya</a>
    <?php endif; ?>
</div>

<?php if ($msg): ?><div class="alert alert-ok"><?= $msg ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

<div class="grid-2">
    <?php if (isAdmin()): ?>
    <div class="card">
        <h3>Tambah Akun Karyawan</h3>
        <form method="post">
            <input type="hidden" name="action" value="add_user">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Nama Lengkap</label>
                <input type="text" name="name" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Role</label>
                <select name="role" class="form-control">
                    <option value="staff">Karyawan (staff)</option>
                    <option value="admin">Administrator</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Tambah Akun</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="card">
        <h3>Ganti Password Saya</h3>
        <form method="post">
            <input type="hidden" name="action" value="change_pass">
            <div class="form-group">
                <label>Password Lama</label>
                <input type="password" name="old_password" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Password Baru</label>
                <input type="password" name="new_password" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Ulangi Password Baru</label>
                <input type="password" name="new_password2" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary">Simpan</button>
        </form>
    </div>
</div>

<?php if (isAdmin()): ?>
<div class="card">
    <h3>Daftar Akun</h3>
    <div class="table-wrap">
        <table class="table table-wide">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Nama</th>
                    <th>Role</th>
                    <th>Tiket Ditutup</th>
                    <th>Client Dipulihkan</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td><strong><?= e($u['username']) ?></strong></td>
                        <td><?= e($u['name']) ?></td>
                        <td><span class="tag <?= $u['role'] === 'admin' ? 'tag-admin' : 'tag-ok' ?>"><?= e($u['role']) ?></span></td>
                        <td><?= (int)$u['tickets_closed'] ?></td>
                        <td><?= (int)$u['clients_recovered'] ?></td>
                        <td>
                            <?php if ((int)$u['id'] !== (int)currentUser()['id']): ?>
                            <form method="post" class="inline" onsubmit="return confirm('Reset password akun <?= e($u['username']) ?>?');">
                                <input type="hidden" name="action" value="reset_pass">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <input type="password" name="password" placeholder="Password baru" class="form-control input-inline" required minlength="6">
                                <button type="submit" class="btn btn-sm btn-secondary">Reset</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../inc/footer.php'; ?>