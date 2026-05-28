/**
 * Modais de confirmação e aviso (substituem confirm/alert/prompt nativos).
 * API global:
 *   appConfirm(message | { message, title?, confirmText?, cancelText?, danger? }) → Promise<boolean>
 *   appAlert(message, title?) → Promise<void>
 *   appPrompt({ message, title?, defaultValue?, confirmText?, cancelText? }) → Promise<string|null>
 *   appToast(message, 'ok'|'info') — toast breve
 *   crmUi.alert / crmUi.confirm / crmUi.prompt — aliases
 */
(function () {
  'use strict';

  var overlay = null;
  var modalEl = null;
  var titleEl = null;
  var textEl = null;
  var inputWrapEl = null;
  var inputEl = null;
  var actionsEl = null;
  var resolveFn = null;
  var mode = 'confirm'; // 'confirm' | 'alert' | 'prompt'
  var lastFocus = null;

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function buildDom() {
    if (overlay) return;
    overlay = document.createElement('div');
    overlay.className = 'app-modal-overlay';
    overlay.setAttribute('role', 'presentation');
    overlay.innerHTML =
      '<div class="app-modal" role="dialog" aria-modal="true" aria-labelledby="app-modal-title" aria-describedby="app-modal-desc">' +
      '<h3 class="app-modal-title" id="app-modal-title"></h3>' +
      '<p class="app-modal-text" id="app-modal-desc"></p>' +
      '<div class="app-modal-input-wrap" hidden>' +
      '<input type="text" class="app-modal-input" id="app-modal-input" autocomplete="off">' +
      '</div>' +
      '<div class="app-modal-actions"></div>' +
      '</div>';
    overlay.setAttribute('hidden', 'hidden');
    document.body.appendChild(overlay);
    modalEl = overlay.querySelector('.app-modal');
    titleEl = overlay.querySelector('.app-modal-title');
    textEl = overlay.querySelector('.app-modal-text');
    inputWrapEl = overlay.querySelector('.app-modal-input-wrap');
    inputEl = overlay.querySelector('.app-modal-input');
    actionsEl = overlay.querySelector('.app-modal-actions');

    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) {
        if (mode === 'alert') {
          closeAlert();
        } else if (mode === 'prompt') {
          closePrompt(null);
        } else {
          closeConfirm(false);
        }
      }
    });

    if (inputEl) {
      inputEl.addEventListener('keydown', function (e) {
        if (mode !== 'prompt' || !overlay.classList.contains('is-open')) return;
        if (e.key === 'Enter') {
          e.preventDefault();
          closePrompt(String(inputEl.value || ''));
        }
      });
    }

    document.addEventListener('keydown', onKeydown);
  }

  function onKeydown(e) {
    if (!overlay || !overlay.classList.contains('is-open')) return;
    if (e.key === 'Escape') {
      e.preventDefault();
      if (mode === 'alert') {
        closeAlert();
      } else if (mode === 'prompt') {
        closePrompt(null);
      } else {
        closeConfirm(false);
      }
    }
  }

  function hideInput() {
    if (inputWrapEl) {
      inputWrapEl.setAttribute('hidden', 'hidden');
    }
    if (modalEl) {
      modalEl.classList.remove('app-modal--prompt');
    }
  }

  function showInput(defaultValue) {
    if (!inputWrapEl || !inputEl) return;
    inputWrapEl.removeAttribute('hidden');
    if (modalEl) {
      modalEl.classList.add('app-modal--prompt');
    }
    inputEl.value = defaultValue == null ? '' : String(defaultValue);
  }

  function open() {
    lastFocus = document.activeElement;
    overlay.classList.add('is-open');
    overlay.removeAttribute('hidden');
  }

  function finish() {
    overlay.classList.remove('is-open');
    overlay.setAttribute('hidden', 'hidden');
    hideInput();
    if (lastFocus && typeof lastFocus.focus === 'function') {
      try {
        lastFocus.focus();
      } catch (err) { /* ignore */ }
    }
  }

  function closeConfirm(confirmed) {
    var r = resolveFn;
    resolveFn = null;
    finish();
    if (r) r(!!confirmed);
  }

  function closeAlert() {
    var r = resolveFn;
    resolveFn = null;
    finish();
    if (r) r();
  }

  function closePrompt(value) {
    var r = resolveFn;
    resolveFn = null;
    finish();
    if (r) r(value);
  }

  function setActions(opts) {
    actionsEl.innerHTML = '';
    if (mode === 'alert') {
      var ok = document.createElement('button');
      ok.type = 'button';
      ok.className = 'btn btn-primary';
      ok.textContent = 'OK';
      ok.addEventListener('click', function () {
        closeAlert();
      });
      actionsEl.appendChild(ok);
      ok.focus();
      return;
    }

    var cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'btn btn-secondary';
    cancel.textContent = opts.cancelText || 'Cancelar';
    cancel.addEventListener('click', function () {
      if (mode === 'prompt') {
        closePrompt(null);
      } else {
        closeConfirm(false);
      }
    });

    var confirm = document.createElement('button');
    confirm.type = 'button';
    confirm.className = 'btn ' + (opts.danger ? 'btn-danger' : 'btn-primary');
    confirm.textContent = opts.confirmText || (mode === 'prompt' ? 'OK' : 'Confirmar');
    confirm.addEventListener('click', function () {
      if (mode === 'prompt' && inputEl) {
        closePrompt(String(inputEl.value || ''));
      } else {
        closeConfirm(true);
      }
    });

    actionsEl.appendChild(cancel);
    actionsEl.appendChild(confirm);
    if (mode === 'prompt' && inputEl) {
      inputEl.focus();
      inputEl.select();
    } else {
      confirm.focus();
    }
  }

  /**
   * @param {string|{message:string,title?:string,confirmText?:string,cancelText?:string,danger?:boolean}} opts
   * @returns {Promise<boolean>}
   */
  window.appConfirm = function (opts) {
    buildDom();
    var o = typeof opts === 'string' ? { message: opts } : opts || {};
    mode = 'confirm';
    hideInput();
    titleEl.textContent = o.title || 'Confirmar ação';
    textEl.innerHTML = escapeHtml(o.message || 'Deseja continuar?').replace(/\n/g, '<br>');
    setActions({
      confirmText: o.confirmText,
      cancelText: o.cancelText,
      danger: !!o.danger
    });
    return new Promise(function (resolve) {
      resolveFn = resolve;
      open();
    });
  };

  /**
   * @param {string} message
   * @param {string} [title]
   * @returns {Promise<void>}
   */
  window.appAlert = function (message, title) {
    buildDom();
    mode = 'alert';
    hideInput();
    titleEl.textContent = title || 'Atenção';
    textEl.innerHTML = escapeHtml(message || '').replace(/\n/g, '<br>');
    setActions({});
    return new Promise(function (resolve) {
      resolveFn = resolve;
      open();
    });
  };

  /**
   * @param {string|{message:string,title?:string,defaultValue?:string,confirmText?:string,cancelText?:string}} opts
   * @returns {Promise<string|null>}
   */
  window.appPrompt = function (opts) {
    buildDom();
    var o = typeof opts === 'string' ? { message: opts } : opts || {};
    mode = 'prompt';
    titleEl.textContent = o.title || 'Informe';
    textEl.innerHTML = escapeHtml(o.message || '').replace(/\n/g, '<br>');
    showInput(o.defaultValue != null ? o.defaultValue : '');
    setActions({
      confirmText: o.confirmText,
      cancelText: o.cancelText,
      danger: false
    });
    return new Promise(function (resolve) {
      resolveFn = resolve;
      open();
    });
  };

  /**
   * @param {string} message
   * @param {'ok'|'info'} [kind]
   */
  window.appToast = function (message, kind) {
    var stack = document.getElementById('app-toast-stack');
    if (!stack) {
      stack = document.createElement('div');
      stack.id = 'app-toast-stack';
      stack.className = 'app-toast-stack';
      document.body.appendChild(stack);
    }
    var t = document.createElement('div');
    t.className = 'app-toast-item app-toast-item--' + (kind === 'info' ? 'info' : 'ok');
    t.textContent = message;
    stack.appendChild(t);
    requestAnimationFrame(function () {
      t.classList.add('is-in');
    });
    setTimeout(function () {
      t.classList.remove('is-in');
      setTimeout(function () {
        t.remove();
      }, 220);
    }, kind === 'info' ? 2400 : 1800);
  };

  window.crmUi = {
    alert: function (message, title) {
      return window.appAlert(message, title);
    },
    confirm: function (message, opts) {
      var o = typeof opts === 'object' && opts !== null ? Object.assign({ message: message }, opts) : { message: message };
      return window.appConfirm(o);
    },
    prompt: function (message, defaultValue, title) {
      return window.appPrompt({
        message: message,
        defaultValue: defaultValue,
        title: title
      });
    }
  };

  /** Formulários com data-confirm (substitui onsubmit return confirm) */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.nodeName !== 'FORM') return;
    var msg = form.getAttribute('data-confirm');
    if (!msg) return;
    e.preventDefault();
    var danger = form.hasAttribute('data-confirm-danger');
    var submit = function (ok) {
      if (!ok) return;
      HTMLFormElement.prototype.submit.call(form);
    };
    if (typeof window.appConfirm === 'function') {
      window.appConfirm({ message: msg, title: 'Confirmar', danger: danger }).then(submit);
    } else {
      submit(false);
    }
  });
})();
