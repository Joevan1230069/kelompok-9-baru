<?php
require_once __DIR__ . '/includes/functions.php';
$user = require_login();
require_permission($user, 'cpoe.manage');
$pageTitle = 'CPOE / Order Management';
$pdo = db();

[$regWhere, $regParams] = registration_filter($user, 'r');
$regStmt = $pdo->prepare(
    "SELECT r.id, r.registration_no, p.medical_record_no, p.full_name, un.name AS unit_name
     FROM registrations r
     JOIN patients p ON p.id=r.patient_id
     JOIN units un ON un.id=r.unit_id
     WHERE {$regWhere}
     ORDER BY r.id DESC LIMIT 80"
);
$regStmt->execute($regParams);
$registrations = $regStmt->fetchAll();

$medications = $pdo->query("SELECT id, code, name, unit, stock, expiry_date FROM medications WHERE is_active=1 ORDER BY name")->fetchAll();
$doctors = $pdo->query("SELECT e.id, e.employee_code, e.full_name, un.name AS unit_name FROM employees e JOIN roles r ON r.id=e.role_id LEFT JOIN units un ON un.id=e.unit_id WHERE r.code='dokter' AND e.is_active=1 ORDER BY e.full_name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_order'])) {
    if ($user['role_code'] !== 'dokter') {
        flash('error', 'Order klinis baru hanya dapat dibuat melalui akun dokter.');
        redirect('cpoe.php');
    }

    $registrationId = (int) ($_POST['registration_id'] ?? 0);
    $type = $_POST['order_type'] ?? '';
    $detail = trim($_POST['detail'] ?? '');
    $priority = $_POST['priority'] ?? 'Rutin';
    $indication = trim($_POST['indication'] ?? '');
    $medicationId = (int) ($_POST['medication_id'] ?? 0);
    $quantity = max(1, (int) ($_POST['quantity'] ?? 1));
    $targetDoctorId = (int) ($_POST['target_employee_id'] ?? 0);

    $allowedTypes = ['Laboratorium', 'Radiologi', 'Obat', 'Konsultasi', 'Rawat Inap'];
    if (!$registrationId || !in_array($type, $allowedTypes, true)) {
        flash('error', 'Data order belum lengkap.');
        redirect('cpoe.php');
    }

    if (!can_access_registration($user, $registrationId)) {
        flash('error', 'Pasien tersebut tidak terdaftar pada akun dokter ini.');
        redirect('cpoe.php');
    }

    if ($type === 'Obat') {
        $medStmt = $pdo->prepare('SELECT name FROM medications WHERE id=? AND is_active=1 LIMIT 1');
        $medStmt->execute([$medicationId]);
        $medName = $medStmt->fetchColumn();
        if (!$medName) {
            flash('error', 'Pilih obat dari master farmasi.');
            redirect('cpoe.php');
        }
        $detail = (string) $medName . ($detail !== '' ? ' · ' . $detail : '');
    } elseif ($type === 'Konsultasi') {
        if (!$targetDoctorId || $targetDoctorId === (int) $user['employee_id']) {
            flash('error', 'Pilih dokter tujuan konsultasi yang berbeda.');
            redirect('cpoe.php');
        }
        if ($detail === '') {
            $detail = 'Konsultasi klinis';
        }
    } elseif ($detail === '') {
        flash('error', 'Perintah atau pemeriksaan wajib diisi.');
        redirect('cpoe.php');
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "INSERT INTO orders
             (order_no, registration_id, ordering_employee_id, order_type, detail, medication_id, quantity, target_employee_id, clinical_indication, priority, status, created_at)
             VALUES (NULL,?,?,?,?,?,?,?,?,?,?,'Ordered',NOW())"
        );
        $stmt->execute([
            $registrationId,
            $user['employee_id'],
            $type,
            $detail,
            $medicationId ?: null,
            $type === 'Obat' ? $quantity : null,
            $targetDoctorId ?: null,
            $indication,
            $priority,
        ]);
        $orderId = (int) $pdo->lastInsertId();
        $orderNo = 'ORD-' . date('Ymd') . '-' . str_pad((string) $orderId, 4, '0', STR_PAD_LEFT);
        $pdo->prepare('UPDATE orders SET order_no=? WHERE id=?')->execute([$orderNo, $orderId]);

        if ($type === 'Laboratorium') {
            $pdo->prepare("INSERT INTO lab_orders (order_id, test_name, specimen, status) VALUES (?,?,NULL,'Menunggu')")
                ->execute([$orderId, $detail]);
        } elseif ($type === 'Radiologi') {
            $pdo->prepare("INSERT INTO radiology_orders (order_id, exam_name, clinical_indication, status) VALUES (?,?,?,'Menunggu')")
                ->execute([$orderId, $detail, $indication]);
        } elseif ($type === 'Obat') {
            $pdo->prepare("INSERT INTO pharmacy_orders (order_id, medication_id, quantity, status) VALUES (?,?,?,'Menunggu')")
                ->execute([$orderId, $medicationId, $quantity]);
        } elseif ($type === 'Konsultasi') {
            $pdo->prepare("INSERT INTO consultation_orders (order_id, consultant_employee_id, status) VALUES (?,?,'Menunggu')")
                ->execute([$orderId, $targetDoctorId]);
        }

        $pdo->commit();
        audit_log('CREATE_ORDER', 'orders', $orderId, $type . ' - ' . $orderNo);
        flash('success', "$type berhasil diorder. No. Order: $orderNo");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', 'Order gagal disimpan.');
    }
    redirect('cpoe.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['respond_consultation'])) {
    $consultId = (int) ($_POST['consultation_id'] ?? 0);
    $response = trim($_POST['response'] ?? '');
    if ($user['role_code'] !== 'dokter' || !$consultId || $response === '') {
        flash('error', 'Jawaban konsultasi belum lengkap.');
        redirect('cpoe.php');
    }

    $stmt = $pdo->prepare(
        "SELECT co.order_id FROM consultation_orders co
         WHERE co.id=? AND co.consultant_employee_id=? AND co.status='Menunggu' LIMIT 1"
    );
    $stmt->execute([$consultId, $user['employee_id']]);
    $orderId = (int) $stmt->fetchColumn();
    if (!$orderId) {
        flash('error', 'Konsultasi tidak ditemukan atau sudah selesai.');
        redirect('cpoe.php');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE consultation_orders SET status='Selesai', response=?, responded_at=NOW() WHERE id=?")
            ->execute([$response, $consultId]);
        $pdo->prepare("UPDATE orders SET status='Completed' WHERE id=?")->execute([$orderId]);
        $pdo->commit();
        audit_log('RESPOND_CONSULTATION', 'consultation_orders', $consultId, 'Konsultasi diselesaikan');
        flash('success', 'Jawaban konsultasi berhasil disimpan.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', 'Jawaban konsultasi gagal disimpan.');
    }
    redirect('cpoe.php');
}

$orderWhere = '1=1';
$orderParams = [];
if ($user['role_code'] === 'dokter') {
    $orderWhere = '(o.ordering_employee_id=? OR co.consultant_employee_id=?)';
    $orderParams = [(int) $user['employee_id'], (int) $user['employee_id']];
} elseif (!is_privileged_record_viewer($user)) {
    $orderWhere = '1=0';
}

$orderSql = "SELECT o.id, o.order_no, o.order_type, o.detail, o.quantity, o.priority, o.status, o.created_at,
                    p.full_name AS patient_name, p.medical_record_no,
                    e.employee_code, e.full_name AS doctor_name,
                    l.status AS lab_status, l.result AS lab_result, l.verified_at AS lab_verified_at,
                    lv.employee_code AS lab_verifier_code, lv.full_name AS lab_verifier_name,
                    ro.status AS radiology_status, ro.result AS radiology_result,
                    po.status AS pharmacy_status, m.name AS medication_name,
                    co.id AS consultation_id, co.status AS consultation_status, co.response AS consultation_response,
                    ce.full_name AS consultant_name
             FROM orders o
             JOIN registrations r ON r.id=o.registration_id
             JOIN patients p ON p.id=r.patient_id
             JOIN employees e ON e.id=o.ordering_employee_id
             LEFT JOIN lab_orders l ON l.order_id=o.id
             LEFT JOIN employees lv ON lv.id=l.verified_by
             LEFT JOIN radiology_orders ro ON ro.order_id=o.id
             LEFT JOIN pharmacy_orders po ON po.order_id=o.id
             LEFT JOIN medications m ON m.id=o.medication_id
             LEFT JOIN consultation_orders co ON co.order_id=o.id
             LEFT JOIN employees ce ON ce.id=co.consultant_employee_id
             WHERE {$orderWhere}
             ORDER BY o.id DESC LIMIT 40";
$orderStmt = $pdo->prepare($orderSql);
$orderStmt->execute($orderParams);
$orders = $orderStmt->fetchAll();

$incomingStmt = $pdo->prepare(
    "SELECT co.id, co.status, o.order_no, o.detail, o.clinical_indication,
            p.full_name AS patient_name, p.medical_record_no,
            req.employee_code AS requester_code, req.full_name AS requester_name
     FROM consultation_orders co
     JOIN orders o ON o.id=co.order_id
     JOIN registrations r ON r.id=o.registration_id
     JOIN patients p ON p.id=r.patient_id
     JOIN employees req ON req.id=o.ordering_employee_id
     WHERE co.consultant_employee_id=? AND co.status='Menunggu'
     ORDER BY co.id DESC"
);
$incomingStmt->execute([(int) $user['employee_id']]);
$incomingConsultations = $user['role_code'] === 'dokter' ? $incomingStmt->fetchAll() : [];

require __DIR__ . '/includes/header.php';
?>
<h1 class="page-title">CPOE / Order Management</h1>
<p class="page-subtitle">Order dibuat oleh dokter untuk pasien yang menjadi tanggung jawabnya dan diteruskan ke unit pelaksana.</p>

<?php if ($user['role_code'] === 'dokter'): ?>
<div class="grid grid-2">
  <div class="card">
    <h2 class="section-title">Buat Order Dokter</h2>
    <div class="access-note">Pasien pada daftar ini hanya pasien yang terdaftar pada akun <?= e($user['full_name']) ?>.</div>
    <form method="POST" id="cpoeForm">
      <input type="hidden" name="create_order" value="1">
      <div class="field"><label>Pasien / Kunjungan</label><select class="input" name="registration_id" required><option value="">-- Pilih Pasien --</option><?php foreach($registrations as $r): ?><option value="<?= $r['id'] ?>"><?= e($r['registration_no'].' · '.$r['medical_record_no'].' · '.$r['full_name'].' · '.$r['unit_name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Jenis Order</label><select class="input" name="order_type" id="orderType" required><option>Laboratorium</option><option>Radiologi</option><option>Obat</option><option>Konsultasi</option><option>Rawat Inap</option></select></div>

      <div id="medicationFields" class="hidden">
        <div class="field"><label>Obat</label><select class="input" name="medication_id"><option value="">-- Pilih Obat --</option><?php foreach($medications as $med): ?><option value="<?= $med['id'] ?>"><?= e($med['code'].' · '.$med['name'].' · stok '.$med['stock'].' '.$med['unit']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Jumlah</label><input class="input" type="number" name="quantity" value="1" min="1"></div>
      </div>

      <div id="consultationFields" class="hidden">
        <div class="field"><label>Dokter Konsultan</label><select class="input" name="target_employee_id"><option value="">-- Pilih Dokter --</option><?php foreach($doctors as $doctor): if((int)$doctor['id']===(int)$user['employee_id']) continue; ?><option value="<?= $doctor['id'] ?>"><?= e($doctor['employee_code'].' · '.$doctor['full_name'].' · '.($doctor['unit_name'] ?? '-')) ?></option><?php endforeach; ?></select></div>
      </div>

      <div class="field"><label>Perintah / Pemeriksaan / Aturan Pakai</label><input class="input" name="detail" placeholder="Contoh: Darah lengkap / X-Ray Thorax / 3 x 1 sesudah makan"></div>
      <div class="field"><label>Indikasi Klinis</label><textarea class="input" name="indication" rows="3"></textarea></div>
      <div class="field"><label>Prioritas</label><select class="input" name="priority"><option>Rutin</option><option>CITO</option></select></div>
      <button class="btn btn-primary">Kirim Order</button>
    </form>
  </div>

  <div class="card">
    <h2 class="section-title">Konsultasi Masuk</h2>
    <?php if (!$incomingConsultations): ?>
      <div class="empty-state">Tidak ada konsultasi yang menunggu jawaban.</div>
    <?php else: foreach($incomingConsultations as $consult): ?>
      <div style="padding:12px 0;border-bottom:1px solid #edf0f4">
        <b><?= e($consult['patient_name']) ?></b> · <?= e($consult['order_no']) ?><br>
        <small>Dari <?= e($consult['requester_code'].' - '.$consult['requester_name']) ?></small>
        <p class="note"><?= e($consult['detail']) ?><?= $consult['clinical_indication'] ? ' · '.e($consult['clinical_indication']) : '' ?></p>
        <form method="POST">
          <input type="hidden" name="respond_consultation" value="1">
          <input type="hidden" name="consultation_id" value="<?= $consult['id'] ?>">
          <textarea class="input" name="response" rows="3" placeholder="Tulis hasil konsultasi" required></textarea>
          <button class="btn btn-success" style="margin-top:6px">Simpan Jawaban</button>
        </form>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="card" style="margin-top:18px">
  <h2 class="section-title">Riwayat Order</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Order</th><th>Pasien</th><th>Perintah</th><th>Dokter</th><th>Status / Hasil</th></tr></thead>
      <tbody>
      <?php if (!$orders): ?><tr><td colspan="5" class="empty-state">Belum ada order yang dapat ditampilkan.</td></tr><?php endif; ?>
      <?php foreach($orders as $o): ?>
        <tr>
          <td><b><?= e($o['order_no']) ?></b><br><span class="badge badge-blue"><?= e($o['order_type']) ?></span></td>
          <td><?= e($o['patient_name']) ?><br><small><?= e($o['medical_record_no']) ?></small></td>
          <td><?= e($o['detail']) ?><?php if($o['quantity']): ?><br><small>Jumlah: <?= (int)$o['quantity'] ?></small><?php endif; ?><br><small><?= e($o['priority']) ?></small></td>
          <td><?= e($o['employee_code']) ?><br><small><?= e($o['doctor_name']) ?></small></td>
          <td>
            <?php if($o['order_type']==='Laboratorium'): ?>
              <span class="badge <?= $o['lab_status']==='Tervalidasi'?'badge-green':($o['lab_status']==='In Process'?'badge-blue':'badge-yellow') ?>"><?= e($o['lab_status'] ?? 'Menunggu') ?></span>
              <div class="result-box"><?= $o['lab_result'] ? e($o['lab_result']) : 'Hasil belum tersedia' ?></div>
            <?php elseif($o['order_type']==='Radiologi'): ?>
              <span class="badge <?= $o['radiology_status']==='Selesai'?'badge-green':'badge-yellow' ?>"><?= e($o['radiology_status'] ?? 'Menunggu') ?></span>
              <div class="result-box"><?= $o['radiology_result'] ? e($o['radiology_result']) : 'Hasil belum tersedia' ?></div>
            <?php elseif($o['order_type']==='Obat'): ?>
              <span class="badge <?= $o['pharmacy_status']==='Selesai'?'badge-green':'badge-yellow' ?>"><?= e($o['pharmacy_status'] ?? 'Menunggu') ?></span>
            <?php elseif($o['order_type']==='Konsultasi'): ?>
              <span class="badge <?= $o['consultation_status']==='Selesai'?'badge-green':'badge-yellow' ?>"><?= e($o['consultation_status'] ?? 'Menunggu') ?></span><br>
              <small>Konsultan: <?= e($o['consultant_name'] ?? '-') ?></small>
              <?php if($o['consultation_response']): ?><div class="result-box"><?= e($o['consultation_response']) ?></div><?php endif; ?>
            <?php else: ?>
              <span class="badge badge-blue"><?= e($o['status']) ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
  const orderType = document.getElementById('orderType');
  const medicationFields = document.getElementById('medicationFields');
  const consultationFields = document.getElementById('consultationFields');

  function updateOrderFields() {
    if (!orderType) return;
    medicationFields?.classList.toggle('hidden', orderType.value !== 'Obat');
    consultationFields?.classList.toggle('hidden', orderType.value !== 'Konsultasi');
  }

  orderType?.addEventListener('change', updateOrderFields);
  updateOrderFields();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
