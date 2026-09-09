<?php
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_login();
require_once __DIR__ . '/../inc/audit.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    die('Invalid service ID');
}

$stmt = $pdo->prepare("SELECT id, service_type FROM service_sales WHERE id = ? AND agency_id = ? LIMIT 1");
$stmt->execute([$id, agency_id()]);
$service = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$service) {
    die('Service not found');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('CSRF inválido');
    }

    try {
        ensure_audit_table($pdo);
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("DELETE FROM service_hotel_rooms WHERE service_id = ? AND agency_id = ?");
        $stmt->execute([$id, agency_id()]);

        $stmt = $pdo->prepare("DELETE FROM service_guests WHERE service_id = ? AND agency_id = ?");
        $stmt->execute([$id, agency_id()]);

        $stmt = $pdo->prepare("DELETE FROM service_hotels WHERE service_id = ? AND agency_id = ?");
        $stmt->execute([$id, agency_id()]);

        $stmt = $pdo->prepare("DELETE FROM service_cars WHERE service_id = ? AND agency_id = ?");
        $stmt->execute([$id, agency_id()]);

        $stmt = $pdo->prepare("DELETE FROM service_insurance WHERE service_id = ? AND agency_id = ?");
        $stmt->execute([$id, agency_id()]);

        $stmt = $pdo->prepare("DELETE FROM service_details WHERE service_id = ? AND agency_id = ?");
        $stmt->execute([$id, agency_id()]);

        $stmt = $pdo->prepare("DELETE FROM service_sales WHERE id = ? AND agency_id = ?");
        $stmt->execute([$id, agency_id()]);

        audit_log($pdo, 'delete', 'service', $id, ['service_type' => (string)$service['service_type']]);

        $pdo->commit();

        header('Location: /services/index.php?deleted=1');
        exit;
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('services/delete.php: ' . $e->getMessage());
        die('Delete failed.');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Excluir serviço</title>
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #f5f7fb;
            font-family: Arial, Helvetica, sans-serif;
            color: #16395f;
        }

        .wrap {
            max-width: 680px;
            margin: 40px auto;
            padding: 20px;
        }

        .card {
            background: #fff;
            border: 1px solid #d9e3ef;
            border-radius: 16px;
            overflow: hidden;
        }

        .card-header {
            padding: 18px 20px;
            border-bottom: 1px solid #e8eef5;
            font-size: 22px;
            font-weight: 700;
        }

        .card-body {
            padding: 20px;
            line-height: 1.7;
        }

        .meta {
            background: #f7faff;
            border: 1px solid #dce7f3;
            border-radius: 12px;
            padding: 14px;
            margin: 14px 0;
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .btn {
            display: inline-block;
            padding: 11px 14px;
            border-radius: 10px;
            border: 1px solid #d9e3ef;
            background: #fff;
            color: #16395f;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }

        .btn-danger {
            background: #dc3545;
            border-color: #dc3545;
            color: #fff;
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="card-header">Excluir serviço</div>

        <div class="card-body">
            <p>Você está prestes a excluir este serviço permanentemente.</p>

            <div class="meta">
                <strong>ID do serviço:</strong> #<?= (int)$service['id'] ?><br>
                <strong>Tipo:</strong> <?= htmlspecialchars($service['service_type'], ENT_QUOTES, 'UTF-8') ?>
            </div>

            <p>A ação também excluirá os detalhes, quartos e hóspedes relacionados e ficará registrada.</p>

            <form method="post">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <div class="actions">
                    <a class="btn" href="/services/show.php?id=<?= (int)$service['id'] ?>">Cancelar</a>
                    <button type="submit" class="btn btn-danger">Sim, excluir</button>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>
