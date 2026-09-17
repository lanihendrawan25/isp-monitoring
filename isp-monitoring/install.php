<?php
require_once __DIR__ . '/lib/db.php';

header('Content-Type: text/html; charset=utf-8');
$dbName = DB_NAME;

echo "<!DOCTYPE html><html lang='id'><head><meta charset='utf-8'><title>Instalasi - Monitoring ISP</title>";
echo "<style>body{font-family:'Segoe UI',Arial,sans-serif;max-width:760px;margin:40px auto;line-height:1.6;color:#333}code{background:#f5f5f5;padding:2px 6px;border-radius:4px}.ok{color:green;font-weight:bold}.err{color:red;font-weight:bold}li{margin:4px 0}pre{background:#f5f5f5;padding:10px;border-radius:6px;overflow-x:auto}</style></head><body><h1>Instalasi Aplikasi Monitoring ISP</h1>";

$ok = true;
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "<p class='ok'>[OK] Database <code>$dbName</code> dibuat/tersedia.</p>";
} catch (Exception $ex) {
    echo "<p class='err'>[GAGAL] Koneksi database: " . e($ex->getMessage()) . "</p>";
    $ok = false;
}

if ($ok) {
    $sql = file_get_contents(__DIR__ . '/database.sql');
    $db  = db();
    try {
        $db->exec($sql);
        echo "<p class='ok'>[OK] Tabel berhasil dibuat/diupdate (users, clients, vendors, tickets, ticket_clients, ticket_updates, ping_logs).</p>";
    } catch (Exception $ex) {
        echo "<p class='err'>[GAGAL] Skema database: " . e($ex->getMessage()) . "</p>";
        $ok = false;
    }

    if ($ok) {
        // Migrasi foreign key ticket_clients.client_id -> ON DELETE SET NULL
        // (riwayat tiket tetap utuh walau client otomatis dihapus saat tiket solved)
        foreach ([
            "ALTER TABLE ticket_clients DROP FOREIGN KEY fk_tc_client",
            "ALTER TABLE ticket_clients MODIFY COLUMN client_id INT DEFAULT NULL",
            "ALTER TABLE ticket_clients ADD CONSTRAINT fk_tc_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL",
        ] as $stmt) {
            try { $db->exec($stmt); } catch (Exception $ex) { /* sudah sesuai, dilewati */ }
        }
        echo "<p class='ok'>[OK] Relasi client-tiket disesuaikan (ON DELETE SET NULL).</p>";

        $c = $db->query('SELECT COUNT(*) AS n FROM users')->fetch();
        if ((int)$c['n'] === 0) {
            $hashes = [
                'admin'  => password_hash('admin123', PASSWORD_DEFAULT),
                'staff1' => password_hash('staff123', PASSWORD_DEFAULT),
                'staff2' => password_hash('staff123', PASSWORD_DEFAULT),
                'staff3' => password_hash('staff123', PASSWORD_DEFAULT),
            ];
            $ins = $db->prepare('INSERT INTO users (username, password, name, role) VALUES (?,?,?,?)');
            $ins->execute(['admin',  $hashes['admin'],  'Administrator', 'admin']);
            $ins->execute(['staff1', $hashes['staff1'], 'Karyawan 1', 'staff']);
            $ins->execute(['staff2', $hashes['staff2'], 'Karyawan 2', 'staff']);
            $ins->execute(['staff3', $hashes['staff3'], 'Karyawan 3', 'staff']);
            echo "<p class='ok'>[OK] Akun default dibuat.</p>";
        } else {
            echo "<p>[INFO] Akun sudah ada, dilewati.</p>";
        }

        $cc = $db->query('SELECT COUNT(*) AS n FROM clients')->fetch();
        if ((int)$cc['n'] === 0) {
            $insC = $db->prepare('INSERT INTO clients (name, ip_address, description) VALUES (?,?,?)');
            $insC->execute(['Router Core', '192.168.1.1', 'Gateway utama']);
            $insC->execute(['Client Rumah 1', '192.168.1.10', 'Paket 20 Mbps']);
            $insC->execute(['Client Rumah 2', '192.168.1.20', 'Paket 50 Mbps']);
            $insC->execute(['Client RT/RW Net', '192.168.1.30', 'Paket 100 Mbps']);
            echo "<p class='ok'>[OK] Contoh client dibuat.</p>";
        } else {
            echo "<p>[INFO] Data client sudah ada, dilewati.</p>";
        }

        $vc = $db->query('SELECT COUNT(*) AS n FROM vendors')->fetch();
        if ((int)$vc['n'] === 0) {
            $insV = $db->prepare('INSERT INTO vendors (name, contact_person, phone, email, description) VALUES (?,?,?,?,?)');
            $insV->execute(['Vendor Fiber Optik A', 'Budi', '0812-0000-0001', 'noc@vendorA.co.id', 'Penyedia backbone fiber']);
            $insV->execute(['Vendor Perangkat B', 'Siti', '0812-0000-0002', 'support@vendorB.co.id', 'Suplier router & OLT']);
            echo "<p class='ok'>[OK] Contoh vendor dibuat.</p>";
        } else {
            echo "<p>[INFO] Data vendor sudah ada, dilewati.</p>";
        }
    }
}

echo "<h2>Akun Default</h2><ul><li><code>admin / admin123</code> (Administrator)</li><li><code>staff1 / staff123</code>, <code>staff2 / staff123</code>, <code>staff3 / staff123</code> (Karyawan)</li></ul>";
echo "<p><a href='login.php'><strong>&raquo; Silakan ubah password setelah login.</strong></a> &nbsp;|&nbsp; Hapus file <code>install.php</code> setelah berhasil.</p>";
echo "</body></html>";