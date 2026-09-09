<?php
// require_once __DIR__ . '/../inc/auth.php'; // فعّل لاحقًا
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Importar Reserva</title>

<link href="https://cdn.jsdelivr.net/npm/@tabler/core@latest/dist/css/tabler.min.css" rel="stylesheet"/>

<style>
textarea {
    min-height: 220px;
    font-family: monospace;
}
.result-box {
    background: #fff;
    border-radius: 8px;
    padding: 15px;
}
</style>

</head>
<body class="bg-light">

<div class="container py-5">

    <div class="card shadow">
        <div class="card-header">
            <h3 class="card-title">📥 Importar Reserva</h3>
        </div>

        <div class="card-body">

            <!-- القسم 1 -->
            <label class="form-label">
                Cole aqui o e-mail, PNR, PDF text ou reserva
            </label>
            <textarea id="raw_text" class="form-control" placeholder="Cole aqui o conteúdo..."></textarea>

            <!-- القسم 2 -->
            <div class="mt-3">
                <button id="btnExtract" class="btn btn-primary">
                    🔍 Extrair dados
                </button>
            </div>

            <hr>

            <!-- القسم 3 -->
            <div id="result" class="result-box text-muted">
                Nenhum dado ainda...
            </div>

            <!-- القسم 4 -->
            <div class="mt-3 d-flex gap-2">
                <button id="btnSend" class="btn btn-success d-none">
                    ➕ Enviar para Fatura
                </button>

                <button id="btnDraft" class="btn btn-secondary d-none">
                    💾 Salvar como Rascunho
                </button>
            </div>

        </div>
    </div>

</div>

<script src="./import-booking/js/import-booking.js?v=1"></script>

</body>
</html>