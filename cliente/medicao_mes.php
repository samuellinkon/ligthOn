<?php
/**
 * Medição — detalhe de um mês (portal cliente).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/medicao_helpers.php';

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

$contratoRef = trim((string) ($_GET['contrato'] ?? ''));

$rel    = repo_medicao_chamados_relatorio($clienteId, $dataDe, $dataAte);
$linhas = $rel['rows'];
$tot    = $rel['totais'];

$impPkg    = repo_medicao_import_fetch($clienteId, $mesRef);
$impLinhas = $impPkg['linhas'] ?? [];

$linhasExibicao = medicao_linhas_exibicao_mes($linhas, $impLinhas);
$totExibicao    = medicao_tot_resumo_com_import_bm($tot, $impLinhas);
$listagemSoBm   = medicao_listagem_so_import_bm($linhas, $impLinhas);

$periodoTxt   = $periodoResolvido['label_curto'];
$contratoDisp = $contratoRef !== '' ? $contratoRef : '—';
$mesLabel     = medicao_mes_label_pt($mesRef);

if (($_GET['export'] ?? '') === 'planilha') {
    require_once __DIR__ . '/../includes/medicao_export.php';
    audit_log_registar('medicao.exportar_planilha_csv', 'medicao', null, $clienteId > 0 ? $clienteId : null, [
        'ref_ym'    => $mesRef,
        'contrato'  => $contratoDisp,
        'n_linhas'  => count($linhasExibicao),
        'portal'    => 1,
    ]);
    medicao_export_planilha_csv(
        $clienteMatriz,
        $contratoDisp,
        $periodoTxt,
        $totExibicao,
        $linhasExibicao,
        $dataDe,
        $dataAte
    );
    exit;
}

audit_log_registar('medicao.acessar_mes', 'medicao', null, $clienteId > 0 ? $clienteId : null, [
    'ref_ym'  => $mesRef,
    'empresa' => function_exists('mb_substr') ? mb_substr((string) ($clienteMatriz['empresa'] ?? ''), 0, 120, 'UTF-8') : substr((string) ($clienteMatriz['empresa'] ?? ''), 0, 120),
    'portal'  => 1,
]);

$medicaoFiltrosQs = [
    'mes'         => $mesRef,
    'periodo_de'  => $dataDe,
    'periodo_ate' => $dataAte,
    'contrato'    => $contratoRef,
];
$medicaoExportPlanilhaHref = 'medicao_mes.php?' . http_build_query(array_merge($medicaoFiltrosQs, ['export' => 'planilha']));

$topTitle    = 'Medição · ' . $mesLabel;
$topSubtitle = $periodoTxt;
$topSearch   = '';
$topAction   = ['label' => '← Medições mensais', 'href' => 'medicao.php', 'icon' => '←'];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-cliente.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">

  <div class="card" style="margin-bottom:20px;">
    <div class="panel-head">
      <div>
        <h4>Referência do mês</h4>
        <span class="panel-sub">Mês fixo <strong><?= htmlspecialchars($mesRef) ?></strong> · contrato opcional na planilha</span>
      </div>
      <a href="<?= htmlspecialchars($medicaoExportPlanilhaHref) ?>" class="btn btn-secondary btn-sm">↓ Exportar planilha (CSV)</a>
    </div>
    <form method="get" action="medicao_mes.php">
      <input type="hidden" name="mes" value="<?= htmlspecialchars($mesRef) ?>">
      <div class="panel-body">
        <div class="form form-grid" style="grid-template-columns:minmax(0,1fr);max-width:28rem;">
          <div class="form-group">
            <label for="contrato">Contrato / referência</label>
            <input type="text" id="contrato" name="contrato" class="input" value="<?= htmlspecialchars($contratoRef) ?>" placeholder="Nº contrato ou PMI">
          </div>
        </div>
      </div>
      <div class="form-actions between">
        <span class="muted" style="font-size:13px;max-width:min(28rem,100%);line-height:1.45;">Período: <strong><?= htmlspecialchars($periodoTxt) ?></strong> · CSV com separador <strong>;</strong></span>
        <button type="submit" class="btn btn-primary">Atualizar</button>
      </div>
    </form>
  </div>

  <div class="card" style="margin-bottom:20px;">
    <div class="panel-head">
      <h4>Resumo do período</h4>
      <span class="panel-sub"><?= $listagemSoBm
        ? 'Valores da importação BM (sem chamados no CRM neste mês civil).'
        : 'Valores consolidados' ?></span>
    </div>
    <div class="panel-body">
      <div class="grid-2" style="margin-bottom:20px;">
        <div>
          <div class="metric-label">Prefeitura / contratante</div>
          <div class="td-strong"><?= htmlspecialchars((string) ($clienteMatriz['empresa'] ?? '')) ?></div>
        </div>
        <div>
          <div class="metric-label">Contrato / referência</div>
          <div class="td-strong"><?= htmlspecialchars($contratoDisp) ?></div>
        </div>
        <div>
          <div class="metric-label">Período de medição</div>
          <div class="td-strong"><?= htmlspecialchars($periodoTxt) ?></div>
        </div>
      </div>

      <div class="cards-metrics" style="grid-template-columns: repeat(3, 1fr);">
        <div class="card metric">
          <div class="metric-top">
            <div>
              <div class="metric-label">Valor materiais aplicados</div>
              <div class="metric-value metric-value--compact">R$ <?= number_format($totExibicao['valor_materiais'], 2, ',', '.') ?></div>
            </div>
            <div class="icon-box blue">M</div>
          </div>
          <div class="metric-change muted">Itens tipo produto (utilizado)</div>
        </div>
        <div class="card metric">
          <div class="metric-top">
            <div>
              <div class="metric-label">Valor serviços (itens)</div>
              <div class="metric-value metric-value--compact">R$ <?= number_format($totExibicao['valor_servicos'], 2, ',', '.') ?></div>
            </div>
            <div class="icon-box green">S</div>
          </div>
          <div class="metric-change muted">Itens tipo serviço em chamado_itens</div>
        </div>
        <div class="card metric">
          <div class="metric-top">
            <div>
              <div class="metric-label">Valor total (período)</div>
              <div class="metric-value metric-value--compact">R$ <?= number_format($totExibicao['valor_total'], 2, ',', '.') ?></div>
            </div>
            <div class="icon-box purple">Σ</div>
          </div>
          <div class="metric-change"><?= (int) $totExibicao['n_chamados'] ?> <?= $listagemSoBm ? 'linha(s) BM' : 'chamado(s)' ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="panel-head">
      <h4>Chamados no período</h4>
      <span class="panel-sub"><?= $listagemSoBm
        ? 'Uma linha por item da planilha BM importada (não são chamados no CRM).'
        : 'Matriz e unidades' ?></span>
    </div>
    <div class="panel-body" style="padding-top:0;">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Data</th>
              <th>Chamado</th>
              <th>Unidade</th>
              <th>Título</th>
              <th>Status</th>
              <th>Prioridade</th>
              <th>Técnico</th>
              <th>Serviço (cat.)</th>
              <th class="text-right">Materiais</th>
              <th class="text-right">Serv. itens</th>
              <th class="text-right">Total</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($linhasExibicao)): ?>
              <tr><td colspan="11" class="muted" style="padding:28px;text-align:center;">Nenhum chamado neste mês e sem importação BM para este mês.</td></tr>
            <?php else: foreach ($linhasExibicao as $r): ?>
              <tr>
                <td class="td-mute"><?= htmlspecialchars((string) ($r['aberto_em_br'] ?? '')) ?></td>
                <td class="td-id"><?php if ((int) ($r['id'] ?? 0) > 0): ?>
                  <a href="chamado_detalhe.php?id=<?= (int) $r['id'] ?>">#<?= (int) $r['id'] ?></a>
                <?php else: ?>
                  <span class="td-mute" title="Item da planilha BM"><?= htmlspecialchars((string) ($r['medicao_bm_item_codigo'] ?? 'BM')) ?></span>
                <?php endif; ?></td>
                <td><?= htmlspecialchars((string) ($r['unidade_nome'] ?? '')) ?></td>
                <td><div class="td-title"><?= htmlspecialchars((string) ($r['titulo'] ?? '')) ?></div></td>
                <td><span class="badge <?= status_class($r['status'] ?? '') ?>"><?= htmlspecialchars((string) ($r['status'] ?? '')) ?></span></td>
                <td><span class="badge <?= status_class($r['prioridade'] ?? '') ?>"><?= htmlspecialchars((string) ($r['prioridade'] ?? '')) ?></span></td>
                <td class="td-mute"><?= htmlspecialchars((string) ($r['tecnico_nome'] ?? '—')) ?></td>
                <td class="td-mute" style="max-width:220px;font-size:13px;"><?= htmlspecialchars((string) ($r['servico_principal_nome'] ?? '—')) ?></td>
                <td class="text-right">R$ <?= number_format((float) ($r['valor_materiais'] ?? 0), 2, ',', '.') ?></td>
                <td class="text-right">R$ <?= number_format((float) ($r['valor_servicos_itens'] ?? 0), 2, ',', '.') ?></td>
                <td class="text-right"><strong>R$ <?= number_format((float) ($r['valor_total_linha'] ?? 0), 2, ',', '.') ?></strong></td>
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

<?php include __DIR__ . '/../includes/footer.php'; ?>
