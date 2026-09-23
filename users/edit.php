<?php
require __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/helpers.php';
require_role_admin();

$id = (int)($_GET['id'] ?? 0);
[$agencyCondition, $agencyParams] = agency_scope_sql('agency_id');
$st = $pdo->prepare("SELECT id,name,email,login,role,is_active,agency_id FROM users WHERE id=? AND $agencyCondition");
$st->execute(array_merge([$id], $agencyParams)); $u = $st->fetch();
if (!$u) { http_response_code(404); exit('Usuário não encontrado'); }
$isProtectedMaster = is_master_user($u);
$targetAgencyId = (int)($u['agency_id'] ?? agency_id());

$err = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $err = 'Sessão expirada. Atualize e tente novamente.';
  } else {
    $name  = trim($_POST['name'] ?? '');
    $email = mb_strtolower(trim($_POST['email'] ?? ''), 'UTF-8');
    $role  = (($_POST['role'] ?? 'operador') === 'admin') ? 'admin' : 'operador';
    $active = isset($_POST['is_active']) ? 1 : 0;
    $pass  = $_POST['password'] ?? '';

    if ($isProtectedMaster) {
      $role = 'superadmin';
      $active = 1;
    }

    // منع فقدان الوصول: لا تغيّر نفسك لغير admin ولا تعطل نفسك
    if ($name === '' || $email === '') {
      $err = 'Preencha todos os campos obrigatórios.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $err = 'Informe um e-mail válido.';
    } elseif ($u['id'] == $_SESSION['uid'] && ($active==0 || (in_array((string)$u['role'], ['admin','superadmin'], true) && !in_array($role, ['admin','superadmin'], true)))) {
      $err = 'Não é permitido desativar ou remover o seu próprio papel admin.';
    } elseif (!$isProtectedMaster && in_array((string)$u['role'], ['admin', 'superadmin'], true) && $active === 0) {
      $adminCheck = $pdo->prepare("SELECT COUNT(*) FROM users WHERE agency_id=? AND is_active=1 AND role IN ('admin','superadmin') AND id<>?");
      $adminCheck->execute([$targetAgencyId, $id]);
      if ((int)$adminCheck->fetchColumn() === 0) {
        $err = 'Não é permitido desativar o último administrador ativo desta empresa.';
      }
    } else {
      // تأكد من عدم تكرار البريد
      $dupSql = "SELECT 1 FROM users WHERE LOWER(email)=LOWER(?) AND id<>? LIMIT 1";
      $dupParams = [$email, $id];
      $ck = $pdo->prepare($dupSql);
      $ck->execute($dupParams);
      if ($ck->fetch()) {
        $err = 'E-mail já cadastrado em outro usuário.';
      } else {
        try {
          if ($pass !== '') {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $up = $pdo->prepare("UPDATE users SET name=?,email=?,role=?,is_active=?,password_hash=? WHERE id=? AND $agencyCondition");
            $up->execute(array_merge([$name,$email,$role,$active,$hash,$id], $agencyParams));
          } else {
            $up = $pdo->prepare("UPDATE users SET name=?,email=?,role=?,is_active=? WHERE id=? AND $agencyCondition");
            $up->execute(array_merge([$name,$email,$role,$active,$id], $agencyParams));
          }
          header('Location: /users/index.php'); exit;
        } catch (PDOException $e) {
          $message = $e->getMessage();
          if ((string)$e->getCode() === '23000' && stripos($message, 'users.email') !== false) {
            $err = 'E-mail já cadastrado em outro usuário.';
          } elseif ((string)$e->getCode() === '23000' && stripos($message, 'users.login') !== false) {
            $err = 'Login já cadastrado em outro usuário.';
          } else {
            throw $e;
          }
        }
      }
    }
  }
}

$token = csrf_token();
$pageTitle = 'Editar Usuário';
ob_start();
?>
<div class="mx-auto max-w-2xl">
  <form method="post" autocomplete="off" class="space-y-5">
    <?php if ($err): ?>
      <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800" role="alert">
        <?= htmlspecialchars($err) ?>
      </div>
    <?php endif; ?>

    <div class="card overflow-hidden">
      <div class="border-b border-ink-100 px-5 py-4">
        <h3 class="text-sm font-bold text-ink-950">Editar Usuário #<?= (int)$u['id'] ?></h3>
      </div>
      <div class="p-5">
        <?php if ($isProtectedMaster): ?>
          <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800">
            Conta master protegida: não pode ser desativada nem rebaixada.
          </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 gap-4">
          <div>
            <label class="label-field">Nome *</label>
            <input class="input-field" name="name" value="<?= htmlspecialchars($u['name']) ?>" required>
          </div>
          <div>
            <label class="label-field">E-mail *</label>
            <input class="input-field" name="email" type="email" value="<?= htmlspecialchars($u['email']) ?>" required>
          </div>
          <div>
            <label class="label-field">Papel</label>
            <?php if ($isProtectedMaster): ?>
              <input class="input-field" value="superadmin / master" readonly>
            <?php else: ?>
              <select class="select-field" name="role">
                <option value="operador" <?= $u['role'] === 'operador' ? 'selected' : '' ?>>operador</option>
                <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>admin</option>
              </select>
            <?php endif; ?>
          </div>
          <div>
            <label class="switch-item">
              <input type="checkbox" id="is_active" name="is_active" <?= $u['is_active'] ? 'checked' : '' ?> <?= $isProtectedMaster ? 'disabled' : '' ?>>
              <span class="switch-track"><span class="switch-thumb"></span></span>
              <span class="switch-text">Ativo</span>
            </label>
          </div>
          <div>
            <label class="label-field">Nova senha (opcional)</label>
            <input class="input-field" name="password" type="password" placeholder="Deixe em branco para não alterar">
          </div>
        </div>

        <input type="hidden" name="csrf" value="<?= htmlspecialchars($token) ?>">
      </div>
    </div>

    <div class="flex flex-wrap items-center justify-end gap-2">
      <a class="btn-ghost" href="/users/index.php">Cancelar</a>
      <button class="btn-primary px-6" type="submit">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
        Salvar
      </button>
    </div>
  </form>
</div>
<?php
$body = ob_get_clean();
require __DIR__ . '/../inc/layout.php';