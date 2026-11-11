<?php
require_once __DIR__ . '/includes/functions.php';
$user = require_login();
require_permission($user, 'triage.manage');
$pageTitle = 'IGD / Triage';
$pdo = db();

$registrations = $pdo->query("SELECT r.id, r.registration_no, p.full_name, p.medical_record_no FROM registrations r JOIN patients p ON p.id=r.patient_id JOIN units u ON u.id=r.unit_id WHERE u.unit_type='IGD' OR u.code='IGD' ORDER BY r.id DESC")->fetchAll();

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $registrationId=(int)($_POST['registration_id']??0);
    $urgency=$_POST['urgency']??'';
    if($registrationId && $urgency!==''){
        $stmt=$pdo->prepare('INSERT INTO triage (registration_id,nurse_employee_id,urgency,bp,hr,rr,temp,spo2,notes,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())');
        $stmt->execute([$registrationId,$user['employee_id'],$urgency,trim($_POST['bp']??''),trim($_POST['hr']??''),trim($_POST['rr']??''),trim($_POST['temp']??''),trim($_POST['spo2']??''),trim($_POST['notes']??'')]);
        $triageId=(int)$pdo->lastInsertId();
        audit_log('SAVE_TRIAGE','triage',$triageId,$urgency);
        flash('success','Data triase berhasil disimpan ke database.');
    } else {
        flash('error','Pilih pasien dan kategori triase.');
    }
    redirect('triage.php');
}
$history=$pdo->query("SELECT t.*,p.full_name,p.medical_record_no,e.employee_code,e.full_name AS nurse_name FROM triage t JOIN registrations r ON r.id=t.registration_id JOIN patients p ON p.id=r.patient_id JOIN employees e ON e.id=t.nurse_employee_id ORDER BY t.id DESC LIMIT 20")->fetchAll();
require __DIR__ . '/includes/header.php';
?>
<h1 class="page-title">IGD / Triage</h1><p class="page-subtitle">Data triase tersimpan dengan identitas petugas yang sedang login.</p>
<div class="grid grid-2"><div class="card"><form method="POST"><div class="field"><label>Pasien IGD</label><select class="input" name="registration_id" required><option value="">-- Pilih --</option><?php foreach($registrations as $r): ?><option value="<?= $r['id'] ?>"><?= e($r['registration_no'].' · '.$r['medical_record_no'].' · '.$r['full_name']) ?></option><?php endforeach; ?></select></div><div class="field"><label>Kategori</label><select class="input" name="urgency"><option>Merah</option><option>Kuning</option><option>Hijau</option><option>Hitam</option></select></div><div class="grid grid-2"><div class="field"><label>TD</label><input class="input" name="bp"></div><div class="field"><label>HR</label><input class="input" name="hr"></div><div class="field"><label>RR</label><input class="input" name="rr"></div><div class="field"><label>Suhu</label><input class="input" name="temp"></div><div class="field"><label>SpO₂</label><input class="input" name="spo2"></div></div><div class="field"><label>Catatan</label><textarea class="input" name="notes"></textarea></div><button class="btn btn-primary">Simpan Triase</button></form></div><div class="card"><h2 class="section-title">Riwayat Triase</h2><div class="table-wrap"><table><thead><tr><th>Pasien</th><th>Triage</th><th>Petugas</th></tr></thead><tbody><?php foreach($history as $h): ?><tr><td><?= e($h['full_name']) ?><br><small><?= e($h['medical_record_no']) ?></small></td><td><span class="badge badge-red"><?= e($h['urgency']) ?></span><br><small>TD <?= e($h['bp']) ?> · HR <?= e($h['hr']) ?> · RR <?= e($h['rr']) ?> · SpO₂ <?= e($h['spo2']) ?></small></td><td><?= e($h['employee_code']) ?><br><small><?= e($h['nurse_name']) ?></small></td></tr><?php endforeach; ?></tbody></table></div></div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
