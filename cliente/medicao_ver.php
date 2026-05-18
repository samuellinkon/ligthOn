<?php
/**
 * Medição — visão resumida do mês com custos, itens e chamados (portal cliente).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/medicao_helpers.php';
require_once __DIR__ . '/../includes/chamados_list_urls.php';

$CLIENTE = require_auth('cliente');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_cliente('medicao');
require_once __DIR__ . '/../includes/audit_log.php';

$pageTitle  = 'Ver medição';
$basePath   = '../';
$activePage = 'medicao';

if (!db_ok()) {
    flash_set('err', 'Banco indisponível.');
    header('Location: index.php');
    exit;
}

$mesRaw = trim((string) ($_GET['mes'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $mesRaw)) {
    flash_set('err', 'Informe um mês válido (AAAA-MM).');
    header('Location: medicao.php');
    exit;
}
$mesRef = $mesRaw;
$periodoResolvido = medicao_resolve_periodo_filtro(
    $mesRef,
    trim((string) ($_GET['periodo_de'] ?? '')),
    trim((string) ($_GET['periodo_ate'] ?? ''))
);
if (!$periodoResolvido['ok']) {
    flash_set('err', $periodoResolvido['err']);
    header('Location: medicao.php');
    exit;
}
$dataDe  = $periodoResolvido['de'];
$dataAte = $periodoResolvido['ate'];

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

$rel          = repo_medicao_chamados_relatorio($clienteId, $dataDe, $dataAte);
$linhas       = $rel['rows'];
$tot          = $rel['totais'];
$itensResumo  = repo_medicao_itens_movimento_resumo($clienteId, $dataDe, $dataAte);
$itensLinhas  = $itensResumo['rows'];
$itensTotais  = $itensResumo['totais'];

$impPkg    = repo_medicao_import_fetch($clienteId, $mesRef);
$impCab    = $impPkg['cabecalho'] ?? null;
$impLinhas = is_array($impPkg['linhas'] ?? null) ? $impPkg['linhas'] : [];

if (($_GET['export'] ?? '') === 'relatorio_xlsx') {
    try {
        require_once __DIR__ . '/../includes/medicao_export_relatorio_xlsx.php';
        $sheet = medicao_relatorio_detalhado_sheet_rows($linhas, $impLinhas, $mesRef);
        audit_log_registar('medicao.exportar_relatorio_xlsx', 'medicao', null, $clienteId > 0 ? $clienteId : null, [
            'ref_ym'         => $mesRef,
            'n_chamados_rel' => count($linhas),
            'n_linhas_imp'   => count($impLinhas),
            'portal'         => 1,
        ]);
        medicao_export_relatorio_detalhado_xlsx_send(
            $mesRef,
            $sheet,
            (string) ($clienteMatriz['empresa'] ?? ''),
            medicao_mes_label_pt($mesRef),
            (int) $clienteId,
            $dataDe,
            $dataAte
        );
    } catch (Throwable $e) {
        flash_set('err', 'Falha ao exportar XLSX: ' . $e->getMessage());
        header('Location: medicao_ver.php?' . http_build_query([
            'mes'         => $mesRef,
            'periodo_de'  => $dataDe,
            'periodo_ate' => $dataAte,
        ]));
    }
    exit;
}

audit_log_registar('medicao.acessar_resumo', 'medicao', null, $clienteId > 0 ? $clienteId : null, [
    'ref_ym'  => $mesRef,
    'empresa' => function_exists('mb_substr') ? mb_substr((string) ($clienteMatriz['empresa'] ?? ''), 0, 120, 'UTF-8') : substr((string) ($clienteMatriz['empresa'] ?? ''), 0, 120),
    'portal'  => 1,
]);

$linhasExibicao = medicao_linhas_exibicao_mes($linhas, $impLinhas);
$totExibicao    = medicao_tot_resumo_com_import_bm($tot, $impLinhas);
$listagemSoBm   = medicao_listagem_so_import_bm($linhas, $impLinhas);

$impValorSoma = 0.0;
$impQtdSoma   = 0.0;
foreach ($impLinhas as $il) {
    $impValorSoma += (float) ($il['valor_medido_periodo'] ?? 0);
    $qtd = $il['qtd_medido_periodo'] ?? null;
    if ($qtd !== null && $qtd !== '') {
        $impQtdSoma += (float) $qtd;
    }
}

$periodoTxt = $periodoResolvido['label_curto'];
$mesLabel   = medicao_mes_label_pt($mesRef);

$bmPrimeiroDia   = $mesRef . '-01';
$bmPeriodoAteMax = medicao_bm_export_v2_periodo_ate($mesRef);
$bmPeriodoDeMin  = medicao_bm_export_v2_periodo_de_min($mesRef);
$bmPeriodoAteFmt = date('d/m/Y', strtotime($bmPeriodoAteMax));
$bmIdYm          = htmlspecialchars(preg_replace('/\W/', '_', $mesRef), ENT_QUOTES, 'UTF-8');
$bmFormId        = 'bm-export-ver';
$bmDateTitle     = 'Mês ' . $mesRef . ' · início mín. ' . date('d/m/Y', strtotime($bmPeriodoDeMin))
    . ' · fecho até ' . $bmPeriodoAteFmt;

$chExportCtxVer = [
    'medicao_mes'       => $mesRef,
    'periodo_de'        => $dataDe,
    'periodo_ate'       => $dataAte,
    'periodo_limpar'    => false,
    'cliente_id'        => null,
    'envolvido_user'    => null,
    'tecnico_user_id'   => null,
    'local_q'           => null,
];
$hrefChamados = 'chamados.php?' . http_build_query([
    'medicao_mes' => $mesRef,
    'periodo_de'  => $dataDe,
    'periodo_ate' => $dataAte,
]);
$hrefXlsxDet = adm_chamados_export_url('xlsx_detalhes', '', '', $chExportCtxVer);
$hrefPdfAnexos = adm_chamados_export_url('pdf_anexos', '', '', $chExportCtxVer);
if (defined('CRM_EXPORT_PDF_DEBUG') && CRM_EXPORT_PDF_DEBUG) {
    $hrefPdfAnexos .= (strpos($hrefPdfAnexos, '?') !== false ? '&' : '?') . 'pdf_debug=1';
}

function medicao_ver_fmt_qtd(float $valor): string
{
    $fmt = number_format($valor, 4, ',', '.');
    return rtrim(rtrim($fmt, '0'), ',');
}

$topTitle    = 'Ver medição · ' . $mesLabel;
$topSubtitle = ($clienteMatriz['empresa'] ?? '') . ' · ' . $periodoTxt;
$topSearch   = '';
$topAction   = ['label' => 'Medições mensais', 'href' => 'medicao.php', 'icon' => '←'];

include __DIR__ . '/../includes/head.php';
?>
<style>
  .medicao-view-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 20px;
  }

  .medicao-view-section {
    margin-bottom: 20px;
  }

  .medicao-view-table {
    max-height: 480px;
    overflow: auto;
  }

  @media (max-width: 1280px) {
    .medicao-view-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }

  @media (max-width: 640px) {
    .medicao-view-grid {
      grid-template-columns: 1fr;
    }
  }
</style>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-cliente.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <div class="card medicao-view-section">
    <div class="panel-head medicao-ver-panel-head">
      <div>
        <h4>Resumo da medição</h4>
        <span class="panel-sub">
          Mês <strong><?= htmlspecialchars($mesRef) ?></strong> · <?= htmlspecialchars($periodoTxt) ?>
          <?= $listagemSoBm ? ' · dados exibidos a partir da importação BM' : '' ?>
        </span>
      </div>
      <div class="medicao-export-toolbar medicao-export-toolbar--ver medicao-mes-row"
           id="medicao-ver-toolbar"
           data-medicao-mes="<?= htmlspecialchars($mesRef, ENT_QUOTES, 'UTF-8') ?>"
           data-periodo-de="<?= htmlspecialchars($dataDe, ENT_QUOTES, 'UTF-8') ?>"
           data-periodo-ate="<?= htmlspecialchars($dataAte, ENT_QUOTES, 'UTF-8') ?>">
        <form id="<?= htmlspecialchars($bmFormId, ENT_QUOTES, 'UTF-8') ?>" method="get" action="medicao_export_boletim_bm.php" class="medicao-bm-export-form-hidden" hidden aria-hidden="true">
          <input type="hidden" name="mes" value="<?= htmlspecialchars($mesRef, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="export" value="1">
          <input type="hidden" name="periodo_de" value="<?= htmlspecialchars($dataDe, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="periodo_ate" value="<?= htmlspecialchars($dataAte, ENT_QUOTES, 'UTF-8') ?>">
        </form>
        <?php
        $bmMes = $mesRef;
        $bmMostrarClienteHidden = false;
        $bmExportComLabels = true;
        require __DIR__ . '/../includes/partials/medicao_bm_export_acoes.php';
        ?>
      </div>
    </div>
    <div class="panel-body">
      <div class="medicao-view-grid">
        <div class="card metric">
          <div class="metric-top">
            <div>
              <div class="metric-label">Chamados / linhas</div>
              <div class="metric-value metric-value--compact"><?= (int) ($totExibicao['n_chamados'] ?? 0) ?></div>
            </div>
            <div class="icon-box purple">CH</div>
          </div>
          <div class="metric-change muted"><?= $listagemSoBm ? 'Linhas da BM importada' : 'Chamados abertos no mês' ?></div>
        </div>
        <div class="card metric">
          <div class="metric-top">
            <div>
              <div class="metric-label">Valor medido</div>
              <div class="metric-value metric-value--compact">R$ <?= number_format((float) ($totExibicao['valor_total'] ?? 0), 2, ',', '.') ?></div>
            </div>
            <div class="icon-box green">R$</div>
          </div>
          <div class="metric-change muted">Materiais + serviços medidos</div>
        </div>
        <div class="card metric">
          <div class="metric-top">
            <div>
              <div class="metric-label">Itens usados</div>
              <div class="metric-value metric-value--compact"><?= (int) ($itensTotais['n_itens_usados'] ?? 0) ?></div>
            </div>
            <div class="icon-box blue">US</div>
          </div>
          <div class="metric-change muted">Qtd <?= htmlspecialchars(medicao_ver_fmt_qtd((float) ($itensTotais['qtd_usada'] ?? 0))) ?> · R$ <?= number_format((float) ($itensTotais['valor_usado'] ?? 0), 2, ',', '.') ?></div>
        </div>
        <div class="card metric">
          <div class="metric-top">
            <div>
              <div class="metric-label">Itens devolvidos</div>
              <div class="metric-value metric-value--compact"><?= (int) ($itensTotais['n_itens_devolvidos'] ?? 0) ?></div>
            </div>
            <div class="icon-box red">DV</div>
          </div>
          <div class="metric-change muted">Qtd <?= htmlspecialchars(medicao_ver_fmt_qtd((float) ($itensTotais['qtd_devolvida'] ?? 0))) ?> · R$ <?= number_format((float) ($itensTotais['valor_devolvido'] ?? 0), 2, ',', '.') ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="card medicao-view-section">
    <div class="panel-head">
      <h4>Custos do mês</h4>
      <span class="panel-sub">Custo líquido considera usados menos devolvidos.</span>
    </div>
    <div class="panel-body">
      <div class="medicao-view-grid">
        <div>
          <div class="metric-label">Materiais aplicados</div>
          <div class="td-strong">R$ <?= number_format((float) ($totExibicao['valor_materiais'] ?? 0), 2, ',', '.') ?></div>
        </div>
        <div>
          <div class="metric-label">Serviços / itens</div>
          <div class="td-strong">R$ <?= number_format((float) ($totExibicao['valor_servicos'] ?? 0), 2, ',', '.') ?></div>
        </div>
        <div>
          <div class="metric-label">Devoluções registradas</div>
          <div class="td-strong">R$ <?= number_format((float) ($itensTotais['valor_devolvido'] ?? 0), 2, ',', '.') ?></div>
        </div>
        <div>
          <div class="metric-label">Custo líquido</div>
          <div class="td-strong">R$ <?= number_format((float) ($itensTotais['custo_liquido'] ?? 0), 2, ',', '.') ?></div>
        </div>
      </div>
    </div>
  </div>

  <?php if ($impCab): ?>
  <div class="card medicao-view-section">
    <div class="panel-head">
      <div>
        <h4>Importação BM</h4>
        <span class="panel-sub">
          <?= htmlspecialchars((string) ($impCab['nome_arquivo'] ?? '')) ?>
          · <?= htmlspecialchars((string) ($impCab['importado_em'] ?? '')) ?>
        </span>
      </div>
    </div>
    <div class="panel-body">
      <div class="medicao-view-grid">
        <div>
          <div class="metric-label">Linhas importadas</div>
          <div class="td-strong"><?= count($impLinhas) ?></div>
        </div>
        <div>
          <div class="metric-label">Qtd medida</div>
          <div class="td-strong"><?= htmlspecialchars(medicao_ver_fmt_qtd($impQtdSoma)) ?></div>
        </div>
        <div>
          <div class="metric-label">Valor importado</div>
          <div class="td-strong">R$ <?= number_format($impValorSoma, 2, ',', '.') ?></div>
        </div>
        <div>
          <div class="metric-label">Importado por</div>
          <div class="td-strong"><?= htmlspecialchars((string) ($impCab['importado_por'] ?? '—')) ?></div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="card medicao-view-section">
    <div class="panel-head">
      <h4>Itens usados e devolvidos</h4>
      <span class="panel-sub"><?= count($itensLinhas) ?> item(ns) agrupados por movimento</span>
    </div>
    <div class="panel-body" style="padding-top:0;">
      <div class="table-wrap medicao-view-table">
        <table>
          <thead>
            <tr>
              <th>Movimento</th>
              <th>Item</th>
              <th>Tipo</th>
              <th>Unid.</th>
              <th class="text-right">Qtd</th>
              <th class="text-right">Valor</th>
              <th class="text-right">Chamados</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($itensLinhas)): ?>
            <tr><td colspan="7" class="muted" style="padding:28px;text-align:center;">Nenhum item usado ou devolvido foi lançado nos chamados deste mês.</td></tr>
            <?php else: foreach ($itensLinhas as $item): ?>
            <?php $mov = (string) ($item['movimento'] ?? 'utilizado'); ?>
            <tr>
              <td><span class="badge <?= $mov === 'devolvido' ? 'info' : 'success' ?>"><?= $mov === 'devolvido' ? 'Devolvido' : 'Usado' ?></span></td>
              <td>
                <div class="td-title"><?= htmlspecialchars((string) ($item['item_nome'] ?? '')) ?></div>
                <?php if (!empty($item['item_codigo'])): ?>
                <small class="muted">Código: <?= htmlspecialchars((string) $item['item_codigo']) ?></small>
                <?php endif; ?>
              </td>
              <td class="td-mute"><?= htmlspecialchars((string) ($item['item_tipo'] ?? '')) ?></td>
              <td class="td-mute"><?= htmlspecialchars((string) ($item['unidade'] ?? '')) ?></td>
              <td class="text-right"><?= htmlspecialchars(medicao_ver_fmt_qtd((float) ($item['quantidade'] ?? 0))) ?></td>
              <td class="text-right"><strong>R$ <?= number_format((float) ($item['valor_total'] ?? 0), 2, ',', '.') ?></strong></td>
              <td class="text-right td-mute"><?= (int) ($item['n_chamados'] ?? 0) ?></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="panel-head">
      <h4>Listagem dos chamados</h4>
      <span class="panel-sub"><?= $listagemSoBm ? 'Sem chamados no CRM; exibindo linhas da BM.' : 'Chamados da matriz e unidades no período.' ?></span>
    </div>
    <div class="panel-body" style="padding-top:0;">
      <div class="table-wrap medicao-view-table">
        <table>
          <thead>
            <tr>
              <th>Data</th>
              <th>Chamado</th>
              <th>Título</th>
              <th>Status</th>
              <th class="text-right">Materiais</th>
              <th class="text-right">Serviços</th>
              <th class="text-right">Devolvidos</th>
              <th class="text-right">Custo líq.</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($linhasExibicao)): ?>
            <tr><td colspan="8" class="muted" style="padding:28px;text-align:center;">Nenhum chamado neste mês e sem importação BM para esta referência.</td></tr>
            <?php else: foreach ($linhasExibicao as $r): ?>
            <?php
              $valorLinha = (float) ($r['valor_total_linha'] ?? 0);
              $valorDev   = (float) ($r['valor_devolvidos'] ?? 0);
              $valorLiq   = $valorLinha - $valorDev;
            ?>
            <tr>
              <td class="td-mute"><?= htmlspecialchars((string) ($r['aberto_em_br'] ?? '')) ?></td>
              <td class="td-id">
                <?php if ((int) ($r['id'] ?? 0) > 0): ?>
                <a href="chamado_detalhe.php?id=<?= (int) $r['id'] ?>">#<?= (int) $r['id'] ?></a>
                <?php else: ?>
                <span class="td-mute"><?= htmlspecialchars((string) ($r['medicao_bm_item_codigo'] ?? 'BM')) ?></span>
                <?php endif; ?>
              </td>
              <td><div class="td-title"><?= htmlspecialchars((string) ($r['titulo'] ?? '')) ?></div></td>
              <td><span class="badge <?= status_class((string) ($r['status'] ?? '')) ?>"><?= htmlspecialchars((string) ($r['status'] ?? '')) ?></span></td>
              <td class="text-right">R$ <?= number_format((float) ($r['valor_materiais'] ?? 0), 2, ',', '.') ?></td>
              <td class="text-right">R$ <?= number_format((float) ($r['valor_servicos_itens'] ?? 0), 2, ',', '.') ?></td>
              <td class="text-right td-mute">R$ <?= number_format($valorDev, 2, ',', '.') ?></td>
              <td class="text-right"><strong>R$ <?= number_format($valorLiq, 2, ',', '.') ?></strong></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</section>

</main>
</div>

<script>
(function () {
  var toolbar = document.getElementById('medicao-ver-toolbar');
  if (!toolbar) return;

  function periodoFromToolbar() {
    var q = {};
    var de = toolbar.getAttribute('data-periodo-de') || '';
    var ate = toolbar.getAttribute('data-periodo-ate') || '';
    if (de) {
      q.periodo_de = de;
    }
    if (ate) {
      q.periodo_ate = ate;
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

  function hrefForLink(kind) {
    var mes = toolbar.getAttribute('data-medicao-mes') || '';
    var p = periodoFromToolbar();
    if (kind === 'chamados') {
      return appendQuery('chamados.php', Object.assign({ medicao_mes: mes }, p));
    }
    if (kind === 'xlsx_detalhes' || kind === 'pdf_anexos') {
      return appendQuery('chamados.php', Object.assign({ medicao_mes: mes, export: kind }, p));
    }
    return null;
  }

  toolbar.querySelectorAll('.js-medicao-periodo-link').forEach(function (a) {
    a.addEventListener('click', function (ev) {
      var kind = a.getAttribute('data-link-kind') || '';
      var href = hrefForLink(kind);
      if (!href) {
        return;
      }
      ev.preventDefault();
      if (kind === 'pdf_anexos') {
        window.open(href, '_blank', 'noopener,noreferrer');
        return;
      }
      window.location.href = href;
    });
  });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
