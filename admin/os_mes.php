<?php
/**
 * OS de um mês (ref_ym).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/medicao_helpers.php';

$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('os');

$pageTitle  = 'OS do mês';
$basePath   = '../';
$activePage = 'os';

$mesRaw = trim((string) ($_GET['mes'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $mesRaw)) {
    flash_set('err', 'Informe um mês válido (AAAA-MM).');
    header('Location: os.php');
    exit;
}

if (!db_ok()) {
    flash_set('err', 'Banco indisponível.');
    header('Location: index.php');
    exit;
}

$escopoEmpresa = gestao_scope_cliente_id($me);
if ($escopoEmpresa !== null) {
    $clienteId = $escopoEmpresa;
} else {
    $clienteId = (int) (repo_catalogo_cliente_id_padrao_admin() ?? 0);
    if ($clienteId <= 0) {
        $empresasFallback = repo_clientes_empresas();
        $clienteId = (int) (($empresasFallback[0]['id'] ?? 0));
    }
}

$clienteMatriz = $clienteId > 0 ? repo_cliente($clienteId) : null;
if (!$clienteMatriz) {
    flash_set('err', 'Empresa não encontrada.');
    header('Location: os.php');
    exit;
}
gestor_assert_escopo_cliente($clienteId, 'os.php');

$q = trim((string) ($_GET['q'] ?? ''));

$aba = (string) ($_GET['aba'] ?? 'todas');
if (!in_array($aba, ['todas', 'aprovadas', 'rejeitadas'], true)) {
    $aba = 'todas';
}
$statusLista = $aba === 'aprovadas' ? 'Aprovada' : ($aba === 'rejeitadas' ? 'Rejeitada' : null);

$resumo = repo_os_pedido_resumo_mes($clienteId, $mesRaw);
$linhas  = repo_os_pedido_list_empresa_mes($clienteId, $mesRaw, $q !== '' ? $q : null, $statusLista);

$qsMes = ['mes' => $mesRaw];
if ($q !== '') {
    $qsMes['q'] = $q;
}
$hrefAba = static function (string $slug) use ($qsMes): string {
    $p = $qsMes;
    if ($slug !== 'todas') {
        $p['aba'] = $slug;
    }

    return 'os_mes.php?' . http_build_query($p);
};

$topTitle    = 'OS — ' . medicao_mes_label_pt($mesRaw);
$topSubtitle = ($clienteMatriz['empresa'] ?? '');
$topSearch   = '';
$topAction   = ['label' => '← OS por mês', 'href' => 'os.php', 'icon' => '←'];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <div class="cards-metrics" style="margin-bottom:20px;">
    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">Total em valor (mês)</div>
          <div class="metric-value" style="font-size:1.35rem;">R$ <?= number_format((float) ($resumo['valor_total'] ?? 0), 2, ',', '.') ?></div>
        </div>
        <div class="icon-box purple">Σ</div>
      </div>
      <div class="metric-change info">Soma dos itens (utilizado) · <?= (int) ($resumo['n_total'] ?? 0) ?> OS no período</div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">OS aprovadas</div>
          <div class="metric-value"><?= (int) ($resumo['n_aprovada'] ?? 0) ?></div>
        </div>
        <div class="icon-box green">✓</div>
      </div>
      <div class="metric-change success">R$ <?= number_format((float) ($resumo['valor_aprovadas'] ?? 0), 2, ',', '.') ?> em itens aprovados</div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">OS reprovadas (rejeitadas)</div>
          <div class="metric-value"><?= (int) ($resumo['n_rejeitada'] ?? 0) ?></div>
        </div>
        <div class="icon-box red">✕</div>
      </div>
      <div class="metric-change warning">R$ <?= number_format((float) ($resumo['valor_rejeitadas'] ?? 0), 2, ',', '.') ?> · decisão do cliente</div>
    </div>
  </div>

  <nav class="admin-tabs card mb-24" aria-label="Filtro por situação" style="padding:8px 10px;">
    <a href="<?= htmlspecialchars($hrefAba('todas')) ?>" class="admin-tab<?= $aba === 'todas' ? ' active' : '' ?>">Todas</a>
    <a href="<?= htmlspecialchars($hrefAba('aprovadas')) ?>" class="admin-tab<?= $aba === 'aprovadas' ? ' active' : '' ?>">Aprovadas</a>
    <a href="<?= htmlspecialchars($hrefAba('rejeitadas')) ?>" class="admin-tab<?= $aba === 'rejeitadas' ? ' active' : '' ?>">Reprovadas</a>
  </nav>

  <div class="card">
    <div class="panel-head" style="flex-wrap:wrap;gap:10px;align-items:center;">
      <h4>Lista do mês</h4>
      <form method="get" class="form" style="display:flex;gap:8px;align-items:center;margin:0;flex:1;min-width:200px;justify-content:flex-end;">
        <input type="hidden" name="mes" value="<?= htmlspecialchars($mesRaw) ?>">
        <?php if ($aba !== 'todas'): ?>
          <input type="hidden" name="aba" value="<?= htmlspecialchars($aba) ?>">
        <?php endif; ?>
        <input type="search" name="q" class="input" placeholder="Buscar título ou órgão..." value="<?= htmlspecialchars($q) ?>" style="max-width:280px;">
        <button type="submit" class="btn btn-secondary btn-sm">Buscar</button>
      </form>
      <a href="os_novo.php?ref_ym=<?= rawurlencode($mesRaw) ?>" class="btn btn-primary btn-sm">Nova OS neste mês</a>
    </div>
    <div class="panel-body" style="padding-top:0;">
      <div class="table-wrap">
        <table data-crm-sortable>
          <thead>
            <tr class="crm-table-head-sort">
              <?php crm_sort_th('OS', 'os', ['type' => 'number']); ?>
              <?php crm_sort_th('Prefeitura', 'prefeitura'); ?>
              <?php crm_sort_th('Título', 'titulo'); ?>
              <?php crm_sort_th('Status', 'status'); ?>
              <?php crm_sort_th('Valor (itens)', 'valor', ['type' => 'number', 'right' => true]); ?>
              <?php crm_sort_th('Abertura', 'abertura', ['type' => 'date', 'right' => true]); ?>
              <?php crm_sort_th('Ações', null, ['class' => 'crm-table-col-acoes', 'right' => true]); ?>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($linhas)): ?>
              <tr><td colspan="7" class="muted" style="text-align:center;padding:24px;">
                <?php if ($aba === 'aprovadas'): ?>Nenhuma OS aprovada neste mês (com o filtro de busca atual).<?php elseif ($aba === 'rejeitadas'): ?>Nenhuma OS reprovada neste mês (com o filtro de busca atual).<?php else: ?>Nenhuma OS neste mês.<?php endif; ?>
              </td></tr>
            <?php else: foreach ($linhas as $o): ?>
              <?php
                $abSort = (string) ($o['aberto_em'] ?? '');
                $abIso  = $abSort !== '' ? date('Y-m-d H:i:s', strtotime($abSort)) : '';
              ?>
              <tr <?= crm_sort_row_attr([
                  'os'         => (string) (int) ($o['id'] ?? 0),
                  'prefeitura' => (string) ($o['cliente_empresa'] ?? ''),
                  'titulo'     => (string) ($o['titulo'] ?? ''),
                  'status'     => (string) ($o['status'] ?? ''),
                  'valor'      => (string) (float) ($o['valor_itens'] ?? 0),
                  'abertura'   => $abIso,
              ]) ?>>
                <td class="td-strong">#<?= (int) $o['id'] ?></td>
                <td><?= htmlspecialchars((string) ($o['cliente_empresa'] ?? '')) ?></td>
                <td>
                  <a href="os_detalhe.php?id=<?= (int) $o['id'] ?>"><?= htmlspecialchars((string) ($o['titulo'] ?? '')) ?></a>
                </td>
                <td><span class="badge <?= status_class($o['status'] ?? '') ?>"><?= htmlspecialchars((string) ($o['status'] ?? '')) ?></span></td>
                <td class="text-right td-mute">R$ <?= number_format((float) ($o['valor_itens'] ?? 0), 2, ',', '.') ?></td>
                <td class="text-right td-mute"><?= date('d/m/Y H:i', strtotime((string) ($o['aberto_em'] ?? ''))) ?></td>
                <td class="td-actions">
                  <a class="action primary" href="os_detalhe.php?id=<?= (int) $o['id'] ?>">Ver OS</a>
                </td>
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
