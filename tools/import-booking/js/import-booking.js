document.getElementById('btnExtract').addEventListener('click', async () => {
    const text = document.getElementById('raw_text').value.trim();
    const box = document.getElementById('result');

    if (!text) {
        alert('Cole o conteúdo primeiro');
        return;
    }

    box.innerHTML = '<div class="alert alert-info">Processando...</div>';

    try {
        const res = await fetch('./import-booking/parser-ai.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ raw_text: text })
        });

        const raw = await res.text();
        let data;

        try {
            data = JSON.parse(raw);
        } catch (e) {
            box.innerHTML = `<div class="alert alert-danger">Servidor retornou algo inválido:<br><pre>${raw}</pre></div>`;
            return;
        }

        if (!data.ok) {
            box.innerHTML = `<div class="alert alert-danger">${data.error}</div>`;
            return;
        }

        const d = data.data;

        const passageirosHtml = (d.passageiros || []).map(p =>
            `<li>${p.type} - ${p.name}</li>`
        ).join('') || '<li>-</li>';

        const segmentosHtml = (d.segmentos || []).map(s =>
            `<li><strong>${s.companhia} ${s.voo}</strong> | ${s.origem} → ${s.destino} | ${s.saida} | Classe: ${s.classe} | Bagagem: ${s.bagagem}</li>`
        ).join('') || '<li>-</li>';

        box.innerHTML = `
            <h4>📌 PNR: ${d.pnr || '-'}</h4>
            <p><strong>Bilhete:</strong> ${d.ticket_number || '-'}</p>
            <p><strong>Emissão:</strong> ${d.issue_date || '-'}</p>

            <hr>

            <h5>👤 Passageiros</h5>
            <ul>${passageirosHtml}</ul>

            <h5>✈️ Segmentos</h5>
            <ul>${segmentosHtml}</ul>

            <h5>💰 Valor</h5>
            <p>
                Tarifa: ${d.valor?.tarifa || '-'}<br>
                Tx Emb.: ${d.valor?.tx_emb || '-'}<br>
                Taxa DU: ${d.valor?.taxa_du || '-'}<br>
                <strong>Total: ${d.valor?.total || '-'}</strong>
            </p>

            <h5>📜 Regras</h5>
            <p>${d.regras?.texto || '-'}</p>
        `;
    } catch (e) {
        box.innerHTML = `<div class="alert alert-danger">Erro: ${e.message}</div>`;
    }
});