<?php
requireLogin();
$user = currentUser();
$page = basename($_SERVER['PHP_SELF']);
$navItems = [
    'dashboard.php'     => 'Dashboard',
    'tickets.php'       => 'Tiket Gangguan',
    'ticket_create.php' => 'Buat Tiket',
    'ping.php'          => 'Monitoring Ping',
    'vendors.php'       => 'Vendor',
    'kpi.php'           => 'KPI Saya',
];
$navItems['users.php'] = isAdmin() ? 'Pengguna' : 'Akun';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Monitoring Gangguan ISP') ?></title>
    <?= $headExtra ?? '' ?>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="layout">
    <aside class="sidebar" id="sidebar">
        <a class="side-brand" href="dashboard.php">
            <span class="brand-badge">ISP</span>
            <span class="brand-text">Monitoring<br>Gangguan</span>
        </a>
        <nav class="side-nav">
            <?php foreach ($navItems as $file => $label): ?>
                <a class="side-link <?= $page === $file ? 'active' : '' ?>" href="<?= e($file) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="side-foot">
            <div class="side-user">
                <div class="side-user-name"><?= e($user['name']) ?></div>
                <div class="side-user-role"><?= e(ucfirst($user['role'])) ?></div>
            </div>
            <a class="btn btn-ghost btn-block" href="../logout.php">Keluar</a>
        </div>
    </aside>
    <button class="side-toggle" type="button" onclick="document.getElementById('sidebar').classList.toggle('open')">&#9776;</button>
    <div class="main">
        <div class="container">