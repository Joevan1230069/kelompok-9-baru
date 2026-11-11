<?php
require_once __DIR__ . '/includes/functions.php';
$user = require_login();
require_permission($user, 'emr.manage');
$pageTitle = 'Electronic Medical Record';
$pdo = db();
$canWrite = in_array($user['role_code'], ['dokter', 'perawat', 'igd'], true);

[$regWhere, $regParams] = registration_filter($user, 'r');
$regStmt = $pdo->prepare(
    "SELECT r.id, r.registration_no, r.visit_date, r.status,
            p.medical_record_no, p.full_name, un.name AS unit_name,
            d.employee_code AS doctor_code, d.full_name AS doctor_name
     FROM registrations r
     JOIN patients p ON p.id=r.patient_id
     JOIN units un ON un.id=r.unit_id
     LEFT JOIN employees d ON d.id=r.doctor_employee_id
     WHERE {$regWhere}
     ORDER BY r.id DESC LIMIT 80"
);
$regStmt->execute($regParams);
$registrations = $regStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canWrite) {
        flash('error', 'Akun ini hanya memiliki akses melihat rekam medis.');
        redirect('emr.php');
    }

    $registrationId = (int) ($_POST['registration_id'] ?? 0);
    $activity = trim($_POST['activity'] ?? '');
    $subjective = trim($_POST['subjective'] ?? '');
    $objective = trim($_POST['objective'] ?? '');
    $assessment = trim($_POST['assessment'] ?? '');
    $plan = trim($_POST['plan'] ?? '');

    if (!$registrationId || $activity === '') {
        flash('error', 'Pilih pasien dan isi aktivitas pemeriksaan.');
        redirect('emr.php');
    }

    if (!can_access_registration($user, $registrationId)) {
        flash('error', 'Rekam medis pasien tersebut tidak berada dalam kewenangan akun ini.');
        redirect('emr.php');
    }

    $pdo->beginTransaction();
    try {
        $find = $pdo->prepare('SELECT id FROM emr WHERE registration_id=? LIMIT 1');
        $find->execute([$registrationId]);
        $emrId = (int) $find->fetchColumn();

        if ($emrId) {
            $pdo->prepare(
                'UPDATE emr SET subjective=?, objective=?, assessment=?, plan=?, updated_at=NOW() WHERE id=?'
            )->execute([$subjective, $objective, $assessment, $plan, $emrId]);
        } else {
            $pdo->prepare(
                'INSERT INTO emr (registration_id, subjective, objective, assessment, plan, created_at, updated_at)
                 VALUES (?,?,?,?,?,NOW(),NOW())'
            )->execute([$registrationId, $subjective, $objective, $assessment, $plan]);
            $emrId = (int) $pdo->lastInsertId();
        }

        $pdo->prepare(
            'INSERT INTO emr_staff (emr_id, employee_id, activity, created_at) VALUES (?,?,?,NOW())'
        )->execute([$emrId, $user['employee_id'], $activity]);

        $pdo->commit();
        audit_log('SAVE_EMR', 'emr', $emrId, 'Registrasi ID ' . $registrationId . ' oleh ' . $user['employee_code']);
        flash('success', 'Catatan rekam medis berhasil disimpan.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', 'Gagal menyimpan rekam medis.');
    }
    redirect('emr.php');
}

$historySql = "SELECT em.id, em.registration_id, em.subjective, em.objective, em.assessment, em.plan, em.updated_at,
                      p.full_name AS patient_name, p.medical_record_no, r.registration_no,
                      d.employee_code AS dpjp_code, d.full_name AS dpjp_name,
                      GROUP_CONCAT(CONCAT(e.employee_code,' - ',e.full_name,' [',es.activity,']') ORDER BY es.id SEPARATOR ' | ') AS examiners
               FROM emr em
               JOIN registrations r ON r.id=em.registration_id
               JOIN patients p ON p.id=r.patient_id
               LEFT JOIN employees d ON d.id=r.doctor_employee_id
               LEFT JOIN emr_staff es ON es.emr_id=em.id
               LEFT JOIN employees e ON e.id=es.employee_id
               WHERE {$regWhere}
               GROUP BY em.id
               ORDER BY em.updated_at DESC LIMIT 30";
$historyStmt = $pdo->prepare($historySql);
$historyStmt->execute($regParams);
$history = $historyStmt->fetchAll();

$labSql = "SELECT l.status AS lab_status, l.result, l.verified_at, l.test_name,
                  o.order_no, o.priority, p.full_name AS patient_name, p.medical_record_no,
                  ver.employee_code AS verifier_code, ver.full_name AS verifier_name
           FROM lab_orders l
           JOIN orders o ON o.id=l.order_id
           JOIN registrations r ON r.id=o.registration_id
           JOIN patients p ON p.id=r.patient_id
           LEFT JOIN employees ver ON ver.id=l.verified_by
           WHERE {$regWhere}
           ORDER BY l.id DESC LIMIT 20";
$labStmt = $pdo->prepare($labSql);
$labStmt->execute($regParams);
$labResults = $labStmt->fetchAll();

$radSql = "SELECT ro.status AS rad_status, ro.result, ro.verified_at, ro.exam_name,
                  o.order_no, p.full_name AS patient_name, p.medical_record_no,
                  ver.employee_code AS verifier_code, ver.full_name AS verifier_name
           FROM radiology_orders ro
           JOIN orders o ON o.id=ro.order_id
           JOIN registrations r ON r.id=o.registration_id
           JOIN patients p ON p.id=r.patient_id
           LEFT JOIN employees ver ON ver.id=ro.verified_by
           WHERE {$regWhere}
           ORDER BY ro.id DESC LIMIT 20";
$radStmt = $pdo->prepare($radSql);
$radStmt->execute($regParams);
$radResults = $radStmt->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<h1 class="page-title">Electronic Medical Record</h1>
<p class="page-subtitle">Akses rekam medis mengikuti dokter penanggung jawab atau kewenangan unit. Pemeriksa otomatis mengikuti akun yang sedang login.</p>

<?php if ($user['role_code'] === 'dokter'): ?>
<div class="access-note">Akun dokter hanya dapat melihat dan mengubah EMR pasien yang registrasinya ditujukan kepada <?= e($user['full_name']) ?> (<?= e($user['employee_code']) ?>).</div>
<?php elseif (is_privileged_record_viewer($user)): ?>
<div class="access-note">Akun <?= e($user['role_name']) ?> memiliki akses administratif untuk melihat seluruh rekam medis.</div>
<?php endif; ?>

<div class="grid <?= $canWrite ? 'grid-2' : '' ?>">
  <?php if ($canWrite): ?>
  <div class="card">
    <h2 class="section-title">Tambah / Update Catatan</h2>
    <p class="note">Pemeriksa: <b><?= e($user['employee_code'].' - '.$user['full_name']) ?></b></p>
    <form method="POST" style="margin-top:12px">
      <div class="field"><label>Kunjungan Pasien</label><select class="input" name="registration_id" required><option value="">-- Pilih Kunjungan --</option><?php foreach($registrations as $r): ?><option value="<?= $r['id'] ?>"><?= e($r['registration_no'].' · '.$r['medical_record_no'].' · '.$r['full_name'].' · '.$r['unit_name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Aktivitas Pemeriksa</label><input class="input" name="activity" placeholder="Contoh: anamnesis dan pemeriksaan fisik" required></div>
      <div class="field"><label>Subjective</label><textarea class="input" name="subjective" rows="3"></textarea></div>
      <div class="field"><label>Objective</label><textarea class="input" name="objective" rows="3"></textarea></div>
      <div class="field"><label>Assessment</label><textarea class="input" name="assessment" rows="3"></textarea></div>
      <div class="field"><label>Plan</label><textarea class="input" name="plan" rows="3"></textarea></div>
      <button class="btn btn-primary">Simpan EMR</button>
    </form>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2 class="section-title">Riwayat Rekam Medis</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Pasien</th><th>Assessment / Plan</th><th>DPJP & Pemeriksa</th></tr></thead>
        <tbody>
        <?php if (!$history): ?><tr><td colspan="3" class="empty-state">Belum ada rekam medis yang dapat ditampilkan.</td></tr><?php endif; ?>
        <?php foreach($history as $h): ?>
          <tr>
            <td><b><?= e($h['patient_name']) ?></b><br><small><?= e($h['medical_record_no'].' · '.$h['registration_no']) ?></small></td>
            <td><b><?= e($h['assessment']) ?></b><br><small><?= e($h['plan']) ?></small></td>
            <td><b><?= e(($h['dpjp_code'] ?? '-').' - '.($h['dpjp_name'] ?? '-')) ?></b><br><small><?= e($h['examiners'] ?? 'Belum ada aktivitas pemeriksa') ?></small></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="grid grid-2" style="margin-top:18px">
  <div class="card">
    <h2 class="section-title">Hasil Laboratorium</h2>
    <div class="table-wrap"><table><thead><tr><th>Pasien</th><th>Pemeriksaan</th><th>Status / Hasil</th></tr></thead><tbody>
    <?php if (!$labResults): ?><tr><td colspan="3" class="empty-state">Belum ada hasil laboratorium.</td></tr><?php endif; ?>
    <?php foreach($labResults as $lab): ?><tr><td><b><?= e($lab['patient_name']) ?></b><br><small><?= e($lab['medical_record_no']) ?></small></td><td><?= e($lab['test_name']) ?><br><small><?= e($lab['order_no']) ?></small></td><td><span class="badge <?= $lab['lab_status']==='Tervalidasi'?'badge-green':($lab['lab_status']==='In Process'?'badge-blue':'badge-yellow') ?>"><?= e($lab['lab_status']) ?></span><div class="result-box"><?= $lab['result'] ? e($lab['result']) : 'Belum tersedia' ?></div><?php if($lab['verified_at']): ?><small>Verifikasi: <?= e(($lab['verifier_code'] ?? '-').' - '.($lab['verifier_name'] ?? '-')) ?></small><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </div>

  <div class="card">
    <h2 class="section-title">Hasil Radiologi</h2>
    <div class="table-wrap"><table><thead><tr><th>Pasien</th><th>Pemeriksaan</th><th>Status / Hasil</th></tr></thead><tbody>
    <?php if (!$radResults): ?><tr><td colspan="3" class="empty-state">Belum ada hasil radiologi.</td></tr><?php endif; ?>
    <?php foreach($radResults as $rad): ?><tr><td><b><?= e($rad['patient_name']) ?></b><br><small><?= e($rad['medical_record_no']) ?></small></td><td><?= e($rad['exam_name']) ?><br><small><?= e($rad['order_no']) ?></small></td><td><span class="badge <?= $rad['rad_status']==='Selesai'?'badge-green':'badge-yellow' ?>"><?= e($rad['rad_status']) ?></span><div class="result-box"><?= $rad['result'] ? e($rad['result']) : 'Belum tersedia' ?></div><?php if($rad['verified_at']): ?><small>Verifikasi: <?= e(($rad['verifier_code'] ?? '-').' - '.($rad['verifier_name'] ?? '-')) ?></small><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
