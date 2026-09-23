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
        <h3 class="text-sm font-bold text-ink-950">Novo Usuário</h3>
      </div>
      <div class="p-5">
        <div class="grid grid-cols-1 gap-4">
          <div>
            <label class="label-field">Nome *</label>
            <input class="input-field" name="name" value="<?= htmlspecialchars($form['name']) ?>" required>
          </div>
          <div>
            <label class="label-field">E-mail *</label>
            <input class="input-field" name="email" type="email" value="<?= htmlspecialchars($form['email']) ?>" required>
          </div>
          <div>
            <label class="label-field">Senha *</label>
            <input class="input-field" name="password" type="password" required placeholder="mín. 8 caracteres">
          </div>
          <div>
            <label class="label-field">Papel</label>
            <select class="select-field" name="role">
              <option value="operador" <?= $form['role'] === 'operador' ? 'selected' : '' ?>>operador</option>
              <option value="admin" <?= $form['role'] === 'admin' ? 'selected' : '' ?>>admin</option>
            </select>
          </div>
          <div>
            <label class="switch-item">
              <input type="checkbox" id="is_active" name="is_active" <?= !empty($form['is_active']) ? 'checked' : '' ?>>
              <span class="switch-track"><span class="switch-thumb"></span></span>
              <span class="switch-text">Ativo</span>
            </label>
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