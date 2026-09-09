<?php
declare(strict_types=1);

require __DIR__ . '/inc/auth.php';

$error = '';
$old = $_POST;

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function clean_doc(string $value): string {
    return preg_replace('/\D+/', '', $value) ?? '';
}

function upload_asset(string $field, int $agencyId): ?string {
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($_FILES[$field]['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Erro ao enviar arquivo.');
    }

    $tmp = (string)$_FILES[$field]['tmp_name'];
    $size = (int)($_FILES[$field]['size'] ?? 0);
    if ($size <= 0 || $size > 2 * 1024 * 1024) {
        throw new RuntimeException('Arquivo inválido ou maior que 2MB.');
    }

    $info = @getimagesize($tmp);
    if (!$info) {
        throw new RuntimeException('Envie apenas imagens válidas.');
    }

    $allowed = [
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_ICO => 'ico',
    ];
    $type = $info[2] ?? null;
    if (!isset($allowed[$type])) {
        throw new RuntimeException('Formato de imagem não permitido.');
    }

    $dir = __DIR__ . '/uploads/agencies/' . $agencyId;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Não foi possível criar a pasta de uploads.');
    }

    $filename = $field . '.' . $allowed[$type];
    $target = $dir . '/' . $filename;
    if (!move_uploaded_file($tmp, $target)) {
        throw new RuntimeException('Não foi possível salvar o arquivo.');
    }

    return '/uploads/agencies/' . $agencyId . '/' . $filename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        http_response_code(400);
        $error = 'Sessão expirada. Atualize a página e tente novamente.';
    } else {
    $cnpj = clean_doc((string)($_POST['cnpj'] ?? ''));
    $legalName = trim((string)($_POST['legal_name'] ?? ''));
    $fantasyName = trim((string)($_POST['fantasy_name'] ?? ''));
    $agencyEmail = strtolower(trim((string)($_POST['agency_email'] ?? '')));
    $agencyPhone = trim((string)($_POST['agency_phone'] ?? ''));
    $cep = trim((string)($_POST['cep'] ?? ''));
    $street = trim((string)($_POST['street'] ?? ''));
    $district = trim((string)($_POST['district'] ?? ''));
    $complement = trim((string)($_POST['complement'] ?? ''));
    $number = trim((string)($_POST['number'] ?? ''));
    $city = trim((string)($_POST['city'] ?? ''));
    $uf = strtoupper(substr(trim((string)($_POST['uf'] ?? '')), 0, 2));
    $bankDetails = trim((string)($_POST['bank_details'] ?? ''));

    $masterName = trim((string)($_POST['master_name'] ?? ''));
    $masterEmail = strtolower(trim((string)($_POST['master_email'] ?? '')));
    $cpf = clean_doc((string)($_POST['cpf'] ?? ''));
    $birthDate = trim((string)($_POST['birth_date'] ?? '')) ?: null;
    $position = trim((string)($_POST['position'] ?? ''));
    $masterPhone = trim((string)($_POST['master_phone'] ?? ''));
    $login = strtolower(trim((string)($_POST['login'] ?? '')));
    $password = (string)($_POST['password'] ?? '');

    if ($cnpj === '' || $fantasyName === '' || $masterName === '' || $masterEmail === '' || $login === '' || $password === '') {
        $error = 'Preencha todos os campos obrigatórios.';
    } elseif ($agencyEmail !== '' && !filter_var($agencyEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email da agência inválido.';
    } elseif (!filter_var($masterEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'E-mail do login master inválido.';
    } elseif (strlen($password) < 8) {
        $error = 'A senha deve ter pelo menos 8 caracteres.';
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT id FROM agencies
                 WHERE cnpj = ? OR LOWER(TRIM(email)) IN (?, ?)
                 LIMIT 1
            ");
            $stmt->execute([$cnpj, $agencyEmail, $masterEmail]);
            if ($stmt->fetch()) {
                throw new RuntimeException('Agência já cadastrada com este CNPJ ou email.');
            }

            $stmt = $pdo->prepare("
                SELECT id FROM users
                 WHERE LOWER(TRIM(email)) = ? OR LOWER(TRIM(login)) = ?
                 LIMIT 1
            ");
            $stmt->execute([$masterEmail, $login]);
            if ($stmt->fetch()) {
                throw new RuntimeException('Login ou e-mail master já cadastrado.');
            }

            $pdo->beginTransaction();

            $passHash = password_hash($password, PASSWORD_DEFAULT);
            $agencyName = $fantasyName !== '' ? $fantasyName : $legalName;
            $primaryAgencyEmail = $agencyEmail !== '' ? $agencyEmail : $masterEmail;

            $stmt = $pdo->prepare("
                INSERT INTO agencies
                    (name, cnpj, legal_name, fantasy_name, email, phone, cep, street, district, complement, number, city, uf,
                     bank_details)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $agencyName,
                $cnpj,
                $legalName ?: null,
                $fantasyName,
                $primaryAgencyEmail,
                $agencyPhone ?: null,
                $cep ?: null,
                $street ?: null,
                $district ?: null,
                $complement ?: null,
                $number ?: null,
                $city ?: null,
                $uf ?: null,
                $bankDetails ?: null,
            ]);

            $agencyId = (int)$pdo->lastInsertId();

            $logoPath = upload_asset('logo', $agencyId);
            $stampPath = upload_asset('stamp', $agencyId);
            $faviconPath = upload_asset('favicon', $agencyId);

            if ($logoPath || $stampPath || $faviconPath) {
                $stmt = $pdo->prepare("UPDATE agencies SET logo_path=?, stamp_path=?, favicon_path=? WHERE id=?");
                $stmt->execute([$logoPath, $stampPath, $faviconPath, $agencyId]);
            }

            $stmt = $pdo->prepare("
                INSERT INTO users
                    (name, email, cpf, birth_date, position, phone, login, password_hash, role, agency_id)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, 'admin', ?)
            ");
            $stmt->execute([
                $masterName,
                $masterEmail,
                $cpf ?: null,
                $birthDate,
                $position ?: null,
                $masterPhone ?: null,
                $login,
                $passHash,
                $agencyId,
            ]);

            $userId = (int)$pdo->lastInsertId();

            $pdo->commit();

            session_regenerate_id(true);
            $_SESSION['uid'] = $userId;
            $_SESSION['name'] = $masterName;
            $_SESSION['agency_id'] = $agencyId;
            $_SESSION['role'] = 'admin';

            header('Location: /dashboard.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('register.php: ' . $e->getMessage());
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Erro ao criar a conta.';
        }
    }
    }
}
$registrationCsrf = csrf_token();
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cadastro de Agência</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tabler-icons@3.34.1/iconfont/tabler-icons.min.css">
<style>
:root {
    --ink: #122033;
    --muted: #667085;
    --line: rgba(14, 165, 233, .16);
    --panel: rgba(255, 255, 255, .96);
    --accent: #0ea5e9;
    --accent-dark: #0284c7;
    --green: #22c55e;
    --field: #f8fafc;
}
* { box-sizing: border-box; }
body {
    margin: 0;
    min-height: 100svh;
    font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
    color: var(--ink);
    background:
        radial-gradient(circle at 12% 18%, rgba(56, 189, 248, .24), transparent 30%),
        radial-gradient(circle at 76% 10%, rgba(34, 197, 94, .14), transparent 26%),
        linear-gradient(110deg, rgba(244, 251, 255, .97), rgba(231, 246, 255, .88) 42%, rgba(255, 255, 255, .94) 42.2%),
        url('/assets/img/bg-login.jpg') center / cover fixed no-repeat;
}
.register-shell {
    width: min(1480px, calc(100vw - 48px));
    margin: 0 auto;
    padding: 36px 0;
    display: grid;
    grid-template-columns: minmax(280px, .65fr) minmax(680px, 1.35fr);
    gap: 28px;
    align-items: start;
}
.brand-pane {
    min-height: calc(100svh - 72px);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    color: var(--ink);
    padding: 16px 0 20px;
}
.brand-logo {
    width: 142px;
    height: auto;
    filter: drop-shadow(0 12px 28px rgba(14, 165, 233, .18));
}
.brand-copy {
    max-width: 420px;
    animation: rise .55s ease both;
}
.brand-copy h1 {
    margin: 0;
    font-size: clamp(42px, 6vw, 76px);
    line-height: .95;
    letter-spacing: 0;
}
.brand-copy p {
    margin: 20px 0 0;
    color: #475569;
    line-height: 1.7;
    font-size: 17px;
}
.brand-foot {
    display: grid;
    gap: 10px;
    color: #475569;
    font-size: 13px;
}
.form-panel {
    background: var(--panel);
    border: 1px solid rgba(14, 165, 233, .16);
    border-radius: 22px;
    box-shadow: 0 24px 80px rgba(15, 118, 176, .16);
    backdrop-filter: blur(18px);
    overflow: hidden;
    animation: panel .48s ease .08s both;
}
.panel-head {
    padding: 26px 30px 18px;
    border-bottom: 1px solid var(--line);
    display: flex;
    gap: 18px;
    align-items: center;
    justify-content: space-between;
}
.panel-head h2 {
    margin: 0;
    font-size: 28px;
    letter-spacing: 0;
}
.panel-head a {
    color: var(--accent);
    font-weight: 700;
    text-decoration: none;
}
.form-body { padding: 28px 30px 30px; }
.alert {
    border: 1px solid rgba(220, 38, 38, .25);
    background: #fff1f2;
    color: #991b1b;
    border-radius: 12px;
    padding: 12px 14px;
    margin-bottom: 20px;
    font-weight: 650;
}
.section {
    padding: 22px 0;
    border-top: 1px solid var(--line);
}
.section:first-of-type {
    border-top: 0;
    padding-top: 0;
}
.section-title {
    margin: 0 0 18px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 14px;
    font-weight: 800;
    color: #344054;
    text-transform: uppercase;
    letter-spacing: .08em;
}
.section-title i {
    color: var(--accent);
    font-size: 19px;
}
.grid {
    display: grid;
    grid-template-columns: repeat(12, minmax(0, 1fr));
    gap: 16px;
}
.field { grid-column: span 4; }
.field.wide { grid-column: span 8; }
.field.full { grid-column: 1 / -1; }
.field.small { grid-column: span 2; }
label {
    display: block;
    margin: 0 0 7px;
    font-size: 13px;
    color: #344054;
    font-weight: 700;
}
input, textarea {
    width: 100%;
    border: 1px solid #cbd5e1;
    background: var(--field);
    color: var(--ink);
    border-radius: 11px;
    min-height: 46px;
    padding: 11px 13px;
    font: inherit;
    outline: none;
    transition: border-color .18s ease, box-shadow .18s ease, background .18s ease;
}
textarea {
    min-height: 116px;
    resize: vertical;
}
input:focus, textarea:focus {
    border-color: var(--accent);
    background: #fff;
    box-shadow: 0 0 0 4px rgba(14, 165, 233, .15);
}
.file-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
}
.file-input {
    position: relative;
    min-height: 118px;
    border: 1px dashed rgba(14, 165, 233, .42);
    border-radius: 16px;
    background: #f8fafc;
    display: grid;
    place-items: center;
    text-align: center;
    padding: 16px;
    transition: border-color .18s ease, background .18s ease, transform .18s ease;
}
.file-input:hover {
    border-color: var(--accent);
    background: #effaff;
    transform: translateY(-1px);
}
.file-input input {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
}
.file-input i {
    display: block;
    margin-bottom: 8px;
    font-size: 28px;
    color: var(--accent);
}
.file-input strong {
    display: block;
    font-size: 14px;
}
.file-input span {
    color: var(--muted);
    font-size: 12px;
}
.actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding-top: 8px;
}
.btn {
    border: 0;
    border-radius: 12px;
    min-height: 48px;
    padding: 0 22px;
    font: inherit;
    font-weight: 800;
    cursor: pointer;
    transition: transform .18s ease, box-shadow .18s ease, background .18s ease;
}
.btn-primary {
    color: #fff;
    background: linear-gradient(135deg, var(--accent), var(--green));
    box-shadow: 0 14px 28px rgba(14, 165, 233, .22);
}
.btn-primary:hover {
    background: linear-gradient(135deg, var(--accent-dark), #16a34a);
    transform: translateY(-1px);
}
.back-link {
    color: #475467;
    font-weight: 700;
    text-decoration: none;
}
@keyframes rise {
    from { opacity: 0; transform: translateY(16px); }
    to { opacity: 1; transform: translateY(0); }
}
@keyframes panel {
    from { opacity: 0; transform: translateY(12px) scale(.99); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
@media (max-width: 1100px) {
    body {
        background:
            radial-gradient(circle at 18% 8%, rgba(56, 189, 248, .22), transparent 28%),
            linear-gradient(180deg, rgba(244, 251, 255, .98), rgba(231, 246, 255, .86) 34%, rgba(255, 255, 255, .98) 34.2%),
            url('/assets/img/bg-login.jpg') center / cover fixed no-repeat;
    }
    .register-shell {
        grid-template-columns: 1fr;
    }
    .brand-pane {
        min-height: auto;
        gap: 36px;
    }
    .brand-copy h1 { max-width: 10ch; }
}
@media (max-width: 720px) {
    .register-shell {
        width: 100%;
        padding: 0;
    }
    .brand-pane {
        padding: 24px;
    }
    .form-panel {
        border-radius: 22px 22px 0 0;
        border-left: 0;
        border-right: 0;
    }
    .panel-head, .form-body {
        padding-left: 18px;
        padding-right: 18px;
    }
    .field, .field.wide, .field.small {
        grid-column: 1 / -1;
    }
    .file-grid {
        grid-template-columns: 1fr;
    }
    .actions {
        flex-direction: column-reverse;
        align-items: stretch;
    }
    .btn { width: 100%; }
}
</style>
</head>
<body>
<main class="register-shell">
    <aside class="brand-pane">
        <img class="brand-logo" src="/assets/img/kamaltur.png" alt="KAMALTUR">
        <div class="brand-copy">
            <h1>Cadastro de Agência</h1>
            <p>Configure os dados comerciais, identidade visual e login master da operação.</p>
        </div>
        <div class="brand-foot">
            <span>Gestão de faturas, fornecedores e viagens</span>
            <span>Ambiente protegido para operações de turismo</span>
        </div>
    </aside>

    <section class="form-panel">
        <div class="panel-head">
            <div>
                <h2>Nova conta</h2>
            </div>
            <a href="/login.php">Entrar</a>
        </div>

        <form class="form-body" method="post" enctype="multipart/form-data" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= e($registrationCsrf) ?>">
            <?php if ($error): ?>
                <div class="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <section class="section">
                <h3 class="section-title"><i class="ti ti-building-skyscraper"></i> Dados da Agência</h3>
                <div class="grid">
                    <div class="field">
                        <label for="cnpj">*CNPJ</label>
                        <input id="cnpj" name="cnpj" value="<?= e($old['cnpj'] ?? '') ?>" required>
                    </div>
                    <div class="field wide">
                        <label for="legal_name">Razão Social</label>
                        <input id="legal_name" name="legal_name" value="<?= e($old['legal_name'] ?? '') ?>">
                    </div>
                    <div class="field wide">
                        <label for="fantasy_name">*Nome Fantasia</label>
                        <input id="fantasy_name" name="fantasy_name" value="<?= e($old['fantasy_name'] ?? '') ?>" required>
                    </div>
                    <div class="field">
                        <label for="agency_email">Email</label>
                        <input id="agency_email" type="email" name="agency_email" value="<?= e($old['agency_email'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="agency_phone">Fone</label>
                        <input id="agency_phone" name="agency_phone" value="<?= e($old['agency_phone'] ?? '') ?>">
                    </div>
                </div>
            </section>

            <section class="section">
                <h3 class="section-title"><i class="ti ti-map-pin"></i> Endereço Comercial</h3>
                <div class="grid">
                    <div class="field small">
                        <label for="cep">CEP</label>
                        <input id="cep" name="cep" value="<?= e($old['cep'] ?? '') ?>">
                    </div>
                    <div class="field wide">
                        <label for="street">Logradouro</label>
                        <input id="street" name="street" value="<?= e($old['street'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="district">Bairro</label>
                        <input id="district" name="district" value="<?= e($old['district'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="complement">Complemento</label>
                        <input id="complement" name="complement" value="<?= e($old['complement'] ?? '') ?>">
                    </div>
                    <div class="field small">
                        <label for="number">Número</label>
                        <input id="number" name="number" value="<?= e($old['number'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="city">Cidade</label>
                        <input id="city" name="city" value="<?= e($old['city'] ?? '') ?>">
                    </div>
                    <div class="field small">
                        <label for="uf">UF</label>
                        <input id="uf" name="uf" maxlength="2" value="<?= e($old['uf'] ?? '') ?>">
                    </div>
                </div>
            </section>

            <section class="section">
                <h3 class="section-title"><i class="ti ti-building-bank"></i> Dados Bancária</h3>
                <div class="grid">
                    <div class="field full">
                        <label for="bank_details">Text Box</label>
                        <textarea id="bank_details" name="bank_details"><?= e($old['bank_details'] ?? '') ?></textarea>
                    </div>
                </div>
            </section>

            <section class="section">
                <h3 class="section-title"><i class="ti ti-user-shield"></i> Dados para Criação do Login Master</h3>
                <div class="grid">
                    <div class="field wide">
                        <label for="master_name">*Nome completo</label>
                        <input id="master_name" name="master_name" value="<?= e($old['master_name'] ?? '') ?>" required>
                    </div>
                    <div class="field">
                        <label for="master_email">*E-mail</label>
                        <input id="master_email" type="email" name="master_email" value="<?= e($old['master_email'] ?? '') ?>" required>
                    </div>
                    <div class="field">
                        <label for="cpf">CPF</label>
                        <input id="cpf" name="cpf" value="<?= e($old['cpf'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="birth_date">Data de Nascimento</label>
                        <input id="birth_date" type="date" name="birth_date" value="<?= e($old['birth_date'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="position">Cargo</label>
                        <input id="position" name="position" value="<?= e($old['position'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="master_phone">Telefone</label>
                        <input id="master_phone" name="master_phone" value="<?= e($old['master_phone'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="login">*Login</label>
                        <input id="login" name="login" value="<?= e($old['login'] ?? '') ?>" required>
                    </div>
                    <div class="field">
                        <label for="password">*Senha</label>
                        <input id="password" type="password" name="password" required>
                    </div>
                </div>
            </section>

            <section class="section">
                <h3 class="section-title"><i class="ti ti-photo-up"></i> Identidade Visual</h3>
                <div class="file-grid">
                    <label class="file-input">
                        <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif">
                        <span><i class="ti ti-photo"></i><strong>Logo</strong><span>PNG, JPG ou WEBP</span></span>
                    </label>
                    <label class="file-input">
                        <input type="file" name="stamp" accept="image/png,image/jpeg,image/webp,image/gif">
                        <span><i class="ti ti-rubber-stamp"></i><strong>Carimbo</strong><span>Imagem transparente recomendada</span></span>
                    </label>
                    <label class="file-input">
                        <input type="file" name="favicon" accept="image/png,image/jpeg,image/webp,image/gif,image/x-icon">
                        <span><i class="ti ti-world-www"></i><strong>Favicon</strong><span>Ícone do navegador</span></span>
                    </label>
                </div>
            </section>

            <div class="actions">
                <a class="back-link" href="/login.php">Já tenho cadastro</a>
                <button class="btn btn-primary" type="submit">Criar agência</button>
            </div>
        </form>
    </section>
</main>
</body>
</html>
