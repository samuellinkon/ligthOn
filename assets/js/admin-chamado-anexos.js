/**
 * Anexos do chamado (admin): upload imediato via AJAX ao selecionar ficheiros.
 */
(function (global) {
  'use strict';

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function alertMsg(msg, title) {
    if (typeof global.appAlert === 'function') {
      global.appAlert(msg, title || 'Anexos');
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

  function rowHtml(a) {
    var nome = esc(a.nome_original || '');
    var urlImg = a.url_inline || a.url || '';
    var thumb = '';
    if (a.eh_imagem) {
      thumb =
        '<button type="button" class="file-item__thumb js-chamado-anexo-preview"' +
        ' data-preview-src="' +
        esc(urlImg) +
        '"' +
        ' data-preview-title="' +
        nome +
        '"' +
        ' aria-label="Ver imagem em tamanho maior">' +
        '<img src="' +
        esc(urlImg) +
        '" alt="" loading="lazy" width="72" height="72">' +
        '</button>';
    }
    var clienteTag = a.enviado_tipo === 'cliente' ? ' (cliente)' : '';
    return (
      '<div class="file-item' +
      (a.eh_imagem ? ' file-item--thumb' : '') +
      '" data-anexo-id="' +
      esc(String(a.id)) +
      '">' +
      thumb +
      '<span class="file-item__meta">' +
      (a.icone_html || '') +
      '<strong>' +
      nome +
      '</strong><br>' +
      '<small class="muted">' +
      esc(a.tamanho_fmt || '') +
      ' · ' +
      esc(a.enviado_por || '—') +
      clienteTag +
      ' · ' +
      esc(a.enviado_em || '') +
      '</small></span>' +
      '<div class="file-item-actions">' +
      '<a class="btn btn-primary btn-sm" href="' +
      esc(a.url || '#') +
      '">Baixar</a>' +
      '<button type="button" class="btn btn-danger btn-sm" data-chamado-anexo-delete' +
      ' data-anexo-id="' +
      esc(String(a.id)) +
      '"' +
      ' data-anexo-nome="' +
      nome +
      '">Excluir</button>' +
      '</div></div>'
    );
  }

  function renderList(anexos) {
    var list = document.getElementById('chamado-anexos-list');
    var empty = document.getElementById('chamado-anexos-empty');
    var countEl = document.getElementById('chamado-anexos-count');
    if (!list) return;

    if (!anexos || !anexos.length) {
      if (empty) {
        empty.hidden = false;
      } else {
        list.innerHTML =
          '<div class="empty-state" id="chamado-anexos-empty" style="padding:12px 0 8px;">' +
          '<div class="empty-icon">📎</div>' +
          '<p style="font-size:14px;margin-bottom:4px;">Nenhum anexo neste chamado ainda.</p>' +
          '<small class="muted">Use o envio acima ou «+ Anexo» na conversa.</small></div>';
      }
    } else {
      if (empty) empty.hidden = true;
      list.innerHTML = anexos.map(rowHtml).join('');
    }
    if (countEl) {
      var n = anexos ? anexos.length : 0;
      countEl.textContent = n + (n === 1 ? ' arquivo' : ' arquivos');
    }
  }

  function setUploadBusy(busy) {
    var wrap = document.querySelector('[data-chamado-anexos-ajax]');
    var input = document.getElementById('chamado_anexos_painel_input');
    var box = wrap ? wrap.querySelector('.file-upload') : null;
    if (input) input.disabled = !!busy;
    if (box) {
      box.style.opacity = busy ? '0.65' : '';
      box.style.pointerEvents = busy ? 'none' : '';
    }
  }

  function clearPendingPreview() {
    var input = document.getElementById('chamado_anexos_painel_input');
    var list = document.querySelector('[data-chamado-anexos-ajax] .file-list');
    if (input) input.value = '';
    if (list) list.innerHTML = '';
  }

  function uploadFiles(files) {
    if (!files || !files.length) return Promise.resolve();

    var fd = new FormData();
    fd.append('acao', 'anexos_painel');
    fd.append('ajax', '1');
    for (var i = 0; i < files.length; i++) {
      fd.append('anexos[]', files[i]);
    }

    setUploadBusy(true);
    return fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
    })
      .then(parseJsonResponse)
      .then(function (data) {
        if (!data || !data.ok) {
          var err = (data && data.err) || (data && data.msg) || 'Não foi possível enviar os anexos.';
          alertMsg(err);
          return data;
        }
        if (data.anexos) {
          renderList(data.anexos);
        }
        clearPendingPreview();
        var msg = data.msg || (data.saved ? data.saved + ' anexo(s) enviado(s).' : 'Anexo(s) enviado(s).');
        toastOk(msg);
        return data;
      })
      .catch(function (err) {
        alertMsg((err && err.message) || 'Erro de rede ao enviar anexos.');
      })
      .finally(function () {
        setUploadBusy(false);
      });
  }

  function confirmDelete(msg, title) {
    if (typeof global.appConfirm === 'function') {
      return global.appConfirm({ message: msg, title: title || 'Anexos', danger: true });
    }
    return Promise.resolve(false);
  }

  function deleteAnexo(anexoId) {
    var fd = new FormData();
    fd.append('acao', 'excluir_anexo');
    fd.append('ajax', '1');
    fd.append('anexo_id', String(anexoId));

    return fetch(global.location.pathname + global.location.search, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
    })
      .then(parseJsonResponse)
      .then(function (data) {
        if (!data || !data.ok) {
          alertMsg((data && data.err) || 'Não foi possível excluir o anexo.');
          return;
        }
        if (data.anexos) renderList(data.anexos);
        toastOk(data.msg || 'Anexo removido.');
      })
      .catch(function (err) {
        alertMsg((err && err.message) || 'Erro de rede ao excluir anexo.');
      });
  }

  function initDelete() {
    var list = document.getElementById('chamado-anexos-list');
    if (!list) return;
    list.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-chamado-anexo-delete]');
      if (!btn) return;
      e.preventDefault();
      var id = parseInt(btn.getAttribute('data-anexo-id') || '0', 10);
      if (!id) return;
      var nome = btn.getAttribute('data-anexo-nome') || 'este anexo';
      confirmDelete('Excluir o anexo "' + nome + '"?', 'Anexos').then(function (ok) {
        if (ok) deleteAnexo(id);
      });
    });
  }

  function init() {
    var input = document.getElementById('chamado_anexos_painel_input');
    var uploadWrap = document.querySelector('[data-chamado-anexos-ajax]');
    initDelete();
    if (!input || !uploadWrap) return;

    input.addEventListener('change', function () {
      var files = input.files;
      if (!files || !files.length) return;
      uploadFiles(files);
    });

    var box = uploadWrap.querySelector('.file-upload');
    if (box) {
      box.addEventListener('click', function () {
        if (!input.disabled) input.click();
      });
      box.addEventListener('dragover', function (e) {
        e.preventDefault();
        box.style.borderColor = 'var(--primary)';
      });
      box.addEventListener('dragleave', function () {
        box.style.borderColor = '';
      });
      box.addEventListener('drop', function (e) {
        e.preventDefault();
        box.style.borderColor = '';
        var files = e.dataTransfer && e.dataTransfer.files;
        if (!files || !files.length) return;
        window.setTimeout(function () {
          uploadFiles(files);
        }, 0);
      });
    }
  }

  global.AdminChamadoAnexos = { init: init, renderList: renderList };
})(window);
