<?php
declare(strict_types=1);

require __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/ui.php';

$error = '';
$maxAttempts = 5;
$lockSeconds = 15 * 60;
$now = time();
$lockedUntil = (int)($_SESSION['_login_locked_until'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_check($_POST['csrf'] ?? '')) {
        http_response_code(400);
        $error = 'Sessão expirada. Atualize a página e tente novamente.';
    } elseif ($lockedUntil > $now) {
        http_response_code(429);
        $minutes = max(1, (int)ceil(($lockedUntil - $now) / 60));
        $error = "Muitas tentativas. Tente novamente em {$minutes} minuto(s).";
    } else {

    $identifier = strtolower(trim($_POST['identifier'] ?? ($_POST['email'] ?? '')));
    $password = $_POST['password'] ?? '';

    if ($identifier === '' || $password === '') {
        $error = 'Informe o login/e-mail e a senha.';
    } else {

        $stmt = $pdo->prepare("
            SELECT id, name, email, login, password_hash, role, agency_id, is_active
            FROM users
            WHERE LOWER(email) = ? OR LOWER(login) = ?
            LIMIT 1
        ");
        $stmt->execute([$identifier, $identifier]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && (int)($user['is_active'] ?? 1) === 1 && password_verify($password, $user['password_hash'])) {

            // 🔐 تأمين السيشن
            session_regenerate_id(true);

            $_SESSION['uid']        = (int)$user['id'];
            $_SESSION['name']       = $user['name'];
            $_SESSION['agency_id']  = (int)$user['agency_id'];
            $_SESSION['role']       = $user['role'];
            unset($_SESSION['_login_attempts'], $_SESSION['_login_locked_until']);

            if (password_needs_rehash((string)$user['password_hash'], PASSWORD_DEFAULT)) {
                $rehash = $pdo->prepare('UPDATE users SET password_hash=? WHERE id=? LIMIT 1');
                $rehash->execute([password_hash($password, PASSWORD_DEFAULT), (int)$user['id']]);
            }

            header("Location: /dashboard.php");
            exit;

        } else {
            $attempts = (int)($_SESSION['_login_attempts'] ?? 0) + 1;
            $_SESSION['_login_attempts'] = $attempts;
            if ($attempts >= $maxAttempts) {
                $_SESSION['_login_locked_until'] = $now + $lockSeconds;
                $_SESSION['_login_attempts'] = 0;
            }
            usleep(300000);
            $error = 'Login/e-mail ou senha inválidos.';
        }
    }
    }
}
$loginCsrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Login · KAMALTUR POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light">
<?= vite_head() ?>
</head>
<body class="min-h-svh bg-ink-50">

<main class="grid min-h-svh lg:grid-cols-[1.15fr_minmax(0,30rem)]">
    <!-- ============ Brand panel ============ -->
    <section class="relative hidden overflow-hidden bg-ink-950 lg:flex lg:flex-col lg:justify-between lg:p-12"
             aria-label="KAMALTUR POS">
        <!-- decorative background -->
        <div class="pointer-events-none absolute inset-0">
            <div class="absolute inset-0 bg-[radial-gradient(60rem_40rem_at_18%_18%,rgba(98,115,242,.35),transparent_55%)]"></div>
            <div class="absolute inset-0 bg-[radial-gradient(50rem_34rem_at_85%_85%,rgba(6,182,212,.22),transparent_55%)]"></div>
            <div class="absolute -right-40 -top-40 h-[34rem] w-[34rem] rounded-full border border-white/10"></div>
            <div class="absolute -right-24 -top-24 h-[24rem] w-[24rem] rounded-full border border-white/10"></div>
            <div class="absolute inset-0 opacity-[0.05]"
                 style="background-image:url('/assets/img/bg-login.jpg');background-size:cover;background-position:center;"></div>
        </div>

        <div class="relative">
            <img src="/assets/img/kamaltur.png" alt="KamalTur"
                 class="h-12 w-auto drop-shadow-lg">
        </div>

        <div class="relative max-w-md">
            <p class="mb-3 text-xs font-bold uppercase tracking-[0.2em] text-accent-400">KAMALTUR POS</p>
            <h1 class="text-4xl font-extrabold leading-[1.05] text-white xl:text-5xl">
                O controle de viagens<br>num só painel.
            </h1>
            <p class="mt-5 text-base leading-relaxed text-ink-300">
                Faturas, passageiros, fornecedores e reembolsos em um ambiente seguro
                e organizado para a operação da agência.
            </p>
        </div>

        <div class="relative flex flex-wrap gap-x-6 gap-y-2 text-sm text-ink-400">
            <span>KAMALTUR VIAGENS</span>
            <span>Operação de viagens</span>
            <span>Acesso restrito</span>
        </div>
    </section>

    <!-- ============ Login panel ============ -->
    <section class="flex items-center justify-center bg-ink-50 px-5 py-10 sm:px-10"
             aria-label="Login">
        <div class="w-full max-w-sm">
            <div class="mb-8 flex items-center gap-3 lg:hidden">
                <img src="/assets/img/kamaltur.png" alt="KamalTur" class="h-9 w-auto">
            </div>

            <div class="card p-7 shadow-xl shadow-ink-900/5">
                <h2 class="text-2xl font-bold text-ink-950">Acesse sua conta</h2>
                <p class="mt-1 text-sm text-ink-500">Entre para continuar no painel operacional.</p>

                <?php if ($error): ?>
                    <div class="mt-5 flex items-start gap-2.5 rounded-xl border border-red-200 bg-red-50 px-3.5 py-3 text-sm text-red-800" role="alert">
                        <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line>
                        </svg>
                        <span><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>

                <form method="post" autocomplete="on" class="mt-6 space-y-4">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($loginCsrf, ENT_QUOTES, 'UTF-8') ?>">

                    <div>
                        <label for="identifier" class="mb-1.5 block text-sm font-medium text-ink-700">Login ou e-mail</label>
                        <input id="identifier" type="text" name="identifier" placeholder="seu@email.com"
                               autocomplete="username" required autofocus class="input-field">
                    </div>

                    <div>
                        <label for="password" class="mb-1.5 block text-sm font-medium text-ink-700">Senha</label>
                        <input id="password" type="password" name="password" placeholder="••••••••"
                               autocomplete="current-password" required class="input-field">
                    </div>

                    <button type="submit" class="btn-primary w-full">
                        Entrar
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path>
                        </svg>
                    </button>
                </form>
            </div>

            <p class="mt-6 text-center text-sm text-ink-500">
                Ainda não tem acesso?
                <a href="/register.php" class="font-semibold text-brand-600 hover:text-brand-700 hover:underline">Criar conta</a>
            </p>
        </div>
    </section>
</main>

</body>
</html>