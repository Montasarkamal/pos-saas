<?php /* inc/footer.php */ ?>
        </div><!-- /.container-xl -->
      </div><!-- /.page-body -->

      <footer class="footer footer-transparent">
        <div class="container-xl">
          <div class="text-muted">© <?= date('Y') ?> KAMALTUR • By Montasar Kamal</div>
        </div>
      </footer>
    </div><!-- /.page-wrapper -->
  </div><!-- /.page -->

  <!-- Tabler JS (inclui Bootstrap JS) -->
  <script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.4.0/dist/js/tabler.min.js" defer></script>

  <!-- Select2 (depende do jQuery já carregado no header) -->
  <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js" defer></script>

  
		<script>
		(function () {
	  function pad(n) { return String(n).padStart(2, '0'); }
	  function tickClock() {
	    const el = document.getElementById('headerClock');
	    if (!el) return;
	    const d = new Date();
	    el.textContent = `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
	  }
	
	  async function loadUsdRate() {
	    const el = document.getElementById('headerUsdRate');
	    if (!el) return;
	    try {
	      const res = await fetch('/inc/usd_bcb.php', { cache: 'no-store' });
	      if (!res.ok) throw new Error('HTTP ' + res.status);
	      const data = await res.json();
	      if (!data.ok || !data.rate) throw new Error('no-rate');
	      const rate = Number(data.rate).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	      el.textContent = `USD ${rate}`;
	      if (data.date) el.closest('.header-pill')?.setAttribute('title', `Banco Central do Brasil - ${data.date}`);
	    } catch (e) {
	      el.textContent = 'USD indisponível';
	    }
	  }
	
	  const rules = [
    [/nova|novo|adicionar|criar|\+/i, 'plus'],
    [/salvar|gravar/i, 'device-floppy'],
    [/editar|alterar/i, 'pencil'],
    [/excluir|apagar|remover|deletar/i, 'trash'],
    [/voltar|anterior/i, 'arrow-left'],
    [/próxima|proxima/i, 'arrow-right'],
    [/cancelar/i, 'x'],
    [/filtrar|buscar|pesquisar/i, 'search'],
    [/limpar/i, 'eraser'],
    [/imprimir|print/i, 'printer'],
    [/voucher/i, 'file-check'],
    [/recibo/i, 'receipt'],
    [/pdf|bilhete|ticket/i, 'ticket'],
    [/pago|pagar/i, 'check'],
    [/atualizar|refresh/i, 'refresh'],
    [/backup|baixar|download/i, 'download'],
    [/usuário|usuario|user/i, 'user-cog'],
    [/cliente/i, 'user-plus'],
    [/fornecedor/i, 'building-store'],
    [/configurações|configuracoes|settings/i, 'settings'],
    [/listas|classe|bagagem|companhias/i, 'list-details']
  ];

  function labelOf(btn) {
    return (btn.textContent || btn.getAttribute('aria-label') || btn.getAttribute('title') || '').replace(/\s+/g, ' ').trim();
  }

  function hasVisualIcon(btn) {
    return !!btn.querySelector('i, svg, img, .avatar');
  }

  function iconFor(btn) {
    const text = labelOf(btn);
    const href = btn.getAttribute('href') || btn.getAttribute('action') || '';
    const haystack = `${text} ${href}`;
    for (const [re, icon] of rules) {
      if (re.test(haystack)) return icon;
    }
    if (btn.classList.contains('btn-primary')) return 'circle-plus';
    return '';
  }

  function enhanceButton(btn) {
    if (!btn || btn.dataset.iconEnhanced === '1') return;
    if (btn.classList.contains('btn-icon') || btn.classList.contains('btn-sm') || hasVisualIcon(btn)) {
      btn.dataset.iconEnhanced = '1';
      return;
    }
    const icon = iconFor(btn);
    if (!icon) {
      btn.dataset.iconEnhanced = '1';
      return;
    }
    const i = document.createElement('i');
    i.className = `ti ti-${icon}`;
    i.setAttribute('aria-hidden', 'true');
    btn.prepend(i);
    btn.dataset.iconEnhanced = '1';
  }

  function enhanceAllButtons(root) {
    (root || document).querySelectorAll('a.btn, button.btn').forEach(enhanceButton);
  }

	  document.addEventListener('DOMContentLoaded', function () {
	    tickClock();
	    setInterval(tickClock, 30000);
	    loadUsdRate();
	    enhanceAllButtons(document);
    const observer = new MutationObserver((mutations) => {
      for (const mutation of mutations) {
        for (const node of mutation.addedNodes) {
          if (!(node instanceof HTMLElement)) continue;
          if (node.matches?.('a.btn, button.btn')) enhanceButton(node);
          enhanceAllButtons(node);
        }
      }
    });
    observer.observe(document.body, { childList: true, subtree: true });
  });
})();

(function () {
  const root = document.documentElement;   // <html>
  const KEY  = 'ui_root_font_px';          // اسم المفتاح للتخزين
  const MIN  = 12;                         // أقل حجم خط px
  const MAX  = 22;                         // أكبر حجم خط px
  const STEP = 1.10;                       // نسبة الزيادة/النقصان (10%)
  const DEFAULT = 15;                      // الحجم الافتراضي px (≈ 0.95rem)

  const btnInc   = document.getElementById('font-bigger');
  const btnDec   = document.getElementById('font-smaller');
  const btnReset = document.getElementById('font-reset');

  function getPx() {
    return parseFloat(getComputedStyle(root).fontSize);
  }

  function setPx(px) {
    const clamped = Math.max(MIN, Math.min(MAX, px));
    root.style.fontSize = clamped + 'px';
    localStorage.setItem(KEY, String(clamped));
  }

  // عند التحميل: طبّق الحجم المحفوظ إن وجد
  const saved = parseFloat(localStorage.getItem(KEY));
  if (!Number.isNaN(saved)) {
    root.style.fontSize = saved + 'px';
  } else {
    root.style.fontSize = DEFAULT + 'px';
  }

  // أحداث الأزرار
  btnInc?.addEventListener('click', () => setPx(getPx() * STEP));
  btnDec?.addEventListener('click', () => setPx(getPx() / STEP));
  btnReset?.addEventListener('click', () => setPx(DEFAULT));
})();
</script>



</body>
</html>
