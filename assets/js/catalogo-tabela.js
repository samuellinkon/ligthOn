/**
 * Catálogo — filtros e ordenação por coluna (estilo planilha).
 */
(function () {
  'use strict';

  var table = document.getElementById('catalogo-itens-table');
  if (!table) return;

  var tbody = table.querySelector('tbody');
  if (!tbody) return;

  var countEl = document.getElementById('catalogo-itens-count');
  var sortBtns = table.querySelectorAll('.catalogo-excel-sort');
  var filters = table.querySelectorAll('.catalogo-excel-filter');
  var btnClear = document.getElementById('catalogo-excel-clear-filters');

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

  function rowPasses(row) {
    var ok = true;
    filters.forEach(function (el) {
      if (!ok) return;
      var key = el.getAttribute('data-filter-key');
      if (!key) return;
      var val = norm(el.value);
      if (val === '') return;
      var cell = norm(row.getAttribute('data-filter-' + key) || '');
      if (key === 'tipo' || key === 'status') {
        if (cell !== val) ok = false;
      } else if (cell.indexOf(val) === -1) {
        ok = false;
      }
    });
    return ok;
  }

  function applyFilters() {
    var rows = dataRows();
    var visible = 0;
    rows.forEach(function (row) {
      var show = rowPasses(row);
      row.style.display = show ? '' : 'none';
      if (show) visible += 1;
    });
    if (countEl) {
      countEl.textContent = String(visible);
    }
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
    var rows = dataRows().filter(function (r) {
      return r.style.display !== 'none';
    });
    var hidden = dataRows().filter(function (r) {
      return r.style.display === 'none';
    });
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
    rows.concat(hidden).forEach(function (row) {
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

  filters.forEach(function (el) {
    el.addEventListener('input', applyFilters);
    el.addEventListener('change', applyFilters);
  });

  if (btnClear) {
    btnClear.addEventListener('click', function () {
      filters.forEach(function (el) {
        el.value = '';
      });
      applyFilters();
    });
  }

  applyFilters();
})();
