(function () {
  'use strict';

  const sidebar = document.querySelector('.sidebar');
  const overlay = document.querySelector('.sidebar-overlay');
  const toggle = document.querySelector('.hamburger');

  function open() {
    if (!sidebar) return;
    sidebar.classList.add('open');
    if (overlay) overlay.classList.add('active');
  }

  function close() {
    if (!sidebar) return;
    sidebar.classList.remove('open');
    if (overlay) overlay.classList.remove('active');
  }

  if (toggle) toggle.addEventListener('click', open);
  if (overlay) overlay.addEventListener('click', close);

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') close();
  });

  // Fecha ao clicar em link (mobile)
  document.querySelectorAll('.sidebar .nav a').forEach(function (a) {
    a.addEventListener('click', function () {
      if (window.innerWidth <= 860) close();
    });
  });
})();
