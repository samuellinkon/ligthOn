/**
 * Itens do chamado (admin): add / edit / delete via AJAX sem reload.
 */
(function (global) {
  'use strict';

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function qtdFmt(n) {
    var f = parseFloat(String(n).replace(',', '.'));
    if (isNaN(f)) return '0';
    var t = String(f);
    var parts = t.split('.');
    if (parts[1]) {
      parts[1] = parts[1].replace(/0+$/, '');
      if (parts[1] === '') return parts[0];
      return parts[0] + '.' + parts[1];
    }
    return parts[0];
  }

  function alertErr(msg) {
    if (typeof global.appAlert === 'function') {
      global.appAlert(msg, 'Itens do chamado');
    } else {
      global.alert(msg);
    }
  }

  function closePickModal() {
    var pickModal = document.getElementById('chamado-mat-modal-pick');
    if (!pickModal) return;
    pickModal.hidden = true;
    pickModal.setAttribute('aria-hidden', 'true');
    if (!isDrawerOpen()) document.body.style.overflow = '';
  }

  function closeDrawer() {
    var drawer = document.getElementById('chamado-mat-drawer');
    if (!drawer) return;
    drawer.hidden = true;
    drawer.setAttribute('aria-hidden', 'true');
    if (!isPickOpen()) document.body.style.overflow = '';
  }

  function isPickOpen() {
    var pickModal = document.getElementById('chamado-mat-modal-pick');
    return pickModal && !pickModal.hidden;
  }

  function isDrawerOpen() {
    var drawer = document.getElementById('chamado-mat-drawer');
    return drawer && !drawer.hidden;
  }

  function clearPickSelection() {
    var pickInpItemId = document.getElementById('chamado-mat-pick-item-id');
    var pickPreview = document.getElementById('chamado-mat-pick-preview');
    var pickPreviewNome = document.getElementById('chamado-mat-pick-preview-nome');
    var pickPreviewMeta = document.getElementById('chamado-mat-pick-preview-meta');
    if (!pickInpItemId || !pickPreview || !pickPreviewNome || !pickPreviewMeta) return;
    pickInpItemId.value = '';
    pickPreview.hidden = true;
    pickPreviewNome.textContent = '';
    pickPreviewMeta.textContent = '';
  }

  function moneyFmt(n) {
    var f = parseFloat(String(n).replace(',', '.'));
    if (isNaN(f) || f <= 0) return '—';
    return 'R$ ' + f.toFixed(2).replace('.', ',');
  }

  function rowHtml(item, canEdit) {
    var lid = item.id;
    var nome = esc(item.nome || '');
    var cod = (item.codigo || '').trim();
    var codH = cod ? '<div class="td-mute" style="font-size:12px;">Cód. ' + esc(cod) + '</div>' : '';
    var desc = (item.descricao || '').trim();
    var tipAttr = desc ? ' title="' + esc(item.nome + ' — ' + desc) + '"' : '';
    var qtd = esc(qtdFmt(item.quantidade));
    var tipo = esc(item.tipo || '');
    var vu = moneyFmt(item.valor_unitario);
    var vt = moneyFmt(item.subtotal);
    var mov = item.movimento === 'devolvido' ? 'devolvido' : 'utilizado';
    var obsAttr = esc(item.observacao || '');
    var lblAttr = esc(item.nome || '');
    var acts = '';
    if (canEdit) {
      acts =
        '<td class="text-right td-actions">' +
        '<button type="button" class="btn btn-secondary btn-sm js-cham-mat-open-edit"' +
        ' data-linha-id="' +
        lid +
        '" data-qty="' +
        qtd +
        '" data-obs="' +
        obsAttr +
        '" data-item-label="' +
        lblAttr +
        '" data-movimento="' +
        mov +
        '">Editar</button>' +
        ' <button type="button" class="btn btn-danger btn-sm" data-ch-mat-del data-linha-id="' +
        lid +
        '">Excluir</button></td>';
    }
    return (
      '<tr>' +
      '<td class="chamado-mat-col-item"' +
      tipAttr +
      '><strong>' +
      nome +
      '</strong>' +
      codH +
      '</td>' +
      '<td class="td-mute">' +
      tipo +
      '</td>' +
      '<td class="text-right td-mute">' +
      vu +
      '</td>' +
      '<td class="text-right">' +
      vt +
      '</td>' +
      '<td class="text-right">' +
      qtd +
      '</td>' +
      acts +
      '</tr>'
    );
  }

  function init(config) {
    config = config || {};
    var canEdit = config.canEdit !== false;
    var card = document.querySelector('.chamado-materiais-card');
    if (!card || !canEdit) return;

    var formPick = document.getElementById('chamado-mat-form-pick');
    var formEdit = document.getElementById('chamado-mat-form-edit');
    var pickInpItemId = document.getElementById('chamado-mat-pick-item-id');

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
      fd.append('ajax', '1');
      return fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
      }).then(parseJsonResponse);
    }

    function updateStats(stats) {
      if (!stats) return;
      var u = card.querySelector('[data-ch-mat-count="utilizados"]');
      var d = card.querySelector('[data-ch-mat-count="recolhidos"]');
      var v = card.querySelector('[data-ch-mat-valor]');
      if (u) u.textContent = String(stats.utilizados != null ? stats.utilizados : 0);
      if (d) d.textContent = String(stats.recolhidos != null ? stats.recolhidos : 0);
      if (v && stats.valor_itens_fmt) v.textContent = stats.valor_itens_fmt;
    }

    function syncSection(mov, items) {
      var empty = card.querySelector('[data-ch-mat-empty="' + mov + '"]');
      var wrap = card.querySelector('[data-ch-mat-table-wrap="' + mov + '"]');
      var tbody = card.querySelector('[data-ch-mat-tbody="' + mov + '"]');
      var has = items && items.length > 0;
      if (empty) empty.hidden = has;
      if (wrap) wrap.hidden = !has;
      if (tbody) {
        tbody.innerHTML = (items || [])
          .map(function (it) {
            return rowHtml(it, canEdit);
          })
          .join('');
      }
    }

    function syncMateriais(data) {
      syncSection('utilizado', data.utilizados || []);
      syncSection('devolvido', data.devolvidos || []);
      updateStats(data.stats);
    }

    if (formPick) {
      formPick.addEventListener('submit', function (ev) {
        ev.preventDefault();
        if (!pickInpItemId || !pickInpItemId.value) {
          alertErr('Selecione um item do catálogo na lista.');
          return;
        }
        var submitBtn = document.getElementById('chamado-mat-pick-submit');
        if (submitBtn) submitBtn.disabled = true;
        var fd = new FormData(formPick);
        postForm(fd)
          .then(function (data) {
            if (!data || !data.ok) {
              alertErr((data && data.err) || 'Não foi possível adicionar.');
              return;
            }
            syncMateriais(data);
            formPick.reset();
            clearPickSelection();
            var pickQty = document.getElementById('chamado-mat-pick-qty');
            if (pickQty) pickQty.value = '1';
            closePickModal();
          })
          .catch(function () {
            alertErr('Erro de rede ao adicionar item.');
          })
          .finally(function () {
            if (submitBtn) submitBtn.disabled = false;
          });
      });
    }

    if (formEdit) {
      formEdit.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var submitBtn = formEdit.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;
        var fd = new FormData(formEdit);
        postForm(fd)
          .then(function (data) {
            if (!data || !data.ok) {
              alertErr((data && data.err) || 'Não foi possível atualizar.');
              return;
            }
            syncMateriais(data);
            closeDrawer();
          })
          .catch(function () {
            alertErr('Erro de rede ao atualizar item.');
          })
          .finally(function () {
            if (submitBtn) submitBtn.disabled = false;
          });
      });
    }

    var formSolDev = document.getElementById('ch-sol-dev-form');
    if (formSolDev && formSolDev.getAttribute('data-ch-sol-dev-ajax') === '1') {
      formSolDev.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var submitBtn = formSolDev.querySelector('[data-ch-sol-dev-submit]');
        var nomeInp = formSolDev.querySelector('[name="item_devolutivo_nome"]');
        if (nomeInp && !String(nomeInp.value || '').trim()) {
          alertErr('Informe o nome do item devolutivo.');
          return;
        }
        if (submitBtn) submitBtn.disabled = true;
        postForm(new FormData(formSolDev))
          .then(function (data) {
            if (!data || !data.ok) {
              alertErr((data && data.err) || 'Não foi possível criar o item devolutivo.');
              return;
            }
            syncMateriais(data);
            formSolDev.reset();
            var qtdInp = formSolDev.querySelector('[name="item_devolutivo_qtd"]');
            if (qtdInp) qtdInp.value = '1';
            var devModal = document.getElementById('ch-solicitar-devolutivo-modal');
            if (devModal && typeof devModal._chSolDevClose === 'function') {
              devModal._chSolDevClose();
            }
          })
          .catch(function (err) {
            alertErr((err && err.message) || 'Erro de rede ao criar o item devolutivo.');
          })
          .finally(function () {
            if (submitBtn) submitBtn.disabled = false;
          });
      });
    }

    card.addEventListener('click', function (e) {
      var delBtn = e.target.closest('[data-ch-mat-del]');
      if (delBtn) {
        e.preventDefault();
        var lid = delBtn.getAttribute('data-linha-id');
        if (!lid) return;
        var doDel = function () {
          var fd = new FormData();
          fd.append('acao', 'chamado_item_del');
          fd.append('linha_id', lid);
          postForm(fd)
            .then(function (data) {
              if (!data || !data.ok) {
                alertErr((data && data.err) || 'Não foi possível remover.');
                return;
              }
              syncMateriais(data);
            })
            .catch(function () {
              alertErr('Erro de rede ao remover.');
            });
        };
        if (typeof global.appConfirm === 'function') {
          global
            .appConfirm({
              message: 'Remover esta linha?',
              title: 'Itens do chamado',
              danger: true,
            })
            .then(function (ok) {
              if (ok) doDel();
            });
        } else if (global.confirm('Remover esta linha?')) {
          doDel();
        }
      }
    });

    global.AdminChamadoMateriaisSync = syncMateriais;
  }

  global.AdminChamadoMateriais = { init: init };
})(window);
