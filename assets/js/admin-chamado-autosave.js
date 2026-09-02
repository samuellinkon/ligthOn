/**
 * Chamado (admin): salvamento manual da ficha OS (FAB) + AJAX de prioridade/status.
 */
(function (global) {
  'use strict';

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
    // Inclui campos disabled: o valor ainda existe no DOM e o save não pode
    // omiti-los (senão o backend grava NULL e apaga a ficha).
    panel.querySelectorAll('input, select, textarea').forEach(function (el) {
      if (!el.name) return;
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

  function isInternalNavLink(anchor) {
    if (!anchor || !anchor.getAttribute) return false;
    var href = anchor.getAttribute('href');
    if (!href || href === '#' || href.charAt(0) === '#') return false;
    if (anchor.getAttribute('download') != null) return false;
    if (anchor.target === '_blank') return false;
    if (/^(mailto:|tel:|javascript:)/i.test(href)) return false;
    try {
      var url = new URL(href, global.location.href);
      if (url.origin !== global.location.origin) return false;
      // Mesma página, só âncora
      if (
        url.pathname === global.location.pathname &&
        url.search === global.location.search &&
        url.hash
      ) {
        return false;
      }
      return true;
    } catch (e) {
      return false;
    }
  }

  function initOs() {
    var panel = document.getElementById('chamado-form-os-dados');
    if (!panel || panel.getAttribute('data-chamado-os-save') !== '1') return;

    var saving = false;
    var dirty = false;
    var lastSnap = panelSnapshot(panel);
    var leaveConfirmOpen = false;

    var fab = document.createElement('button');
    fab.type = 'button';
    fab.id = 'chamado-os-save-fab';
    fab.className = 'chamado-os-save-fab';
    fab.setAttribute('aria-label', 'Salvar alterações da ordem de serviço');
    fab.title = 'Salvar alterações da ordem de serviço';
    fab.textContent = 'Salvar alterações';
    document.body.appendChild(fab);

    function setDirty(next) {
      dirty = !!next;
      fab.classList.toggle('is-dirty', dirty);
      fab.disabled = saving;
    }

    function syncDirtyFromPanel() {
      setDirty(panelSnapshot(panel) !== lastSnap);
    }

    function setBusy(busy) {
      panel.classList.toggle('chamado-os-dados-panel--saving', !!busy);
      fab.disabled = !!busy;
      fab.classList.toggle('is-saving', !!busy);
      fab.textContent = busy ? 'Salvando…' : 'Salvar alterações';
      panel.querySelectorAll('input, select, textarea').forEach(function (el) {
        el.disabled = !!busy;
      });
    }

    function onBeforeUnload(e) {
      if (!dirty || saving) return;
      e.preventDefault();
      e.returnValue = '';
    }

    function confirmLeave() {
      if (!dirty || saving) return Promise.resolve(true);
      if (typeof global.appConfirm !== 'function') {
        return Promise.resolve(
          global.confirm(
            'Você tem alterações na ordem de serviço que ainda não foram salvas. Deseja sair sem salvar?'
          )
        );
      }
      leaveConfirmOpen = true;
      return global
        .appConfirm({
          title: 'Alterações não salvas',
          message:
            'Você tem alterações na ordem de serviço que ainda não foram salvas. Deseja sair sem salvar?',
          confirmText: 'Sair sem salvar',
          cancelText: 'Continuar editando',
          danger: true,
        })
        .then(function (ok) {
          leaveConfirmOpen = false;
          return !!ok;
        })
        .catch(function () {
          leaveConfirmOpen = false;
          return false;
        });
    }

    function doSave() {
      var snap = panelSnapshot(panel);
      if (snap === lastSnap || saving) return;

      // Montar o payload ANTES de setBusy: inputs disabled são ignorados
      // em buildOsFormData e acabavam gravando ficha vazia (apagando endereço).
      var fd = buildOsFormData(panel);

      saving = true;
      setBusy(true);

      postForm(fd)
        .then(function (data) {
          if (!data || !data.ok) {
            alertMsg((data && data.err) || 'Não foi possível salvar a ficha.', 'Ordem de serviço');
            return;
          }
          lastSnap = snap;
          setDirty(false);
          if (data.msg) toastOk(data.msg);
        })
        .catch(function (err) {
          alertMsg((err && err.message) || 'Erro de rede ao salvar a ficha.', 'Ordem de serviço');
        })
        .finally(function () {
          saving = false;
          setBusy(false);
          syncDirtyFromPanel();
        });
    }

    panel.addEventListener('change', function (e) {
      var t = e.target;
      if (!t || !t.name) return;
      syncDirtyFromPanel();
    });
    panel.addEventListener('input', function (e) {
      var t = e.target;
      if (!t || !t.name) return;
      if (t.tagName === 'SELECT') return;
      syncDirtyFromPanel();
    });

    fab.addEventListener('click', function () {
      if (saving) return;
      if (!dirty) {
        toastOk('Nenhuma alteração para salvar.');
        return;
      }
      doSave();
    });

    document.addEventListener(
      'click',
      function (e) {
        if (!dirty || saving || leaveConfirmOpen) return;
        var anchor = e.target && e.target.closest ? e.target.closest('a[href]') : null;
        if (!isInternalNavLink(anchor)) return;
        e.preventDefault();
        e.stopPropagation();
        var href = anchor.href;
        confirmLeave().then(function (ok) {
          if (!ok) return;
          dirty = false;
          global.location.href = href;
        });
      },
      true
    );

    global.addEventListener('beforeunload', onBeforeUnload);
  }

  function initMetaSelect(selectEl, acao) {
    if (!selectEl) return;

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
            if (acao === 'status' && data.reload) {
              global.location.reload();
              return;
            }
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

  function initMetaDate(inputEl, acao) {
    if (!inputEl) return;

    var initial = inputEl.value;

    inputEl.addEventListener('change', function () {
      var novo = inputEl.value;
      if (novo === initial) return;
      if (!novo) {
        inputEl.value = initial;
        alertMsg('Informe a data de validação.', 'Data de validação');
        return;
      }

      inputEl.disabled = true;
      var fd = new FormData();
      fd.append('acao', acao);
      fd.append('ajax', '1');
      fd.append(inputEl.name, novo);

      postForm(fd)
        .then(function (data) {
          if (!data || !data.ok) {
            inputEl.value = initial;
            alertMsg((data && data.err) || 'Não foi possível salvar.', 'Data de validação');
            return;
          }
          if (data.validado_em) {
            inputEl.value = data.validado_em;
            initial = data.validado_em;
          } else {
            initial = novo;
          }
          if (data.msg) toastOk(data.msg);
        })
        .catch(function (err) {
          inputEl.value = initial;
          alertMsg((err && err.message) || 'Erro de rede.', 'Data de validação');
        })
        .finally(function () {
          inputEl.disabled = false;
        });
    });
  }

  function initMeta() {
    document.querySelectorAll('[data-chamado-autosave="prioridade"]').forEach(function (el) {
      initMetaSelect(el, 'prioridade');
    });
    document.querySelectorAll('[data-chamado-autosave="status"]').forEach(function (el) {
      initMetaSelect(el, 'status');
    });
    document.querySelectorAll('[data-chamado-autosave="validado_em"]').forEach(function (el) {
      initMetaDate(el, 'validado_em');
    });
  }

  function init() {
    initOs();
    initMeta();
  }

  global.AdminChamadoAutosave = { init: init };
})(window);
