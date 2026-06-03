/**
 * Overlay de carregamento ao enviar formulários de importação de planilha (POST tradicional).
 */
(function () {
  'use strict';

  var DEFAULT_MSG = 'Processando importação…';
  var shown = false;

  function showImportLoading(message) {
    if (shown) {
      return;
    }
    shown = true;

    var overlay = document.createElement('div');
    overlay.id = 'crm-import-loader';
    overlay.className = 'pontos-page-loading is-visible';
    overlay.setAttribute('role', 'alertdialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-busy', 'true');
    overlay.setAttribute('aria-live', 'polite');
    overlay.setAttribute('aria-label', 'Importando planilha');
    overlay.innerHTML =
      '<div class="pontos-page-loading__panel">' +
      '  <div class="pontos-page-loading__spinner" aria-hidden="true"></div>' +
      '  <p class="pontos-page-loading__title">Importando</p>' +
      '  <p class="pontos-page-loading__msg">' + (message || DEFAULT_MSG) + '</p>' +
      '</div>';

    document.body.classList.add('pontos-page-loading-active');
    document.body.appendChild(overlay);
  }

  function disableSubmitButtons(form) {
    var buttons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
    for (var i = 0; i < buttons.length; i++) {
      buttons[i].disabled = true;
    }
  }

  function handleFormSubmit(ev) {
    var form = ev.target;
    if (!form || !form.classList.contains('js-crm-import-form')) {
      return;
    }
    if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
      return;
    }

    var msg = form.getAttribute('data-import-msg') || DEFAULT_MSG;
    showImportLoading(msg);
    disableSubmitButtons(form);
  }

  document.addEventListener('submit', handleFormSubmit, true);
})();
