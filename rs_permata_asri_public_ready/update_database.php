<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/schema_updates.php';

$success = false;
$message = '';
$summary = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = db();
        ensure_final_schema($pdo);
        $success = true;
        $message = 'Update database final berhasil. Data lama tidak dihapus.';
        $summary = [
            'Tabel database' => (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()")->fetchColumn(),
            'Dokter aktif' => (int) $pdo->query("SELECT COUNT(*) FROM employees e JOIN roles r ON r.id=e.role_id WHERE r.code='dokter' AND e.is_active=1")->fetchColumn(),
            'Akun pegawai aktif' => (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_active=1')->fetchColumn(),
            'Poli aktif' => (int) $pdo->query("SELECT COUNT(*) FROM units WHERE unit_type='POLI'")->fetchColumn(),
            'Jadwal dokter' => (int) $pdo->query('SELECT COUNT(*) FROM doctor_schedules')->fetchColumn(),
            'Master obat' => (int) $pdo->query('SELECT COUNT(*) FROM medications WHERE is_active=1')->fetchColumn(),
        ];
    } catch (Throwable $e) {
        $message = 'Update gagal: ' . $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Update Database - RS Permata Asri</title><style>body{font-family:Arial,sans-serif;background:#f4f7fc;color:#172033;padding:32px}.box{max-width:760px;margin:auto;background:#fff;border:1px solid #e6eaf0;border-radius:14px;padding:24px}.ok{color:#15803d}.bad{color:#b91c1c}button,.btn{display:inline-block;background:#2563eb;color:#fff;border:0;border-radius:8px;padding:11px 18px;cursor:pointer;text-decoration:none}table{width:100%;border-collapse:collapse;margin:16px 0}td{padding:9px;border-bottom:1px solid #e6eaf0}code{background:#eef2f7;padding:2px 5px;border-radius:5px}</style></head>
<body><div class="box"><h2>Update Database Final — RS Permata Asri</h2><p>Gunakan updater ini jika database versi sebelumnya sudah ada. Data pasien, registrasi, EMR, dan order lama tidak dihapus.</p>
<?php if($message): ?><p class="<?= $success?'ok':'bad' ?>"><b><?= htmlspecialchars($message) ?></b></p><?php endif; ?>
<?php if($success): ?><table><?php foreach($summary as $label=>$value): ?><tr><td><?= htmlspecialchars($label) ?></td><td><b><?= (int)$value ?></b></td></tr><?php endforeach; ?></table><p><a class="btn" href="setup_check.php">Cek Setup</a> <a class="btn" href="login.php">Buka Login</a></p><?php else: ?><form method="post"><button type="submit">Jalankan Update Final</button></form><?php endif; ?></div></body></html>
