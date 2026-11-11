<?php
require_once __DIR__ . '/includes/functions.php';
$user = require_login();
require_permission($user, 'pharmacy.manage');
$pageTitle = 'Farmasi & Inventory';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_medication'])) {
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $name = trim($_POST['name'] ?? '');
    $unit = trim($_POST['unit'] ?? '');
    $stock = max(0, (int) ($_POST['stock'] ?? 0));
    $expiry = $_POST['expiry_date'] ?? '';
    $provider = trim($_POST['provider'] ?? '');
    $price = max(0, (float) ($_POST['price'] ?? 0));

    if ($code === '' || $name === '' || $unit === '') {
        flash('error', 'Kode, nama obat, dan satuan wajib diisi.');
        redirect('pharmacy.php');
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO medications (code,name,unit,stock,expiry_date,provider,price,is_active)
             VALUES (?,?,?,?,?,?,?,1)'
        );
        $stmt->execute([$code, $name, $unit, $stock, $expiry ?: null, $provider ?: null, $price]);
        $id = (int) $pdo->lastInsertId();
        audit_log('ADD_MEDICATION', 'medications', $id, $code . ' - ' . $name);
        flash('success', 'Obat baru berhasil ditambahkan ke inventory.');
    } catch (PDOException $e) {
        flash('error', 'Kode obat sudah digunakan atau data obat tidak valid.');
    }
    redirect('pharmacy.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_stock'])) {
    $medicationId = (int) ($_POST['medication_id'] ?? 0);
    $quantity = max(0, (int) ($_POST['stock_addition'] ?? 0));
    $expiry = $_POST['new_expiry_date'] ?? '';
    $provider = trim($_POST['new_provider'] ?? '');

    if (!$medicationId || $quantity <= 0) {
        flash('error', 'Pilih obat dan isi jumlah stok tambahan.');
        redirect('pharmacy.php');
    }

    $stmt = $pdo->prepare(
        'UPDATE medications
         SET stock=stock+?, expiry_date=COALESCE(NULLIF(?,\'\'),expiry_date), provider=COALESCE(NULLIF(?,\'\'),provider)
         WHERE id=?'
    );
    $stmt->execute([$quantity, $expiry, $provider, $medicationId]);
    audit_log('ADD_STOCK', 'medications', $medicationId, 'Tambah stok ' . $quantity);
    flash('success', 'Stok obat berhasil ditambahkan.');
    redirect('pharmacy.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dispense_order'])) {
    $pharmacyOrderId = (int) ($_POST['pharmacy_order_id'] ?? 0);
    if (!$pharmacyOrderId) {
        flash('error', 'Order farmasi tidak ditemukan.');
        redirect('pharmacy.php');
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "SELECT po.id, po.order_id, po.medication_id, po.quantity, po.status,
                    m.name, m.stock, m.expiry_date
             FROM pharmacy_orders po
             JOIN medications m ON m.id=po.medication_id
             WHERE po.id=? FOR UPDATE"
        );
        $stmt->execute([$pharmacyOrderId]);
        $order = $stmt->fetch();

        if (!$order || $order['status'] !== 'Menunggu') {
            throw new RuntimeException('Order sudah diproses atau tidak ditemukan.');
        }
        if ($order['expiry_date'] && $order['expiry_date'] < date('Y-m-d')) {
            throw new RuntimeException('Obat sudah melewati tanggal kedaluwarsa.');
        }
        if ((int) $order['stock'] < (int) $order['quantity']) {
            throw new RuntimeException('Stok ' . $order['name'] . ' tidak mencukupi.');
        }

        $pdo->prepare('UPDATE medications SET stock=stock-? WHERE id=?')
            ->execute([(int) $order['quantity'], (int) $order['medication_id']]);
        $pdo->prepare("UPDATE pharmacy_orders SET status='Selesai', dispensed_by=?, dispensed_at=NOW() WHERE id=?")
            ->execute([$user['employee_id'], $pharmacyOrderId]);
        $pdo->prepare("UPDATE orders SET status='Completed' WHERE id=?")
            ->execute([(int) $order['order_id']]);
        $pdo->commit();
        audit_log('DISPENSE_MEDICATION', 'pharmacy_orders', $pharmacyOrderId, $order['name'] . ' x ' . $order['quantity']);
        flash('success', 'Obat berhasil disiapkan dan stok telah diperbarui.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $e->getMessage());
    }
    redirect('pharmacy.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_order'])) {
    $pharmacyOrderId = (int) ($_POST['pharmacy_order_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT order_id FROM pharmacy_orders WHERE id=? AND status='Menunggu' LIMIT 1");
    $stmt->execute([$pharmacyOrderId]);
    $orderId = (int) $stmt->fetchColumn();
    if ($orderId) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE pharmacy_orders SET status='Dibatalkan', dispensed_by=?, dispensed_at=NOW() WHERE id=?")
                ->execute([$user['employee_id'], $pharmacyOrderId]);
            $pdo->prepare("UPDATE orders SET status='Cancelled' WHERE id=?")->execute([$orderId]);
            $pdo->commit();
            audit_log('CANCEL_MEDICATION_ORDER', 'pharmacy_orders', $pharmacyOrderId, 'Order dibatalkan');
            flash('success', 'Order obat dibatalkan tanpa mengurangi stok.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', 'Order belum dapat dibatalkan.');
        }
    }
    redirect('pharmacy.php');
}

$queue = $pdo->query(
    "SELECT po.id, po.quantity, po.status, o.order_no, o.detail, o.priority,
            m.code AS medication_code, m.name AS medication_name, m.unit, m.stock, m.expiry_date,
            p.full_name AS patient_name, p.medical_record_no,
            d.employee_code AS doctor_code, d.full_name AS doctor_name
     FROM pharmacy_orders po
     JOIN orders o ON o.id=po.order_id
     JOIN medications m ON m.id=po.medication_id
     JOIN registrations r ON r.id=o.registration_id
     JOIN patients p ON p.id=r.patient_id
     JOIN employees d ON d.id=o.ordering_employee_id
     WHERE po.status='Menunggu'
     ORDER BY po.id"
)->fetchAll();

$medications = $pdo->query(
    'SELECT id, code, name, unit, stock, expiry_date, provider, price
     FROM medications WHERE is_active=1 ORDER BY name'
)->fetchAll();

$history = $pdo->query(
    "SELECT po.status, po.quantity, po.dispensed_at, o.order_no, m.name AS medication_name,
            p.full_name AS patient_name, e.employee_code AS staff_code, e.full_name AS staff_name
     FROM pharmacy_orders po
     JOIN orders o ON o.id=po.order_id
     JOIN medications m ON m.id=po.medication_id
     JOIN registrations r ON r.id=o.registration_id
     JOIN patients p ON p.id=r.patient_id
     LEFT JOIN employees e ON e.id=po.dispensed_by
     WHERE po.status IN ('Selesai','Dibatalkan')
     ORDER BY po.id DESC LIMIT 15"
)->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<h1 class="page-title">Farmasi & Inventory</h1>
<p class="page-subtitle">Resep elektronik dari CPOE masuk ke farmasi, stok berkurang saat dispensing, dan data provider serta tanggal kedaluwarsa tersimpan.</p>

<div class="grid grid-2">
  <div class="card">
    <h2 class="section-title">Antrian Resep Elektronik</h2>
    <?php if (!$queue): ?><div class="empty-state">Tidak ada resep yang menunggu.</div><?php endif; ?>
    <?php foreach($queue as $row): ?>
      <div style="padding:13px 0;border-bottom:1px solid #edf0f4">
        <div class="actions" style="justify-content:space-between">
          <div>
            <b><?= e($row['patient_name']) ?></b> · <?= e($row['order_no']) ?><br>
            <small><?= e($row['medical_record_no'].' · '.$row['doctor_code'].' - '.$row['doctor_name']) ?></small>
          </div>
          <span class="badge badge-yellow">Menunggu</span>
        </div>
        <p style="margin:8px 0 4px"><b><?= e($row['medication_name']) ?></b> · <?= (int)$row['quantity'] ?> <?= e($row['unit']) ?></p>
        <small>Stok: <?= (int)$row['stock'] ?> · Exp: <?= e($row['expiry_date'] ?? '-') ?> · <?= e($row['detail']) ?></small>
        <div class="actions" style="margin-top:10px">
          <form method="POST" onsubmit="return confirm('Selesaikan resep dan kurangi stok obat?')">
            <input type="hidden" name="pharmacy_order_id" value="<?= $row['id'] ?>">
            <button class="btn btn-success" name="dispense_order">Selesaikan</button>
          </form>
          <form method="POST" onsubmit="return confirm('Batalkan order obat ini?')">
            <input type="hidden" name="pharmacy_order_id" value="<?= $row['id'] ?>">
            <button class="btn btn-secondary" name="cancel_order">Batalkan</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <h2 class="section-title">Tambah Obat</h2>
    <form method="POST">
      <input type="hidden" name="add_medication" value="1">
      <div class="grid grid-2">
        <div class="field"><label>Kode Obat</label><input class="input" name="code" placeholder="MED-006" required></div>
        <div class="field"><label>Nama Obat</label><input class="input" name="name" required></div>
        <div class="field"><label>Satuan</label><input class="input" name="unit" placeholder="tablet / kapsul / botol" required></div>
        <div class="field"><label>Stok Awal</label><input class="input" type="number" min="0" name="stock" value="0"></div>
        <div class="field"><label>Expired Date</label><input class="input" type="date" name="expiry_date"></div>
        <div class="field"><label>Provider / Supplier</label><input class="input" name="provider"></div>
        <div class="field"><label>Harga Satuan</label><input class="input" type="number" min="0" step="100" name="price" value="0"></div>
      </div>
      <button class="btn btn-primary">Tambah Obat</button>
    </form>
  </div>
</div>

<div class="card" style="margin-top:18px">
  <h2 class="section-title">Inventory Obat</h2>
  <div class="table-wrap"><table><thead><tr><th>Kode / Obat</th><th>Stok</th><th>Expired</th><th>Provider</th><th>Tambah Stok</th></tr></thead><tbody>
  <?php foreach($medications as $med): ?>
    <tr>
      <td><b><?= e($med['code']) ?></b><br><?= e($med['name']) ?><br><small>Rp <?= number_format((float)$med['price'],0,',','.') ?> / <?= e($med['unit']) ?></small></td>
      <td><span class="badge <?= (int)$med['stock']<20?'badge-red':'badge-green' ?>"><?= (int)$med['stock'] ?> <?= e($med['unit']) ?></span></td>
      <td><?= e($med['expiry_date'] ?? '-') ?></td>
      <td><?= e($med['provider'] ?? '-') ?></td>
      <td>
        <form method="POST" class="toolbar" style="margin:0">
          <input type="hidden" name="add_stock" value="1">
          <input type="hidden" name="medication_id" value="<?= $med['id'] ?>">
          <input class="input" style="width:90px" type="number" name="stock_addition" min="1" placeholder="Qty" required>
          <input class="input" style="width:145px" type="date" name="new_expiry_date">
          <input class="input" style="width:180px" name="new_provider" placeholder="Provider">
          <button class="btn btn-primary btn-sm">Tambah</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>

<div class="card" style="margin-top:18px">
  <h2 class="section-title">Riwayat Dispensing</h2>
  <div class="table-wrap"><table><thead><tr><th>Order</th><th>Pasien</th><th>Obat</th><th>Status</th><th>Petugas</th></tr></thead><tbody>
  <?php if(!$history): ?><tr><td colspan="5" class="empty-state">Belum ada riwayat dispensing.</td></tr><?php endif; ?>
  <?php foreach($history as $row): ?><tr><td><?= e($row['order_no']) ?></td><td><?= e($row['patient_name']) ?></td><td><?= e($row['medication_name']) ?> x <?= (int)$row['quantity'] ?></td><td><span class="badge <?= $row['status']==='Selesai'?'badge-green':'badge-yellow' ?>"><?= e($row['status']) ?></span></td><td><?= e(($row['staff_code'] ?? '-').' - '.($row['staff_name'] ?? '-')) ?><br><small><?= e($row['dispensed_at'] ?? '-') ?></small></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
