/**
 * Loading global enquanto exportações PDF/XLSX (e BM) são geradas no servidor.
 * Intercepta cliques e submits; usa fetch + blob para manter o overlay até concluir.
 */
(function () {
  'use strict';

  var EXPORT_PARAM = /^(xlsx|pdf|pdf_anexos|xlsx_detalhes|relatorio_xlsx)$/i;
  var EXPORT_PATH = /(export_boletim_bm|export_xlsx|catalogo_export|chamado_export|medicao_export)/i;

  var overlay = null;
  var activeCount = 0;
  var defaultMessage = 'Gerando exportação…';

  function ensureOverlay() {
    if (overlay) {
      return overlay;
    }
    overlay = document.createElement('div');
    overlay.className = 'crm-export-loading';
    overlay.setAttribute('role', 'alertdialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-busy', 'true');
    overlay.setAttribute('aria-live', 'polite');
    overlay.hidden = true;
    overlay.innerHTML =
      '<div class="crm-export-loading__panel">' +
      '  <div class="crm-export-loading__spinner" aria-hidden="true"></div>' +
      '  <p class="crm-export-loading__title">Exportando</p>' +
      '  <p class="crm-export-loading__msg">' + defaultMessage + '</p>' +
      '</div>';
    document.body.appendChild(overlay);
    return overlay;
  }

  function showLoading(message) {
    var el = ensureOverlay();
    var msgEl = el.querySelector('.crm-export-loading__msg');
    if (msgEl) {
      msgEl.textContent = message || defaultMessage;
    }
    activeCount += 1;
    el.hidden = false;
    el.classList.add('is-visible');
    document.body.classList.add('crm-export-loading-active');
  }

  function hideLoading() {
    activeCount = Math.max(0, activeCount - 1);
    if (activeCount > 0 || !overlay) {
      return;
    }
    overlay.hidden = true;
    overlay.classList.remove('is-visible');
    document.body.classList.remove('crm-export-loading-active');
  }

  function parseUrl(href) {
    try {
      return new URL(href, window.location.href);
    } catch (e) {
      return null;
    }
  }

  function isExportUrl(href) {
    var u = parseUrl(href);
    if (!u) {
      return false;
    }
    var exp = u.searchParams.get('export');
    if (exp && EXPORT_PARAM.test(exp)) {
      return true;
    }
    if (EXPORT_PATH.test(u.pathname)) {
      return true;
    }
    return false;
  }

  function isExportForm(form) {
    if (!form || form.tagName !== 'FORM') {
      return false;
    }
    if (form.classList.contains('medicao-bm-inline-form')) {
      return true;
    }
    if (form.classList.contains('medicao-bm-export-form-hidden')) {
      return true;
    }
    var action = (form.getAttribute('action') || '').toLowerCase();
    if (EXPORT_PATH.test(action)) {
      return true;
    }
    var expInp = form.querySelector('input[name="export"]');
    return !!(expInp && expInp.value);
  }

  function formToUrl(form) {
    var action = form.getAttribute('action') || window.location.pathname;
    var u = parseUrl(action);
    if (!u) {
      return action;
    }
    var data = new FormData(form);
    data.forEach(function (value, key) {
      u.searchParams.set(key, String(value));
    });
    return u.pathname + u.search;
  }

  function filenameFromDisposition(header, fallback) {
    if (!header) {
      return fallback || 'export';
    }
    var m = /filename\*=UTF-8''([^;\n]+)|filename="([^"]+)"|filename=([^;\n]+)/i.exec(header);
    if (!m) {
      return fallback || 'export';
    }
    try {
      return decodeURIComponent((m[1] || m[2] || m[3] || '').trim().replace(/^["']|["']$/g, ''));
    } catch (e) {
      return (m[1] || m[2] || m[3] || fallback || 'export').trim();
    }
  }

  function guessFilename(url, contentType) {
    var u = parseUrl(url);
    var base = 'export';
    if (u) {
      var exp = u.searchParams.get('export') || '';
      if (exp === 'pdf_anexos' || exp === 'pdf') {
        base = 'relatorio.pdf';
      } else if (exp.indexOf('xlsx') !== -1 || exp === 'relatorio_xlsx') {
        base = 'exportacao.xlsx';
      } else if (u.pathname.indexOf('boletim_bm') !== -1) {
        base = 'boletim-bm.xlsx';
      }
    }
    if (contentType && contentType.indexOf('pdf') !== -1) {
      return base.endsWith('.pdf') ? base : base + '.pdf';
    }
    if (contentType && (contentType.indexOf('spreadsheet') !== -1 || contentType.indexOf('excel') !== -1)) {
      return base.endsWith('.xlsx') ? base : base.replace(/\.\w+$/, '') + '.xlsx';
    }
    return base;
  }

  function triggerDownload(blob, filename) {
    var blobUrl = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = blobUrl;
    a.download = filename;
    a.rel = 'noopener';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(function () {
      URL.revokeObjectURL(blobUrl);
    }, 4000);
  }

  function openPdfTab(blob) {
    var blobUrl = URL.createObjectURL(blob);
    var w = window.open(blobUrl, '_blank', 'noopener,noreferrer');
    if (!w) {
      triggerDownload(blob, 'relatorio.pdf');
      URL.revokeObjectURL(blobUrl);
      return;
    }
    setTimeout(function () {
      try {
        URL.revokeObjectURL(blobUrl);
      } catch (e) { /* ignore */ }
    }, 120000);
  }

  function loadingMessageForUrl(url) {
    var u = parseUrl(url);
    if (!u) {
      return defaultMessage;
    }
    var exp = (u.searchParams.get('export') || '').toLowerCase();
    if (exp === 'pdf_anexos' || exp === 'pdf') {
      return 'Gerando relatório fotográfico (PDF)…';
    }
    if (exp.indexOf('xlsx') !== -1 || u.pathname.indexOf('boletim_bm') !== -1) {
      return 'Gerando planilha Excel…';
    }
    return defaultMessage;
  }

  function runExport(url, options) {
    options = options || {};
    var openInNewTab = !!options.openInNewTab;

    showLoading(options.message || loadingMessageForUrl(url));

    var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var timeoutId = setTimeout(function () {
      if (controller) {
        controller.abort();
      }
    }, 600000);

    var fetchOpts = {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: '*/*' },
    };
    if (controller) {
      fetchOpts.signal = controller.signal;
    }

    return fetch(url, fetchOpts)
      .then(function (response) {
        clearTimeout(timeoutId);
        var ct = (response.headers.get('Content-Type') || '').toLowerCase();

        if (response.redirected && ct.indexOf('text/html') !== -1) {
          window.location.href = response.url;
          return null;
        }

        if (!response.ok) {
          throw new Error('Falha na exportação (HTTP ' + response.status + ').');
        }

        if (ct.indexOf('text/html') !== -1) {
          window.location.href = url;
          return null;
        }

        return response.blob().then(function (blob) {
          return {
            blob: blob,
            contentType: ct,
            disposition: response.headers.get('Content-Disposition') || '',
          };
        });
      })
      .then(function (result) {
        if (!result) {
          return;
        }
        var name = filenameFromDisposition(result.disposition, guessFilename(url, result.contentType));
        var isPdf = result.contentType.indexOf('pdf') !== -1 || /\.pdf$/i.test(name);
        var dispInline = result.disposition.toLowerCase().indexOf('inline') !== -1;

        if (openInNewTab || (isPdf && dispInline)) {
          openPdfTab(result.blob);
        } else {
          triggerDownload(result.blob, name);
        }
      })
      .catch(function (err) {
        clearTimeout(timeoutId);
        if (err && err.name === 'AbortError') {
          if (typeof window.appAlert === 'function') {
            window.appAlert('A exportação demorou demais e foi cancelada. Tente um período menor.', 'Exportação');
          }
        } else {
          var errMsg = (err && err.message) ? err.message : 'Não foi possível concluir a exportação.';
          if (typeof window.appAlert === 'function') {
            window.appAlert(errMsg, 'Exportação');
          }
        }
      })
      .finally(hideLoading);
  }

  function shouldSkipLink(a) {
    if (!a || a.tagName !== 'A') {
      return true;
    }
    if (a.classList.contains('js-crm-export-skip')) {
      return true;
    }
    var kind = a.getAttribute('data-link-kind');
    if (kind === 'chamados' || kind === 'ver') {
      return true;
    }
    return !isExportUrl(a.href);
  }

  function handleLinkClick(ev) {
    var a = ev.target.closest('a');
    if (shouldSkipLink(a)) {
      return;
    }
    ev.preventDefault();
    ev.stopPropagation();
    if (typeof ev.stopImmediatePropagation === 'function') {
      ev.stopImmediatePropagation();
    }
    var openTab = a.getAttribute('target') === '_blank';
    runExport(a.href, { openInNewTab: openTab });
  }

  function handleFormSubmit(ev) {
    var form = ev.target;
    if (!isExportForm(form)) {
      return;
    }
    ev.preventDefault();
    ev.stopPropagation();
    if (typeof ev.stopImmediatePropagation === 'function') {
      ev.stopImmediatePropagation();
    }
    var url = formToUrl(form);
    runExport(url, { message: 'Gerando boletim BM (Excel)…' });
  }

  document.addEventListener('click', handleLinkClick, true);
  document.addEventListener('submit', handleFormSubmit, true);

  window.CrmExportLoading = {
    show: showLoading,
    hide: hideLoading,
    run: runExport,
    isExportUrl: isExportUrl,
  };
})();
