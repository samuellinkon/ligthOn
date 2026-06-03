(function () {
  var modal = document.getElementById('cliente-plano-modal');
  if (!modal) return;

  function open() {
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    var closeBtn = modal.querySelector('[data-plano-modal-close].btn');
    if (closeBtn) {
      window.setTimeout(function () { closeBtn.focus(); }, 80);
    }
  }

  function close() {
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  document.querySelectorAll('[data-plano-modal-open]').forEach(function (btn) {
    btn.addEventListener('click', open);
  });

  modal.querySelectorAll('[data-plano-modal-close]').forEach(function (btn) {
    btn.addEventListener('click', close);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !modal.hidden) {
      close();
    }
  });
})();
