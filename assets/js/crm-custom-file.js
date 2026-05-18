/**
 * UI customizada para <input type="file"> (tema CRM), mantendo o input nativo para POST e validação.
 */
(function () {
  'use strict';

  function enhance(input) {
    if (!input || input.dataset.crmCustomFileEnhanced === '1') return;
    if (input.closest('.file-upload')) return;
    if (input.hasAttribute('hidden') || input.getAttribute('aria-hidden') === 'true') return;

    input.dataset.crmCustomFileEnhanced = '1';
    input.classList.add('crm-file-input-native');
    input.setAttribute('tabindex', '-1');

    var parent = input.parentNode;
    if (!parent) return;

    var wrap = document.createElement('div');
    wrap.className = 'crm-file-input';
    wrap.setAttribute('data-crm-custom-file', '1');

    var shell = document.createElement('div');
    shell.className = 'crm-file-input__shell';

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'crm-file-input__btn';
    btn.textContent = 'Escolher arquivos';

    var status = document.createElement('span');
    status.className = 'crm-file-input__status crm-file-input__status--empty';
    status.setAttribute('aria-live', 'polite');

    shell.appendChild(btn);
    shell.appendChild(status);

    parent.insertBefore(wrap, input);
    wrap.appendChild(shell);
    wrap.appendChild(input);

    function renderStatus() {
      var files = input.files;
      if (!files || files.length === 0) {
        status.textContent = 'Nenhum arquivo selecionado';
        status.classList.add('crm-file-input__status--empty');
        return;
      }
      status.classList.remove('crm-file-input__status--empty');
      if (files.length === 1) {
        status.textContent = files[0].name;
        return;
      }
      status.textContent = files.length + ' arquivos selecionados';
    }

    btn.addEventListener('click', function () {
      input.click();
    });

    input.addEventListener('change', renderStatus);

    shell.addEventListener('dragenter', function (e) {
      e.preventDefault();
    });
    shell.addEventListener('dragover', function (e) {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'copy';
      shell.classList.add('crm-file-input__shell--drag');
    });
    shell.addEventListener('dragleave', function (e) {
      var next = e.relatedTarget;
      if (next && shell.contains(next)) return;
      shell.classList.remove('crm-file-input__shell--drag');
    });
    shell.addEventListener('drop', function (e) {
      e.preventDefault();
      shell.classList.remove('crm-file-input__shell--drag');
      var dt = e.dataTransfer;
      if (!dt || !dt.files || !dt.files.length) return;
      try {
        input.files = dt.files;
      } catch (err) {
        return;
      }
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });

    renderStatus();

    if (input.disabled) {
      btn.disabled = true;
      shell.classList.add('crm-file-input__shell--disabled');
    }

    var form = input.form;
    if (form) {
      form.addEventListener('reset', function () {
        window.setTimeout(renderStatus, 0);
      });
    }
  }

  function run() {
    document.querySelectorAll('.app input[type="file"]:not(.crm-no-custom-file)').forEach(enhance);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }

  window.crmCustomFileEnhance = run;
})();
