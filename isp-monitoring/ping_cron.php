<?php
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/functions.php';

$isCLI = (php_sapi_name() === 'cli');

if ($isCLI) {
    echo "=== Monitoring Ping (ICMP) — " . date('Y-m-d H:i:s') . " ===\n";
}

try {
    pingAllClients();
    if ($isCLI) {
        $clients = db()->query('SELECT name, ip_address, is_online FROM clients ORDER BY name')->fetchAll();
        foreach ($clients as $c) {
            $status = (int)$c['is_online'] ? 'ONLINE' : 'OFFLINE';
            echo "[$status] {$c['name']} ({$c['ip_address']})\n";
        }
        echo "=== Selesai. Pingeran otomatis via Windows Task Scheduler ===\n";
    } else {
        echo "OK";
    }
} catch (Exception $ex) {
    $msg = 'Gagal: ' . $ex->getMessage();
    if ($isCLI) {
        echo $msg . "\n";
        exit(1);
    } else {
        http_response_code(500);
        echo $msg;
    }
}