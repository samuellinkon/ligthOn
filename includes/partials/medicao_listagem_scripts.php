<?php
/** JS da listagem de medição — requer $medicaoJsClienteId (int, 0 no portal). */
$medicaoJsClienteId = (int) ($medicaoJsClienteId ?? 0);
?>
<script>
(function () {
  var clienteId = <?= $medicaoJsClienteId ?>;

  function periodoFromRow(tr) {
    var deIn = tr.querySelector('.medicao-periodo-de');
    var ateIn = tr.querySelector('.medicao-periodo-ate');
    var q = {};
    if (deIn && deIn.value) {
      q.periodo_de = deIn.value;
    }
    if (ateIn && ateIn.value) {
      q.periodo_ate = ateIn.value;
    }
    return q;
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

  function hrefForLink(tr, kind) {
    var mes = tr.getAttribute('data-medicao-mes') || '';
    var p = periodoFromRow(tr);
    if (kind === 'ver') {
      return appendQuery('medicao_ver.php', Object.assign({ mes: mes }, p));
    }
    if (kind === 'chamados') {
      var qc = Object.assign({ medicao_mes: mes }, p);
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

  document.querySelectorAll('.medicao-mes-row').forEach(function (tr) {
    tr.addEventListener('click', function (ev) {
      if (ev.target.closest('a, button, input, select, label, form')) {
        return;
      }
      var href = hrefForLink(tr, 'ver');
      if (href) {
        window.location.href = href;
      }
    });
    tr.querySelectorAll('.js-medicao-periodo-link').forEach(function (a) {
      a.addEventListener('click', function (ev) {
        ev.stopPropagation();
        var kind = a.getAttribute('data-link-kind') || '';
        var href = hrefForLink(tr, kind);
        if (!href) {
          return;
        }
        ev.preventDefault();
        window.location.href = href;
      });
    });
  });
})();
</script>
