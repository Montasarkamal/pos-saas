document.addEventListener("DOMContentLoaded", function () {
  const el = document.getElementById('chartDistribuicao');
  if (!el) return;

  const paid   = parseInt(el.dataset.paid || "0");
  const unpaid = 100 - paid;

  const centerText = {
    id: 'centerText',
    afterDraw(chart) {
      const {ctx, chartArea: {width, height}} = chart;
      ctx.save();
      ctx.font = '600 16px system-ui, -apple-system, Segoe UI, Roboto, Arial';
      ctx.fillStyle = '#111827';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText(paid + '%', width / 2, height / 2);
      ctx.restore();
    }
  };

  new Chart(el.getContext('2d'), {
    type: 'doughnut',
    data: {
      labels: ['Pago', 'Em aberto'],
      datasets: [{
        data: [paid, unpaid],
        backgroundColor: ['#22c55e', '#ef4444'],
        borderWidth: 0
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '70%',
      plugins: { legend: { display: false }, tooltip: { enabled: true } }
    },
    plugins: [centerText]
  });
});
