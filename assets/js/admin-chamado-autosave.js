/**
 * Chamado (admin): gravação automática da ficha OS, prioridade e status via AJAX.
 */
(function (global) {
  'use strict';

  var DEBOUNCE_MS = 650;

  var STATUS_BADGE_CLASS = {
    Aberto: 'open',
    'Em andamento': 'progress',
    'Aguardando Aprovação': 'waiting',
    Resolvido: 'done',
    Validado: 'done',
    Fechado: 'done',
    Cancelado: 'cancelled',
    Baixa: 'waiting',
    Normal: 'waiting',
    Alta: 'urgent',
    Urgente: 'urgent',
  };

  function alertMsg(msg, title) {
    if (typeof global.appAlert === 'function') {
      global.appAlert(msg, title || 'Chamado');
    }
  }

  function toastOk(msg) {
    if (typeof global.appToast === 'function') {
      global.appToast(msg, 'ok');
    }
  }

  function parseJsonResponse(r) {
    return r.text().then(function (text) {
      var trimmed = (text || '').trim();
      if (!trimmed) {
        throw new Error('Resposta vazia do servidor.');
      }
      try {
        return JSON.parse(trimmed);
      } catch (e) {
        throw new Error('Resposta inválida do servidor.');
      }
    });
  }

  function postForm(fd) {
    return fetch(global.location.pathname + global.location.search, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
    }).then(parseJsonResponse);
  }

  function panelSnapshot(panel) {
    if (!panel) return '';
    try {
      var fd = new FormData();
      panel.querySelectorAll('input, select, textarea').forEach(function (el) {
        if (!el.name || el.disabled) return;
        if (el.type === 'checkbox' || el.type === 'radio') {
          if (el.checked) fd.append(el.name, el.value);
          return;
        }
        if (el.type === 'file') return;
        fd.append(el.name, el.value);
      });
      var parts = [];
      fd.forEach(function (v, k) {
        parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(String(v)));
      });
      parts.sort();
      return parts.join('&');
    } catch (e) {
      return '';
    }
  }

  function buildOsFormData(panel) {
    var fd = new FormData();
    fd.append('acao', 'os_dados');
    fd.append('ajax', '1');
    panel.querySelectorAll('input, select, textarea').forEach(function (el) {
      if (!el.name || el.disabled) return;
      if (el.type === 'checkbox' || el.type === 'radio') {
        if (el.checked) fd.append(el.name, el.value);
        return;
      }
      if (el.type === 'file') return;
      fd.append(el.name, el.value);
    });
    return fd;
  }

  function badgeClassFor(value) {
    var mod = STATUS_BADGE_CLASS[value] || 'plain';
    return 'chamado-header-toolbar__chip badge ' + mod;
  }

  function updateHeaderChip(selector, text, kind) {
    var el = document.querySelector(selector);
    if (!el || text == null) return;
    el.textContent = text;
    if (kind === 'status') {
      el.className =
        'chamado-header-toolbar__chip chamado-header-toolbar__chip--status ' + badgeClassFor(text);
    } else if (kind === 'prioridade') {
      el.className =
        'chamado-header-toolbar__chip chamado-header-toolbar__chip--prio ' + badgeClassFor(text);
    }
  }

  function initOs() {
    var panel = document.getElementById('chamado-form-os-dados');
    if (!panel || panel.getAttribute('data-chamado-os-ajax') !== '1') return;

    var saving = false;
    var pending = false;
    var debounceTimer = null;
    var lastSnap = panelSnapshot(panel);

    function setBusy(busy) {
      panel.classList.toggle('chamado-os-dados-panel--saving', !!busy);
      panel.querySelectorAll('input, select, textarea').forEach(function (el) {
        el.disabled = !!busy;
      });
    }

    function doSave() {
      var snap = panelSnapshot(panel);
      if (snap === lastSnap) return;
      if (saving) {
        pending = true;
        return;
      }

      saving = true;
      pending = false;
      setBusy(true);

      postForm(buildOsFormData(panel))
        .then(function (data) {
          if (!data || !data.ok) {
            alertMsg((data && data.err) || 'Não foi possível salvar a ficha.', 'Ordem de serviço');
            return;
          }
          lastSnap = panelSnapshot(panel);
          if (data.msg) toastOk(data.msg);
        })
        .catch(function (err) {
          alertMsg((err && err.message) || 'Erro de rede ao salvar a ficha.', 'Ordem de serviço');
        })
        .finally(function () {
          saving = false;
          setBusy(false);
          if (pending) {
            pending = false;
            doSave();
          }
        });
    }

    function scheduleSave() {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(doSave, DEBOUNCE_MS);
    }

    panel.addEventListener('change', function (e) {
      var t = e.target;
      if (!t || !t.name) return;
      scheduleSave();
    });
    panel.addEventListener('input', function (e) {
      var t = e.target;
      if (!t || !t.name) return;
      if (t.tagName === 'SELECT') return;
      scheduleSave();
    });
  }

  function initMetaSelect(selectEl, acao) {
    if (!selectEl) return;

    var form = selectEl.closest('form');
    var initial = selectEl.value;

    selectEl.addEventListener('change', function () {
      var novo = selectEl.value;
      if (novo === initial) return;

      var needsPortalValidarConfirm =
        acao === 'status' &&
        novo === 'Validado' &&
        document.body &&
        document.body.getAttribute('data-chamado-portal') === '1';

      function runSave() {
        selectEl.disabled = true;
        var fd = new FormData();
        fd.append('acao', acao);
        fd.append('ajax', '1');
        fd.append(selectEl.name, novo);

        postForm(fd)
          .then(function (data) {
            if (!data || !data.ok) {
              selectEl.value = initial;
              alertMsg((data && data.err) || 'Não foi possível salvar.', acao === 'status' ? 'Status' : 'Prioridade');
              return;
            }
            initial = novo;
            if (data.msg) toastOk(data.msg);
            if (acao === 'status' && data.status) {
              updateHeaderChip('.chamado-header-toolbar__chip--status', data.status, 'status');
            }
            if (acao === 'prioridade' && data.prioridade) {
              updateHeaderChip('.chamado-header-toolbar__chip--prio', data.prioridade, 'prioridade');
            }
          })
          .catch(function (err) {
            selectEl.value = initial;
            alertMsg((err && err.message) || 'Erro de rede.', acao === 'status' ? 'Status' : 'Prioridade');
          })
          .finally(function () {
            selectEl.disabled = false;
          });
      }

      if (needsPortalValidarConfirm && typeof global.appConfirm === 'function') {
        global
          .appConfirm({
            message:
              'Confirmar que o atendimento foi concluído satisfatoriamente? O chamado passará ao status Validado.',
            title: 'Validar atendimento',
          })
          .then(function (ok) {
            if (!ok) {
              selectEl.value = initial;
              return;
            }
            runSave();
          });
        return;
      }

      if (needsPortalValidarConfirm) {
        selectEl.value = initial;
        return;
      }

      runSave();
    });
  }

  function initMeta() {
    document.querySelectorAll('[data-chamado-autosave="prioridade"]').forEach(function (el) {
      initMetaSelect(el, 'prioridade');
    });
    document.querySelectorAll('[data-chamado-autosave="status"]').forEach(function (el) {
      initMetaSelect(el, 'status');
    });
  }

  function init() {
    initOs();
    initMeta();
  }

  global.AdminChamadoAutosave = { init: init };
})(window);
