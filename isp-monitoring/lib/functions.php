<?php
function e($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function post($k, $default = '')
{
    return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $default;
}

function get($k, $default = '')
{
    return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $default;
}

function redirect($url)
{
    header('Location: ' . $url);
    exit;
}

function bucketOf(float $hours): string
{
    if ($hours <= 8)  return 'under8';
    if ($hours <= 12) return 'under12';
    if ($hours <= 24) return 'under24';
    return 'over24';
}

function bucketLabel(string $b): string
{
    return [
        'under8'  => '8 Jam kebawah',
        'under12' => '12 Jam kebawah',
        'under24' => '24 Jam kebawah',
        'over24'  => 'Di atas 24 Jam',
    ][$b] ?? 'Lainnya';
}

function formatDuration(float $hours): string
{
    $h = floor($hours);
    $m = round(($hours - $h) * 60);
    return $h . ' jam ' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . ' menit';
}

function formatDateTime($dt)
{
    if (!$dt) return '-';
    return date('d M Y H:i', strtotime($dt));
}

function nowDT()
{
    return date('Y-m-d H:i:s');
}

function pingClient(string $ip): array
{
    $ms     = null;
    $online = false;
    $cmd    = 'ping -n 1 -w 2000 ' . escapeshellarg($ip) . ' 2>&1';
    $output = @shell_exec($cmd);
    if ($output !== null && stripos((string)$output, 'TTL=') !== false) {
        $online = true;
        if (preg_match('/time[=<\s]+(\d+)\s*ms/i', (string)$output, $m)) {
            $ms = (int)$m[1];
        }
    }
    return [$online, $ms];
}

function monitorModeLabel(string $m): string
{
    return [
        'ping'   => 'Ping',
        'noping' => 'No-ping',
        'll'     => 'Client LL',
    ][$m] ?? 'Ping';
}

function monitorModeTagClass(string $m): string
{
    return [
        'ping'   => 'tag-ok',
        'noping' => 'tag-muted',
        'll'     => 'tag-vendor',
    ][$m] ?? 'tag-muted';
}

function pingAllClients(): void
{
    $stmt = db()->query("SELECT * FROM clients WHERE monitor_mode <> 'noping' ORDER BY id ASC");
    while ($c = $stmt->fetch()) {
        if (trim((string)$c['ip_address']) === '') {
            continue;
        }
        list($online, $ms) = pingClient($c['ip_address']);
        $ins = db()->prepare('INSERT INTO ping_logs (client_id, is_online, response_ms, checked_at) VALUES (?,?,?,?)');
        $ins->execute([$c['id'], $online ? 1 : 0, $ms, nowDT()]);
        $upd = db()->prepare('UPDATE clients SET is_online = ?, ping_response_ms = ?, last_ping = ? WHERE id = ?');
        $upd->execute([$online ? 1 : 0, $ms, nowDT(), $c['id']]);
    }
}

function resolveOrCreateClient(string $name, string $ip, string $mode = 'ping'): int
{
    $name = trim($name);
    $ip   = trim($ip);
    if (!in_array($mode, ['ping', 'noping', 'll'], true)) {
        $mode = 'ping';
    }
    if ($ip === '') {
        // Tanpa IP tidak bisa diping, jadi paksa No-ping.
        $mode = 'noping';
    }
    if ($ip !== '') {
        $st = db()->prepare('SELECT id FROM clients WHERE ip_address = ? LIMIT 1');
        $st->execute([$ip]);
        $c = $st->fetch();
        if ($c) {
            db()->prepare('UPDATE clients SET monitor_mode = ? WHERE id = ?')->execute([$mode, (int)$c['id']]);
            return (int)$c['id'];
        }
    }
    if ($name === '') {
        $name = 'Client ' . $ip;
    }
    $ins = db()->prepare('INSERT INTO clients (name, ip_address, description, monitor_mode) VALUES (?,?,?,?)');
    $ins->execute([$name, $ip, 'Dibuat otomatis dari tiket', $mode]);
    return (int)db()->lastInsertId();
}

/**
 * Hapus client yang dibuat otomatis dari tiket ketika tiket sudah solved,
 * dengan syarat client tersebut tidak dipakai tiket lain yang masih open.
 * Riwayat tiket tetap utuh (ticket_clients menyimpan nama & IP sendiri).
 */
function cleanupAutoClientsForTicket(int $ticketId): int
{
    $st = db()->prepare("SELECT DISTINCT tc.client_id
                         FROM ticket_clients tc
                         JOIN clients c ON c.id = tc.client_id
                         WHERE tc.ticket_id = ? AND tc.client_id IS NOT NULL
                           AND c.description = 'Dibuat otomatis dari tiket'");
    $st->execute([$ticketId]);
    $deleted = 0;
    foreach ($st->fetchAll() as $row) {
        $cid = (int)$row['client_id'];
        $chk = db()->prepare("SELECT COUNT(*) AS n
                              FROM ticket_clients tc
                              JOIN tickets t ON t.id = tc.ticket_id
                              WHERE tc.client_id = ? AND t.status = 'open'");
        $chk->execute([$cid]);
        if ((int)$chk->fetch()['n'] === 0) {
            db()->prepare('DELETE FROM clients WHERE id = ? AND description = ?')
                ->execute([$cid, 'Dibuat otomatis dari tiket']);
            $deleted++;
        }
    }
    return $deleted;
}

function getTicketClientsByTicket(int $ticketId): array
{
    $st = db()->prepare("SELECT tc.*,
                               COALESCE(tc.client_name, c.name) AS client_name,
                               COALESCE(tc.ip_address, c.ip_address) AS ip_address,
                               u.name AS closed_by_name
                        FROM ticket_clients tc
                        LEFT JOIN clients c ON c.id = tc.client_id
                        LEFT JOIN users u ON u.id = tc.closed_by
                        WHERE tc.ticket_id = ?
                        ORDER BY tc.id ASC");
    $st->execute([$ticketId]);
    return $st->fetchAll();
}

function getVendors(): array
{
    return db()->query('SELECT * FROM vendors ORDER BY name')->fetchAll();
}

function getPingStats(): array
{
    $d = db();
    $row = $d->query("
        SELECT COUNT(*) AS all_cnt,
               COALESCE(SUM(CASE WHEN COALESCE(tm.mode, c.monitor_mode) = 'll' THEN 1 ELSE 0 END), 0) AS ll_cnt,
               COALESCE(SUM(CASE WHEN COALESCE(tm.mode, c.monitor_mode) = 'noping' THEN 1 ELSE 0 END), 0) AS noping_cnt,
               COALESCE(SUM(CASE WHEN COALESCE(tm.mode, c.monitor_mode) IN ('ping','ll') AND IFNULL(TRIM(c.ip_address), '') <> '' THEN 1 ELSE 0 END), 0) AS mon_cnt,
               COALESCE(SUM(CASE WHEN COALESCE(tm.mode, c.monitor_mode) IN ('ping','ll') AND IFNULL(TRIM(c.ip_address), '') <> '' AND c.is_online = 1 THEN 1 ELSE 0 END), 0) AS online_cnt
        FROM clients c
        LEFT JOIN (
            SELECT tc.client_id, tc.monitor_mode AS mode,
                   ROW_NUMBER() OVER (PARTITION BY tc.client_id ORDER BY (t.status = 'open') DESC, tc.id DESC) AS rn
            FROM ticket_clients tc
            JOIN tickets t ON t.id = tc.ticket_id
        ) tm ON tm.client_id = c.id AND tm.rn = 1
    ")->fetch();

    $all    = (int)$row['all_cnt'];
    $ll     = (int)$row['ll_cnt'];
    $noping = (int)$row['noping_cnt'];
    $mon    = (int)$row['mon_cnt'];
    $online = (int)$row['online_cnt'];

    return [
        'all'     => $all,
        'noping'  => $noping,
        'll'      => $ll,
        'mon'     => $mon,
        'online'  => $online,
        'offline' => max(0, $mon - $online),
    ];
}

function getTickets(string $status = ''): array
{
    $sql = "SELECT t.*,
                u.name AS created_by_name,
                u2.name AS closed_by_name,
                v.name AS vendor_name,
                COUNT(tc.id) AS total_clients,
                COALESCE(SUM(CASE WHEN tc.status = 'recovered' THEN 1 ELSE 0 END), 0) AS recovered_clients,
                COALESCE(MIN(tc.downtime_start), t.created_at) AS d_start,
                CASE WHEN MAX(CASE WHEN tc.downtime_end IS NOT NULL THEN tc.downtime_end END) IS NOT NULL
                     THEN MAX(CASE WHEN tc.downtime_end IS NOT NULL THEN tc.downtime_end END)
                     ELSE COALESCE(t.closed_at, NOW()) END AS d_end
            FROM tickets t
            LEFT JOIN users u  ON u.id  = t.created_by
            LEFT JOIN users u2 ON u2.id = t.closed_by
            LEFT JOIN vendors v ON v.id = t.vendor_id
            LEFT JOIN ticket_clients tc ON tc.ticket_id = t.id
            ";
    $params = [];
    if ($status !== '') {
        $sql .= ' WHERE t.status = :st';
        $params[':st'] = $status;
    }
    $sql .= ' GROUP BY t.id ORDER BY t.id DESC';
    $st = db()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['start_dt'] = $r['d_start'];
        $r['end_dt']   = $r['d_end'];
        $r['hours']    = max(0, (strtotime($r['end_dt']) - strtotime($r['start_dt'])) / 3600.0);
        $r['bucket']   = bucketOf($r['hours']);
    }
    unset($r);
    return $rows;
}

function bucketCounts(array $tickets): array
{
    $out = ['under8' => 0, 'under12' => 0, 'under24' => 0, 'over24' => 0];
    foreach ($tickets as $t) {
        if (isset($out[$t['bucket']])) $out[$t['bucket']]++;
    }
    return $out;
}

function ticketFilterLabels(): array
{
    return [
        'all'     => 'Semua Tiket',
        'open'    => 'Terbuka',
        'closed'  => 'Ditutup',
        'under8'  => '8 Jam kebawah',
        'under12' => '12 Jam kebawah',
        'under24' => '24 Jam kebawah',
        'over24'  => 'Di atas 24 Jam',
    ];
}

function filterTickets(array $tickets, string $f): array
{
    if ($f === 'open' || $f === 'closed') {
        return array_values(array_filter($tickets, fn($t) => $t['status'] === $f));
    }
    if (in_array($f, ['under8', 'under12', 'under24', 'over24'], true)) {
        return array_values(array_filter($tickets, fn($t) => $t['bucket'] === $f));
    }
    return array_values($tickets);
}

/**
 * KPI setiap pengguna (untuk admin): tiket ditutup, rata-rata waktu tutup,
 * jumlah client dipulihkan, dan rata-rata durasi pemulihan.
 */
function getKpiByUser(): array
{
    $users = db()->query('SELECT id, name, username, role FROM users ORDER BY role DESC, name ASC')->fetchAll();

    $closedMap = [];
    foreach (db()->query("SELECT closed_by, COUNT(*) AS n,
                                 COALESCE(AVG(TIMESTAMPDIFF(MINUTE, created_at, closed_at)),0) AS avg_min
                          FROM tickets
                          WHERE status='closed' AND closed_by IS NOT NULL
                          GROUP BY closed_by")->fetchAll() as $r) {
        $closedMap[(int)$r['closed_by']] = $r;
    }

    $recoverMap = [];
    foreach (db()->query("SELECT closed_by, COUNT(*) AS n,
                                 COALESCE(AVG(TIMESTAMPDIFF(MINUTE, downtime_start, downtime_end)),0) AS avg_min
                          FROM ticket_clients
                          WHERE closed_by IS NOT NULL
                          GROUP BY closed_by")->fetchAll() as $r) {
        $recoverMap[(int)$r['closed_by']] = $r;
    }

    $out = [];
    foreach ($users as $u) {
        $id = (int)$u['id'];
        $out[] = [
            'id'           => $id,
            'name'         => $u['name'],
            'username'     => $u['username'],
            'role'         => $u['role'],
            'closed'       => isset($closedMap[$id]) ? (int)$closedMap[$id]['n'] : 0,
            'avg_close_h'  => isset($closedMap[$id]) ? (float)$closedMap[$id]['avg_min'] / 60.0 : 0,
            'recoveries'   => isset($recoverMap[$id]) ? (int)$recoverMap[$id]['n'] : 0,
            'avg_recover_h'=> isset($recoverMap[$id]) ? (float)$recoverMap[$id]['avg_min'] / 60.0 : 0,
        ];
    }
    return $out;
}