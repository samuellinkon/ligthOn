<?php
require_once __DIR__ . '/../includes/auth.php';
require_auth('admin');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('relatorio_financeiro');

/**
 * @param array<string,mixed> $row
 */
function rf_tipo_label(array $row): string
{
    $t = (string) ($row['tipo_cobranca'] ?? 'mensalidade');

    switch ($t) {
        case 'setup':
            return 'Setup';
        case 'mensalidade':
            return 'Mensalidade';
        case 'os':
            return 'OS';
        default:
            return $t !== '' ? $t : '—';
    }
}

$mesRef = trim((string) ($_GET['mes'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $mesRef)) {
    $mesRef = date('Y-m');
}
$clienteFiltro = max(0, (int) ($_GET['cliente_id'] ?? 0));
$cidParam     = $clienteFiltro > 0 ? $clienteFiltro : null;

$contasDet = repo_relatorio_financeiro_contas_linhas($mesRef, $cidParam);
$rep       = repo_relatorio_financeiro_mes($mesRef, $cidParam);
$linhas    = $rep['linhas'];
$tot       = $rep['totais'];
$inicio    = $rep['inicio'];
$fim       = $rep['fim'];

if (($_GET['export'] ?? '') === 'csv') {
    $fn = 'relatorio_financeiro_' . $mesRef . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Resumo — Prefeitura', 'Tipo de cobrança', 'Status', 'Quantidade', 'Valor (R$)', 'Período vencimento'], ';');
    foreach ($linhas as $r) {
        fputcsv($out, [
            (string) ($r['cliente'] ?? ''),
            rf_tipo_label($r),
            (string) ($r['status'] ?? ''),
            (string) ($r['qtd'] ?? 0),
            number_format((float) ($r['valor'] ?? 0), 2, ',', '.'),
            $inicio . ' a ' . $fim,
        ], ';');
    }
    fputcsv($out, ['TOTAL GERAL', '', '', (string) ($tot['qtd'] ?? 0), number_format((float) ($tot['total'] ?? 0), 2, ',', '.'), ''], ';');
    fputcsv($out, ['Realizado (Pago)', '', '', '', number_format((float) ($tot['realizado'] ?? 0), 2, ',', '.'), ''], ';');
    fputcsv($out, ['Previsto (Pendente + Vencido)', '', '', '', number_format((float) ($tot['previsto'] ?? 0), 2, ',', '.'), ''], ';');
    fputcsv($out, [], ';');
    fputcsv($out, ['Detalhe — cobranças no período'], ';');
    fputcsv($out, ['#', 'Prefeitura', 'Descrição', 'Tipo', 'OS', 'Vencimento', 'Status', 'Valor (R$)'], ';');
    foreach ($contasDet as $c) {
        fputcsv($out, [
            (string) ($c['id'] ?? ''),
            (string) ($c['cliente'] ?? ''),
            (string) ($c['descricao'] ?? ''),
            rf_tipo_label($c),
            isset($c['os_id']) && (int) $c['os_id'] > 0 ? (string) (int) $c['os_id'] : '',
            (string) ($c['vencimento'] ?? ''),
            (string) ($c['status'] ?? ''),
            number_format((float) ($c['valor'] ?? 0), 2, ',', '.'),
        ], ';');
    }
    fclose($out);
    exit;
}

$pageTitle  = 'Relatório financeiro';
$basePath   = '../';
$activePage = 'relatorio_financeiro';

$clientesOpts = repo_clientes();
if ($clientesOpts === [] && isset($MOCK_CLIENTES) && is_array($MOCK_CLIENTES)) {
    $clientesOpts = $MOCK_CLIENTES;
}

$qsBase = ['mes' => $mesRef];
if ($clienteFiltro > 0) {
    $qsBase['cliente_id'] = $clienteFiltro;
}
$csvHref = 'relatorio_financeiro.php?' . http_build_query(array_merge($qsBase, ['export' => 'csv']));

$topTitle    = 'Relatório financeiro';
$topSubtitle = 'Cobranças no mês (vencimento) · consolidado e detalhe por conta';
$topSearch   = 'Filtrar por mês de referência e cliente...';
$topAction   = ['label' => 'Exportar CSV', 'href' => $csvHref, 'icon' => '↓'];

$totalDetalheValor = 0.0;
foreach ($contasDet as $cd) {
    $totalDetalheValor += (float) ($cd['valor'] ?? 0);
}

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">

  <div class="cards-metrics">
    <div class="card metric">
      <div class="metric-top">
        <div><div class="metric-label">Total no período</div><div class="metric-value"><?= brl((float) $tot['total']) ?></div></div>
        <div class="icon-box blue">Σ</div>
      </div>
      <div class="metric-change info"><?= (int) $tot['qtd'] ?> cobrança(s) com venc. no mês</div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div><div class="metric-label">Realizado</div><div class="metric-value"><?= brl((float) $tot['realizado']) ?></div></div>
        <div class="icon-box green">OK</div>
      </div>
      <div class="metric-change success">Status Pago (vencimento neste mês)</div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div><div class="metric-label">Previsto no mês</div><div class="metric-value"><?= brl((float) $tot['previsto']) ?></div></div>
        <div class="icon-box purple">≈</div>
      </div>
      <div class="metric-change warning">Pendente + Vencido (ainda a receber)</div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">Detalhe status</div>
          <div class="metric-value metric-value--compact">
            <span class="muted">Pago</span> <?= brl((float) $tot['pago']) ?><br>
            <span class="muted">Pend.</span> <?= brl((float) $tot['pendente']) ?> · <span class="muted">Venc.</span> <?= brl((float) $tot['vencido']) ?>
          </div>
        </div>
        <div class="icon-box neutral">≡</div>
      </div>
      <div class="metric-change info">Referência: <?= htmlspecialchars($inicio) ?> — <?= htmlspecialchars($fim) ?></div>
    </div>
  </div>

  <div class="card">
    <div class="panel-head">
      <h4>Filtros</h4>
      <a href="<?= htmlspecialchars($csvHref) ?>" class="btn btn-primary btn-sm">↓ Exportar CSV</a>
    </div>

    <form method="get" action="relatorio_financeiro.php" class="filters filters--form">
      <div class="form-group form-group--mes">
        <label for="mes">Mês (vencimento)</label>
        <input type="month" id="mes" name="mes" class="input" value="<?= htmlspecialchars($mesRef) ?>" required>
      </div>
      <div class="form-group form-group--grow">
        <label for="cliente_id">Prefeitura / cadastro</label>
        <select id="cliente_id" name="cliente_id" class="select">
          <option value="0">Todos os cadastros</option>
          <?php foreach ($clientesOpts as $cli): ?>
            <option value="<?= (int) $cli['id'] ?>"<?= $clienteFiltro === (int) $cli['id'] ? ' selected' : '' ?>>
              <?= htmlspecialchars((string) ($cli['empresa'] ?? $cli['nome'] ?? ('#' . $cli['id']))) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filters-form-actions">
        <button type="submit" class="btn btn-primary">Aplicar</button>
        <a href="relatorio_financeiro.php" class="btn btn-secondary">Limpar</a>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="panel-head">
      <h4>Cobranças no período</h4>
      <span class="panel-sub">Cada linha é uma conta vinculada ao cliente — descrição e valores do que foi lançado</span>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Prefeitura</th>
            <th>Descrição / serviço</th>
            <th>Tipo</th>
            <th>OS</th>
            <th>Vencimento</th>
            <th>Status</th>
            <th class="text-right">Valor</th>
            <th class="text-right">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($contasDet) === 0): ?>
          <tr>
            <td colspan="9" class="muted" style="padding:24px; text-align:center;">Nenhuma cobrança com vencimento neste mês<?= $clienteFiltro > 0 ? ' para o cliente selecionado' : '' ?>.</td>
          </tr>
          <?php else: ?>
          <?php foreach ($contasDet as $c): ?>
          <?php $oid = isset($c['os_id']) ? (int) $c['os_id'] : 0; ?>
          <tr>
            <td class="td-id">
              <a href="conta_detalhe.php?id=<?= (int) $c['id'] ?>">#<?= (int) $c['id'] ?></a>
            </td>
            <td>
              <a href="cliente_detalhe.php?id=<?= (int) $c['cliente_id'] ?>" style="color:inherit;text-decoration:none;">
                <div class="cell-client">
                  <div class="avatar avatar-sm"><?= initials((string) ($c['cliente'] ?? '')) ?></div>
                  <strong><?= htmlspecialchars((string) ($c['cliente'] ?? '')) ?></strong>
                </div>
              </a>
            </td>
            <td class="td-mute"><?= htmlspecialchars((string) ($c['descricao'] ?? '—')) ?></td>
            <td><?= htmlspecialchars(rf_tipo_label($c)) ?></td>
            <td class="td-mute">
              <?= $oid > 0 ? '#' . $oid : '—' ?>
            </td>
            <td class="td-mute"><?= htmlspecialchars(date('d/m/Y', strtotime((string) ($c['vencimento'] ?? '')))) ?></td>
            <td><span class="badge <?= status_class((string) ($c['status'] ?? '')) ?>"><?= htmlspecialchars((string) ($c['status'] ?? '')) ?></span></td>
            <td class="text-right"><strong><?= brl((float) ($c['valor'] ?? 0)) ?></strong></td>
            <td class="td-actions text-right">
              <a class="action primary" href="conta_detalhe.php?id=<?= (int) $c['id'] ?>">Abrir conta</a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
        <?php if (count($contasDet) > 0): ?>
        <tfoot>
          <tr>
            <th colspan="7">Total (<?= count($contasDet) ?> cobrança<?= count($contasDet) !== 1 ? 's' : '' ?>)</th>
            <th class="text-right"><?= brl($totalDetalheValor) ?></th>
            <th></th>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="panel-head">
      <h4>Consolidado por cliente, tipo e status</h4>
      <span class="panel-sub">Mesmos valores agrupados para visão gerencial</span>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Prefeitura</th>
            <th>Tipo</th>
            <th>Status</th>
            <th>Qtd.</th>
            <th class="text-right">Valor</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($linhas) === 0): ?>
          <tr>
            <td colspan="5" class="muted" style="padding:24px; text-align:center;">Nenhum agrupamento neste período<?= $clienteFiltro > 0 ? ' para o cliente selecionado' : '' ?>.</td>
          </tr>
          <?php else: ?>
          <?php foreach ($linhas as $r): ?>
          <tr>
            <td>
              <a href="cliente_detalhe.php?id=<?= (int) ($r['cliente_id'] ?? 0) ?>" style="color:inherit;text-decoration:none;">
                <div class="cell-client">
                  <div class="avatar avatar-sm"><?= initials((string) ($r['cliente'] ?? '')) ?></div>
                  <strong><?= htmlspecialchars((string) ($r['cliente'] ?? '')) ?></strong>
                </div>
              </a>
            </td>
            <td><?= htmlspecialchars(rf_tipo_label($r)) ?></td>
            <td><span class="badge <?= status_class((string) ($r['status'] ?? '')) ?>"><?= htmlspecialchars((string) ($r['status'] ?? '')) ?></span></td>
            <td class="td-mute"><?= (int) ($r['qtd'] ?? 0) ?></td>
            <td class="text-right"><strong><?= brl((float) ($r['valor'] ?? 0)) ?></strong></td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
        <?php if (count($linhas) > 0): ?>
        <tfoot>
          <tr>
            <th colspan="3">Totais</th>
            <th><?= (int) $tot['qtd'] ?></th>
            <th class="text-right"><?= brl((float) $tot['total']) ?></th>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
