/**
 * Substitui o painel nativo de opções do <select> por uma lista estilizada (tema CRM).
 * Mantém o <select> no DOM para submit, validação e listeners existentes.
 */
(function () {
  'use strict';

  var SELECTOR =
    '.app select:not([multiple]):not([size]):not(.chamado-catalog-select):not(.crm-no-custom-select)';

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function normalizeSearchText(s) {
    return String(s || '')
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '');
  }

  function isSearchEnabled(sel) {
    var v = sel.getAttribute('data-crm-custom-select-search');
    return v === '1' || v === 'true';
  }

  function getSelectedLabel(sel) {
    var opt = sel.options[sel.selectedIndex];
    return opt ? (opt.textContent || '').trim() : '';
  }

  function buildOptionButton(sel, opt, panel, trigger, syncTriggerLabel, close, afterPick) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.setAttribute('role', 'option');
    btn.className = 'crm-custom-select__option';
    btn.innerHTML = '<span class="crm-custom-select__option-text">' + escapeHtml(opt.textContent || '') + '</span>';
    btn.dataset.value = opt.value;

    if (opt.disabled) {
      btn.disabled = true;
      btn.setAttribute('aria-disabled', 'true');
    }

    if (opt.selected) {
      btn.setAttribute('aria-selected', 'true');
    } else {
      btn.setAttribute('aria-selected', 'false');
    }

    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      if (opt.disabled) return;
      var prev = sel.value;
      sel.value = opt.value;
      if (prev !== sel.value) {
        sel.dispatchEvent(new Event('input', { bubbles: true }));
        sel.dispatchEvent(new Event('change', { bubbles: true }));
      }
      syncTriggerLabel();
      close();
      afterPick();
    });

    panel.appendChild(btn);
  }

  function enhanceSelect(sel) {
    if (!sel || sel.dataset.crmCustomSelectEnhanced === '1') return;
    var parent = sel.parentNode;
    if (!parent) return;

    sel.dataset.crmCustomSelectEnhanced = '1';
    sel.classList.add('crm-custom-select-native');
    sel.setAttribute('tabindex', '-1');

    var wrap = document.createElement('div');
    wrap.className = 'crm-custom-select';
    wrap.setAttribute('data-crm-custom-select', '1');

    var trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'crm-custom-select__trigger';
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');
    trigger.innerHTML =
      '<span class="crm-custom-select__value"></span>' +
      '<span class="crm-custom-select__chevron" aria-hidden="true"></span>';

    var panel = document.createElement('div');
    panel.className = 'crm-custom-select__panel';
    panel.setAttribute('role', 'listbox');
    panel.hidden = true;
    panel.id = 'crm-cs-' + Math.random().toString(36).slice(2, 11);
    trigger.setAttribute('aria-controls', panel.id);

    var searchEnabled = isSearchEnabled(sel);
    var searchWrap = null;
    var searchInput = null;
    if (searchEnabled) {
      wrap.classList.add('crm-custom-select--searchable');
      searchWrap = document.createElement('div');
      searchWrap.className = 'crm-custom-select__search-wrap';
      searchWrap.setAttribute('role', 'presentation');
      searchInput = document.createElement('input');
      searchInput.type = 'search';
      searchInput.className = 'crm-custom-select__search input';
      searchInput.setAttribute('autocomplete', 'off');
      searchInput.setAttribute('autocorrect', 'off');
      searchInput.setAttribute('spellcheck', 'false');
      var ph = sel.getAttribute('data-crm-custom-select-search-placeholder');
      searchInput.setAttribute('placeholder', ph || 'Filtrar…');
      searchInput.setAttribute('aria-label', 'Filtrar opções da lista');
      searchWrap.appendChild(searchInput);
      panel.appendChild(searchWrap);
    }

    var open = false;
    var focusIndex = 0;
    var optionButtons = function () {
      return [].slice.call(
        panel.querySelectorAll(
          '.crm-custom-select__option:not([disabled]):not(.crm-custom-select__option--hidden)'
        )
      );
    };

    function syncGroupLabels() {
      if (!searchInput) return;
      var q = searchInput.value.trim();
      var filtering = q.length > 0;
      var nodes = [].slice.call(panel.children);
      var start = searchWrap ? 1 : 0;
      for (var i = start; i < nodes.length; i++) {
        var el = nodes[i];
        if (!el.classList.contains('crm-custom-select__group-label')) continue;
        if (!filtering) {
          el.classList.remove('crm-custom-select__group-label--hidden');
          continue;
        }
        var has = false;
        for (var j = i + 1; j < nodes.length; j++) {
          var n = nodes[j];
          if (n.classList.contains('crm-custom-select__group-label')) break;
          if (
            n.classList.contains('crm-custom-select__option') &&
            !n.classList.contains('crm-custom-select__option--hidden')
          ) {
            has = true;
            break;
          }
        }
        el.classList.toggle('crm-custom-select__group-label--hidden', !has);
      }
    }

    function clearOptionFilter() {
      panel.querySelectorAll('.crm-custom-select__option').forEach(function (b) {
        b.classList.remove('crm-custom-select__option--hidden');
      });
      panel.querySelectorAll('.crm-custom-select__group-label').forEach(function (g) {
        g.classList.remove('crm-custom-select__group-label--hidden');
      });
    }

    function applyOptionFilter() {
      if (!searchInput) return;
      var q = searchInput.value.trim();
      var nq = normalizeSearchText(q);
      panel.querySelectorAll('.crm-custom-select__option').forEach(function (b) {
        var span = b.querySelector('.crm-custom-select__option-text');
        var raw = span ? span.textContent : b.textContent;
        var t = normalizeSearchText(raw || '');
        var show = nq === '' || t.indexOf(nq) !== -1;
        b.classList.toggle('crm-custom-select__option--hidden', !show);
      });
      syncGroupLabels();
      var ae = document.activeElement;
      if (ae && ae.classList.contains('crm-custom-select__option')) {
        if (ae.classList.contains('crm-custom-select__option--hidden')) {
          var vis = optionButtons();
          if (vis.length) {
            focusIndex = 0;
            vis.forEach(function (b, j) {
              b.classList.toggle('crm-custom-select__option--focus', j === 0);
            });
            vis[0].focus();
          } else if (searchInput) {
            searchInput.focus();
          }
        }
      }
    }

    function syncAriaSelected() {
      var v = sel.value;
      panel.querySelectorAll('.crm-custom-select__option').forEach(function (b) {
        b.setAttribute('aria-selected', b.dataset.value === v ? 'true' : 'false');
      });
    }

    function syncTriggerLabel() {
      trigger.querySelector('.crm-custom-select__value').textContent = getSelectedLabel(sel);
      syncAriaSelected();
    }

    function positionPanel() {
      var r = trigger.getBoundingClientRect();
      var margin = 10;
      var spaceBelow = window.innerHeight - r.bottom - margin;
      var spaceAbove = r.top - margin;
      var maxOpenDown = Math.min(280, Math.max(0, spaceBelow - 4));
      var maxOpenUp = Math.min(280, Math.max(0, spaceAbove - 4));
      var openDown = maxOpenDown >= 100 || maxOpenDown >= maxOpenUp;

      panel.style.left = r.left + 'px';
      panel.style.width = Math.max(r.width, 160) + 'px';
      panel.style.right = 'auto';

      if (openDown) {
        panel.style.top = r.bottom + 4 + 'px';
        panel.style.bottom = 'auto';
        panel.style.maxHeight = maxOpenDown + 'px';
      } else {
        panel.style.top = 'auto';
        panel.style.bottom = window.innerHeight - r.top + 4 + 'px';
        panel.style.maxHeight = maxOpenUp + 'px';
      }
    }

    function onEscapeDoc(e) {
      if (e.key !== 'Escape' || !open) return;
      e.preventDefault();
      close();
      trigger.focus();
    }

    function close() {
      open = false;
      panel.hidden = true;
      trigger.setAttribute('aria-expanded', 'false');
      document.removeEventListener('mousedown', onDocDown, true);
      document.removeEventListener('keydown', onEscapeDoc, true);
      window.removeEventListener('scroll', onScrollResize, true);
      window.removeEventListener('resize', onScrollResize);
      if (searchInput) {
        searchInput.value = '';
        clearOptionFilter();
      }
    }

    function afterPick() {
      trigger.focus();
    }

    function onDocDown(e) {
      if (!wrap.contains(e.target)) close();
    }

    function onScrollResize() {
      if (open) positionPanel();
    }

    function highlightStep(delta) {
      var btns = optionButtons();
      if (!btns.length) return;
      focusIndex = (focusIndex + delta + btns.length) % btns.length;
      btns.forEach(function (b, i) {
        b.classList.toggle('crm-custom-select__option--focus', i === focusIndex);
      });
      btns[focusIndex].focus();
    }

    function openPanel() {
      if (sel.disabled) return;
      open = true;
      panel.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
      positionPanel();
      document.addEventListener('mousedown', onDocDown, true);
      document.addEventListener('keydown', onEscapeDoc, true);
      window.addEventListener('scroll', onScrollResize, true);
      window.addEventListener('resize', onScrollResize);

      if (searchInput) {
        searchInput.value = '';
        clearOptionFilter();
      }

      var btns = optionButtons();
      var si = sel.selectedIndex;
      focusIndex = 0;
      for (var i = 0; i < btns.length; i++) {
        if (btns[i].dataset.value === sel.options[si].value && !sel.options[si].disabled) {
          focusIndex = i;
          break;
        }
      }
      btns.forEach(function (b, j) {
        b.classList.toggle('crm-custom-select__option--focus', j === focusIndex);
      });
      if (searchInput) {
        window.setTimeout(function () {
          searchInput.focus();
        }, 0);
      } else if (btns[focusIndex]) {
        btns[focusIndex].focus();
      }
    }

    trigger.addEventListener('click', function (e) {
      e.preventDefault();
      if (open) {
        close();
        return;
      }
      openPanel();
    });

    trigger.addEventListener('keydown', function (e) {
      if (sel.disabled) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (!open) openPanel();
        else highlightStep(e.key === 'ArrowDown' ? 1 : -1);
        return;
      }
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        if (!open) openPanel();
        return;
      }
      if (e.key === 'Escape' && open) {
        e.preventDefault();
        close();
        trigger.focus();
      }
    });

    panel.addEventListener('keydown', function (e) {
      if (searchInput && document.activeElement === searchInput) {
        if (e.key === 'Escape') {
          e.preventDefault();
          close();
          trigger.focus();
          return;
        }
        if (e.key === 'ArrowDown') {
          e.preventDefault();
          var downBtns = optionButtons();
          if (!downBtns.length) return;
          focusIndex = 0;
          downBtns.forEach(function (b, j) {
            b.classList.toggle('crm-custom-select__option--focus', j === 0);
          });
          downBtns[0].focus();
          return;
        }
        if (e.key === 'ArrowUp') {
          e.preventDefault();
          trigger.focus();
          return;
        }
        return;
      }
      if (e.key === 'Escape') {
        e.preventDefault();
        close();
        trigger.focus();
        return;
      }
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        highlightStep(1);
        return;
      }
      if (e.key === 'ArrowUp') {
        e.preventDefault();
        var aeUp = document.activeElement;
        var btnsUp = optionButtons();
        if (
          searchInput &&
          aeUp &&
          aeUp.classList.contains('crm-custom-select__option') &&
          btnsUp.length &&
          btnsUp.indexOf(aeUp) === 0
        ) {
          searchInput.focus();
          btnsUp.forEach(function (b) {
            b.classList.remove('crm-custom-select__option--focus');
          });
          return;
        }
        highlightStep(-1);
        return;
      }
      if (e.key === 'Enter' || e.key === ' ') {
        var ae = document.activeElement;
        if (ae && ae.classList.contains('crm-custom-select__option') && !ae.disabled) {
          e.preventDefault();
          ae.click();
        }
      }
    });

    if (searchInput) {
      searchInput.addEventListener('input', applyOptionFilter);
    }

    sel.addEventListener('change', syncTriggerLabel);

    sel.addEventListener('focus', function () {
      wrap.classList.add('crm-custom-select--focus');
    });
    sel.addEventListener('blur', function () {
      wrap.classList.remove('crm-custom-select--focus');
    });

    parent.insertBefore(wrap, sel);
    wrap.appendChild(trigger);
    wrap.appendChild(panel);
    wrap.appendChild(sel);

    for (var c = 0; c < sel.children.length; c++) {
      var node = sel.children[c];
      if (node.tagName === 'OPTGROUP') {
        var gl = document.createElement('div');
        gl.className = 'crm-custom-select__group-label';
        gl.setAttribute('role', 'presentation');
        gl.textContent = node.label || '';
        panel.appendChild(gl);
        for (var j = 0; j < node.children.length; j++) {
          if (node.children[j].tagName === 'OPTION') {
            buildOptionButton(sel, node.children[j], panel, trigger, syncTriggerLabel, close, afterPick);
          }
        }
      } else if (node.tagName === 'OPTION') {
        buildOptionButton(sel, node, panel, trigger, syncTriggerLabel, close, afterPick);
      }
    }

    syncTriggerLabel();

    function syncDisabledFromNative() {
      trigger.disabled = !!sel.disabled;
    }
    syncDisabledFromNative();
    try {
      var moDis = new MutationObserver(syncDisabledFromNative);
      moDis.observe(sel, { attributes: true, attributeFilter: ['disabled'] });
    } catch (e1) {
      /* ignore */
    }

    var form = sel.form;
    if (form) {
      form.addEventListener('reset', function () {
        window.setTimeout(function () {
          syncTriggerLabel();
          syncDisabledFromNative();
          if (searchInput) {
            searchInput.value = '';
            clearOptionFilter();
          }
          panel.querySelectorAll('.crm-custom-select__option').forEach(function (b) {
            b.setAttribute(
              'aria-selected',
              b.dataset.value === sel.value ? 'true' : 'false'
            );
          });
        }, 0);
      });
    }
  }

  function run() {
    document.querySelectorAll(SELECTOR).forEach(enhanceSelect);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }

  window.crmCustomSelectEnhance = run;
})();
