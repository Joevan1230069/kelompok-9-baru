<?php
require_once __DIR__ . '/includes/functions.php';
$user = require_login();
require_permission($user, 'dashboard.view');
$pageTitle = 'Dashboard';

$pdo = db();
$stats = [
    'patients' => (int) $pdo->query('SELECT COUNT(*) FROM patients')->fetchColumn(),
    'today' => (int) $pdo->query('SELECT COUNT(*) FROM registrations WHERE visit_date = CURDATE()')->fetchColumn(),
    'lab_pending' => (int) $pdo->query("SELECT COUNT(*) FROM lab_orders WHERE status IN ('Menunggu','In Process')")->fetchColumn(),
    'inpatient' => (int) $pdo->query("SELECT COUNT(*) FROM inpatient_admissions WHERE status = 'Dirawat'")->fetchColumn(),
];

$myPatients = [];
$doctorLabResults = [];
$doctorRadiologyResults = [];
$incomingConsultations = [];

if ($user['role_code'] === 'dokter') {
    $stmt = $pdo->prepare(
        "SELECT r.id, r.registration_no, r.visit_date, r.status, r.queue_no,
                p.full_name, p.medical_record_no, p.birth_date, un.name AS unit_name
         FROM registrations r
         JOIN patients p ON p.id=r.patient_id
         JOIN units un ON un.id=r.unit_id
         WHERE r.doctor_employee_id=?
         ORDER BY r.visit_date DESC, r.id DESC LIMIT 12"
    );
    $stmt->execute([(int) $user['employee_id']]);
    $myPatients = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT o.order_no, p.full_name AS patient_name, p.medical_record_no,
                l.test_name, l.status, l.result, l.verified_at,
                ver.employee_code AS verifier_code, ver.full_name AS verifier_name
         FROM lab_orders l
         JOIN orders o ON o.id=l.order_id
         JOIN registrations r ON r.id=o.registration_id
         JOIN patients p ON p.id=r.patient_id
         LEFT JOIN employees ver ON ver.id=l.verified_by
         WHERE o.ordering_employee_id=?
         ORDER BY l.id DESC LIMIT 6"
    );
    $stmt->execute([(int) $user['employee_id']]);
    $doctorLabResults = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT o.order_no, p.full_name AS patient_name, p.medical_record_no,
                ro.exam_name, ro.status, ro.result, ro.verified_at,
                ver.employee_code AS verifier_code, ver.full_name AS verifier_name
         FROM radiology_orders ro
         JOIN orders o ON o.id=ro.order_id
         JOIN registrations r ON r.id=o.registration_id
         JOIN patients p ON p.id=r.patient_id
         LEFT JOIN employees ver ON ver.id=ro.verified_by
         WHERE o.ordering_employee_id=?
         ORDER BY ro.id DESC LIMIT 6"
    );
    $stmt->execute([(int) $user['employee_id']]);
    $doctorRadiologyResults = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT co.id, co.status, co.response, o.order_no, o.detail, p.full_name AS patient_name,
                req.full_name AS requester_name, req.employee_code AS requester_code
         FROM consultation_orders co
         JOIN orders o ON o.id=co.order_id
         JOIN registrations r ON r.id=o.registration_id
         JOIN patients p ON p.id=r.patient_id
         JOIN employees req ON req.id=o.ordering_employee_id
         WHERE co.consultant_employee_id=?
         ORDER BY co.id DESC LIMIT 6"
    );
    $stmt->execute([(int) $user['employee_id']]);
    $incomingConsultations = $stmt->fetchAll();
}

$pharmacyStats = [];
if ($user['role_code'] === 'farmasi') {
    $pharmacyStats = [
        'waiting' => (int) $pdo->query("SELECT COUNT(*) FROM pharmacy_orders WHERE status='Menunggu'")->fetchColumn(),
        'low_stock' => (int) $pdo->query('SELECT COUNT(*) FROM medications WHERE is_active=1 AND stock < 20')->fetchColumn(),
        'expiring' => (int) $pdo->query("SELECT COUNT(*) FROM medications WHERE is_active=1 AND expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)")->fetchColumn(),
    ];
}

require __DIR__ . '/includes/header.php';
?>
<h1 class="page-title">Dashboard</h1>
<p class="page-subtitle">Ringkasan pelayanan sesuai akun dan kewenangan pegawai yang sedang login.</p>

<div class="grid grid-4">
  <div class="card stat"><small>Total Pasien</small><b><?= $stats['patients'] ?></b></div>
  <div class="card stat"><small>Kunjungan Hari Ini</small><b><?= $stats['today'] ?></b></div>
  <div class="card stat"><small>Order Lab Aktif</small><b><?= $stats['lab_pending'] ?></b></div>
  <div class="card stat"><small>Pasien Rawat Inap</small><b><?= $stats['inpatient'] ?></b></div>
</div>

<?php if ($user['role_code'] === 'dokter'): ?>
<div class="card" style="margin-top:18px">
  <h2 class="section-title">Pasien Saya</h2>
  <p class="note">Daftar ini hanya menampilkan pasien yang registrasinya ditujukan kepada <?= e($user['full_name']) ?>.</p>
  <div class="table-wrap" style="margin-top:12px">
    <table>
      <thead><tr><th>Registrasi</th><th>Pasien</th><th>Unit</th><th>Status</th></tr></thead>
      <tbody>
        <?php if (!$myPatients): ?><tr><td colspan="4" class="empty-state">Belum ada pasien yang terdaftar pada akun dokter ini.</td></tr><?php endif; ?>
        <?php foreach ($myPatients as $row): ?>
          <tr>
            <td><b><?= e($row['registration_no']) ?></b><br><small><?= e($row['visit_date'].' · '.$row['queue_no']) ?></small></td>
            <td><b><?= e($row['full_name']) ?></b><br><small><?= e($row['medical_record_no']) ?></small></td>
            <td><?= e($row['unit_name']) ?></td>
            <td><span class="badge badge-blue"><?= e($row['status']) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="grid grid-2" style="margin-top:18px">
  <div class="card">
    <h2 class="section-title">Hasil Laboratorium</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Pasien</th><th>Pemeriksaan</th><th>Status / Hasil</th></tr></thead>
        <tbody>
        <?php if (!$doctorLabResults): ?><tr><td colspan="3" class="empty-state">Belum ada order laboratorium.</td></tr><?php endif; ?>
        <?php foreach ($doctorLabResults as $lab): ?>
          <tr>
            <td><b><?= e($lab['patient_name']) ?></b><br><small><?= e($lab['medical_record_no']) ?></small></td>
            <td><?= e($lab['test_name']) ?><br><small><?= e($lab['order_no']) ?></small></td>
            <td><span class="badge <?= $lab['status']==='Tervalidasi'?'badge-green':($lab['status']==='In Process'?'badge-blue':'badge-yellow') ?>"><?= e($lab['status']) ?></span><div class="result-box"><?= $lab['result'] ? e($lab['result']) : 'Hasil belum tersedia' ?></div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <h2 class="section-title">Hasil Radiologi</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Pasien</th><th>Pemeriksaan</th><th>Status / Hasil</th></tr></thead>
        <tbody>
        <?php if (!$doctorRadiologyResults): ?><tr><td colspan="3" class="empty-state">Belum ada order radiologi.</td></tr><?php endif; ?>
        <?php foreach ($doctorRadiologyResults as $rad): ?>
          <tr>
            <td><b><?= e($rad['patient_name']) ?></b><br><small><?= e($rad['medical_record_no']) ?></small></td>
            <td><?= e($rad['exam_name']) ?><br><small><?= e($rad['order_no']) ?></small></td>
            <td><span class="badge <?= $rad['status']==='Selesai'?'badge-green':'badge-yellow' ?>"><?= e($rad['status']) ?></span><div class="result-box"><?= $rad['result'] ? e($rad['result']) : 'Hasil belum tersedia' ?></div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if ($incomingConsultations): ?>
<div class="card" style="margin-top:18px">
  <h2 class="section-title">Konsultasi Masuk</h2>
  <div class="table-wrap">
    <table><thead><tr><th>Order</th><th>Pasien</th><th>Dokter Pengirim</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($incomingConsultations as $consult): ?>
      <tr><td><?= e($consult['order_no']) ?><br><small><?= e($consult['detail']) ?></small></td><td><?= e($consult['patient_name']) ?></td><td><?= e($consult['requester_code'].' - '.$consult['requester_name']) ?></td><td><span class="badge <?= $consult['status']==='Selesai'?'badge-green':'badge-yellow' ?>"><?= e($consult['status']) ?></span></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($user['role_code'] === 'farmasi'): ?>
<div class="grid grid-3" style="margin-top:18px">
  <div class="card stat"><small>Resep Menunggu</small><b><?= $pharmacyStats['waiting'] ?></b></div>
  <div class="card stat"><small>Stok Menipis</small><b><?= $pharmacyStats['low_stock'] ?></b></div>
  <div class="card stat"><small>Expired ≤ 90 Hari</small><b><?= $pharmacyStats['expiring'] ?></b></div>
</div>
<?php endif; ?>

<div class="card" style="margin-top:18px">
  <h2 class="section-title">Akses Aktif</h2>
  <div class="kpi-inline">
    <span class="badge badge-blue"><?= e($user['employee_code']) ?></span>
    <span class="badge badge-green"><?= e($user['role_name']) ?></span>
    <span class="badge badge-yellow"><?= e($user['unit_name'] ?? 'Tanpa Unit') ?></span>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
