<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/schema_updates.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

ensure_final_schema(db());

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT u.id AS user_id, u.username, e.id AS employee_id, e.employee_code,
                e.full_name, e.profession, r.id AS role_id, r.code AS role_code,
                r.name AS role_name, un.id AS unit_id, un.name AS unit_name
         FROM users u
         JOIN employees e ON e.id = u.employee_id
         JOIN roles r ON r.id = e.role_id
         LEFT JOIN units un ON un.id = e.unit_id
         WHERE u.id = ? AND u.is_active = 1 AND e.is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('login.php');
    }
    return $user;
}

function user_has_permission(int $roleId, string $permissionCode): bool
{
    $stmt = db()->prepare(
        'SELECT 1
         FROM role_permissions rp
         JOIN permissions p ON p.id = rp.permission_id
         WHERE rp.role_id = ? AND p.code = ?
         LIMIT 1'
    );
    $stmt->execute([$roleId, $permissionCode]);
    return (bool) $stmt->fetchColumn();
}

function require_permission(array $user, string $permissionCode): void
{
    if (!user_has_permission((int) $user['role_id'], $permissionCode)) {
        http_response_code(403);
        echo '<h2 style="font-family:Arial">403 — Akses ditolak</h2><p>Role Anda tidak memiliki akses ke halaman ini.</p>';
        exit;
    }
}

function sidebar_menus(int $roleId): array
{
    $stmt = db()->prepare(
        'SELECT DISTINCT m.label, m.icon, m.section_name, m.page_file, m.sort_order
         FROM menus m
         JOIN permissions p ON p.code = m.permission_code
         JOIN role_permissions rp ON rp.permission_id = p.id
         WHERE rp.role_id = ? AND m.is_active = 1
         ORDER BY m.sort_order, m.id'
    );
    $stmt->execute([$roleId]);
    return $stmt->fetchAll();
}

function flash(string $key, ?string $value = null): ?string
{
    if ($value !== null) {
        $_SESSION['_flash'][$key] = $value;
        return null;
    }

    if (!isset($_SESSION['_flash'][$key])) {
        return null;
    }

    $message = $_SESSION['_flash'][$key];
    unset($_SESSION['_flash'][$key]);
    return $message;
}

function make_code(string $prefix, int $id, int $pad = 4): string
{
    return $prefix . '-' . str_pad((string) $id, $pad, '0', STR_PAD_LEFT);
}

function is_privileged_record_viewer(array $user): bool
{
    return in_array($user['role_code'], ['admin_simrs', 'rekammedis'], true);
}

function can_access_registration(array $user, int $registrationId): bool
{
    if ($registrationId <= 0) {
        return false;
    }

    if (is_privileged_record_viewer($user)) {
        return true;
    }

    if ($user['role_code'] === 'dokter') {
        $stmt = db()->prepare(
            "SELECT 1
             FROM registrations r
             WHERE r.id=? AND (
                 r.doctor_employee_id=? OR
                 EXISTS (
                     SELECT 1 FROM orders o
                     JOIN consultation_orders co ON co.order_id=o.id
                     WHERE o.registration_id=r.id AND co.consultant_employee_id=?
                 )
             ) LIMIT 1"
        );
        $stmt->execute([$registrationId, $user['employee_id'], $user['employee_id']]);
        return (bool) $stmt->fetchColumn();
    }

    if ($user['role_code'] === 'igd') {
        $stmt = db()->prepare(
            "SELECT 1 FROM registrations r JOIN units u ON u.id=r.unit_id
             WHERE r.id=? AND (u.code='IGD' OR u.unit_type='IGD') LIMIT 1"
        );
        $stmt->execute([$registrationId]);
        return (bool) $stmt->fetchColumn();
    }

    if ($user['role_code'] === 'perawat') {
        $stmt = db()->prepare(
            "SELECT 1 FROM registrations r
             LEFT JOIN inpatient_admissions ia ON ia.registration_id=r.id AND ia.status='Dirawat'
             WHERE r.id=? AND (r.status='Rawat Inap' OR ia.id IS NOT NULL) LIMIT 1"
        );
        $stmt->execute([$registrationId]);
        return (bool) $stmt->fetchColumn();
    }

    return false;
}

function require_registration_access(array $user, int $registrationId): void
{
    if (!can_access_registration($user, $registrationId)) {
        http_response_code(403);
        echo '<h2 style="font-family:Arial">403 — Rekam medis tidak dapat diakses</h2><p>Pasien ini tidak terdaftar pada dokter atau unit Anda.</p>';
        exit;
    }
}

function registration_filter(array $user, string $alias = 'r'): array
{
    if (is_privileged_record_viewer($user)) {
        return ['1=1', []];
    }

    if ($user['role_code'] === 'dokter') {
        return [
            "({$alias}.doctor_employee_id=? OR EXISTS (SELECT 1 FROM orders oflt JOIN consultation_orders cflt ON cflt.order_id=oflt.id WHERE oflt.registration_id={$alias}.id AND cflt.consultant_employee_id=?))",
            [(int) $user['employee_id'], (int) $user['employee_id']]
        ];
    }

    if ($user['role_code'] === 'igd') {
        return ["EXISTS (SELECT 1 FROM units uf WHERE uf.id={$alias}.unit_id AND (uf.code='IGD' OR uf.unit_type='IGD'))", []];
    }

    if ($user['role_code'] === 'perawat') {
        return ["({$alias}.status='Rawat Inap' OR EXISTS (SELECT 1 FROM inpatient_admissions iaf WHERE iaf.registration_id={$alias}.id AND iaf.status='Dirawat'))", []];
    }

    return ['1=0', []];
}

function audit_log(string $action, ?string $entityType = null, ?int $entityId = null, ?string $details = null): void
{
    try {
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt = db()->prepare(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?,?,?,?,?,?)'
        );
        $stmt->execute([$userId ?: null, $action, $entityType, $entityId, $details, $ip]);
    } catch (Throwable $e) {
        // Audit tidak boleh menghentikan pelayanan utama.
    }
}
