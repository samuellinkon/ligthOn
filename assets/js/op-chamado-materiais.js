/**
 * Itens do chamado (operador): autocomplete rico + lista inline com AJAX.
 */
(function (global) {
  'use strict';

  var LOW_STOCK = 10;

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function qtdFmt(n) {
    var s = String(n);
    var f = parseFloat(s.replace(',', '.'));
    if (isNaN(f)) return '0';
    var t = rtrim(rtrim(f.toFixed(4), '0'), '.');
    return t === '' ? '0' : t;
  }

  function rtrim(str, ch) {
    while (str.length && str.charAt(str.length - 1) === ch) str = str.slice(0, -1);
    return str;
  }

  function metaLine(row) {
    var parts = [];
    if (row.categoria) parts.push(row.categoria);
    if (row.unidade) parts.push(row.unidade);
    if (row.codigo) parts.push('Cód ' + row.codigo);
    return parts.join(' · ');
  }

  function stockBadge(row) {
    if (row.estoque === null || row.estoque === undefined || row.estoque === '') {
      return '';
    }
    var n = Number(row.estoque);
    if (isNaN(n)) return '';
    var cls = 'op-item-combo__stock';
    if (n < LOW_STOCK) cls += ' op-item-combo__stock--low';
    return '<span class="' + cls + '">' + esc(String(n)) + ' un</span>';
  }

  function renderOption(row, active) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'op-item-combo__opt' + (active ? ' is-active' : '');
    btn.setAttribute('role', 'option');
    btn.setAttribute('data-id', String(row.id));
    btn.innerHTML =
      '<span class="op-item-combo__opt-main">' +
      '<span class="op-item-combo__opt-name">' + esc(row.nome || row.label) + '</span>' +
      stockBadge(row) +
      '</span>' +
      '<span class="op-item-combo__opt-meta">' + esc(metaLine(row)) + '</span>';
    return btn;
  }

  function stackRowHtml(item, readonly) {
    var lid = item.id;
    var nome = esc(item.nome || '');
    var qtd = esc(qtdFmt(item.quantidade));
    var obs = (item.observacao || '').trim();
    var obsH = obs ? '<span class="op-mat-row__obs">' + esc(obs) + '</span>' : '';
    var acts;
    if (readonly) {
      acts =
        '<div class="op-mat-row__actions"><span class="op-mat-row__qtd op-mat-row__qtd--readonly">× ' +
        qtd +
        '</span></div>';
    } else {
      acts =
        '<div class="op-mat-row__actions">' +
        '<button type="button" class="op-mat-row__qtd" data-op-mat-qtd-edit data-linha-id="' +
        lid +
        '" data-qtd="' +
        esc(qtdFmt(item.quantidade)) +
        '" title="Clique para editar a quantidade">× ' +
        qtd +
        '</button>' +
        '<button type="button" class="op-mat-row__del" data-op-mat-del data-linha-id="' +
        lid +
        '" aria-label="Remover item">' +
        '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' +
        '<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/></svg>' +
        '</button></div>';
    }
    return (
      '<li class="op-mat-row" data-linha-id="' +
      lid +
      '">' +
      '<span class="op-mat-row__icon" aria-hidden="true">' +
      '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
      '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>' +
      '</span>' +
      '<div class="op-mat-row__body"><strong class="op-mat-row__name">' +
      nome +
      '</strong>' +
      obsH +
      '</div>' +
      acts +
      '</li>'
    );
  }

  function init(config) {
    var catalog = config.catalog || [];
    var readonly = !!config.readonly;
    var card = document.querySelector('.chamado-materiais-card');
    if (!card) return;

    function norm(s) {
      return (s || '').trim().toLowerCase();
    }

    function filterCatalog(q, onlyProduto) {
      var n = norm(q);
      return catalog.filter(function (row) {
        if (onlyProduto && row.tipo !== 'produto') return false;
        if (!n) return true;
        return (row.hay || '').indexOf(n) !== -1;
      });
    }

    function updateStats(stats) {
      if (!stats) return;
      var u = card.querySelector('[data-op-mat-count="utilizados"]');
      var d = card.querySelector('[data-op-mat-count="recolhidos"]');
      if (u) u.textContent = String(stats.utilizados != null ? stats.utilizados : 0);
      if (d) d.textContent = String(stats.recolhidos != null ? stats.recolhidos : 0);
    }

    function syncStacks(data) {
      ['utilizado', 'devolvido'].forEach(function (mov) {
        var key = mov === 'devolvido' ? 'devolvidos' : 'utilizados';
        var items = data[key] || [];
        var stack = card.querySelector('[data-op-mat-stack="' + mov + '"]');
        var empty = card.querySelector('[data-op-mat-empty="' + mov + '"]');
        if (stack) {
          stack.innerHTML = items.map(function (it) {
            return stackRowHtml(it, readonly);
          }).join('');
        }
        if (empty) empty.hidden = items.length > 0;
      });
      updateStats(data.stats);
    }

    function postForm(fd) {
      fd.append('ajax', '1');
      return fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      }).then(function (r) {
        return r.json();
      });
    }

    function alertErr(msg) {
      if (typeof global.appAlert === 'function') {
        global.appAlert(msg, 'Itens do chamado');
      } else {
        global.alert(msg);
      }
    }

    function initPicker(form) {
      var itemSearch = form.querySelector('[data-op-item-search]');
      var itemId = form.querySelector('[data-op-item-id]');
      var combo = form.querySelector('[data-op-item-combo]');
      var dd = form.querySelector('[data-op-item-dd]');
      var movimento = form.getAttribute('data-op-mat-movimento') || 'utilizado';
      var onlyProduto = form.getAttribute('data-catalog-filter') === 'produto';
      var selectedNome = '';
      var activeIndex = 0;
      var visibleRows = [];

      if (!itemSearch || !itemId || !combo || !dd || !catalog.length) return;

      function renderList(rows, activeIdx) {
        dd.innerHTML = '';
        if (!rows.length) {
          var empty = document.createElement('div');
          empty.className = 'op-item-combo__empty';
          empty.textContent = combo.getAttribute('data-filter-empty') || 'Nada encontrado.';
          dd.appendChild(empty);
          return;
        }
        rows.forEach(function (row, idx) {
          var btn = renderOption(row, idx === activeIdx);
          btn.addEventListener('mousedown', function (e) {
            e.preventDefault();
            pick(row);
          });
          dd.appendChild(btn);
        });
      }

      function setOpen(open) {
        combo.classList.toggle('is-open', open);
        itemSearch.setAttribute('aria-expanded', open ? 'true' : 'false');
        dd.hidden = !open;
      }

      function openDropdown() {
        if (itemSearch.disabled) return;
        visibleRows = filterCatalog(itemSearch.value, onlyProduto);
        activeIndex = 0;
        renderList(visibleRows, activeIndex);
        setOpen(true);
      }

      function pick(row) {
        itemId.value = String(row.id);
        selectedNome = row.nome || row.label || '';
        itemSearch.value = selectedNome;
        setOpen(false);
      }

      itemSearch.addEventListener('focus', openDropdown);
      itemSearch.addEventListener('click', openDropdown);
      itemSearch.addEventListener('input', function () {
        if (itemSearch.value.trim() !== selectedNome) itemId.value = '';
        openDropdown();
      });
      itemSearch.addEventListener('keydown', function (e) {
        if (!combo.classList.contains('is-open')) {
          if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            openDropdown();
          }
          return;
        }
        if (e.key === 'Escape') {
          e.preventDefault();
          setOpen(false);
          return;
        }
        if (e.key === 'ArrowDown' && visibleRows.length) {
          e.preventDefault();
          activeIndex = (activeIndex + 1) % visibleRows.length;
          renderList(visibleRows, activeIndex);
          return;
        }
        if (e.key === 'ArrowUp' && visibleRows.length) {
          e.preventDefault();
          activeIndex = (activeIndex - 1 + visibleRows.length) % visibleRows.length;
          renderList(visibleRows, activeIndex);
          return;
        }
        if (e.key === 'Enter' && visibleRows.length) {
          e.preventDefault();
          pick(visibleRows[activeIndex]);
        }
      });
      itemSearch.addEventListener('blur', function () {
        window.setTimeout(function () {
          if (document.activeElement && combo.contains(document.activeElement)) return;
          setOpen(false);
        }, 180);
      });
      document.addEventListener('click', function (e) {
        if (!combo.contains(e.target)) setOpen(false);
      });

      if (!readonly) {
        form.addEventListener('submit', function (ev) {
          ev.preventDefault();
          if (itemSearch.value.trim() && !itemId.value) {
            openDropdown();
            itemSearch.focus();
            alertErr('Selecione um item da lista.');
            return;
          }
          if (!itemId.value) {
            alertErr('Selecione um item do catálogo.');
            return;
          }
          var submitBtn = form.querySelector('.op-mat-submit');
          if (submitBtn) submitBtn.disabled = true;
          var fd = new FormData(form);
          postForm(fd)
            .then(function (data) {
              if (!data || !data.ok) {
                alertErr((data && data.err) || 'Não foi possível adicionar.');
                return;
              }
              syncStacks(data);
              form.reset();
              itemId.value = '';
              selectedNome = '';
              var q = form.querySelector('.op-mat-qtd');
              if (q) q.value = '1';
            })
            .catch(function () {
              alertErr('Erro de rede ao adicionar item.');
            })
            .finally(function () {
              if (submitBtn) submitBtn.disabled = false;
            });
        });
      }
    }

    card.querySelectorAll('form[data-op-item-form]').forEach(initPicker);

    if (!readonly) {
      card.addEventListener('click', function (e) {
        var delBtn = e.target.closest('[data-op-mat-del]');
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
                syncStacks(data);
              })
              .catch(function () {
                alertErr('Erro de rede ao remover.');
              });
          };
          if (typeof global.appConfirm === 'function') {
            global
              .appConfirm({
                message: 'Remover este item?',
                title: 'Itens do chamado',
                danger: true,
              })
              .then(function (ok) {
                if (ok) doDel();
              });
          } else if (global.confirm('Remover este item?')) {
            doDel();
          }
          return;
        }
        var qBtn = e.target.closest('[data-op-mat-qtd-edit]');
        if (qBtn) {
          e.preventDefault();
          var lidQ = qBtn.getAttribute('data-linha-id');
          var cur = qBtn.getAttribute('data-qtd') || '1';
          var nv = global.prompt('Nova quantidade:', cur);
          if (nv === null) return;
          nv = nv.trim().replace(',', '.');
          if (!nv || isNaN(parseFloat(nv)) || parseFloat(nv) <= 0) {
            alertErr('Quantidade inválida.');
            return;
          }
          var fdQ = new FormData();
          fdQ.append('acao', 'chamado_item_qtd');
          fdQ.append('linha_id', lidQ);
          fdQ.append('quantidade', nv);
          postForm(fdQ)
            .then(function (data) {
              if (!data || !data.ok) {
                alertErr((data && data.err) || 'Não foi possível atualizar.');
                return;
              }
              syncStacks(data);
            })
            .catch(function () {
              alertErr('Erro de rede ao atualizar.');
            });
        }
      });
    }
  }

  global.OpChamadoMateriais = { init: init };
})(window);
