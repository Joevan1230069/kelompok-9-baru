<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/schema_updates.php';
$ok=true;$message='';$tables=[];$counts=[];
try{
  $pdo=db();
  ensure_final_schema($pdo);
  $tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
  $counts=[
    'Users'=>(int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_active=1')->fetchColumn(),
    'Employees'=>(int)$pdo->query('SELECT COUNT(*) FROM employees WHERE is_active=1')->fetchColumn(),
    'Patients'=>(int)$pdo->query('SELECT COUNT(*) FROM patients')->fetchColumn(),
    'Medications'=>(int)$pdo->query('SELECT COUNT(*) FROM medications')->fetchColumn(),
  ];
  $message='Koneksi database berhasil dan skema final siap digunakan.';
}catch(Throwable $e){$ok=false;$message=$e->getMessage();}
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><title>Setup Check</title><style>body{font-family:Arial;background:#f4f7fc;padding:35px;color:#172033}.box{max-width:780px;margin:auto;background:white;padding:24px;border-radius:14px;border:1px solid #e6eaf0}.ok{color:#15803d}.bad{color:#b91c1c}code{background:#f1f5f9;padding:2px 5px;border-radius:5px}table{width:100%;border-collapse:collapse}td{padding:8px;border-bottom:1px solid #eee}</style></head><body><div class="box"><h2>RS Permata Asri — XAMPP Setup Check</h2><p class="<?= $ok?'ok':'bad' ?>"><b><?= htmlspecialchars($message) ?></b></p><?php if($ok): ?><p>Database: <code><?= DB_NAME ?></code></p><p>Jumlah tabel: <b><?= count($tables) ?></b></p><table><?php foreach($counts as $k=>$v): ?><tr><td><?= htmlspecialchars($k) ?></td><td><b><?= $v ?></b></td></tr><?php endforeach; ?></table><p><a href="login.php">Buka Login SIMRS</a> · <a href="patient/">Portal Pasien</a></p><?php else: ?><p>Pastikan Apache dan MySQL aktif serta pengaturan <code>config/database.php</code> sesuai.</p><?php endif; ?></div></body></html>
