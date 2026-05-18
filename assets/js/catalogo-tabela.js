/**
 * Catálogo — ordenação por coluna na tabela.
 */
(function () {
  'use strict';

  var table = document.getElementById('catalogo-itens-table');
  if (!table) return;

  var tbody = table.querySelector('tbody');
  if (!tbody) return;

  var sortBtns = table.querySelectorAll('.catalogo-excel-sort');
  var sortKey = null;
  var sortDir = 'asc';

  function dataRows() {
    return Array.prototype.slice.call(tbody.querySelectorAll('tr[data-catalogo-row]'));
  }

  function norm(s) {
    return String(s || '')
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '');
  }

  function sortValue(row, key) {
    var raw = row.getAttribute('data-sort-' + key);
    if (raw === null || raw === undefined) return '';
    if (key === 'valor' || key === 'estoque') {
      var n = parseFloat(String(raw).replace(',', '.'));
      return isNaN(n) ? 0 : n;
    }
    if (key === 'status') {
      return parseInt(raw, 10) || 0;
    }
    return norm(raw);
  }

  function applySort() {
    if (!sortKey) return;
    var rows = dataRows();
    rows.sort(function (a, b) {
      var va = sortValue(a, sortKey);
      var vb = sortValue(b, sortKey);
      var cmp = 0;
      if (typeof va === 'number' && typeof vb === 'number') {
        cmp = va - vb;
      } else {
        cmp = String(va).localeCompare(String(vb), 'pt-BR', { numeric: true, sensitivity: 'base' });
      }
      return sortDir === 'desc' ? -cmp : cmp;
    });
    rows.forEach(function (row) {
      tbody.appendChild(row);
    });
  }

  function updateSortUi() {
    sortBtns.forEach(function (btn) {
      var key = btn.getAttribute('data-sort-key');
      var icon = btn.querySelector('.catalogo-excel-sort__icon');
      btn.classList.remove('is-sorted-asc', 'is-sorted-desc');
      if (!icon) return;
      if (key === sortKey) {
        btn.classList.add(sortDir === 'desc' ? 'is-sorted-desc' : 'is-sorted-asc');
        icon.textContent = sortDir === 'desc' ? '▼' : '▲';
      } else {
        icon.textContent = '↕';
      }
    });
  }

  sortBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var key = btn.getAttribute('data-sort-key');
      if (!key) return;
      if (sortKey === key) {
        sortDir = sortDir === 'asc' ? 'desc' : 'asc';
      } else {
        sortKey = key;
        sortDir = 'asc';
      }
      updateSortUi();
      applySort();
    });
  });
})();
