<?php
require_once __DIR__ . '/includes/functions.php';
$user = require_login();
require_permission($user, 'radiology.manage');
$pageTitle = 'Radiology';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['radiology_id'] ?? 0);
    $result = trim($_POST['result'] ?? '');
    if ($id && $result !== '') {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE radiology_orders SET status='Selesai', result=?, verified_by=?, verified_at=NOW() WHERE id=? AND status<>'Selesai'")
                ->execute([$result,$user['employee_id'],$id]);
            $pdo->prepare("UPDATE orders o JOIN radiology_orders ro ON ro.order_id=o.id SET o.status='Completed' WHERE ro.id=?")
                ->execute([$id]);
            $pdo->commit();
            audit_log('SAVE_RADIOLOGY_RESULT', 'radiology_orders', $id, 'Hasil radiologi disimpan');
            flash('success','Hasil radiologi berhasil disimpan dan dapat dilihat dokter peminta.');
        } catch(Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error','Hasil radiologi gagal disimpan.');
        }
    }
    redirect('radiology.php');
}

$rows = $pdo->query(
    "SELECT ro.id, ro.exam_name, ro.clinical_indication, ro.status, ro.result, ro.verified_at,
            o.order_no, p.full_name AS patient_name, p.medical_record_no,
            d.employee_code AS doctor_code, d.full_name AS doctor_name,
            v.employee_code AS verifier_code, v.full_name AS verifier_name
     FROM radiology_orders ro
     JOIN orders o ON o.id=ro.order_id
     JOIN registrations r ON r.id=o.registration_id
     JOIN patients p ON p.id=r.patient_id
     JOIN employees d ON d.id=o.ordering_employee_id
     LEFT JOIN employees v ON v.id=ro.verified_by
     ORDER BY CASE ro.status WHEN 'Menunggu' THEN 1 ELSE 2 END, ro.id DESC"
)->fetchAll();
require __DIR__ . '/includes/header.php';
?>
<h1 class="page-title">Radiology Worklist</h1>
<p class="page-subtitle">Pemeriksaan radiologi berasal dari perintah dokter dan hasil yang selesai kembali ke dashboard/EMR dokter peminta.</p>
<div class="table-wrap"><table><thead><tr><th>Order</th><th>Pasien</th><th>Pemeriksaan</th><th>Dokter Peminta</th><th>Hasil</th></tr></thead><tbody>
<?php if(!$rows): ?><tr><td colspan="5" class="empty-state">Belum ada order radiologi.</td></tr><?php endif; ?>
<?php foreach($rows as $r): ?><tr><td><b><?= e($r['order_no']) ?></b></td><td><?= e($r['patient_name']) ?><br><small><?= e($r['medical_record_no']) ?></small></td><td><?= e($r['exam_name']) ?><br><small><?= e($r['clinical_indication']) ?></small></td><td><?= e($r['doctor_code'].' - '.$r['doctor_name']) ?></td><td><?php if($r['status']==='Selesai'): ?><span class="badge badge-green">Selesai</span><div class="result-box"><?= e($r['result']) ?></div><small><?= e(($r['verifier_code'] ?? '-').' - '.($r['verifier_name'] ?? '-').' · '.($r['verified_at'] ?? '-')) ?></small><?php else: ?><form method="POST"><input type="hidden" name="radiology_id" value="<?= $r['id'] ?>"><textarea class="input" name="result" placeholder="Hasil / ekspertise singkat" required></textarea><button class="btn btn-primary" style="margin-top:6px">Simpan Hasil</button></form><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
