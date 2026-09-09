<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';
require_login();

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$err = '';
$msg = (string)($_GET['msg'] ?? '');
$uid = user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $err = 'Sessão expirada. Recarregue a página e tente novamente.';
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $position = trim((string)($_POST['position'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($name === '') {
            $err = 'Informe o nome.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Email inválido.';
        }

        if ($err === '') {
            try {
                $ck = $pdo->prepare("SELECT 1 FROM users WHERE email=? AND id<>? AND agency_id=? LIMIT 1");
                $ck->execute([$email, $uid, agency_id()]);
                if ($ck->fetch()) {
                    $err = 'Este email já está em uso.';
                } else {
                    if ($password !== '') {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $st = $pdo->prepare("UPDATE users SET name=?, email=?, phone=?, position=?, password_hash=?, updated_at=NOW() WHERE id=? AND agency_id=? LIMIT 1");
                        $st->execute([$name, $email, $phone ?: null, $position ?: null, $hash, $uid, agency_id()]);
                    } else {
                        $st = $pdo->prepare("UPDATE users SET name=?, email=?, phone=?, position=?, updated_at=NOW() WHERE id=? AND agency_id=? LIMIT 1");
                        $st->execute([$name, $email, $phone ?: null, $position ?: null, $uid, agency_id()]);
                    }

                    $_SESSION['name'] = $name;
                    $_SESSION['email'] = $email;
                    header('Location: /profile.php?msg=saved');
                    exit;
                }
            } catch (Throwable $e) {
                error_log('[PROFILE_SAVE] ' . $e->getMessage());
                $err = 'Não foi possível salvar o perfil.';
            }
        }
    }
}

$st = $pdo->prepare("SELECT id, name, email, role, phone, position, login, cpf, birth_date FROM users WHERE id=? AND agency_id=? LIMIT 1");
$st->execute([$uid, agency_id()]);
$user = $st->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    http_response_code(404);
    exit('Usuário não encontrado.');
}

$token = csrf_token();
$pageTitle = 'Profile';
require_once __DIR__ . '/inc/header.php';
?>

<div class="page-header d-print-none mb-3">
  <div class="row align-items-center">
    <div class="col">
      <h2 class="page-title">Profile</h2>
      <div class="text-muted small">Dados do usuário atual.</div>
    </div>
    <div class="col-auto ms-auto">
      <a href="/dashboard.php" class="btn"><i class="ti ti-arrow-left"></i> Voltar</a>
      <button class="btn btn-primary" form="profileForm"><i class="ti ti-device-floppy"></i> Salvar</button>
    </div>
  </div>
</div>

<?php if ($msg === 'saved'): ?>
  <div class="alert alert-success">Profile salvo com sucesso.</div>
<?php endif; ?>
<?php if ($err !== ''): ?>
  <div class="alert alert-danger"><?= h($err) ?></div>
<?php endif; ?>

<form method="post" id="profileForm" class="card" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= h($token) ?>">
  <div class="card-header"><h3 class="card-title">Dados pessoais</h3></div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Nome</label>
        <input class="form-control" name="name" value="<?= h($user['name']) ?>" required>
      </div>
      <div class="col-md-6">
        <label class="form-label">Email</label>
        <input class="form-control" type="email" name="email" value="<?= h($user['email']) ?>" required>
      </div>
      <div class="col-md-4">
        <label class="form-label">Login</label>
        <input class="form-control" value="<?= h($user['login'] ?? '') ?>" disabled>
      </div>
      <div class="col-md-4">
        <label class="form-label">Telefone</label>
        <input class="form-control" name="phone" value="<?= h($user['phone'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Cargo</label>
        <input class="form-control" name="position" value="<?= h($user['position'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">CPF</label>
        <input class="form-control" value="<?= h($user['cpf'] ?? '') ?>" disabled>
      </div>
      <div class="col-md-4">
        <label class="form-label">Data de nascimento</label>
        <input class="form-control" value="<?= h($user['birth_date'] ?? '') ?>" disabled>
      </div>
      <div class="col-md-4">
        <label class="form-label">Permissão</label>
        <input class="form-control" value="<?= h(strtoupper((string)$user['role'])) ?>" disabled>
      </div>
      <div class="col-md-12">
        <label class="form-label">Nova senha</label>
        <input class="form-control" type="password" name="password" placeholder="Preencha somente se quiser alterar">
      </div>
    </div>
  </div>
</form>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
