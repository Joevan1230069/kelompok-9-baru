<?php

declare(strict_types=1);

function envv(string $key, string $fallback = ''): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $fallback : $value;
}

$done = false;
$message = '';
$details = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = envv('MYSQLHOST', envv('DB_HOST', ''));
    $port = (int) envv('MYSQLPORT', envv('DB_PORT', '3306'));
    $user = envv('MYSQLUSER', envv('DB_USER', ''));
    $pass = envv('MYSQLPASSWORD', envv('DB_PASS', ''));
    $name = envv('MYSQLDATABASE', envv('DB_NAME', ''));

    try {
        if ($host === '' || $user === '' || $name === '') {
            throw new RuntimeException('Environment MySQL Railway belum lengkap.');
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $mysqli = new mysqli($host, $user, $pass, $name, $port);
        $mysqli->set_charset('utf8mb4');

        $sqlFile = __DIR__ . '/database/simrs_permata_asri.sql';
        if (!is_file($sqlFile)) {
            throw new RuntimeException('File database/simrs_permata_asri.sql tidak ditemukan.');
        }

        $sql = file_get_contents($sqlFile);
        if ($sql === false) {
            throw new RuntimeException('File SQL tidak dapat dibaca.');
        }

        // Railway sudah menyediakan database. Jangan membuat/mengganti database lain.
        $sql = preg_replace('/^DROP DATABASE[^;]*;\s*/mi', '', $sql) ?? $sql;
        $sql = preg_replace('/^CREATE DATABASE[^;]*;\s*/mi', '', $sql) ?? $sql;
        $sql = preg_replace('/^USE\s+`?simrs_permata_asri`?\s*;\s*/mi', '', $sql) ?? $sql;

        if (!$mysqli->multi_query($sql)) {
            throw new RuntimeException('Import SQL gagal: ' . $mysqli->error);
        }

        do {
            if ($result = $mysqli->store_result()) {
                $result->free();
            }
        } while ($mysqli->more_results() && $mysqli->next_result());

        $mysqli->close();

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        require_once __DIR__ . '/includes/schema_updates.php';
        ensure_final_schema($pdo);

        $done = true;
        $message = 'Database Railway berhasil diinisialisasi dan update final sudah diterapkan.';
        $details = [
            'Database aktif' => $name,
            'Jumlah tabel' => (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn(),
            'Pasien' => (int) $pdo->query('SELECT COUNT(*) FROM patients')->fetchColumn(),
            'Pegawai' => (int) $pdo->query('SELECT COUNT(*) FROM employees')->fetchColumn(),
            'User aktif' => (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_active=1')->fetchColumn(),
            'Dokter aktif' => (int) $pdo->query("SELECT COUNT(*) FROM employees e JOIN roles r ON r.id=e.role_id WHERE r.code='dokter' AND e.is_active=1")->fetchColumn(),
        ];
    } catch (Throwable $e) {
        $message = 'Setup gagal: ' . $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Railway Database Setup - RS Permata Asri</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f4f7fc;color:#172033;padding:32px}
        .box{max-width:760px;margin:auto;background:#fff;border:1px solid #e5eaf2;border-radius:14px;padding:26px;box-shadow:0 8px 28px rgba(15,23,42,.06)}
        .ok{color:#15803d}.bad{color:#b91c1c}
        button,.btn{display:inline-block;background:#2563eb;color:white;border:0;border-radius:8px;padding:11px 18px;text-decoration:none;cursor:pointer}
        table{width:100%;border-collapse:collapse;margin:18px 0}td{padding:9px;border-bottom:1px solid #e5eaf2}
        code{background:#eef2f7;padding:2px 5px;border-radius:5px}
        .warn{background:#fff7ed;border:1px solid #fed7aa;padding:12px;border-radius:9px;color:#9a3412}
    </style>
</head>
<body>
<div class="box">
    <h2>Setup Database Railway — RS Permata Asri</h2>
    <p>Halaman ini mengimpor schema awal ke database MySQL Railway yang sudah terhubung, lalu menjalankan update schema final.</p>

    <?php if ($message): ?>
        <p class="<?= $done ? 'ok' : 'bad' ?>"><b><?= htmlspecialchars($message) ?></b></p>
    <?php endif; ?>

    <?php if ($done): ?>
        <table>
            <?php foreach ($details as $label => $value): ?>
                <tr><td><?= htmlspecialchars((string)$label) ?></td><td><b><?= htmlspecialchars((string)$value) ?></b></td></tr>
            <?php endforeach; ?>
        </table>
        <p><a class="btn" href="login.php">Buka Staff Portal</a> <a class="btn" href="patient/">Buka Patient Portal</a></p>
        <p class="warn"><b>Penting:</b> setelah setup berhasil, hapus file <code>railway_setup.php</code> dari repository GitHub lalu commit perubahan.</p>
    <?php else: ?>
        <form method="post">
            <button type="submit">Inisialisasi Database Railway</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
