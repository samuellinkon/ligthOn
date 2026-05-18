<?php
/**
 * Medição — listagem de medições mensais (portal cliente).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/medicao_helpers.php';
require_once __DIR__ . '/../includes/chamados_list_urls.php';

$CLIENTE = require_auth('cliente');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_cliente('medicao');
require_once __DIR__ . '/../includes/audit_log.php';

$pageTitle  = 'Medição';
$basePath   = '../';
$activePage = 'medicao';

if (!db_ok()) {
    flash_set('err', 'Banco indisponível.');
    header('Location: index.php');
    exit;
}

$cid = (int) ($CLIENTE['cliente_id'] ?? 0);
$clienteId = $cid > 0 ? repo_cliente_matriz_raiz_id($cid) : 0;

if ($clienteId <= 0) {
    flash_set('err', 'Empresa não vinculada ao seu acesso.');
    header('Location: index.php');
    exit;
}

$clienteMatriz = repo_cliente($clienteId);
if (!$clienteMatriz) {
    flash_set('err', 'Empresa não encontrada.');
    header('Location: index.php');
    exit;
}

audit_log_registar('medicao.acessar_lista', 'medicao', null, $clienteId > 0 ? $clienteId : null, [
    'empresa' => function_exists('mb_substr') ? mb_substr((string) ($clienteMatriz['empresa'] ?? ''), 0, 120, 'UTF-8') : substr((string) ($clienteMatriz['empresa'] ?? ''), 0, 120),
    'portal'  => 1,
]);

$mesesLista = repo_medicao_resumo_mensal_list($clienteId, 60);

$topTitle    = 'Medição';
$topSubtitle = 'Medições mensais';
$topSearch   = '';
$topAction   = null;

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-cliente.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content medicao-page">

  <div class="card">
    <div class="panel-head">
      <div>
        <h4>Medições mensais</h4>
        <span class="panel-sub">Chamados por mês de abertura e meses com <strong>importação BM</strong> (mesmo sem chamados no período) · <?= htmlspecialchars((string) ($clienteMatriz['empresa'] ?? '')) ?></span>
      </div>
    </div>
    <div class="panel-body" style="padding-top:0;">
      <div class="table-wrap">
        <table class="medicao-meses-table">
          <thead>
            <tr>
              <th>Mês</th>
              <th class="text-right">Chamados</th>
              <th class="text-right">Materiais</th>
              <th class="text-right">Serv. itens</th>
              <th class="text-right">Total</th>
              <th class="medicao-data-col">Data início</th>
              <th class="medicao-data-col">Data fim</th>
              <th class="td-actions">Ações</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($mesesLista)): ?>
              <tr>
                <td colspan="8" class="muted" style="padding:28px;text-align:center;">
                  Nenhum mês com chamados nem importação BM — quando houver medições ou importação, os meses aparecem aqui.
                </td>
              </tr>
            <?php else: foreach ($mesesLista as $row):
              $ym = (string) ($row['ym'] ?? '');
              $bmPrimeiroDia   = $ym . '-01';
              $bmPeriodoAte    = medicao_bm_export_v2_periodo_ate($ym);
              $bmPeriodoDeMin  = medicao_bm_export_v2_periodo_de_min($ym);
              $bmPeriodoAteFmt = date('d/m/Y', strtotime($bmPeriodoAte));
              $bmIdYm          = htmlspecialchars(preg_replace('/\W/', '_', $ym), ENT_QUOTES, 'UTF-8');
              $bmFormId        = 'bm-export-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $ym);
              $periodoResolvido = medicao_resolve_periodo_filtro($ym, $bmPrimeiroDia, $bmPeriodoAte);
              $hrefChamados = 'chamados.php?' . http_build_query([
                  'medicao_mes' => $ym,
                  'periodo_de'  => $periodoResolvido['de'],
                  'periodo_ate' => $periodoResolvido['ate'],
              ]);
              $chExportCtx = [
                  'medicao_mes'       => $ym,
                  'periodo_de'        => $periodoResolvido['de'],
                  'periodo_ate'       => $periodoResolvido['ate'],
                  'periodo_limpar'    => false,
                  'cliente_id'        => null,
                  'envolvido_user'    => null,
                  'tecnico_user_id'   => null,
                  'local_q'           => null,
              ];
              $hrefXlsxDet = adm_chamados_export_url('xlsx_detalhes', '', '', $chExportCtx);
              $hrefPdfAnexos = adm_chamados_export_url('pdf_anexos', '', '', $chExportCtx);
              if (defined('CRM_EXPORT_PDF_DEBUG') && CRM_EXPORT_PDF_DEBUG) {
                  $hrefPdfAnexos .= (strpos($hrefPdfAnexos, '?') !== false ? '&' : '?') . 'pdf_debug=1';
              }
              $dateTitle = 'Mês ' . htmlspecialchars($ym) . ' · início mín. ' . date('d/m/Y', strtotime($bmPeriodoDeMin))
                  . ' · fecho até ' . htmlspecialchars($bmPeriodoAteFmt);
              ?>
              <tr class="medicao-mes-row" data-medicao-mes="<?= htmlspecialchars($ym, ENT_QUOTES, 'UTF-8') ?>" id="bm-<?= htmlspecialchars(str_replace(['\\', '/'], '-', $ym), ENT_QUOTES, 'UTF-8') ?>">
                <td class="td-strong"><?= htmlspecialchars(medicao_mes_label_pt($ym)) ?></td>
                <td class="text-right"><?= (int) ($row['n_chamados'] ?? 0) ?></td>
                <td class="text-right td-mute">R$ <?= number_format((float) ($row['valor_materiais'] ?? 0), 2, ',', '.') ?></td>
                <td class="text-right td-mute">R$ <?= number_format((float) ($row['valor_servicos'] ?? 0), 2, ',', '.') ?></td>
                <td class="text-right"><strong>R$ <?= number_format((float) ($row['valor_total'] ?? 0), 2, ',', '.') ?></strong></td>
                <td class="medicao-data-cell">
                  <form id="<?= htmlspecialchars($bmFormId, ENT_QUOTES, 'UTF-8') ?>" method="get" action="medicao_export_boletim_bm.php" class="medicao-bm-inline-form">
                    <input type="hidden" name="mes" value="<?= htmlspecialchars($ym) ?>">
                    <input type="hidden" name="export" value="1">
                    <label class="sr-only" for="bm_periodo_de_<?= $bmIdYm ?>">Data início</label>
                    <input id="bm_periodo_de_<?= $bmIdYm ?>" type="date" name="periodo_de"
                           class="input medicao-bm-date medicao-periodo-de"
                           value="<?= htmlspecialchars($bmPrimeiroDia) ?>"
                           min="<?= htmlspecialchars($bmPeriodoDeMin) ?>"
                           max="<?= htmlspecialchars($bmPeriodoAte) ?>"
                           title="<?= $dateTitle ?>">
                  </form>
                </td>
                <td class="medicao-data-cell">
                  <label class="sr-only" for="bm_periodo_ate_<?= $bmIdYm ?>">Data fim</label>
                  <input id="bm_periodo_ate_<?= $bmIdYm ?>" type="date" name="periodo_ate"
                         form="<?= htmlspecialchars($bmFormId, ENT_QUOTES, 'UTF-8') ?>"
                         class="input medicao-bm-date medicao-periodo-ate"
                         value="<?= htmlspecialchars($bmPeriodoAte) ?>"
                         min="<?= htmlspecialchars($bmPeriodoDeMin) ?>"
                         max="<?= htmlspecialchars($bmPeriodoAte) ?>"
                         title="<?= $dateTitle ?>">
                </td>
                <td class="td-actions">
                  <div class="actions-inline">
                    <a class="action-icon js-medicao-periodo-link" href="<?= htmlspecialchars($hrefChamados) ?>" data-link-kind="chamados" title="Chamados" aria-label="Chamados">💬</a>
                    <button type="submit" form="<?= htmlspecialchars($bmFormId, ENT_QUOTES, 'UTF-8') ?>" class="action-icon excel" title="Excel — boletim BM" aria-label="Excel — boletim BM">📄</button>
                    <a class="action-icon excel js-medicao-periodo-link" href="<?= htmlspecialchars($hrefXlsxDet) ?>" data-link-kind="xlsx_detalhes" title="Excel — medição completa" aria-label="Excel — medição completa">📋</a>
                    <a class="action-icon pdf js-medicao-periodo-link" href="<?= htmlspecialchars($hrefPdfAnexos) ?>" data-link-kind="pdf_anexos" title="Relatório Fotográfico" aria-label="Relatório Fotográfico">📎</a>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <p class="muted" style="margin:16px 20px 20px;font-size:13px;line-height:1.45;">
        Defina <strong>Data início</strong> e/ou <strong>Data fim</strong> para filtrar medição, boletim BM (📄), Excel completo (📋) e PDF com imagens (📎).
      </p>
    </div>
  </div>
</section>

</main>
</div>

<script>
(function () {
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
      return appendQuery('chamados.php', Object.assign({ medicao_mes: mes }, p));
    }
    if (kind === 'xlsx_detalhes' || kind === 'pdf_anexos') {
      return appendQuery('chamados.php', Object.assign({ medicao_mes: mes, export: kind }, p));
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

<?php include __DIR__ . '/../includes/footer.php'; ?>
