<?php
declare(strict_types=1);

require __DIR__ . '/inc/auth.php';

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
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Login</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    min-height: 100svh;
    font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
    background: #f4fbff;
    color: #102033;
}

.login-shell {
    position: relative;
    min-height: 100svh;
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(360px, 470px);
    align-items: stretch;
    overflow: hidden;
    background:
        linear-gradient(90deg, rgba(242, 250, 255, .94) 0%, rgba(231, 246, 255, .84) 46%, rgba(255, 255, 255, .58) 100%),
        url('/assets/img/bg-login.jpg') center / cover no-repeat;
}

.login-shell::before {
    content: "";
    position: absolute;
    inset: 0;
    background:
        radial-gradient(circle at 18% 20%, rgba(56, 189, 248, .22), transparent 28%),
        radial-gradient(circle at 62% 72%, rgba(34, 197, 94, .16), transparent 30%);
    pointer-events: none;
}

.brand-panel,
.login-panel {
    position: relative;
    z-index: 1;
}

.brand-panel {
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    min-height: 100svh;
    padding: clamp(28px, 5vw, 68px);
}

.brand-mark {
    width: 158px;
    height: auto;
    filter: drop-shadow(0 10px 24px rgba(15, 118, 176, .16));
}

.brand-copy {
    max-width: 560px;
    animation: riseIn .65s ease both;
}

.eyebrow {
    margin: 0 0 14px;
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0;
    color: #0284c7;
}

.brand-copy h1 {
    margin: 0;
    max-width: 11ch;
    font-size: clamp(44px, 8vw, 86px);
    line-height: .96;
    letter-spacing: 0;
}

.brand-copy p {
    margin: 22px 0 0;
    max-width: 470px;
    font-size: clamp(16px, 1.7vw, 20px);
    line-height: 1.6;
    color: #475569;
}

.flight-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    color: #475569;
    font-size: 13px;
}

.flight-meta span {
    padding-top: 12px;
    border-top: 1px solid rgba(14, 165, 233, .28);
}

.login-panel {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: clamp(20px, 4vw, 48px);
    background: rgba(255, 255, 255, .78);
    border-left: 1px solid rgba(14, 165, 233, .16);
    backdrop-filter: blur(22px) saturate(150%);
}

.box {
    width: 100%;
    max-width: 390px;
    animation: panelIn .5s ease .08s both;
    background: rgba(255, 255, 255, .94);
    border: 1px solid rgba(14, 165, 233, .16);
    border-radius: 18px;
    padding: 28px;
    box-shadow: 0 22px 60px rgba(15, 118, 176, .15);
}

h2 {
    margin: 0 0 8px;
    color: #0f172a;
    font-size: 30px;
    line-height: 1.15;
    letter-spacing: 0;
}

.subcopy {
    margin: 0 0 28px;
    color: #64748b;
    line-height: 1.55;
}

input {
    width: 100%;
    height: 52px;
    padding: 0 16px;
    margin-bottom: 14px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    background: #f8fbff;
    color: #0f172a;
    font: inherit;
    outline: none;
    transition: border-color .18s ease, box-shadow .18s ease, background .18s ease;
}

input:focus {
    border-color: #38bdf8;
    background: #ffffff;
    box-shadow: 0 0 0 4px rgba(56, 189, 248, .18);
}

input::placeholder {
    color: #64748b;
}

button {
    width: 100%;
    height: 52px;
    margin-top: 4px;
    background: linear-gradient(135deg, #0ea5e9, #22c55e);
    color: #fff;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font: inherit;
    font-weight: 800;
    transition: transform .18s ease, background .18s ease, box-shadow .18s ease;
    box-shadow: 0 18px 38px rgba(14, 165, 233, .24);
}

button:hover {
    background: linear-gradient(135deg, #0284c7, #16a34a);
    transform: translateY(-1px);
    box-shadow: 0 22px 44px rgba(14, 165, 233, .34);
}

button:active {
    transform: translateY(0);
}

.error {
    color: #991b1b;
    background: #fff1f2;
    border: 1px solid rgba(248, 113, 113, .42);
    border-radius: 8px;
    margin-bottom: 16px;
    padding: 12px 14px;
    text-align: left;
    font-size: 14px;
}

.link {
    margin-top: 22px;
    text-align: center;
    color: #64748b;
    font-size: 14px;
}

.link a {
    color: #0284c7;
    font-weight: 700;
    text-decoration: none;
}

.link a:hover {
    color: #0369a1;
    text-decoration: underline;
}

@keyframes riseIn {
    from { opacity: 0; transform: translateY(18px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes panelIn {
    from { opacity: 0; transform: translateX(14px); }
    to { opacity: 1; transform: translateX(0); }
}

@media (max-width: 860px) {
    .login-shell {
        grid-template-columns: 1fr;
    }

    .brand-panel {
        min-height: 44svh;
        padding-bottom: 20px;
    }

    .login-panel {
        align-items: flex-start;
        min-height: 56svh;
        border-left: 0;
        border-top: 1px solid rgba(14, 165, 233, .16);
    }

    .brand-copy h1 {
        max-width: 9ch;
        font-size: clamp(38px, 12vw, 58px);
    }
}

@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        animation: none !important;
        transition: none !important;
    }
}
</style>
</head>
<body>

<main class="login-shell">
    <section class="brand-panel" aria-label="KamalTur POS">
        <img class="brand-mark" src="/assets/img/kamaltur.png" alt="KamalTur">

        <div class="brand-copy">
            <p class="eyebrow">KAMALTUR POS</p>
            <h1>Controle de viagens</h1>
            <p>Faturas, passageiros e serviços em um ambiente seguro para a operação da agência.</p>
        </div>

        <div class="flight-meta" aria-label="Informações da operação">
            <span>São Paulo</span>
            <span>Operação de viagens</span>
            <span>Acesso restrito</span>
        </div>
    </section>

    <section class="login-panel" aria-label="Login">
        <div class="box">
            <h2>Acesse sua conta</h2>
            <p class="subcopy">Entre para continuar no painel operacional.</p>

            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="on">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($loginCsrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="text" name="identifier" placeholder="Login ou e-mail" autocomplete="username" aria-label="Login ou e-mail" required autofocus>
                <input type="password" name="password" placeholder="Senha" autocomplete="current-password" aria-label="Senha" required>
                <button type="submit">Entrar</button>
            </form>

            <div class="link">
                <a href="/register.php">Criar conta</a>
            </div>
        </div>
    </section>
</main>

</body>
</html>
