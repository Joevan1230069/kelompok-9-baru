<?php
require_once __DIR__ . '/includes/functions.php';
$user = require_login();
require_permission($user, 'users.manage');
$pageTitle = 'User Registration';

$employees = db()->query(
    'SELECT e.id, e.employee_code, e.full_name, r.name AS role_name
     FROM employees e
     JOIN roles r ON r.id = e.role_id
     LEFT JOIN users u ON u.employee_id = e.id
     WHERE e.is_active = 1 AND u.id IS NULL
     ORDER BY e.full_name'
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $employeeId = (int) ($_POST['employee_id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$employeeId || $username === '' || strlen($password) < 6) {
        flash('error', 'Data belum lengkap. Password minimal 6 karakter.');
        redirect('user_registration.php');
    }

    $check = db()->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    $check->execute([$username]);
    if ((int) $check->fetchColumn() > 0) {
        flash('error', 'Username sudah digunakan.');
        redirect('user_registration.php');
    }

    $stmt = db()->prepare('INSERT INTO users (employee_id, username, password_hash, is_active) VALUES (?, ?, ?, 1)');
    $stmt->execute([$employeeId, $username, password_hash($password, PASSWORD_DEFAULT)]);
    flash('success', 'User baru berhasil diregistrasikan.');
    redirect('user_registration.php');
}

$accounts = db()->query(
    'SELECT u.username, u.is_active, e.employee_code, e.full_name, r.name AS role_name
     FROM users u
     JOIN employees e ON e.id = u.employee_id
     JOIN roles r ON r.id = e.role_id
     ORDER BY u.id DESC'
)->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<h1 class="page-title">User Registration</h1>
<p class="page-subtitle">Mendaftarkan akun login berdasarkan data pegawai dan role yang sudah ada.</p>
<div class="grid grid-2">
  <div class="card">
    <h2 class="section-title">Tambah User</h2>
    <form method="POST">
      <div class="field"><label>Pegawai</label><select class="input" name="employee_id" required><option value="">-- Pilih Pegawai --</option><?php foreach($employees as $e): ?><option value="<?= $e['id'] ?>"><?= e($e['employee_code'].' - '.$e['full_name'].' ('.$e['role_name'].')') ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Username</label><input class="input" name="username" required></div>
      <div class="field"><label>Password</label><input class="input" type="password" name="password" minlength="6" required></div>
      <button class="btn btn-primary">Simpan User</button>
    </form>
  </div>
  <div class="table-wrap">
    <table><thead><tr><th>Username</th><th>ID Pegawai</th><th>Nama</th><th>Role</th></tr></thead><tbody>
      <?php foreach($accounts as $a): ?><tr><td><?= e($a['username']) ?></td><td><?= e($a['employee_code']) ?></td><td><?= e($a['full_name']) ?></td><td><span class="badge badge-blue"><?= e($a['role_name']) ?></span></td></tr><?php endforeach; ?>
    </tbody></table>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
