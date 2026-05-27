(function () {
  'use strict';

  // Confirmação em links / botões [data-confirm] — modal (appConfirm)
  document.addEventListener('click', function (e) {
    const target = e.target.closest('[data-confirm]');
    if (!target) return;
    // <form data-confirm> é tratado no evento "submit" (confirm-modal.js).
    // Sem isto, preventDefault no clique impede o submit e, ao confirmar,
    // target.matches('button…') falha porque target é o FORM.
    if (target.tagName === 'FORM') return;
    e.preventDefault();
    const msg = target.getAttribute('data-confirm') || 'Tem certeza que deseja continuar?';
    const proceed = function (ok) {
      if (!ok) return;
      const href = target.getAttribute('href');
      if (href) {
        window.location.href = href;
        return;
      }
      var form = target.closest('form');
      if (form && target.matches('button[type="submit"], input[type="submit"]')) {
        HTMLFormElement.prototype.submit.call(form);
        return;
      }
      target.classList.add('is-confirmed');
    };
    if (typeof window.appConfirm === 'function') {
      window.appConfirm({
        message: msg,
        title: 'Confirmar',
        danger: target.hasAttribute('data-confirm-danger')
      }).then(proceed);
    } else {
      proceed(false);
    }
  });

  // Toggle de filtros (chips)
  document.querySelectorAll('[data-filter-group]').forEach(function (group) {
    group.addEventListener('click', function (e) {
      const chip = e.target.closest('.chip');
      if (!chip) return;
      group.querySelectorAll('.chip').forEach(c => c.classList.remove('active'));
      chip.classList.add('active');
    });
  });

  // Radio cards (forms)
  document.querySelectorAll('.radio-group').forEach(function (group) {
    group.addEventListener('click', function (e) {
      const card = e.target.closest('.radio-card');
      if (!card) return;
      group.querySelectorAll('.radio-card').forEach(c => c.classList.remove('active'));
      card.classList.add('active');
      const radio = card.querySelector('input[type="radio"]');
      if (radio) radio.checked = true;
    });
  });

  // File upload visual (exceto painel de anexos com upload AJAX imediato)
  document.querySelectorAll('.file-upload').forEach(function (box) {
    if (box.closest('[data-chamado-anexos-ajax]')) return;
    const input = box.querySelector('input[type="file"]');
    const list = box.parentElement.querySelector('.file-list');

    box.addEventListener('click', () => input && input.click());

    box.addEventListener('dragover', (e) => {
      e.preventDefault();
      box.style.borderColor = 'var(--primary)';
    });
    box.addEventListener('dragleave', () => {
      box.style.borderColor = '';
    });
    box.addEventListener('drop', (e) => {
      e.preventDefault();
      box.style.borderColor = '';
      if (input) input.files = e.dataTransfer.files;
      renderFiles();
    });

    if (input) input.addEventListener('change', renderFiles);

    function renderFiles() {
      if (!list || !input) return;
      list.innerHTML = '';
      Array.from(input.files || []).forEach((f, i) => {
        const item = document.createElement('div');
        item.className = 'file-item';
        item.innerHTML =
          '<span>' + f.name + ' <small class="muted">(' + Math.round(f.size / 1024) + ' KB)</small></span>' +
          '<button type="button" class="remove" data-i="' + i + '">Remover</button>';
        list.appendChild(item);
      });
    }
  });

  // Dropdown simples
  document.querySelectorAll('[data-dropdown]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      e.stopPropagation();
      el.classList.toggle('open');
    });
  });

  document.addEventListener('click', function () {
    document.querySelectorAll('[data-dropdown].open').forEach(el => el.classList.remove('open'));
  });

  // Smooth scroll em threads
  document.querySelectorAll('.thread').forEach(function (t) {
    t.scrollTop = t.scrollHeight;
  });

  // Edição inline (troca visualização -> input)
  document.querySelectorAll('.info-edit').forEach(function (row) {
    const trigger = row.querySelector('[data-edit-trigger]');
    const save    = row.querySelector('[data-edit-save]');
    const view    = row.querySelector('[data-view]');
    const input   = row.querySelector('input[type="text"]');
    if (!trigger || !save || !view || !input) return;

    trigger.addEventListener('click', function () {
      view.hidden    = true;
      trigger.hidden = true;
      input.hidden   = false;
      save.hidden    = false;
      input.focus();
      input.select();
    });
  });

  // Copiar texto (linha digitável, PIX, etc.)
  document.addEventListener('click', function (e) {
    const btn = e.target.closest('[data-copy]');
    if (!btn) return;
    const text = btn.getAttribute('data-copy') || '';
    if (!text) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () {
        if (typeof window.appToast === 'function') {
          window.appToast('Copiado.', 'ok');
        } else if (typeof window.appAlert === 'function') {
          window.appAlert('Copiado.', 'Copiar');
        }
      }).catch(function () {
        if (typeof window.appAlert === 'function') {
          window.appAlert('Não foi possível copiar automaticamente. Copie o texto abaixo:\n\n' + text, 'Copiar');
        }
      });
    } else if (typeof window.appAlert === 'function') {
      window.appAlert(text, 'Copie o texto');
    }
  });
})();
