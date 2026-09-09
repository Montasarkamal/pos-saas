<?php
require __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/helpers.php';
require_role_admin();

$err = '';
$form = [
  'name' => '',
  'email' => '',
  'role' => 'operador',
  'is_active' => 1,
];
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $err = 'Sessão expirada. Atualize e tente novamente.';
  } else {
    $name  = trim($_POST['name'] ?? '');
    $email = mb_strtolower(trim($_POST['email'] ?? ''), 'UTF-8');
    $role  = (($_POST['role'] ?? 'operador') === 'admin') ? 'admin' : 'operador';
    $active = isset($_POST['is_active']) ? 1 : 0;
    $pass  = $_POST['password'] ?? '';
    $form = [
      'name' => $name,
      'email' => $email,
      'role' => $role,
      'is_active' => $active,
    ];

    if ($name==='' || $email==='' || $pass==='') {
      $err = 'Preencha todos os campos obrigatórios.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $err = 'Informe um e-mail válido.';
    } else {
      $ck = $pdo->prepare("SELECT 1 FROM users WHERE LOWER(email)=LOWER(?) LIMIT 1");
      $ck->execute([$email]);
      if ($ck->fetch()) {
        $err = 'E-mail já cadastrado.';
      } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        try {
          $ins = $pdo->prepare("INSERT INTO users (name,email,role,password_hash,is_active,agency_id) VALUES (?,?,?,?,?,?)");
          $ins->execute([$name,$email,$role,$hash,$active,agency_id()]);
          header('Location: /users/index.php'); exit;
        } catch (PDOException $e) {
          $message = $e->getMessage();
          if ((string)$e->getCode() === '23000' && stripos($message, 'users.email') !== false) {
            $err = 'E-mail já cadastrado.';
          } elseif ((string)$e->getCode() === '23000' && stripos($message, 'users.login') !== false) {
            $err = 'Login já cadastrado.';
          } else {
            throw $e;
          }
        }
      }
    }
  }
}

$token = csrf_token();
$pageTitle = 'Novo Usuário';
require __DIR__ . '/../inc/header.php';
?>

<div class="row justify-content-center">
  <div class="col-md-7">
    <form class="card" method="post" autocomplete="off">
      <div class="card-header"><h3 class="card-title">Novo Usuário</h3></div>
      <div class="card-body">
        <?php if ($err): ?>
          <div class="alert alert-danger" role="alert"><?= htmlspecialchars($err) ?></div>
        <?php endif; ?>

        <div class="mb-3">
          <label class="form-label">Nome *</label>
          <input class="form-control" name="name" value="<?= htmlspecialchars($form['name']) ?>" required>
        </div>

        <div class="mb-3">
          <label class="form-label">E-mail *</label>
          <input class="form-control" name="email" type="email" value="<?= htmlspecialchars($form['email']) ?>" required>
        </div>

        <div class="mb-3">
          <label class="form-label">Senha *</label>
          <input class="form-control" name="password" type="password" required placeholder="mín. 8 caracteres">
        </div>

        <div class="mb-3">
          <label class="form-label">Papel</label>
          <select class="form-select" name="role">
            <option value="operador" <?= $form['role']==='operador'?'selected':'' ?>>operador</option>
            <option value="admin" <?= $form['role']==='admin'?'selected':'' ?>>admin</option>
          </select>
        </div>

        <div class="mb-3 form-check">
          <input class="form-check-input" id="is_active" type="checkbox" name="is_active" <?= !empty($form['is_active']) ? 'checked' : '' ?>>
          <label class="form-check-label" for="is_active">Ativo</label>
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
