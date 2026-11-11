<?php
require_once __DIR__ . '/includes/functions.php';
$user = require_login();
require_permission($user, 'audit.view');
$pageTitle = 'Audit Trail';

$rows = db()->query(
    "SELECT a.*, u.username, e.employee_code, e.full_name
     FROM audit_logs a
     LEFT JOIN users u ON u.id=a.user_id
     LEFT JOIN employees e ON e.id=u.employee_id
     ORDER BY a.id DESC LIMIT 200"
)->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<h1 class="page-title">Audit Trail</h1>
<p class="page-subtitle">Jejak aktivitas penting seperti login, perubahan EMR, order dokter, validasi hasil, dan dispensing farmasi.</p>
<div class="table-wrap"><table><thead><tr><th>Waktu</th><th>User</th><th>Aktivitas</th><th>Objek</th><th>Keterangan</th></tr></thead><tbody>
<?php if(!$rows): ?><tr><td colspan="5" class="empty-state">Belum ada log aktivitas.</td></tr><?php endif; ?>
<?php foreach($rows as $row): ?><tr><td><?= e($row['created_at']) ?></td><td><?= e(($row['employee_code'] ?? '-').' - '.($row['full_name'] ?? $row['username'] ?? 'System')) ?></td><td><b><?= e($row['action']) ?></b></td><td><?= e(($row['entity_type'] ?? '-').($row['entity_id'] ? ' #'.$row['entity_id'] : '')) ?></td><td><?= e($row['details'] ?? '-') ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
