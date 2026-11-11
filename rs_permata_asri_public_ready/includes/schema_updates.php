<?php

declare(strict_types=1);

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}

function ensure_progress03_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS patient_registration_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            request_no VARCHAR(40) NULL UNIQUE,
            nik VARCHAR(16) NOT NULL,
            full_name VARCHAR(150) NOT NULL,
            birth_date DATE NOT NULL,
            gender ENUM('L','P') NOT NULL,
            address TEXT NULL,
            phone VARCHAR(30) NOT NULL,
            email VARCHAR(120) NULL,
            payer VARCHAR(40) NOT NULL DEFAULT 'Umum',
            unit_id INT NOT NULL,
            doctor_employee_id INT NOT NULL,
            schedule_id INT NOT NULL,
            visit_date DATE NOT NULL,
            complaint TEXT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'Menunggu Verifikasi',
            admin_note TEXT NULL,
            patient_id INT NULL,
            registration_id INT NULL,
            reviewed_by INT NULL,
            reviewed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_prr_status (status),
            INDEX idx_prr_visit_date (visit_date),
            CONSTRAINT fk_prr_unit FOREIGN KEY (unit_id) REFERENCES units(id),
            CONSTRAINT fk_prr_doctor FOREIGN KEY (doctor_employee_id) REFERENCES employees(id),
            CONSTRAINT fk_prr_schedule FOREIGN KEY (schedule_id) REFERENCES doctor_schedules(id),
            CONSTRAINT fk_prr_patient FOREIGN KEY (patient_id) REFERENCES patients(id),
            CONSTRAINT fk_prr_registration FOREIGN KEY (registration_id) REFERENCES registrations(id),
            CONSTRAINT fk_prr_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id)
        ) ENGINE=InnoDB"
    );

    $menuCheck = $pdo->prepare('SELECT id FROM menus WHERE page_file=? LIMIT 1');
    $menuCheck->execute(['patient_registration_requests.php']);
    if (!$menuCheck->fetchColumn()) {
        $menuInsert = $pdo->prepare(
            "INSERT INTO menus (label, icon, section_name, page_file, permission_code, sort_order, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)"
        );
        $menuInsert->execute([
            'Registrasi Online',
            '▦',
            'TRANSAKSI',
            'patient_registration_requests.php',
            'registration.manage',
            19,
        ]);
    }

    $units = [
        ['PB', 'Poli Bedah', 'POLI'],
        ['PK', 'Poli Kandungan', 'POLI'],
    ];
    $unitInsert = $pdo->prepare(
        'INSERT INTO units (code, name, unit_type)
         SELECT ?, ?, ?
         WHERE NOT EXISTS (SELECT 1 FROM units WHERE code=?)'
    );
    foreach ($units as [$code, $name, $type]) {
        $unitInsert->execute([$code, $name, $type, $code]);
    }

    $roleStmt = $pdo->prepare("SELECT id FROM roles WHERE code='dokter' LIMIT 1");
    $roleStmt->execute();
    $doctorRoleId = (int) $roleStmt->fetchColumn();

    if ($doctorRoleId > 0) {
        $doctorSeeds = [
            ['PEG-DOK-003', 'dr. Raka Mahendra', 'Dokter', 'PU'],
            ['PEG-DOK-004', 'dr. Nadia Putri, Sp.PD', 'Dokter Sp.PD', 'PD'],
            ['PEG-DOK-005', 'dr. Salsabila Rahman, Sp.A', 'Dokter Sp.A', 'PA'],
            ['PEG-DOK-006', 'dr. Bima Aditya, Sp.B', 'Dokter Sp.B', 'PB'],
            ['PEG-DOK-007', 'dr. Citra Maharani, Sp.OG', 'Dokter Sp.OG', 'PK'],
        ];

        $unitIdStmt = $pdo->prepare('SELECT id FROM units WHERE code=? LIMIT 1');
        $employeeExists = $pdo->prepare('SELECT id FROM employees WHERE employee_code=? LIMIT 1');
        $employeeInsert = $pdo->prepare(
            'INSERT INTO employees (employee_code, full_name, profession, role_id, unit_id, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        );

        foreach ($doctorSeeds as [$employeeCode, $name, $profession, $unitCode]) {
            $employeeExists->execute([$employeeCode]);
            if ($employeeExists->fetchColumn()) {
                continue;
            }

            $unitIdStmt->execute([$unitCode]);
            $unitId = (int) $unitIdStmt->fetchColumn();
            if ($unitId > 0) {
                $employeeInsert->execute([$employeeCode, $name, $profession, $doctorRoleId, $unitId]);
            }
        }

        $scheduleSeeds = [
            ['PEG-DOK-003', 'PU', 'Selasa', '08:00:00', '12:00:00'],
            ['PEG-DOK-003', 'PU', 'Jumat', '08:00:00', '11:00:00'],
            ['PEG-DOK-004', 'PD', 'Senin', '13:00:00', '16:00:00'],
            ['PEG-DOK-004', 'PD', 'Jumat', '09:00:00', '12:00:00'],
            ['PEG-DOK-005', 'PA', 'Senin', '09:00:00', '12:00:00'],
            ['PEG-DOK-005', 'PA', 'Kamis', '13:00:00', '16:00:00'],
            ['PEG-DOK-006', 'PB', 'Selasa', '13:00:00', '16:00:00'],
            ['PEG-DOK-006', 'PB', 'Jumat', '13:00:00', '16:00:00'],
            ['PEG-DOK-007', 'PK', 'Rabu', '09:00:00', '12:00:00'],
            ['PEG-DOK-007', 'PK', 'Sabtu', '08:00:00', '11:00:00'],
        ];

        $employeeIdStmt = $pdo->prepare('SELECT id FROM employees WHERE employee_code=? LIMIT 1');
        $scheduleExists = $pdo->prepare(
            'SELECT id FROM doctor_schedules
             WHERE employee_id=? AND unit_id=? AND day_name=? AND start_time=? AND end_time=? LIMIT 1'
        );
        $scheduleInsert = $pdo->prepare(
            'INSERT INTO doctor_schedules (employee_id, unit_id, day_name, start_time, end_time)
             VALUES (?, ?, ?, ?, ?)'
        );

        foreach ($scheduleSeeds as [$employeeCode, $unitCode, $dayName, $startTime, $endTime]) {
            $employeeIdStmt->execute([$employeeCode]);
            $employeeId = (int) $employeeIdStmt->fetchColumn();
            $unitIdStmt->execute([$unitCode]);
            $unitId = (int) $unitIdStmt->fetchColumn();
            if (!$employeeId || !$unitId) {
                continue;
            }
            $scheduleExists->execute([$employeeId, $unitId, $dayName, $startTime, $endTime]);
            if (!$scheduleExists->fetchColumn()) {
                $scheduleInsert->execute([$employeeId, $unitId, $dayName, $startTime, $endTime]);
            }
        }
    }
}

function ensure_final_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    ensure_progress03_schema($pdo);

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS medications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(30) NOT NULL UNIQUE,
            name VARCHAR(150) NOT NULL,
            unit VARCHAR(30) NOT NULL DEFAULT 'tablet',
            stock INT NOT NULL DEFAULT 0,
            expiry_date DATE NULL,
            provider VARCHAR(150) NULL,
            price DECIMAL(12,2) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS pharmacy_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL UNIQUE,
            medication_id INT NULL,
            quantity INT NOT NULL DEFAULT 1,
            status VARCHAR(40) NOT NULL DEFAULT 'Menunggu',
            dispensed_by INT NULL,
            dispensed_at DATETIME NULL,
            notes TEXT NULL,
            INDEX idx_pharmacy_status (status),
            CONSTRAINT fk_pharmacy_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            CONSTRAINT fk_pharmacy_medication FOREIGN KEY (medication_id) REFERENCES medications(id),
            CONSTRAINT fk_pharmacy_employee FOREIGN KEY (dispensed_by) REFERENCES employees(id)
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS consultation_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL UNIQUE,
            consultant_employee_id INT NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'Menunggu',
            response TEXT NULL,
            responded_at DATETIME NULL,
            INDEX idx_consult_status (status),
            CONSTRAINT fk_consult_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            CONSTRAINT fk_consult_doctor FOREIGN KEY (consultant_employee_id) REFERENCES employees(id)
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS audit_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            action VARCHAR(120) NOT NULL,
            entity_type VARCHAR(80) NULL,
            entity_id INT NULL,
            details TEXT NULL,
            ip_address VARCHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_audit_user (user_id),
            INDEX idx_audit_created (created_at),
            CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB"
    );

    if (!column_exists($pdo, 'orders', 'medication_id')) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN medication_id INT NULL AFTER detail');
    }
    if (!column_exists($pdo, 'orders', 'quantity')) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN quantity INT NULL AFTER medication_id');
    }
    if (!column_exists($pdo, 'orders', 'target_employee_id')) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN target_employee_id INT NULL AFTER quantity');
    }

    $roleInsert = $pdo->prepare(
        'INSERT INTO roles (code, name) SELECT ?, ? WHERE NOT EXISTS (SELECT 1 FROM roles WHERE code=?)'
    );
    $roleInsert->execute(['farmasi', 'Farmasi / Apoteker', 'farmasi']);

    $unitInsert = $pdo->prepare(
        'INSERT INTO units (code, name, unit_type) SELECT ?, ?, ? WHERE NOT EXISTS (SELECT 1 FROM units WHERE code=?)'
    );
    $unitInsert->execute(['FARM', 'Instalasi Farmasi', 'PENUNJANG', 'FARM']);

    $permissionSeeds = [
        ['pharmacy.manage', 'Kelola Farmasi & Inventory'],
        ['audit.view', 'Lihat Audit Trail'],
    ];
    $permissionInsert = $pdo->prepare(
        'INSERT INTO permissions (code, name) SELECT ?, ? WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code=?)'
    );
    foreach ($permissionSeeds as [$code, $name]) {
        $permissionInsert->execute([$code, $name, $code]);
    }

    $adminRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='admin_simrs' LIMIT 1")->fetchColumn();
    if ($adminRoleId) {
        $pdo->prepare(
            'INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT ?, id FROM permissions'
        )->execute([$adminRoleId]);
    }

    $pharmacyRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='farmasi' LIMIT 1")->fetchColumn();
    if ($pharmacyRoleId) {
        $pharmacyPermissions = ['dashboard.view', 'pharmacy.manage', 'reporting.view'];
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT ?, id FROM permissions WHERE code=?'
        );
        foreach ($pharmacyPermissions as $permissionCode) {
            $stmt->execute([$pharmacyRoleId, $permissionCode]);
        }
    }

    $doctorRoleIdFinal = (int) $pdo->query("SELECT id FROM roles WHERE code='dokter' LIMIT 1")->fetchColumn();
    if ($doctorRoleIdFinal) {
        $pdo->prepare("DELETE rp FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=? AND p.code IN ('registration.manage','inpatient.manage')")
            ->execute([$doctorRoleIdFinal]);
    }

    $recordRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='rekammedis' LIMIT 1")->fetchColumn();
    if ($recordRoleId) {
        $pdo->prepare(
            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT ?, id FROM permissions WHERE code='audit.view'"
        )->execute([$recordRoleId]);
    }

    $existingPharmacyMenu = $pdo->prepare("SELECT id FROM menus WHERE label='Farmasi & Inventory' LIMIT 1");
    $existingPharmacyMenu->execute();
    $pharmacyMenuId = (int) $existingPharmacyMenu->fetchColumn();
    if ($pharmacyMenuId) {
        $pdo->prepare(
            "UPDATE menus SET icon='Rx', section_name='PELAYANAN', page_file='pharmacy.php', permission_code='pharmacy.manage', sort_order=36, is_active=1 WHERE id=?"
        )->execute([$pharmacyMenuId]);
    } else {
        $pdo->prepare(
            "INSERT INTO menus (label, icon, section_name, page_file, permission_code, sort_order, is_active)
             VALUES ('Farmasi & Inventory','Rx','PELAYANAN','pharmacy.php','pharmacy.manage',36,1)"
        )->execute();
    }

    $auditMenuCheck = $pdo->prepare("SELECT id FROM menus WHERE page_file='audit.php' LIMIT 1");
    $auditMenuCheck->execute();
    if (!$auditMenuCheck->fetchColumn()) {
        $pdo->prepare(
            "INSERT INTO menus (label, icon, section_name, page_file, permission_code, sort_order, is_active)
             VALUES ('Audit Trail','◎','REPORTING','audit.php','audit.view',41,1)"
        )->execute();
    }

    $farmUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='FARM' LIMIT 1")->fetchColumn();
    if ($pharmacyRoleId && $farmUnitId) {
        $employeeCheck = $pdo->prepare("SELECT id FROM employees WHERE employee_code='PEG-FAR-001' LIMIT 1");
        $employeeCheck->execute();
        $pharmacistId = (int) $employeeCheck->fetchColumn();
        if (!$pharmacistId) {
            $pdo->prepare(
                'INSERT INTO employees (employee_code, full_name, profession, role_id, unit_id, is_active) VALUES (?,?,?,?,?,1)'
            )->execute(['PEG-FAR-001', 'apt. Sari Dewi, S.Farm.', 'Apoteker', $pharmacyRoleId, $farmUnitId]);
            $pharmacistId = (int) $pdo->lastInsertId();
        }
    }

    $medications = [
        ['MED-001', 'Paracetamol 500 mg', 'tablet', 500, '2028-12-31', 'PT Sehat Farma', 500],
        ['MED-002', 'Amoxicillin 500 mg', 'kapsul', 300, '2028-09-30', 'PT Medika Nusantara', 2500],
        ['MED-003', 'Omeprazole 20 mg', 'kapsul', 200, '2028-10-31', 'PT Sehat Farma', 3000],
        ['MED-004', 'Metformin 500 mg', 'tablet', 400, '2029-01-31', 'PT Farma Sentosa', 1500],
        ['MED-005', 'Amlodipine 5 mg', 'tablet', 250, '2028-08-31', 'PT Farma Sentosa', 4000],
    ];
    $medCheck = $pdo->prepare('SELECT id FROM medications WHERE code=? LIMIT 1');
    $medInsert = $pdo->prepare(
        'INSERT INTO medications (code,name,unit,stock,expiry_date,provider,price,is_active) VALUES (?,?,?,?,?,?,?,1)'
    );
    foreach ($medications as $med) {
        $medCheck->execute([$med[0]]);
        if (!$medCheck->fetchColumn()) {
            $medInsert->execute($med);
        }
    }

    $accountSeeds = [
        'PEG-ADM-001' => ['novella', 'permata123'],
        'PEG-DOK-001' => ['andi', 'andi123'],
        'PEG-DOK-002' => ['maya', 'maya123'],
        'PEG-DOK-003' => ['raka', 'raka123'],
        'PEG-DOK-004' => ['nadia', 'nadia123'],
        'PEG-DOK-005' => ['salsabila', 'salsabila123'],
        'PEG-DOK-006' => ['bima', 'bima123'],
        'PEG-DOK-007' => ['citra', 'citra123'],
        'PEG-PRW-001' => ['intan', 'intan123'],
        'PEG-LAB-001' => ['dewi.lab', 'dewi123'],
        'PEG-RAD-001' => ['rizky.rad', 'rizky123'],
        'PEG-IGD-001' => ['rani.igd', 'rani123'],
        'PEG-ADM-002' => ['rika.admisi', 'rika123'],
        'PEG-RM-001' => ['sinta.rm', 'sinta123'],
        'PEG-FAR-001' => ['sari.farmasi', 'sari123'],
    ];

    $employeeStmt = $pdo->prepare('SELECT id FROM employees WHERE employee_code=? LIMIT 1');
    $userByEmployee = $pdo->prepare('SELECT id, username FROM users WHERE employee_id=? LIMIT 1');
    $userByName = $pdo->prepare('SELECT id FROM users WHERE username=? LIMIT 1');
    $insertUser = $pdo->prepare('INSERT INTO users (employee_id, username, password_hash, is_active) VALUES (?,?,?,1)');
    $updateUser = $pdo->prepare('UPDATE users SET username=?, password_hash=?, is_active=1 WHERE id=?');

    foreach ($accountSeeds as $employeeCode => [$username, $password]) {
        $employeeStmt->execute([$employeeCode]);
        $employeeId = (int) $employeeStmt->fetchColumn();
        if (!$employeeId) {
            continue;
        }

        $userByEmployee->execute([$employeeId]);
        $existing = $userByEmployee->fetch();
        $userByName->execute([$username]);
        $usernameOwner = (int) $userByName->fetchColumn();

        if ($existing) {
            if ($existing['username'] !== $username && (!$usernameOwner || $usernameOwner === (int) $existing['id'])) {
                $updateUser->execute([$username, password_hash($password, PASSWORD_DEFAULT), (int) $existing['id']]);
            }
        } elseif (!$usernameOwner) {
            $insertUser->execute([$employeeId, $username, password_hash($password, PASSWORD_DEFAULT)]);
        }
    }
}
