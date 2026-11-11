<?php
require_once __DIR__ . '/includes/functions.php';
$user = require_login();
require_permission($user, 'inpatient.manage');
$pageTitle = 'Rawat Inap';
$pdo = db();

$pendingOrders = $pdo->query(
    "SELECT o.id AS order_id, o.order_no, o.detail, o.priority, r.id AS registration_id,
            p.full_name, p.medical_record_no, d.employee_code AS doctor_code, d.full_name AS doctor_name
     FROM orders o
     JOIN registrations r ON r.id=o.registration_id
     JOIN patients p ON p.id=r.patient_id
     JOIN employees d ON d.id=o.ordering_employee_id
     WHERE o.order_type='Rawat Inap' AND o.status='Ordered'
     ORDER BY o.id"
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admit_patient'])) {
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $room = trim($_POST['room'] ?? '');
    $bed = trim($_POST['bed'] ?? '');

    $stmt = $pdo->prepare("SELECT registration_id FROM orders WHERE id=? AND order_type='Rawat Inap' AND status='Ordered' LIMIT 1");
    $stmt->execute([$orderId]);
    $registrationId = (int) $stmt->fetchColumn();

    if (!$registrationId || $room === '' || $bed === '') {
        flash('error', 'Pilih order rawat inap, ruang, dan bed.');
        redirect('inpatient.php');
    }

    try {
        $pdo->beginTransaction();
        $existing = $pdo->prepare("SELECT id FROM inpatient_admissions WHERE registration_id=? AND status='Dirawat' LIMIT 1");
        $existing->execute([$registrationId]);
        if ($existing->fetchColumn()) {
            throw new RuntimeException('Pasien sudah tercatat sedang dirawat.');
        }

        $pdo->prepare("INSERT INTO inpatient_admissions (registration_id,ordered_by,room,bed,status,admitted_at) VALUES (?,?,?,?,'Dirawat',NOW())")
            ->execute([$registrationId,$user['employee_id'],$room,$bed]);
        $admissionId = (int) $pdo->lastInsertId();
        $pdo->prepare("UPDATE registrations SET status='Rawat Inap' WHERE id=?")->execute([$registrationId]);
        $pdo->prepare("UPDATE orders SET status='Completed' WHERE id=?")->execute([$orderId]);
        $pdo->commit();
        audit_log('ADMIT_INPATIENT', 'inpatient_admissions', $admissionId, $room . ' / ' . $bed);
        flash('success','Pasien berhasil ditempatkan ke rawat inap.');
    } catch(Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error',$e->getMessage());
    }
    redirect('inpatient.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['discharge_patient'])) {
    $admissionId = (int) ($_POST['admission_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT registration_id FROM inpatient_admissions WHERE id=? AND status='Dirawat' LIMIT 1");
    $stmt->execute([$admissionId]);
    $registrationId = (int) $stmt->fetchColumn();
    if ($registrationId) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE inpatient_admissions SET status='Pulang' WHERE id=?")->execute([$admissionId]);
            $pdo->prepare("UPDATE registrations SET status='Selesai' WHERE id=?")->execute([$registrationId]);
            $pdo->commit();
            audit_log('DISCHARGE_INPATIENT', 'inpatient_admissions', $admissionId, 'Pasien pulang');
            flash('success','Status pasien rawat inap diubah menjadi Pulang.');
        } catch(Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error','Status pasien belum dapat diperbarui.');
        }
    }
    redirect('inpatient.php');
}

$rows = $pdo->query(
    "SELECT ia.id, ia.room, ia.bed, ia.status, ia.admitted_at,
            p.full_name, p.medical_record_no,
            e.employee_code, e.full_name AS handled_by
     FROM inpatient_admissions ia
     JOIN registrations r ON r.id=ia.registration_id
     JOIN patients p ON p.id=r.patient_id
     JOIN employees e ON e.id=ia.ordered_by
     ORDER BY ia.id DESC"
)->fetchAll();

require __DIR__.'/includes/header.php';
?>
<h1 class="page-title">Rawat Inap</h1>
<p class="page-subtitle">Admisi rawat inap diproses dari perintah dokter di CPOE, lalu ruang dan bed dicatat ke database.</p>

<div class="grid grid-2">
  <div class="card">
    <h2 class="section-title">Permintaan Rawat Inap</h2>
    <?php if(!$pendingOrders): ?><div class="empty-state">Tidak ada order rawat inap yang menunggu.</div><?php else: ?>
    <form method="POST">
      <input type="hidden" name="admit_patient" value="1">
      <div class="field"><label>Order Dokter</label><select class="input" name="order_id" required><option value="">-- Pilih Order --</option><?php foreach($pendingOrders as $o): ?><option value="<?= $o['order_id'] ?>"><?= e($o['order_no'].' · '.$o['medical_record_no'].' · '.$o['full_name'].' · '.$o['doctor_code'].' - '.$o['doctor_name']) ?></option><?php endforeach; ?></select></div>
      <div class="grid grid-2"><div class="field"><label>Ruang</label><input class="input" name="room" placeholder="Melati / Anggrek" required></div><div class="field"><label>Bed</label><input class="input" name="bed" placeholder="A-101 / Bed 01" required></div></div>
      <button class="btn btn-primary">Terima Rawat Inap</button>
    </form>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2 class="section-title">Pasien Rawat Inap</h2>
    <div class="table-wrap"><table><thead><tr><th>Pasien</th><th>Ruang / Bed</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
    <?php if(!$rows): ?><tr><td colspan="4" class="empty-state">Belum ada pasien rawat inap.</td></tr><?php endif; ?>
    <?php foreach($rows as $r): ?><tr><td><b><?= e($r['full_name']) ?></b><br><small><?= e($r['medical_record_no']) ?></small></td><td><?= e($r['room'].' · '.$r['bed']) ?><br><small><?= e($r['admitted_at']) ?></small></td><td><span class="badge <?= $r['status']==='Dirawat'?'badge-green':'badge-blue' ?>"><?= e($r['status']) ?></span><br><small>oleh <?= e($r['employee_code'].' - '.$r['handled_by']) ?></small></td><td><?php if($r['status']==='Dirawat'): ?><form method="POST" onsubmit="return confirm('Tandai pasien sudah pulang?')"><input type="hidden" name="admission_id" value="<?= $r['id'] ?>"><button class="btn btn-secondary btn-sm" name="discharge_patient">Pulang</button></form><?php else: ?>-<?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </div>
</div>
<?php require __DIR__.'/includes/footer.php'; ?>
