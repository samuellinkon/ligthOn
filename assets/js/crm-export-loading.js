/**
 * Loading global enquanto exportações PDF/XLSX (e BM) são geradas no servidor.
 * Intercepta cliques e submits; usa fetch + blob para manter o overlay até concluir.
 */
(function () {
  'use strict';

  var EXPORT_PARAM = /^(xlsx|pdf|pdf_anexos|xlsx_detalhes|xlsx_lista|relatorio_xlsx)$/i;
  var EXPORT_PATH = /(export_boletim_bm|export_xlsx|catalogo_export|chamado_export|medicao_export|pontos_iluminacao_export)/i;

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
      } else if (exp === 'xlsx_detalhes') {
        base = 'boletim_medicao_com_detalhes.xlsx';
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
          if (openInNewTab) {
            window.open(response.url, '_blank', 'noopener,noreferrer');
          } else {
            window.location.href = response.url;
          }
          return null;
        }

        if (!response.ok) {
          return response.text().then(function (txt) {
            var extra = '';
            var pre = /<pre[^>]*>([\s\S]*?)<\/pre>/i.exec(txt || '');
            if (pre && pre[1]) {
              extra = ' ' + String(pre[1]).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 180);
            }
            throw new Error('Falha na exportação (HTTP ' + response.status + ').' + extra);
          });
        }

        // HTML de impressão (export=pdf do chamado): respeita target="_blank".
        if (ct.indexOf('text/html') !== -1) {
          if (openInNewTab) {
            window.open(url, '_blank', 'noopener,noreferrer');
          } else {
            window.location.href = url;
          }
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

  function periodoFromMedicaoContext(a) {
    var q = {};
    var card = a.closest('.medicao-mes-card');
    if (card) {
      var deEl = card.querySelector('.medicao-periodo-de');
      var ateEl = card.querySelector('.medicao-periodo-ate');
      if (deEl && deEl.value) {
        q.periodo_de = deEl.value;
      }
      if (ateEl && ateEl.value) {
        q.periodo_ate = ateEl.value;
      }
      var mesCard = card.getAttribute('data-medicao-mes') || '';
      if (mesCard) {
        q.medicao_mes = mesCard;
      }
      return q;
    }
    var toolbar = a.closest('.medicao-export-toolbar') || document.getElementById('medicao-ver-toolbar');
    if (!toolbar) {
      return q;
    }
    var form = toolbar.querySelector('form.medicao-bm-export-form-hidden, form[action*="medicao_export_boletim_bm"]');
    if (form) {
      var deInp = form.querySelector('[name="periodo_de"]');
      var ateInp = form.querySelector('[name="periodo_ate"]');
      if (deInp && deInp.value) {
        q.periodo_de = deInp.value;
      }
      if (ateInp && ateInp.value) {
        q.periodo_ate = ateInp.value;
      }
    } else {
      var deAttr = toolbar.getAttribute('data-periodo-de') || '';
      var ateAttr = toolbar.getAttribute('data-periodo-ate') || '';
      if (deAttr) {
        q.periodo_de = deAttr;
      }
      if (ateAttr) {
        q.periodo_ate = ateAttr;
      }
    }
    var mesTb = toolbar.getAttribute('data-medicao-mes') || '';
    if (mesTb) {
      q.medicao_mes = mesTb;
    }
    return q;
  }

  function urlWithLiveMedicaoPeriodo(a) {
    var href = a.getAttribute('href') || a.href || '';
    var extra = periodoFromMedicaoContext(a);
    var u = parseUrl(href);
    if (!u) {
      return href;
    }
    Object.keys(extra).forEach(function (k) {
      if (extra[k]) {
        u.searchParams.set(k, extra[k]);
      }
    });
    var exp = (u.searchParams.get('export') || '').toLowerCase();
    if ((exp === 'xlsx_detalhes' || exp === 'pdf_anexos') && !u.searchParams.get('f')) {
      u.searchParams.set('f', 'resolvido_bm');
    }
    return u.pathname + u.search;
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
    runExport(urlWithLiveMedicaoPeriodo(a), { openInNewTab: openTab });
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
