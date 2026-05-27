/**
 * Ordenação por coluna em tabelas com data-crm-sortable.
 */
(function () {
  'use strict';

  function norm(s) {
    return String(s || '')
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '');
  }

  function dataRows(tbody) {
    return Array.prototype.slice.call(
      tbody.querySelectorAll('tr[data-sort-row], tr[data-catalogo-row]')
    );
  }

  function sortValue(row, key, type) {
    var raw = row.getAttribute('data-sort-' + key);
    if (raw === null || raw === undefined) {
      return '';
    }
    if (type === 'number') {
      var n = parseFloat(String(raw).replace(',', '.'));
      return isNaN(n) ? 0 : n;
    }
    if (type === 'date') {
      var t = Date.parse(String(raw));
      return isNaN(t) ? 0 : t;
    }
    if (key === 'valor' || key === 'estoque') {
      var num = parseFloat(String(raw).replace(',', '.'));
      return isNaN(num) ? 0 : num;
    }
    if (key === 'status' && /^\d+$/.test(String(raw).trim())) {
      return parseInt(raw, 10) || 0;
    }
    return norm(raw);
  }

  function initTable(table) {
    var tbody = table.querySelector('tbody');
    if (!tbody) {
      return;
    }

    var sortBtns = table.querySelectorAll('.catalogo-excel-sort[data-sort-key]');
    if (!sortBtns.length) {
      return;
    }

    var sortKey = null;
    var sortDir = 'asc';
    var sortType = '';

    function applySort() {
      if (!sortKey) {
        return;
      }
      var rows = dataRows(tbody);
      rows.sort(function (a, b) {
        var va = sortValue(a, sortKey, sortType);
        var vb = sortValue(b, sortKey, sortType);
        var cmp = 0;
        if (typeof va === 'number' && typeof vb === 'number') {
          cmp = va - vb;
        } else {
          cmp = String(va).localeCompare(String(vb), 'pt-BR', {
            numeric: true,
            sensitivity: 'base',
          });
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
        if (!icon) {
          return;
        }
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
        if (!key) {
          return;
        }
        sortType = btn.getAttribute('data-sort-type') || '';
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
  }

  document.querySelectorAll('table[data-crm-sortable]').forEach(initTable);
})();
