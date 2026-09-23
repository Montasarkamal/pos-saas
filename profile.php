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
ob_start();
?>
<div class="mx-auto max-w-3xl">

  <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
      <p class="text-xs font-bold uppercase tracking-wider text-brand-600">Conta</p>
      <h2 class="text-xl font-bold text-ink-950">Profile</h2>
      <p class="mt-1 text-sm text-ink-500">Dados do usuário atual.</p>
    </div>
    <div class="flex items-center gap-2">
      <a href="/dashboard.php" class="btn-ghost">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5"></path><path d="M12 19l-7-7 7-7"></path></svg>
        Voltar
      </a>
      <button class="btn-primary" form="profileForm">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
        Salvar
      </button>
    </div>
  </div>

  <?php if ($msg === 'saved'): ?>
    <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800" role="alert">
      Profile salvo com sucesso.
    </div>
  <?php endif; ?>
  <?php if ($err !== ''): ?>
    <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800" role="alert">
      <?= h($err) ?>
    </div>
  <?php endif; ?>

  <form method="post" id="profileForm" class="card overflow-hidden" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <div class="border-b border-ink-100 px-5 py-4">
      <h3 class="text-sm font-bold text-ink-950">Dados pessoais</h3>
    </div>
    <div class="p-5">
      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label class="label-field" for="name">Nome</label>
          <input class="input-field" id="name" name="name" value="<?= h($user['name']) ?>" required>
        </div>
        <div>
          <label class="label-field" for="email">Email</label>
          <input class="input-field" id="email" type="email" name="email" value="<?= h($user['email']) ?>" required>
        </div>
        <div>
          <label class="label-field" for="login">Login</label>
          <input class="input-field cursor-not-allowed bg-ink-50 text-ink-500" id="login" value="<?= h($user['login'] ?? '') ?>" disabled>
        </div>
        <div>
          <label class="label-field" for="phone">Telefone</label>
          <input class="input-field" id="phone" name="phone" value="<?= h($user['phone'] ?? '') ?>">
        </div>
        <div>
          <label class="label-field" for="position">Cargo</label>
          <input class="input-field" id="position" name="position" value="<?= h($user['position'] ?? '') ?>">
        </div>
        <div>
          <label class="label-field" for="cpf">CPF</label>
          <input class="input-field cursor-not-allowed bg-ink-50 text-ink-500" id="cpf" value="<?= h($user['cpf'] ?? '') ?>" disabled>
        </div>
        <div>
          <label class="label-field" for="birth_date">Data de nascimento</label>
          <input class="input-field cursor-not-allowed bg-ink-50 text-ink-500" id="birth_date" value="<?= h($user['birth_date'] ?? '') ?>" disabled>
        </div>
        <div>
          <label class="label-field" for="role">Permissão</label>
          <input class="input-field cursor-not-allowed bg-ink-50 text-ink-500" id="role" value="<?= h(strtoupper((string)$user['role'])) ?>" disabled>
        </div>
        <div class="sm:col-span-2">
          <label class="label-field" for="password">Nova senha</label>
          <input class="input-field" id="password" type="password" name="password" placeholder="Preencha somente se quiser alterar">
        </div>
      </div>
    </div>
  </form>

</div>
<?php
$body = ob_get_clean();
require __DIR__ . '/inc/layout.php';
