import Alpine from 'alpinejs';
import './app.css';

// Alpine is used progressively on server-rendered pages (no SPA).
window.Alpine = Alpine;
Alpine.start();

console.log('[kamaltur] UI bundle loaded');