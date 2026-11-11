<?php
require_once __DIR__ . '/includes/functions.php';
$user = require_login();
require_permission($user, 'lab.manage');
$pageTitle = 'Laboratory';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $labId = (int) ($_POST['lab_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $result = trim($_POST['result'] ?? '');

    if ($labId) {
        if ($action === 'receive') {
            $specimen = trim($_POST['specimen'] ?? '');
            $pdo->prepare("UPDATE lab_orders SET status='In Process', specimen=COALESCE(NULLIF(?,''), specimen) WHERE id=? AND status='Menunggu'")
                ->execute([$specimen, $labId]);
            audit_log('RECEIVE_LAB_SPECIMEN', 'lab_orders', $labId, $specimen);
            flash('success','Spesimen diterima dan status disimpan.');
        } elseif ($action === 'verify') {
            if ($result === '') {
                flash('error','Hasil pemeriksaan wajib diisi sebelum validasi.');
                redirect('laboratory.php');
            }
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE lab_orders SET status='Tervalidasi', result=?, verified_by=?, verified_at=NOW() WHERE id=? AND status='In Process'")
                    ->execute([$result,$user['employee_id'],$labId]);
                $pdo->prepare("UPDATE orders o JOIN lab_orders l ON l.order_id=o.id SET o.status='Completed' WHERE l.id=?")
                    ->execute([$labId]);
                $pdo->commit();
                audit_log('VERIFY_LAB_RESULT', 'lab_orders', $labId, 'Hasil laboratorium tervalidasi');
                flash('success','Hasil laboratorium berhasil disimpan dan divalidasi.');
            } catch(Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                flash('error','Hasil laboratorium gagal disimpan.');
            }
        }
    }
    redirect('laboratory.php');
}

$rows = $pdo->query(
    "SELECT l.id, l.test_name, l.specimen, l.status, l.result, l.verified_at,
            o.order_no, o.priority, o.created_at,
            p.full_name AS patient_name, p.medical_record_no,
            d.employee_code AS doctor_code, d.full_name AS doctor_name,
            v.employee_code AS verifier_code, v.full_name AS verifier_name
     FROM lab_orders l
     JOIN orders o ON o.id=l.order_id
     JOIN registrations r ON r.id=o.registration_id
     JOIN patients p ON p.id=r.patient_id
     JOIN employees d ON d.id=o.ordering_employee_id
     LEFT JOIN employees v ON v.id=l.verified_by
     ORDER BY CASE l.status WHEN 'Menunggu' THEN 1 WHEN 'In Process' THEN 2 ELSE 3 END, l.id DESC"
)->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<h1 class="page-title">Laboratory Worklist</h1>
<p class="page-subtitle">Pemeriksaan laboratorium hanya muncul dari order dokter di CPOE dan hasil yang tervalidasi langsung dapat dilihat dokter peminta.</p>
<div class="table-wrap"><table><thead><tr><th>Order</th><th>Pasien</th><th>Pemeriksaan</th><th>Dokter Peminta</th><th>Status / Aksi</th></tr></thead><tbody>
<?php if(!$rows): ?><tr><td colspan="5" class="empty-state">Belum ada order laboratorium.</td></tr><?php endif; ?>
<?php foreach($rows as $r): ?><tr>
<td><b><?= e($r['order_no']) ?></b><br><small><?= e($r['priority']) ?></small></td>
<td><?= e($r['patient_name']) ?><br><small><?= e($r['medical_record_no']) ?></small></td>
<td><?= e($r['test_name']) ?><br><small>Spesimen: <?= e($r['specimen'] ?: 'Belum diisi') ?></small></td>
<td><?= e($r['doctor_code'].' - '.$r['doctor_name']) ?></td>
<td>
  <span class="badge <?= $r['status']==='Tervalidasi'?'badge-green':($r['status']==='In Process'?'badge-blue':'badge-yellow') ?>"><?= e($r['status']) ?></span>
  <?php if($r['status']==='Menunggu'): ?>
    <form method="POST" style="margin-top:8px"><input type="hidden" name="lab_id" value="<?= $r['id'] ?>"><input type="hidden" name="action" value="receive"><input class="input" name="specimen" placeholder="Jenis spesimen" style="margin-bottom:6px" required><button class="btn btn-primary">Terima Spesimen</button></form>
  <?php elseif($r['status']==='In Process'): ?>
    <form method="POST" style="margin-top:8px"><input type="hidden" name="lab_id" value="<?= $r['id'] ?>"><input type="hidden" name="action" value="verify"><textarea class="input" name="result" placeholder="Hasil pemeriksaan" required></textarea><button class="btn btn-success" style="margin-top:6px">Simpan & Validasi</button></form>
  <?php else: ?>
    <div class="result-box" style="margin-top:6px"><?= e($r['result']) ?></div>
    <small><?= e(($r['verifier_code'] ?? '-').' - '.($r['verifier_name'] ?? '-').' · '.($r['verified_at'] ?? '-')) ?></small>
  <?php endif; ?>
</td>
</tr><?php endforeach; ?>
</tbody></table></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
