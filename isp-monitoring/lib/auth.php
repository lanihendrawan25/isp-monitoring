<?php
function currentUser()
{
    return $_SESSION['user'] ?? null;
}

function requireLogin()
{
    if (!currentUser()) {
        redirect('login.php');
    }
}

function isAdmin()
{
    $u = currentUser();
    return $u && ($u['role'] === 'admin');
}

function requireAdmin()
{
    requireLogin();
    if (!isAdmin()) {
        redirect('dashboard.php');
    }
}