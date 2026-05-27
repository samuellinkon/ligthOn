/**
 * Equipe no chamado (admin): grava técnicos via AJAX ao marcar/desmarcar.
 */
(function (global) {
  'use strict';

  function alertMsg(msg, title) {
    if (typeof global.appAlert === 'function') {
      global.appAlert(msg, title || 'Equipe');
    } else {
      global.alert(msg);
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

  function equipeSnapshot(root) {
    if (!root) return '';
    var ids = [];
    root.querySelectorAll('input[type="checkbox"][name="tecnico_user_ids[]"]:checked').forEach(function (cb) {
      ids.push(cb.value);
    });
    ids.sort();
    return ids.join(',');
  }

  function applyCountUi(countEl, manyEl, n, labelFromServer) {
    if (!countEl) return;
    if (labelFromServer) {
      countEl.textContent = labelFromServer;
    } else if (n === 0) {
      countEl.textContent = 'Ninguém selecionado';
    } else if (n === 1) {
      countEl.textContent = '1 na equipe';
    } else {
      countEl.textContent = n + ' na equipe';
    }
    countEl.className =
      n === 0 ? 'equipe-picker-count equipe-picker-count--muted' : 'equipe-picker-count equipe-picker-count--ok';
    if (manyEl) {
      if (n > 3) manyEl.removeAttribute('hidden');
      else manyEl.setAttribute('hidden', '');
    }
  }

  function init() {
    var form = document.getElementById('equipe_picker_form');
    var root = document.getElementById('equipe_picker_grid');
    var filtro = document.getElementById('filtro_equipe_chamado');
    var countEl = document.getElementById('equipe_picker_count');
    var manyEl = document.getElementById('equipe_picker_many');
    if (!form || !root || !filtro) return;

    var ajaxOn = form.getAttribute('data-chamado-equipe-ajax') === '1';
    var labels = root.querySelectorAll('[data-equipe-pick]');
    var saving = false;
    var savePending = false;
    var debounceTimer = null;

    function updateCount(labelFromServer) {
      var n = root.querySelectorAll('input[type="checkbox"]:checked').length;
      applyCountUi(countEl, manyEl, n, labelFromServer);
    }

    function applyFilter() {
      var q = filtro.value.trim().toLowerCase();
      labels.forEach(function (lab) {
        var cb = lab.querySelector('input[type="checkbox"]');
        var raw = lab.getAttribute('data-equipe-nome') || '';
        var match = !q || raw.indexOf(q) !== -1;
        var show = match || (cb && cb.checked);
        lab.classList.toggle('equipe-picker__opt--hidden', !show);
      });
    }

    function setBusy(busy) {
      form.classList.toggle('equipe-picker-form--saving', !!busy);
      filtro.disabled = !!busy;
      root.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
        cb.disabled = !!busy;
      });
    }

    function doSave() {
      if (!ajaxOn || saving) {
        if (ajaxOn) savePending = true;
        return;
      }

      var fd = new FormData();
      fd.append('acao', 'tecnico');
      fd.append('ajax', '1');
      root.querySelectorAll('input[type="checkbox"][name="tecnico_user_ids[]"]:checked').forEach(function (cb) {
        fd.append('tecnico_user_ids[]', cb.value);
      });

      saving = true;
      savePending = false;
      setBusy(true);

      fetch(global.location.pathname + global.location.search, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
      })
        .then(parseJsonResponse)
        .then(function (data) {
          if (!data || !data.ok) {
            var err = (data && data.err) || 'Não foi possível salvar a equipe.';
            alertMsg(err);
            return;
          }
          updateCount(data.count_label || '');
          global.document.dispatchEvent(
            new CustomEvent('chamado-equipe-saved', {
              detail: { snap: equipeSnapshot(root) },
            })
          );
          if (data.msg) toastOk(data.msg);
        })
        .catch(function (err) {
          alertMsg((err && err.message) || 'Erro de rede ao salvar a equipe.');
        })
        .finally(function () {
          saving = false;
          setBusy(false);
          if (savePending) {
            savePending = false;
            doSave();
          }
        });
    }

    function scheduleSave() {
      if (!ajaxOn) return;
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(doSave, 350);
    }

    filtro.addEventListener('input', applyFilter);
    root.addEventListener('change', function (e) {
      var t = e.target;
      if (!t || t.type !== 'checkbox') return;
      applyFilter();
      updateCount();
      scheduleSave();
    });

    applyFilter();
    updateCount();
  }

  global.AdminChamadoEquipe = { init: init };
})(window);
