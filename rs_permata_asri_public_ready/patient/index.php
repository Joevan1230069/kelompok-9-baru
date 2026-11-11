<?php
require_once dirname(__DIR__) . '/includes/functions.php';

$pdo = db();
ensure_progress03_schema($pdo);
$errors = [];
$successData = null;

$units = $pdo->query("SELECT id, code, name FROM units WHERE unit_type='POLI' ORDER BY name")->fetchAll();
$doctors = $pdo->query("SELECT e.id, e.employee_code, e.full_name, e.unit_id FROM employees e JOIN roles r ON r.id=e.role_id WHERE r.code='dokter' AND e.is_active=1 ORDER BY e.full_name")->fetchAll();
$schedules = $pdo->query("SELECT ds.id, ds.employee_id, ds.unit_id, ds.day_name, TIME_FORMAT(ds.start_time,'%H:%i') AS start_time, TIME_FORMAT(ds.end_time,'%H:%i') AS end_time, e.full_name, un.name AS unit_name FROM doctor_schedules ds JOIN employees e ON e.id=ds.employee_id JOIN units un ON un.id=ds.unit_id ORDER BY ds.id")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nik = trim($_POST['nik'] ?? '');
    $name = trim($_POST['full_name'] ?? '');
    $birth = $_POST['birth_date'] ?? '';
    $gender = $_POST['gender'] ?? '';
    $address = trim($_POST['address'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $payer = $_POST['payer'] ?? 'Umum';
    $unitId = (int) ($_POST['unit_id'] ?? 0);
    $doctorId = (int) ($_POST['doctor_employee_id'] ?? 0);
    $scheduleId = (int) ($_POST['schedule_id'] ?? 0);
    $visitDate = $_POST['visit_date'] ?? '';
    $complaint = trim($_POST['complaint'] ?? '');

    if (!preg_match('/^\d{16}$/', $nik)) {
        $errors['nik'] = 'NIK harus terdiri dari 16 digit angka.';
    }
    if ($name === '') {
        $errors['full_name'] = 'Nama lengkap wajib diisi.';
    }
    if ($birth === '') {
        $errors['birth_date'] = 'Tanggal lahir wajib diisi.';
    }
    if (!in_array($gender, ['L', 'P'], true)) {
        $errors['gender'] = 'Jenis kelamin wajib dipilih.';
    }
    if ($phone === '') {
        $errors['phone'] = 'Nomor HP wajib diisi.';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Format email tidak sesuai.';
    }
    if (!$unitId || !$doctorId || !$scheduleId || $visitDate === '') {
        $errors['visit'] = 'Poli, dokter, jadwal, dan tanggal kunjungan wajib dipilih.';
    }
    if ($visitDate !== '' && $visitDate < date('Y-m-d')) {
        $errors['visit'] = 'Tanggal kunjungan tidak boleh sebelum hari ini.';
    }

    if (!$errors) {
        $scheduleStmt = $pdo->prepare('SELECT id FROM doctor_schedules WHERE id=? AND employee_id=? AND unit_id=? LIMIT 1');
        $scheduleStmt->execute([$scheduleId, $doctorId, $unitId]);
        if (!$scheduleStmt->fetchColumn()) {
            $errors['visit'] = 'Jadwal yang dipilih tidak sesuai dengan dokter/poli.';
        }
    }

    if (!$errors) {
        $duplicateStmt = $pdo->prepare("SELECT request_no FROM patient_registration_requests WHERE nik=? AND visit_date=? AND status='Menunggu Verifikasi' LIMIT 1");
        $duplicateStmt->execute([$nik, $visitDate]);
        $existingRequest = $duplicateStmt->fetchColumn();
        if ($existingRequest) {
            $errors['nik'] = 'Pendaftaran untuk NIK dan tanggal tersebut sudah dikirim dengan nomor ' . $existingRequest . '.';
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "INSERT INTO patient_registration_requests
                (request_no, nik, full_name, birth_date, gender, address, phone, email, payer, unit_id, doctor_employee_id, schedule_id, visit_date, complaint, status)
                VALUES (NULL,?,?,?,?,?,?,?,?,?,?,?,?,?,'Menunggu Verifikasi')"
            );
            $stmt->execute([$nik, $name, $birth, $gender, $address, $phone, $email, $payer, $unitId, $doctorId, $scheduleId, $visitDate, $complaint]);
            $requestId = (int) $pdo->lastInsertId();
            $requestNo = 'ONL-' . date('Ymd') . '-' . str_pad((string) $requestId, 4, '0', STR_PAD_LEFT);
            $pdo->prepare('UPDATE patient_registration_requests SET request_no=? WHERE id=?')->execute([$requestNo, $requestId]);
            $pdo->commit();
            audit_log('ONLINE_REGISTRATION', 'patient_registration_requests', $requestId, $requestNo . ' - ' . $name);

            $successData = [
                'request_no' => $requestNo,
                'name' => $name,
                'visit_date' => $visitDate,
            ];
            $_POST = [];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors['general'] = 'Pendaftaran belum dapat disimpan. Silakan coba kembali.';
        }
    }
}

function old_value(string $key): string
{
    return e((string) ($_POST[$key] ?? ''));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Pendaftaran Pasien - Rumah Sakit Permata Asri</title>
  <link rel="stylesheet" href="../assets/css/app.css">
</head>
<body class="public-registration-page">
  <div class="public-topbar">
    <div class="brand-row">
      <img src="../assets/img/logo.svg" class="logo-image" alt="Logo Rumah Sakit Permata Asri">
      <div>
        <div class="brand-name">Rumah Sakit Permata Asri</div>
        <div class="brand-sub">Pendaftaran Pasien Online</div>
      </div>
    </div>
    <div class="patient-portal-label">Portal Pasien</div>
  </div>

  <main class="public-registration-shell">
    <section class="public-intro">
      <span class="badge badge-blue">Pendaftaran Online</span>
      <h1>Daftar Kunjungan Pasien</h1>
      <p>Isi data diri dan pilih poli, dokter, serta jadwal kunjungan. Data yang dikirim akan masuk ke petugas admisi untuk diverifikasi.</p>
    </section>

    <?php if ($successData): ?>
      <div class="card public-success-card">
        <h2>Pendaftaran berhasil dikirim</h2>
        <p>Nomor permintaan kamu:</p>
        <div class="request-number"><?= e($successData['request_no']) ?></div>
        <p class="note">Status awal: <b>Menunggu Verifikasi</b>. Simpan nomor ini untuk konfirmasi ke petugas admisi.</p>
        <div class="actions">
          <a class="btn btn-primary" href="index.php">Buat Pendaftaran Baru</a>
          <a class="btn btn-secondary" href="index.php">Kembali</a>
        </div>
      </div>
    <?php else: ?>
      <?php if (isset($errors['general'])): ?>
        <div class="alert alert-error"><?= e($errors['general']) ?></div>
      <?php endif; ?>

      <form method="POST" class="card public-registration-form">
        <h2 class="section-title">Data Diri Pasien</h2>
        <div class="grid grid-2">
          <div class="field">
            <label>NIK *</label>
            <input class="input" name="nik" maxlength="16" inputmode="numeric" value="<?= old_value('nik') ?>" required>
            <?php if (isset($errors['nik'])): ?><small class="field-error"><?= e($errors['nik']) ?></small><?php endif; ?>
          </div>
          <div class="field">
            <label>Nama Lengkap *</label>
            <input class="input" name="full_name" value="<?= old_value('full_name') ?>" required>
            <?php if (isset($errors['full_name'])): ?><small class="field-error"><?= e($errors['full_name']) ?></small><?php endif; ?>
          </div>
          <div class="field">
            <label>Tanggal Lahir *</label>
            <input class="input" type="date" name="birth_date" value="<?= old_value('birth_date') ?>" required>
            <?php if (isset($errors['birth_date'])): ?><small class="field-error"><?= e($errors['birth_date']) ?></small><?php endif; ?>
          </div>
          <div class="field">
            <label>Jenis Kelamin *</label>
            <select class="input" name="gender" required>
              <option value="">-- Pilih --</option>
              <option value="L" <?= old_value('gender') === 'L' ? 'selected' : '' ?>>Laki-laki</option>
              <option value="P" <?= old_value('gender') === 'P' ? 'selected' : '' ?>>Perempuan</option>
            </select>
          </div>
          <div class="field">
            <label>No. HP *</label>
            <input class="input" name="phone" value="<?= old_value('phone') ?>" placeholder="08xxxxxxxxxx" required>
            <?php if (isset($errors['phone'])): ?><small class="field-error"><?= e($errors['phone']) ?></small><?php endif; ?>
          </div>
          <div class="field">
            <label>Email</label>
            <input class="input" type="email" name="email" value="<?= old_value('email') ?>">
            <?php if (isset($errors['email'])): ?><small class="field-error"><?= e($errors['email']) ?></small><?php endif; ?>
          </div>
        </div>
        <div class="field">
          <label>Alamat</label>
          <textarea class="input" name="address" rows="3"><?= old_value('address') ?></textarea>
        </div>

        <h2 class="section-title public-section-gap">Rencana Kunjungan</h2>
        <div class="grid grid-2">
          <div class="field">
            <label>Penjaminan</label>
            <select class="input" name="payer">
              <?php foreach (['Umum','BPJS','Asuransi'] as $payer): ?>
                <option value="<?= e($payer) ?>" <?= old_value('payer') === $payer ? 'selected' : '' ?>><?= e($payer) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Tanggal Kunjungan *</label>
            <input class="input" type="date" name="visit_date" min="<?= date('Y-m-d') ?>" value="<?= old_value('visit_date') ?: date('Y-m-d') ?>" required>
          </div>
          <div class="field">
            <label>Poli / Unit Tujuan *</label>
            <select class="input" name="unit_id" id="publicUnit" required>
              <option value="">-- Pilih Unit --</option>
              <?php foreach ($units as $unit): ?>
                <option value="<?= (int) $unit['id'] ?>" <?= old_value('unit_id') === (string) $unit['id'] ? 'selected' : '' ?>><?= e($unit['code'] . ' - ' . $unit['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Dokter Tujuan *</label>
            <select class="input" name="doctor_employee_id" id="publicDoctor" required>
              <option value="">-- Pilih Dokter --</option>
              <?php foreach ($doctors as $doctor): ?>
                <option value="<?= (int) $doctor['id'] ?>" data-unit="<?= (int) $doctor['unit_id'] ?>" <?= old_value('doctor_employee_id') === (string) $doctor['id'] ? 'selected' : '' ?>><?= e($doctor['employee_code'] . ' - ' . $doctor['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="field">
          <label>Jadwal Praktik *</label>
          <select class="input" name="schedule_id" id="publicSchedule" required>
            <option value="">-- Pilih Jadwal --</option>
            <?php foreach ($schedules as $schedule): ?>
              <option value="<?= (int) $schedule['id'] ?>" data-doctor="<?= (int) $schedule['employee_id'] ?>" data-unit="<?= (int) $schedule['unit_id'] ?>" <?= old_value('schedule_id') === (string) $schedule['id'] ? 'selected' : '' ?>><?= e($schedule['day_name'] . ' · ' . $schedule['start_time'] . '-' . $schedule['end_time'] . ' · ' . $schedule['full_name'] . ' · ' . $schedule['unit_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($errors['visit'])): ?><small class="field-error"><?= e($errors['visit']) ?></small><?php endif; ?>
        </div>
        <div class="field">
          <label>Keluhan Utama</label>
          <textarea class="input" name="complaint" rows="3" placeholder="Tuliskan keluhan utama secara singkat"><?= old_value('complaint') ?></textarea>
        </div>

        <div class="actions public-form-actions">
          <button class="btn btn-primary" type="submit">Kirim Pendaftaran</button>
          <a class="btn btn-secondary" href="index.php">Batal</a>
        </div>
      </form>
    <?php endif; ?>
  </main>

  <script>
    const unitSelect = document.getElementById('publicUnit');
    const doctorSelect = document.getElementById('publicDoctor');
    const scheduleSelect = document.getElementById('publicSchedule');

    function filterDoctorAndSchedule() {
      if (!unitSelect || !doctorSelect || !scheduleSelect) return;
      const unitId = unitSelect.value;
      [...doctorSelect.options].forEach((option, index) => {
        if (index === 0) return;
        option.hidden = unitId !== '' && option.dataset.unit !== unitId;
      });
      if (doctorSelect.selectedOptions[0]?.hidden) doctorSelect.value = '';

      const doctorId = doctorSelect.value;
      [...scheduleSelect.options].forEach((option, index) => {
        if (index === 0) return;
        const unitMatch = unitId === '' || option.dataset.unit === unitId;
        const doctorMatch = doctorId === '' || option.dataset.doctor === doctorId;
        option.hidden = !(unitMatch && doctorMatch);
      });
      if (scheduleSelect.selectedOptions[0]?.hidden) scheduleSelect.value = '';
    }

    unitSelect?.addEventListener('change', filterDoctorAndSchedule);
    doctorSelect?.addEventListener('change', filterDoctorAndSchedule);
    filterDoctorAndSchedule();
  </script>
</body>
</html>
