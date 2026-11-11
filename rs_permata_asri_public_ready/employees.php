<?php
require_once __DIR__ . '/includes/functions.php';
$user = require_login();
require_permission($user, 'employees.manage');
$pageTitle = 'Data Pegawai';

$roles = db()->query('SELECT id, name FROM roles ORDER BY name')->fetchAll();
$units = db()->query('SELECT id, code, name FROM units ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['employee_code'] ?? '');
    $name = trim($_POST['full_name'] ?? '');
    $profession = trim($_POST['profession'] ?? '');
    $roleId = (int) ($_POST['role_id'] ?? 0);
    $unitId = (int) ($_POST['unit_id'] ?? 0);

    if ($code === '' || $name === '' || $profession === '' || !$roleId) {
        flash('error', 'ID pegawai, nama, profesi, dan role wajib diisi.');
        redirect('employees.php');
    }

    try {
        $stmt = db()->prepare('INSERT INTO employees (employee_code, full_name, profession, role_id, unit_id, is_active) VALUES (?, ?, ?, ?, ?, 1)');
        $stmt->execute([$code, $name, $profession, $roleId, $unitId ?: null]);
        flash('success', 'Data pegawai berhasil ditambahkan.');
    } catch (PDOException $e) {
        flash('error', 'ID pegawai sudah digunakan atau data tidak valid.');
    }
    redirect('employees.php');
}

$rows = db()->query(
    'SELECT e.employee_code, e.full_name, e.profession, r.name AS role_name, un.name AS unit_name
     FROM employees e
     JOIN roles r ON r.id = e.role_id
     LEFT JOIN units un ON un.id = e.unit_id
     ORDER BY e.id DESC'
)->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<h1 class="page-title">Data Pegawai</h1>
<p class="page-subtitle">Data dokter sekarang menjadi data pegawai dengan ID, profesi, role, dan unit kerja.</p>
<div class="grid grid-2">
  <div class="card"><h2 class="section-title">Tambah Pegawai</h2><form method="POST">
    <div class="field"><label>ID / Kode Pegawai</label><input class="input" name="employee_code" placeholder="PEG-DOK-003" required></div>
    <div class="field"><label>Nama Pegawai</label><input class="input" name="full_name" required></div>
    <div class="field"><label>Profesi</label><input class="input" name="profession" placeholder="Dokter, Perawat, ATLM, Radiografer" required></div>
    <div class="field"><label>Role</label><select class="input" name="role_id" required><option value="">-- Pilih Role --</option><?php foreach($roles as $r): ?><option value="<?= $r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Unit</label><select class="input" name="unit_id"><option value="">-- Pilih Unit --</option><?php foreach($units as $u): ?><option value="<?= $u['id'] ?>"><?= e($u['code'].' - '.$u['name']) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-primary">Simpan Pegawai</button>
  </form></div>
  <div class="table-wrap"><table><thead><tr><th>ID Pegawai</th><th>Nama</th><th>Profesi</th><th>Role / Unit</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><b><?= e($r['employee_code']) ?></b></td><td><?= e($r['full_name']) ?></td><td><?= e($r['profession']) ?></td><td><?= e($r['role_name']) ?><br><small><?= e($r['unit_name'] ?? '-') ?></small></td></tr><?php endforeach; ?></tbody></table></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
