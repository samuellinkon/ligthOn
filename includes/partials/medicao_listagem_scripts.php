<?php
/** JS da listagem de medição — requer $medicaoJsClienteId (int, 0 no portal). */
$medicaoJsClienteId = (int) ($medicaoJsClienteId ?? 0);
?>
<script>
(function () {
  'use strict';

  var clienteId = <?= $medicaoJsClienteId ?>;

  function pad2(n) {
    return n < 10 ? '0' + n : String(n);
  }

  function faixaCurta(deVal, ateVal) {
    if (!deVal || !ateVal) {
      return '—';
    }
    var de = deVal.split('-');
    var ate = ateVal.split('-');
    if (de.length < 3 || ate.length < 3) {
      return '—';
    }
    return pad2(parseInt(de[2], 10)) + '/' + pad2(parseInt(de[1], 10))
      + ' → ' + pad2(parseInt(ate[2], 10)) + '/' + pad2(parseInt(ate[1], 10));
  }

  function periodoFromCard(card) {
    var deIn = card.querySelector('.medicao-periodo-de');
    var ateIn = card.querySelector('.medicao-periodo-ate');
    var q = {};
    if (deIn && deIn.value) {
      q.periodo_de = deIn.value;
    }
    if (ateIn && ateIn.value) {
      q.periodo_ate = ateIn.value;
    }
    return q;
  }

  function syncPeriodLabel(card) {
    var deIn = card.querySelector('.medicao-periodo-de');
    var ateIn = card.querySelector('.medicao-periodo-ate');
    var label = card.querySelector('[data-medicao-period-label]');
    if (!label || !deIn || !ateIn) {
      return;
    }
    label.textContent = faixaCurta(deIn.value, ateIn.value);
  }

  function appendQuery(baseHref, extra) {
    var url;
    try {
      url = new URL(baseHref, window.location.href);
    } catch (e) {
      return baseHref;
    }
    Object.keys(extra).forEach(function (k) {
      if (extra[k] !== undefined && extra[k] !== null && extra[k] !== '') {
        url.searchParams.set(k, extra[k]);
      }
    });
    return url.pathname + url.search;
  }

  function hrefForCard(card, kind) {
    var mes = card.getAttribute('data-medicao-mes') || '';
    var p = periodoFromCard(card);
    if (kind === 'ver') {
      return appendQuery('medicao_ver.php', Object.assign({ mes: mes }, p));
    }
    if (kind === 'chamados') {
      var qc = Object.assign({ f: 'resolvido_bm', medicao_mes: mes }, p);
      if (clienteId > 0) {
        qc.cliente_id = String(clienteId);
      }
      return appendQuery('chamados.php', qc);
    }
    if (kind === 'xlsx_detalhes' || kind === 'pdf_anexos') {
      var qe = Object.assign({ medicao_mes: mes, export: kind }, p);
      if (clienteId > 0) {
        qe.cliente_id = String(clienteId);
      }
      return appendQuery('chamados.php', qe);
    }
    return null;
  }

  function closeAllPopovers(except) {
    document.querySelectorAll('[data-medicao-period-popover]').forEach(function (pop) {
      if (except && pop === except) {
        return;
      }
      pop.hidden = true;
    });
  }

  document.querySelectorAll('.medicao-mes-card').forEach(function (card) {
    syncPeriodLabel(card);

    card.addEventListener('click', function (ev) {
      if (ev.target.closest('a, button, input, select, label, form, [data-medicao-period-popover]')) {
        return;
      }
      var href = hrefForCard(card, 'ver');
      if (href) {
        window.location.href = href;
      }
    });

    card.querySelectorAll('.js-medicao-periodo-link').forEach(function (a) {
      a.addEventListener('click', function (ev) {
        ev.stopPropagation();
        var kind = a.getAttribute('data-link-kind') || '';
        var href = hrefForCard(card, kind);
        if (!href) {
          return;
        }
        ev.preventDefault();
        closeAllPopovers();
        window.location.href = href;
      });
    });

    var popover = card.querySelector('[data-medicao-period-popover]');
    var fieldDe = card.querySelector('.medicao-mes-card__period-field-de');
    var fieldAte = card.querySelector('.medicao-mes-card__period-field-ate');
    var hiddenDe = card.querySelector('.medicao-periodo-de');
    var hiddenAte = card.querySelector('.medicao-periodo-ate');

    var btnEdit = card.querySelector('[data-medicao-period-edit]');
    if (btnEdit && popover) {
      btnEdit.addEventListener('click', function (ev) {
        ev.stopPropagation();
        var wasHidden = popover.hidden;
        closeAllPopovers();
        if (wasHidden) {
          if (fieldDe && hiddenDe) {
            fieldDe.value = hiddenDe.value;
          }
          if (fieldAte && hiddenAte) {
            fieldAte.value = hiddenAte.value;
          }
          popover.hidden = false;
        }
      });
    }

    var btnApply = card.querySelector('[data-medicao-period-apply]');
    if (btnApply && popover) {
      btnApply.addEventListener('click', function (ev) {
        ev.stopPropagation();
        if (fieldDe && hiddenDe) {
          hiddenDe.value = fieldDe.value;
        }
        if (fieldAte && hiddenAte) {
          hiddenAte.value = fieldAte.value;
        }
        syncPeriodLabel(card);
        popover.hidden = true;
      });
    }

    var btnCancel = card.querySelector('[data-medicao-period-cancel]');
    if (btnCancel && popover) {
      btnCancel.addEventListener('click', function (ev) {
        ev.stopPropagation();
        popover.hidden = true;
      });
    }
  });

  document.addEventListener('click', function () {
    closeAllPopovers();
  });

  document.querySelectorAll('[data-medicao-period-popover]').forEach(function (el) {
    el.addEventListener('click', function (ev) {
      ev.stopPropagation();
    });
  });
})();
</script>
