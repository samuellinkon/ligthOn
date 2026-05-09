/**
 * Modais de confirmação e aviso (substituem confirm/alert nativos).
 * API global:
 *   appConfirm(message | { message, title?, confirmText?, cancelText?, danger? }) → Promise<boolean>
 *   appAlert(message, title?) → Promise<void>
 *   appToast(message, 'ok'|'info') — toast breve
 */
(function () {
  'use strict';

  var overlay = null;
  var titleEl = null;
  var textEl = null;
  var actionsEl = null;
  var resolveFn = null;
  var mode = 'confirm'; // 'confirm' | 'alert'
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
      '<div class="app-modal-actions"></div>' +
      '</div>';
    overlay.setAttribute('hidden', 'hidden');
    document.body.appendChild(overlay);
    titleEl = overlay.querySelector('.app-modal-title');
    textEl = overlay.querySelector('.app-modal-text');
    actionsEl = overlay.querySelector('.app-modal-actions');

    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) {
        if (mode === 'alert') close();
        else close(false);
      }
    });

    document.addEventListener('keydown', onKeydown);
  }

  function onKeydown(e) {
    if (!overlay || !overlay.classList.contains('is-open')) return;
    if (e.key === 'Escape') {
      e.preventDefault();
      if (mode === 'alert') close();
      else close(false);
    }
  }

  function open() {
    lastFocus = document.activeElement;
    overlay.classList.add('is-open');
    overlay.removeAttribute('hidden');
  }

  /**
   * @param {boolean} [confirmed] — ignorado em modo alert (sempre encerra como OK)
   */
  function close(confirmed) {
    var wasAlert = mode === 'alert';
    overlay.classList.remove('is-open');
    overlay.setAttribute('hidden', 'hidden');
    var r = resolveFn;
    resolveFn = null;
    if (r) {
      if (wasAlert) r();
      else r(!!confirmed);
    }
    if (lastFocus && typeof lastFocus.focus === 'function') {
      try {
        lastFocus.focus();
      } catch (err) { /* ignore */ }
    }
  }

  function setActions(opts) {
    actionsEl.innerHTML = '';
    if (mode === 'alert') {
      var ok = document.createElement('button');
      ok.type = 'button';
      ok.className = 'btn btn-primary';
      ok.textContent = 'OK';
      ok.addEventListener('click', function () {
        close();
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
      close(false);
    });

    var confirm = document.createElement('button');
    confirm.type = 'button';
    confirm.className = 'btn ' + (opts.danger ? 'btn-danger' : 'btn-primary');
    confirm.textContent = opts.confirmText || 'Confirmar';
    confirm.addEventListener('click', function () {
      close(true);
    });

    actionsEl.appendChild(cancel);
    actionsEl.appendChild(confirm);
    confirm.focus();
  }

  /**
   * @param {string|{message:string,title?:string,confirmText?:string,cancelText?:string,danger?:boolean}} opts
   * @returns {Promise<boolean>}
   */
  window.appConfirm = function (opts) {
    buildDom();
    var o = typeof opts === 'string' ? { message: opts } : opts || {};
    mode = 'confirm';
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
    titleEl.textContent = title || 'Atenção';
    textEl.innerHTML = escapeHtml(message || '').replace(/\n/g, '<br>');
    setActions({});
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
