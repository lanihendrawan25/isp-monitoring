<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/xlsx.php';
require_once __DIR__ . '/../lib/auth.php';
requireLogin();

$f = get('f', 'all');
$filterLabels = ticketFilterLabels();
if (!isset($filterLabels[$f])) {
    $f = 'all';
}

$tickets = filterTickets(getTickets(), $f);

$clients = [];
foreach ($tickets as $t) {
    $clients[(int)$t['id']] = getTicketClientsByTicket((int)$t['id']);
}

try {
    $tmp = buildGangguanXlsx($tickets, $clients, $filterLabels[$f]);
} catch (Throwable $ex) {
    http_response_code(500);
    exit('Gagal membuat file Excel: ' . $ex->getMessage());
}

$name = 'laporan_gangguan_' . date('Ymd_His') . ($f !== 'all' ? '_' . $f : '') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Cache-Control: max-age=0');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
unlink($tmp);
exit;