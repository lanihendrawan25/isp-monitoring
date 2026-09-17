<?php
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/auth.php';

if (currentUser()) {
    redirect('pages/dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = post('username');
    $p = post('password');
    if ($u === '' || $p === '') {
        $error = 'Username dan password wajib diisi.';
    } else {
        $st = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $st->execute([$u]);
        $user = $st->fetch();
        if ($user && password_verify($p, $user['password'])) {
            unset($user['password']);
            $_SESSION['user'] = $user;
            redirect('pages/dashboard.php');
        }
        $error = 'Username atau password salah.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Monitoring Gangguan ISP</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-body">
    <div class="login-card">
        <div class="login-brand">
            <div class="login-logo">ISP</div>
            <h1>Monitoring Gangguan</h1>
            <p>Manajemen tiket &amp; pemantauan client</p>
        </div>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" class="form-control" required autofocus>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Masuk</button>
        </form>
    </div>
</body>
</html>