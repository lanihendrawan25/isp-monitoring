<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
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

function pdfClientCells(array $a): string
{
    $end   = $a['downtime_end'] ?: nowDT();
    $hours = max(0, (strtotime($end) - strtotime($a['downtime_start'])) / 3600.0);
    $rec   = $a['status'] === 'recovered';
    $name  = ($a['client_name'] ?? '') !== '' ? $a['client_name'] : '-';
    $ip    = ($a['ip_address'] ?? '') !== '' ? $a['ip_address'] : '-';
    $pulih = $rec ? (($a['closed_by_name'] ?: '-') . ' · ' . formatDateTime($a['closed_at'])) : '-';

    return '<td>' . e($name) . '</td>'
         . '<td>' . e($ip) . '</td>'
         . '<td>' . e(monitorModeLabel((string)$a['monitor_mode'])) . '</td>'
         . '<td>' . e(formatDateTime($a['downtime_start'])) . '</td>'
         . '<td>' . e($a['downtime_end'] ? formatDateTime($a['downtime_end']) : '-') . '</td>'
         . '<td>' . e(formatDuration($hours)) . '</td>'
         . '<td>' . ($rec ? 'Pulih' : 'Terimbas') . '</td>'
         . '<td>' . e($pulih) . '</td>';
}

$headers = ['No', 'ID Tiket', 'Subjek Gangguan', 'Vendor', 'Status', 'Awal Gangguan', 'Akhir Gangguan', 'Durasi', 'Dibuka Oleh',
            'Client Terimbas', 'IP / Range', 'Mode', 'Mulai Downtime', 'Selesai Downtime', 'Durasi Downtime', 'Status Client', 'Pulih Oleh'];
$widths  = [3, 4, 13, 6, 4, 7, 7, 5, 4, 9, 7, 4, 7, 7, 5, 4, 4];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Gangguan</title>
    <style>
        @page { size: A4 landscape; margin: 8mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; color: #222; margin: 0; padding: 12px; }
        .report-head { text-align: center; margin-bottom: 10px; }
        .report-head h1 { font-size: 16px; margin: 0 0 3px; letter-spacing: .5px; }
        .report-head .meta { font-size: 10px; color: #555; }
        table.rep { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.rep th, table.rep td {
            border: 1px solid #8a8a8a; font-size: 8px; padding: 3px 4px;
            text-align: center; vertical-align: middle; word-wrap: break-word; overflow-wrap: anywhere;
        }
        table.rep thead th { background: #1F4E78; color: #fff; }
        table.rep td.ticket { background: #f2f2f2; font-weight: 600; }
        .toolbar { text-align: center; margin: 0 0 12px; }
        .toolbar button, .toolbar a {
            font: inherit; font-size: 12px; padding: 8px 16px; margin: 0 4px; cursor: pointer;
            border: 1px solid #1F4E78; border-radius: 6px; text-decoration: none;
            background: #1F4E78; color: #fff;
        }
        .toolbar a.back { background: #fff; color: #1F4E78; }
        @media print { .toolbar { display: none; } body { padding: 0; } }
    </style>
</head>
<body onload="setTimeout(function(){ window.print(); }, 350);">

<div class="toolbar">
    <button type="button" onclick="window.print()">Cetak / Simpan sebagai PDF</button>
    <a class="back" href="tickets.php">Kembali</a>
</div>

<div class="report-head">
    <h1>LAPORAN GANGGUAN / TIKET</h1>
    <div class="meta">
        Filter: <?= e($filterLabels[$f]) ?> &nbsp;|&nbsp;
        Jumlah Tiket: <?= count($tickets) ?> &nbsp;|&nbsp;
        Dicetak: <?= e(date('d M Y H:i')) ?> WIB
    </div>
</div>

<table class="rep">
    <colgroup>
        <?php foreach ($widths as $w): ?>
            <col style="width: <?= (int)$w ?>%">
        <?php endforeach; ?>
    </colgroup>
    <thead>
        <tr>
            <?php foreach ($headers as $h): ?>
                <th><?= e($h) ?></th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
        <?php if (!$tickets): ?>
            <tr><td colspan="17">Tidak ada tiket.</td></tr>
        <?php endif; ?>
        <?php $no = 0; ?>
        <?php foreach ($tickets as $t): ?>
            <?php
            $no++;
            $id     = (int)$t['id'];
            $list   = $clients[$id] ?? [];
            $nCli   = count($list);
            $isOpen = $t['status'] === 'open';
            $tv = [
                $no, $id,
                $t['subject'] !== '' ? $t['subject'] : '-',
                $t['vendor_name'] ?: '-',
                $isOpen ? 'Terbuka' : 'Ditutup',
                formatDateTime($t['start_dt']),
                formatDateTime($t['end_dt']),
                formatDuration((float)$t['hours']),
                ($t['created_by_name'] ?? '') !== '' ? $t['created_by_name'] : '-',
            ];
            ?>
            <?php if ($nCli === 0): ?>
                <tr>
                    <?php foreach ($tv as $v): ?><td class="ticket"><?= e((string)$v) ?></td><?php endforeach; ?>
                    <td colspan="8">Tidak ada client terimbas</td>
                </tr>
            <?php else: ?>
                <?php foreach ($list as $k => $a): ?>
                    <tr>
                        <?php if ($k === 0): ?>
                            <?php foreach ($tv as $v): ?><td class="ticket" rowspan="<?= $nCli ?>"><?= e((string)$v) ?></td><?php endforeach; ?>
                        <?php endif; ?>
                        <?= pdfClientCells($a) ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endforeach; ?>
    </tbody>
</table>

</body>
</html>
