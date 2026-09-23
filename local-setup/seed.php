<?php
/**
 * Local seed for KAMALTUR POS — dev only.
 * Creates the base agency, superadmin + admin users, and a bit of
 * sample data so the dashboards are not empty.
 *
 * Credentials are printed at the end. Change them before real use.
 */

declare(strict_types=1);

require __DIR__ . '/../inc/db.php';

function seed_run(string $msg, callable $fn): void {
    try {
        $fn();
        echo "  ok  {$msg}\n";
    } catch (Throwable $e) {
        echo "  FAIL {$msg} :: " . $e->getMessage() . "\n";
        exit(1);
    }
}

$agencyId = 1;
$superLogin = 'master';
$superPass  = 'Master!2026';
$adminLogin = 'admin';
$adminPass  = 'Admin!2026';

echo "Seeding KAMALTUR POS local database...\n";

// ---- agency -----------------------------------------------------------
seed_run('agency', function () use ($pdo, $agencyId) {
    $st = $pdo->prepare("SELECT id FROM agencies WHERE id = ? LIMIT 1");
    $st->execute([$agencyId]);
    if ($st->fetch()) {
        $u = $pdo->prepare("UPDATE agencies SET name=?, fantasy_name=?, legal_name=?, cnpj=?, email=?, phone=?, city=?, uf=?, updated_at=NOW() WHERE id=?");
        $u->execute(['KAMALTUR VIAGENS', 'KAMALTUR', 'KAMALTUR TURISMO LTDA', '13403060000105', 'kamaltur@kamaltur.com.br', '+55 61 9 9393-2819', 'Brasília', 'DF', $agencyId]);
    } else {
        $i = $pdo->prepare("INSERT INTO agencies (id, name, fantasy_name, legal_name, cnpj, email, phone, city, uf, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())");
        $i->execute([$agencyId, 'KAMALTUR VIAGENS', 'KAMALTUR', 'KAMALTUR TURISMO LTDA', '13403060000105', 'kamaltur@kamaltur.com.br', '+55 61 9 9393-2819', 'Brasília', 'DF']);
    }
});

// ---- users ------------------------------------------------------------
seed_run('superadmin', function () use ($pdo, $agencyId, $superLogin, $superPass) {
    $st = $pdo->prepare("SELECT id FROM users WHERE LOWER(login) = ? LIMIT 1");
    $st->execute([$superLogin]);
    $hash = password_hash($superPass, PASSWORD_DEFAULT);
    if ($r = $st->fetch()) {
        $u = $pdo->prepare("UPDATE users SET password_hash=?, role='superadmin', agency_id=?, is_active=1 WHERE id=?");
        $u->execute([$hash, $agencyId, (int)$r['id']]);
    } else {
        $i = $pdo->prepare("INSERT INTO users (name,email,login,password_hash,role,agency_id,is_active,created_at,updated_at) VALUES (?,?,?,?,'superadmin',?,1,NOW(),NOW())");
        $i->execute(['Master', 'master@kamaltur.local', $superLogin, $hash, $agencyId]);
    }
});

seed_run('admin', function () use ($pdo, $agencyId, $adminLogin, $adminPass) {
    $st = $pdo->prepare("SELECT id FROM users WHERE LOWER(login) = ? LIMIT 1");
    $st->execute([$adminLogin]);
    $hash = password_hash($adminPass, PASSWORD_DEFAULT);
    if ($r = $st->fetch()) {
        $u = $pdo->prepare("UPDATE users SET password_hash=?, role='admin', agency_id=?, is_active=1 WHERE id=?");
        $u->execute([$hash, $agencyId, (int)$r['id']]);
    } else {
        $i = $pdo->prepare("INSERT INTO users (name,email,login,password_hash,role,agency_id,is_active,created_at,updated_at) VALUES (?,?,?,?,'admin',?,1,NOW(),NOW())");
        $i->execute(['Administrador', 'admin@kamaltur.local', $adminLogin, $hash, $agencyId]);
    }
});

// ---- sample data -------------------------------------------------------
seed_run('sample client', function () use ($pdo, $agencyId) {
    $st = $pdo->prepare("SELECT id FROM clients WHERE name='CLIENTE LOCAL TESTE' AND agency_id=? LIMIT 1");
    $st->execute([$agencyId]);
    if ($st->fetch()) return;
    $i = $pdo->prepare("INSERT INTO clients (client_type,name,document,phone,email,address,gender,agency_id,created_by,created_at,updated_at) VALUES ('pf','CLIENTE LOCAL TESTE','00000000000','(00) 00000-0000','cliente@teste.local','Rua Teste 123','M',?,1,NOW(),NOW())");
    $i->execute([$agencyId]);
});

seed_run('sample supplier', function () use ($pdo, $agencyId) {
    $st = $pdo->prepare("SELECT id FROM suppliers WHERE name='FORNECEDOR LOCAL TESTE' AND agency_id=? LIMIT 1");
    $st->execute([$agencyId]);
    if ($st->fetch()) return;
    $i = $pdo->prepare("INSERT INTO suppliers (supplier_type,name,document,phone,email,address,is_active,agency_id,created_at,updated_at) VALUES ('pj','FORNECEDOR LOCAL TESTE','00000000000000','(00) 00000-0000','fornecedor@teste.local','Rua Teste 456',1,?,NOW(),NOW())");
    $i->execute([$agencyId]);
});

echo "\nDONE.\n";
echo "-----------------------------------------------------------\n";
echo "  Local login credentials\n";
echo "  Superadmin:  {$superLogin} / {$superPass}\n";
echo "  Admin:       {$adminLogin} / {$adminPass}\n";
echo "-----------------------------------------------------------\n";