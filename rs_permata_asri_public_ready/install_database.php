<?php
// Installer database lokal untuk XAMPP.
// Jalankan sekali melalui browser, lalu hapus/rename file ini bila sudah selesai.

$host = '127.0.0.1';
$user = 'root';
$pass = '';
$sqlFile = __DIR__ . '/database/simrs_permata_asri.sql';

$success = false;
$message = '';
$tables = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $mysqli = new mysqli($host, $user, $pass);
        $mysqli->set_charset('utf8mb4');

        $sql = file_get_contents($sqlFile);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException('File SQL tidak ditemukan atau kosong.');
        }

        if (!$mysqli->multi_query($sql)) {
            throw new RuntimeException('Import database gagal dijalankan.');
        }

        do {
            if ($result = $mysqli->store_result()) {
                $result->free();
            }
        } while ($mysqli->more_results() && $mysqli->next_result());

        $mysqli->select_db('simrs_permata_asri');

        require_once __DIR__ . '/config/database.php';
        require_once __DIR__ . '/includes/schema_updates.php';
        ensure_final_schema(db());

        $result = $mysqli->query('SHOW TABLES');
        while ($row = $result->fetch_row()) {
            $tables[] = $row[0];
        }

        $success = count($tables) > 0;
        $message = $success
            ? 'Database berhasil dibuat dan diisi.'
            : 'Database terbuat, tetapi tabel belum terdeteksi.';
    } catch (Throwable $e) {
        $message = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Install Database - RS Permata Asri</title>
    <style>
        body { font-family: Arial, sans-serif; background:#f4f7fc; color:#172033; padding:32px; }
        .box { max-width:780px; margin:auto; background:#fff; border:1px solid #e6eaf0; border-radius:14px; padding:24px; }
        .ok { color:#15803d; }
        .bad { color:#b91c1c; }
        button { background:#2563eb; color:#fff; border:0; border-radius:8px; padding:11px 18px; cursor:pointer; }
        code { background:#eef2f7; padding:2px 5px; border-radius:5px; }
        ul { columns:2; }
    </style>
</head>
<body>
<div class="box">
    <h2>Database Installer — RS Permata Asri</h2>
    <p>Installer ini akan membuat ulang database <code>simrs_permata_asri</code> beserta tabel dan data awal.</p>

    <?php if ($message !== ''): ?>
        <p class="<?= $success ? 'ok' : 'bad' ?>"><b><?= htmlspecialchars($message) ?></b></p>
    <?php endif; ?>

    <?php if ($success): ?>
        <p>Jumlah tabel: <b><?= count($tables) ?></b></p>
        <ul>
            <?php foreach ($tables as $table): ?>
                <li><?= htmlspecialchars($table) ?></li>
            <?php endforeach; ?>
        </ul>
        <p><a href="setup_check.php">Buka Setup Check</a> · <a href="login.php">Buka Login</a></p>
    <?php else: ?>
        <form method="post">
            <button type="submit">Buat / Reset Database</button>
        </form>
        <p style="font-size:13px;color:#667085;margin-top:16px;">
            Jika user <code>root</code> MySQL kamu memakai password, edit variabel <code>$pass</code> di file ini terlebih dahulu.
        </p>
    <?php endif; ?>
</div>
</body>
</html>
