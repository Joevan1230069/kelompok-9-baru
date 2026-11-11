<?php
require_once __DIR__ . '/includes/functions.php';
$user = require_login();
require_permission($user, 'registration.manage');
$pageTitle = 'Registrasi Pasien Online';
$pdo = db();
ensure_progress03_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $note = trim($_POST['admin_note'] ?? '');

    $stmt = $pdo->prepare('SELECT * FROM patient_registration_requests WHERE id=? LIMIT 1');
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();

    if (!$request) {
        flash('error', 'Data pendaftaran tidak ditemukan.');
        redirect('patient_registration_requests.php');
    }

    if ($request['status'] !== 'Menunggu Verifikasi') {
        flash('error', 'Pendaftaran ini sudah pernah diproses.');
        redirect('patient_registration_requests.php');
    }

    if ($action === 'reject') {
        $stmt = $pdo->prepare("UPDATE patient_registration_requests SET status='Ditolak', admin_note=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?");
        $stmt->execute([$note !== '' ? $note : 'Pendaftaran tidak disetujui.', $user['user_id'], $requestId]);
        audit_log('REJECT_ONLINE_REGISTRATION', 'patient_registration_requests', $requestId, $note);
        flash('success', 'Pendaftaran online ditandai sebagai Ditolak.');
        redirect('patient_registration_requests.php');
    }

    if ($action === 'approve') {
        try {
            $pdo->beginTransaction();

            $find = $pdo->prepare('SELECT id, medical_record_no FROM patients WHERE nik=? LIMIT 1');
            $find->execute([$request['nik']]);
            $patient = $find->fetch();

            if (!$patient) {
                $insertPatient = $pdo->prepare('INSERT INTO patients (medical_record_no, nik, full_name, birth_date, gender, address, phone, email, payer) VALUES (NULL,?,?,?,?,?,?,?,?)');
                $insertPatient->execute([
                    $request['nik'],
                    $request['full_name'],
                    $request['birth_date'],
                    $request['gender'],
                    $request['address'],
                    $request['phone'],
                    $request['email'],
                    $request['payer'],
                ]);
                $patientId = (int) $pdo->lastInsertId();
                $mrn = 'RM-' . date('Y') . '-' . str_pad((string) $patientId, 6, '0', STR_PAD_LEFT);
                $pdo->prepare('UPDATE patients SET medical_record_no=? WHERE id=?')->execute([$mrn, $patientId]);
            } else {
                $patientId = (int) $patient['id'];
            }

            $duplicate = $pdo->prepare('SELECT id, registration_no FROM registrations WHERE patient_id=? AND unit_id=? AND visit_date=? AND status<>? LIMIT 1');
            $duplicate->execute([$patientId, $request['unit_id'], $request['visit_date'], 'Batal']);
            $existingRegistration = $duplicate->fetch();

            if ($existingRegistration) {
                $registrationId = (int) $existingRegistration['id'];
                $registrationNo = $existingRegistration['registration_no'];
            } else {
                $countStmt = $pdo->prepare('SELECT COUNT(*) FROM registrations WHERE visit_date=? AND unit_id=?');
                $countStmt->execute([$request['visit_date'], $request['unit_id']]);
                $queue = (int) $countStmt->fetchColumn() + 1;

                $unitCodeStmt = $pdo->prepare('SELECT code FROM units WHERE id=?');
                $unitCodeStmt->execute([$request['unit_id']]);
                $unitCode = (string) $unitCodeStmt->fetchColumn();
                $queueNo = substr($unitCode, 0, 2) . str_pad((string) $queue, 3, '0', STR_PAD_LEFT);

                $regStmt = $pdo->prepare("INSERT INTO registrations (registration_no, patient_id, unit_id, doctor_employee_id, schedule_id, visit_date, complaint, payer, status, queue_no, created_by) VALUES (NULL,?,?,?,?,?,?,?,'Menunggu',?,?)");
                $regStmt->execute([
                    $patientId,
                    $request['unit_id'],
                    $request['doctor_employee_id'],
                    $request['schedule_id'],
                    $request['visit_date'],
                    $request['complaint'],
                    $request['payer'],
                    $queueNo,
                    $user['user_id'],
                ]);
                $registrationId = (int) $pdo->lastInsertId();
                $registrationNo = 'REG-' . date('Ymd') . '-' . str_pad((string) $registrationId, 4, '0', STR_PAD_LEFT);
                $pdo->prepare('UPDATE registrations SET registration_no=? WHERE id=?')->execute([$registrationNo, $registrationId]);
            }

            $update = $pdo->prepare("UPDATE patient_registration_requests SET status='Disetujui', patient_id=?, registration_id=?, admin_note=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?");
            $update->execute([$patientId, $registrationId, $note !== '' ? $note : 'Pendaftaran telah diverifikasi.', $user['user_id'], $requestId]);

            $pdo->commit();
            audit_log('APPROVE_ONLINE_REGISTRATION', 'patient_registration_requests', $requestId, $registrationNo);
            flash('success', 'Pendaftaran disetujui dan masuk ke registrasi rumah sakit: ' . $registrationNo);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', 'Pendaftaran belum berhasil diproses.');
        }

        redirect('patient_registration_requests.php');
    }
}

$status = trim($_GET['status'] ?? '');
$sql = "SELECT pr.*, un.name AS unit_name, e.full_name AS doctor_name, ds.day_name, TIME_FORMAT(ds.start_time,'%H:%i') AS start_time, TIME_FORMAT(ds.end_time,'%H:%i') AS end_time, rv.username AS reviewer_name, r.registration_no
        FROM patient_registration_requests pr
        JOIN units un ON un.id=pr.unit_id
        JOIN employees e ON e.id=pr.doctor_employee_id
        JOIN doctor_schedules ds ON ds.id=pr.schedule_id
        LEFT JOIN users rv ON rv.id=pr.reviewed_by
        LEFT JOIN registrations r ON r.id=pr.registration_id";
$params = [];
if ($status !== '') {
    $sql .= ' WHERE pr.status=?';
    $params[] = $status;
}
$sql .= ' ORDER BY pr.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

$stats = $pdo->query("SELECT COUNT(*) AS total, SUM(status='Menunggu Verifikasi') AS pending, SUM(status='Disetujui') AS approved, SUM(status='Ditolak') AS rejected FROM patient_registration_requests")->fetch();

require __DIR__ . '/includes/header.php';
?>
<h1 class="page-title">Registrasi Pasien Online</h1>
<p class="page-subtitle">Pendaftaran yang dikirim pasien dari halaman publik. Petugas admisi/admin melakukan verifikasi sebelum data menjadi registrasi rumah sakit.</p>

<div class="grid grid-4">
  <div class="card metric-card"><label>Total Permintaan</label><strong><?= (int) ($stats['total'] ?? 0) ?></strong></div>
  <div class="card metric-card"><label>Menunggu Verifikasi</label><strong><?= (int) ($stats['pending'] ?? 0) ?></strong></div>
  <div class="card metric-card"><label>Disetujui</label><strong><?= (int) ($stats['approved'] ?? 0) ?></strong></div>
  <div class="card metric-card"><label>Ditolak</label><strong><?= (int) ($stats['rejected'] ?? 0) ?></strong></div>
</div>

<div class="card" style="margin-top:18px">
  <div class="actions" style="justify-content:space-between">
    <div class="actions">
      <a class="btn <?= $status === '' ? 'btn-primary' : 'btn-secondary' ?> btn-sm" href="patient_registration_requests.php">Semua</a>
      <a class="btn <?= $status === 'Menunggu Verifikasi' ? 'btn-primary' : 'btn-secondary' ?> btn-sm" href="patient_registration_requests.php?status=<?= urlencode('Menunggu Verifikasi') ?>">Menunggu</a>
      <a class="btn <?= $status === 'Disetujui' ? 'btn-primary' : 'btn-secondary' ?> btn-sm" href="patient_registration_requests.php?status=<?= urlencode('Disetujui') ?>">Disetujui</a>
      <a class="btn <?= $status === 'Ditolak' ? 'btn-primary' : 'btn-secondary' ?> btn-sm" href="patient_registration_requests.php?status=<?= urlencode('Ditolak') ?>">Ditolak</a>
    </div>
    <a class="btn btn-secondary btn-sm" href="patient_registration.php">Registrasi oleh Petugas</a>
  </div>
</div>

<div class="table-wrap" style="margin-top:18px">
  <table>
    <thead>
      <tr>
        <th>No. Permintaan</th>
        <th>Pasien</th>
        <th>Rencana Kunjungan</th>
        <th>Status</th>
        <th>Registrasi RS</th>
        <th>Aksi</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$requests): ?>
        <tr><td colspan="6" style="text-align:center;padding:28px;color:#667085">Belum ada pendaftaran pasien online.</td></tr>
      <?php endif; ?>
      <?php foreach ($requests as $request): ?>
        <?php
          $badgeClass = match ($request['status']) {
              'Disetujui' => 'badge-green',
              'Ditolak' => 'badge-red',
              default => 'badge-yellow',
          };
        ?>
        <tr>
          <td><b><?= e($request['request_no']) ?></b><br><small><?= e(date('d/m/Y H:i', strtotime($request['created_at']))) ?></small></td>
          <td><b><?= e($request['full_name']) ?></b><br><small>NIK <?= e($request['nik']) ?> · <?= e($request['phone']) ?></small></td>
          <td><?= e($request['unit_name']) ?><br><small><?= e($request['doctor_name']) ?> · <?= e($request['day_name'] . ' ' . $request['start_time'] . '-' . $request['end_time']) ?></small><br><small><?= e(date('d/m/Y', strtotime($request['visit_date']))) ?></small></td>
          <td><span class="badge <?= $badgeClass ?>"><?= e($request['status']) ?></span><?php if ($request['reviewed_at']): ?><br><small><?= e($request['reviewer_name'] ?? '-') ?> · <?= e(date('d/m/Y H:i', strtotime($request['reviewed_at']))) ?></small><?php endif; ?></td>
          <td><?= $request['registration_no'] ? '<b>' . e($request['registration_no']) . '</b>' : '<span class="note">Belum dibuat</span>' ?></td>
          <td>
            <?php if ($request['status'] === 'Menunggu Verifikasi'): ?>
              <button class="btn btn-success btn-sm" type="button" onclick="openRegistrationReview(<?= (int) $request['id'] ?>, '<?= e(addslashes($request['request_no'])) ?>', '<?= e(addslashes($request['full_name'])) ?>')">Verifikasi</button>
            <?php else: ?>
              <span class="note"><?= e($request['admin_note'] ?? '-') ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="modal-backdrop" id="registrationReviewModal">
  <div class="modal-card">
    <h3 style="margin-top:0">Verifikasi Pendaftaran Online</h3>
    <p class="note" id="registrationReviewInfo"></p>
    <form method="POST">
      <input type="hidden" name="request_id" id="registrationRequestId">
      <div class="field">
        <label>Catatan Petugas</label>
        <textarea class="input" name="admin_note" rows="3" placeholder="Opsional"></textarea>
      </div>
      <div class="actions" style="justify-content:flex-end">
        <button class="btn btn-secondary" type="button" onclick="closeRegistrationReview()">Batal</button>
        <button class="btn btn-danger" name="action" value="reject" type="submit">Tolak</button>
        <button class="btn btn-success" name="action" value="approve" type="submit">Setujui & Buat Registrasi</button>
      </div>
    </form>
  </div>
</div>

<script>
function openRegistrationReview(id, requestNo, name) {
  document.getElementById('registrationRequestId').value = id;
  document.getElementById('registrationReviewInfo').textContent = requestNo + ' · ' + name;
  document.getElementById('registrationReviewModal').classList.add('open');
}
function closeRegistrationReview() {
  document.getElementById('registrationReviewModal').classList.remove('open');
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
