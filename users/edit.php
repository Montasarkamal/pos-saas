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
require __DIR__ . '/../inc/header.php';
?>

<div class="row justify-content-center">
  <div class="col-md-7">
    <form class="card" method="post" autocomplete="off">
      <div class="card-header">
        <h3 class="card-title">Editar Usuário #<?= (int)$u['id'] ?></h3>
      </div>
      <div class="card-body">
        <?php if ($err): ?>
          <div class="alert alert-danger" role="alert"><?= htmlspecialchars($err) ?></div>
        <?php endif; ?>

        <div class="mb-3">
          <label class="form-label">Nome *</label>
          <input class="form-control" name="name" value="<?= htmlspecialchars($u['name']) ?>" required>
        </div>

        <div class="mb-3">
          <label class="form-label">E-mail *</label>
          <input class="form-control" name="email" type="email" value="<?= htmlspecialchars($u['email']) ?>" required>
        </div>

        <div class="mb-3">
          <label class="form-label">Papel</label>
          <?php if ($isProtectedMaster): ?>
            <input class="form-control" value="superadmin / master" readonly>
          <?php else: ?>
            <select class="form-select" name="role">
              <option value="operador" <?= $u['role']==='operador'?'selected':'' ?>>operador</option>
              <option value="admin" <?= $u['role']==='admin'?'selected':'' ?>>admin</option>
            </select>
          <?php endif; ?>
        </div>

        <div class="mb-3 form-check">
          <input class="form-check-input" id="is_active" type="checkbox" name="is_active" <?= $u['is_active']?'checked':'' ?> <?= $isProtectedMaster ? 'disabled' : '' ?>>
          <label class="form-check-label" for="is_active">Ativo</label>
        </div>

        <?php if ($isProtectedMaster): ?>
          <div class="alert alert-warning">Conta master protegida: não pode ser desativada nem rebaixada.</div>
        <?php endif; ?>

        <div class="mb-3">
          <label class="form-label">Nova senha (opcional)</label>
          <input class="form-control" name="password" type="password" placeholder="Deixe em branco para não alterar">
        </div>

        <input type="hidden" name="csrf" value="<?= htmlspecialchars($token) ?>">
      </div>
      <div class="card-footer d-flex justify-content-between">
        <a href="/users/index.php" class="btn">Cancelar</a>
        <button class="btn btn-primary" type="submit"><i class="ti ti-device-floppy me-1"></i>Salvar</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/../inc/footer.php'; ?>
