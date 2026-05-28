/**
 * Custos adicionais na medição — modal, API e aprovação (cliente).
 */
(function () {
  'use strict';

  var secao = document.getElementById('medicao-custos-secao');
  if (!secao) return;

  var apiUrl = secao.getAttribute('data-api-url') || 'medicao_custos_api.php';
  var clienteId = parseInt(secao.getAttribute('data-cliente-id') || '0', 10);
  var refYm = secao.getAttribute('data-ref-ym') || '';
  var podeCriar = secao.getAttribute('data-pode-criar') === '1';
  var podeEditar = secao.getAttribute('data-pode-editar') === '1';
  var podeAprovar = secao.getAttribute('data-pode-aprovar') === '1';

  function parseNum(s) {
    if (s === null || s === undefined) return 0;
    var t = String(s).trim().replace(/\./g, '').replace(',', '.');
    if (t === '' || t === '-') return 0;
    var n = parseFloat(t);
    return isNaN(n) ? 0 : n;
  }

  function fmtBrl(n) {
    return 'R$ ' + n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function alertErr(msg, title) {
    if (typeof window.appAlert === 'function') {
      return window.appAlert(msg, title || 'Erro');
    }
    return Promise.resolve();
  }

  function confirmAction(message, title, danger) {
    if (typeof window.appConfirm === 'function') {
      return window.appConfirm({ message: message, title: title || 'Confirmar', danger: !!danger });
    }
    return Promise.resolve(false);
  }

  function promptAction(message, defaultValue, title) {
    if (typeof window.appPrompt === 'function') {
      return window.appPrompt({ message: message, defaultValue: defaultValue || '', title: title || 'Informe' });
    }
    return Promise.resolve(null);
  }

  function postForm(data) {
    var body = new URLSearchParams();
    Object.keys(data).forEach(function (k) {
      if (data[k] !== undefined && data[k] !== null) {
        body.set(k, String(data[k]));
      }
    });
    return fetch(apiUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString(),
      credentials: 'same-origin',
    })
      .then(function (r) {
        return r
          .json()
          .catch(function () {
            return { ok: false, err: 'Resposta inválida do servidor.' };
          })
          .then(function (res) {
            if (!r.ok && res && !res.err) {
              res.err = 'Erro ' + r.status;
              res.ok = false;
            }
            return res;
          });
      })
      .catch(function () {
        return { ok: false, err: 'Erro de rede.' };
      });
  }

  function postAcao(action, extra) {
    var payload = { action: action, ref_ym: refYm };
    if (clienteId > 0) {
      payload.cliente_id = clienteId;
    }
    if (extra) {
      Object.keys(extra).forEach(function (k) {
        payload[k] = extra[k];
      });
    }
    return postForm(payload);
  }

  function getJson(url) {
    return fetch(url, { credentials: 'same-origin' }).then(function (r) {
      return r.json();
    });
  }

  function reloadSoon() {
    window.location.reload();
  }

  function setModalTitle(isEdit) {
    var titleEl = document.getElementById('medicao-custo-modal-title');
    var descEl = document.getElementById('medicao-custo-modal-desc');
    if (titleEl) {
      titleEl.textContent = isEdit ? 'Editar custo adicional' : 'Adicionar custo adicional';
    }
    if (descEl) {
      descEl.textContent = isEdit
        ? 'Atualize quantidade ou observação. O item deve permanecer vinculado ao catálogo; ao salvar, volta para pendente de aprovação.'
        : 'Selecione um item do catálogo (produto ou serviço) para este custo extra.';
    }
  }

  /* —— Aprovar / rejeitar (cliente ou admin) —— */
  if (podeAprovar) {
    secao.querySelectorAll('.js-medicao-custo-aprovar').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = parseInt(btn.getAttribute('data-id') || '0', 10);
        if (!id) return;
        confirmAction('Aprovar este custo para compor o BM?', 'Aprovar custo').then(function (ok) {
          if (!ok) return;
          postAcao('aprovar', { id: id }).then(function (res) {
            if (res && res.ok) reloadSoon();
            else alertErr((res && res.err) || 'Falha ao aprovar.');
          });
        });
      });
    });

    secao.querySelectorAll('.js-medicao-custo-rejeitar').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = parseInt(btn.getAttribute('data-id') || '0', 10);
        if (!id) return;
        promptAction('Motivo da rejeição:', '', 'Rejeitar custo').then(function (motivo) {
          if (motivo === null) return;
          motivo = String(motivo).trim();
          if (!motivo) {
            alertErr('Informe o motivo da rejeição.');
            return;
          }
          postAcao('rejeitar', { id: id, motivo: motivo }).then(function (res) {
            if (res && res.ok) reloadSoon();
            else alertErr((res && res.err) || 'Falha ao rejeitar.');
          });
        });
      });
    });
  }

  if (!podeEditar && !podeCriar) return;

  var modal = document.getElementById('medicao-custo-modal');
  var form = document.getElementById('medicao-custo-form');
  if (!modal || !form) return;

  var idInput = document.getElementById('medicao-custo-id');
  var buscaInput = document.getElementById('medicao-custo-busca');
  var catResults = document.getElementById('medicao-custo-catalogo-results');
  var itemIdInput = document.getElementById('medicao-custo-item-id');
  var codInput = document.getElementById('medicao-custo-codigo');
  var descInput = document.getElementById('medicao-custo-descricao');
  var unInput = document.getElementById('medicao-custo-unidade');
  var qtdInput = document.getElementById('medicao-custo-qtd');
  var vunitInput = document.getElementById('medicao-custo-vunit');
  var totalPreview = document.getElementById('medicao-custo-total-preview');
  var errEl = document.getElementById('medicao-custo-form-err');
  var itemSelEl = document.getElementById('medicao-custo-item-selecionado');
  var buscaTimer = null;

  function setErr(msg) {
    if (!errEl) return;
    if (msg) {
      errEl.textContent = msg;
      errEl.hidden = false;
    } else {
      errEl.textContent = '';
      errEl.hidden = true;
    }
  }

  function updateTotalPreview() {
    var t = parseNum(qtdInput.value) * parseNum(vunitInput.value);
    if (totalPreview) totalPreview.textContent = fmtBrl(t);
  }

  function clearItemFields() {
    if (itemIdInput) itemIdInput.value = '';
    if (codInput) codInput.value = '';
    if (descInput) descInput.value = '';
    if (unInput) unInput.value = 'UN';
    if (vunitInput) vunitInput.value = '0';
    if (buscaInput) buscaInput.value = '';
    if (catResults) catResults.innerHTML = '';
    if (itemSelEl) {
      itemSelEl.textContent = '';
      itemSelEl.hidden = true;
    }
    updateTotalPreview();
  }

  function applyItemFromCatalog(it) {
    if (itemIdInput) itemIdInput.value = String(it.id || '');
    if (codInput) codInput.value = it.codigo || '';
    if (descInput) descInput.value = it.nome || '';
    if (unInput) unInput.value = it.unidade || 'UN';
    if (vunitInput) vunitInput.value = String(it.valor_unitario || 0).replace('.', ',');
    if (buscaInput) buscaInput.value = '';
    if (catResults) catResults.innerHTML = '';
    if (itemSelEl) {
      itemSelEl.textContent = 'Selecionado: ' + catalogItemLabel(it);
      itemSelEl.hidden = false;
    }
    updateTotalPreview();
  }

  [qtdInput, vunitInput].forEach(function (el) {
    if (el) el.addEventListener('input', updateTotalPreview);
  });

  function openModal(editData) {
    setErr('');
    form.reset();
    if (unInput) unInput.value = 'UN';
    if (qtdInput) qtdInput.value = '1';
    if (vunitInput) vunitInput.value = '0';
    clearItemFields();

    var isEdit = editData && editData.id;
    setModalTitle(!!isEdit);
    if (idInput) idInput.value = isEdit ? String(editData.id) : '';

    if (isEdit) {
      if (qtdInput) qtdInput.value = String(editData.quantidade || 1).replace('.', ',');
      var obs = document.getElementById('medicao-custo-obs');
      if (obs) obs.value = editData.observacao || '';
      if (editData.item_id) {
        if (itemIdInput) itemIdInput.value = String(editData.item_id);
        if (descInput) descInput.value = editData.descricao || '';
        if (codInput) codInput.value = editData.item_codigo || '';
        if (unInput) unInput.value = editData.unidade || 'UN';
        if (vunitInput) vunitInput.value = String(editData.valor_unitario || 0).replace('.', ',');
        if (itemSelEl && editData.descricao) {
          var cod = editData.item_codigo || '';
          itemSelEl.textContent = 'Selecionado: ' + (cod ? cod + ' · ' : '') + editData.descricao;
          itemSelEl.hidden = false;
        }
      } else {
        setErr('Selecione novamente um item do catálogo para salvar.');
      }
    }

    updateTotalPreview();
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    if (buscaInput) buscaInput.focus();
  }

  function closeModal() {
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
  }

  secao.querySelectorAll('.medicao-custo-btn-add').forEach(function (btn) {
    btn.addEventListener('click', function () {
      openModal(null);
    });
  });

  modal.querySelectorAll('[data-medicao-custo-close]').forEach(function (el) {
    el.addEventListener('click', closeModal);
  });

  secao.querySelectorAll('.js-medicao-custo-editar').forEach(function (btn) {
    btn.addEventListener('click', function () {
      try {
        var data = JSON.parse(btn.getAttribute('data-custo') || '{}');
        openModal(data);
      } catch (e) {
        alertErr('Dados inválidos.');
      }
    });
  });

  secao.querySelectorAll('.js-medicao-custo-excluir').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = parseInt(btn.getAttribute('data-id') || '0', 10);
      if (!id) return;
      var go = function () {
        postForm({ action: 'excluir', id: id, cliente_id: clienteId, ref_ym: refYm }).then(function (res) {
          if (res && res.ok) reloadSoon();
          else alertErr((res && res.err) || 'Falha ao excluir.');
        });
      };
      confirmAction('Excluir este custo?', 'Excluir custo', true).then(function (ok) {
        if (ok) go();
      });
    });
  });

  function catalogItemLabel(it) {
    var parts = [];
    if (it.codigo) parts.push(it.codigo);
    if (it.nome) parts.push(it.nome);
    if (it.tipo_label) parts.push(it.tipo_label);
    return parts.join(' · ') || 'Item';
  }

  function buscarCatalogo() {
    if (!buscaInput || !catResults) return;
    var q = buscaInput.value.trim();
    var url = apiUrl + '?action=buscar_itens&q=' + encodeURIComponent(q) + '&cliente_id=' + clienteId;
    getJson(url).then(function (res) {
      catResults.innerHTML = '';
      if (!res || !res.ok || !res.itens || !res.itens.length) {
        catResults.innerHTML = '<div class="medicao-custo-catalogo-empty muted">Nenhum item encontrado.</div>';
        return;
      }
      res.itens.forEach(function (it) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'chamado-mat-results__opt';
        btn.setAttribute('role', 'option');
        btn.textContent = catalogItemLabel(it);
        btn.addEventListener('click', function () {
          setErr('');
          applyItemFromCatalog(it);
        });
        catResults.appendChild(btn);
      });
    });
  }

  if (buscaInput) {
    buscaInput.addEventListener('input', function () {
      clearTimeout(buscaTimer);
      buscaTimer = setTimeout(buscarCatalogo, 280);
    });
    buscaInput.addEventListener('focus', function () {
      if (!catResults.innerHTML) {
        buscarCatalogo();
      }
    });
  }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    setErr('');
    var id = parseInt(idInput.value || '0', 10);
    var itemId = itemIdInput ? parseInt(itemIdInput.value || '0', 10) : 0;
    if (!itemId) {
      setErr('Selecione um item do catálogo.');
      if (buscaInput) buscaInput.focus();
      return;
    }
    var payload = {
      action: id > 0 ? 'atualizar' : 'criar',
      id: id > 0 ? id : undefined,
      cliente_id: clienteId,
      ref_ym: refYm,
      item_id: itemId,
      descricao: descInput ? descInput.value : '',
      item_codigo: codInput ? codInput.value : '',
      unidade: unInput ? unInput.value : 'UN',
      quantidade: qtdInput ? qtdInput.value : '1',
      valor_unitario: vunitInput ? vunitInput.value : '0',
      observacao: document.getElementById('medicao-custo-obs')?.value || '',
    };
    var submitBtn = document.getElementById('medicao-custo-submit');
    if (submitBtn) submitBtn.disabled = true;
    postForm(payload)
      .then(function (res) {
        if (res && res.ok) reloadSoon();
        else setErr((res && res.err) || 'Falha ao salvar.');
      })
      .catch(function () {
        setErr('Erro de rede.');
      })
      .finally(function () {
        if (submitBtn) submitBtn.disabled = false;
      });
  });
})();
