<?php
/**
 * OS — resumo por mês (mês de referência automático em novas ordens).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/medicao_helpers.php';

$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('os');

$pageTitle  = 'Ordem de serviço';
$basePath   = '../';
$activePage = 'os';

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

if ($clienteId <= 0) {
    flash_set('err', 'Defina a empresa padrão do catálogo em Configurações (super admin) ou cadastre uma empresa raiz.');
    $redir = (($me['perfil'] ?? '') === 'gestor') ? 'clientes.php' : 'configuracoes.php';
    header('Location: ' . $redir);
    exit;
}

$clienteMatriz = repo_cliente($clienteId);
if (!$clienteMatriz) {
    flash_set('err', 'Empresa não encontrada.');
    header('Location: clientes.php');
    exit;
}
gestor_assert_escopo_cliente($clienteId, 'os.php');

$mesesLista = repo_os_pedido_meses_resumo($clienteId, 60);

$refYmAtual     = date('Y-m');
$resumoMesAtual = repo_os_pedido_resumo_mes($clienteId, $refYmAtual);
$osAcumQtd      = 0;
$osAcumValor    = 0.0;
foreach ($mesesLista as $row) {
    $osAcumQtd += (int) ($row['n_os'] ?? 0);
    $osAcumValor += (float) ($row['valor_total'] ?? 0);
}
$hrefMesAtual = 'os_mes.php?' . http_build_query(['mes' => $refYmAtual]);

$topTitle    = 'Ordem de serviço';
$topSubtitle = 'Por mês de referência';
$topSearch   = '';
$topAction   = ['label' => 'Nova OS', 'href' => 'os_novo.php', 'icon' => '+'];

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
          <div class="metric-label">Total em valor (mês atual)</div>
          <div class="metric-value" style="font-size:1.35rem;">R$ <?= number_format((float) ($resumoMesAtual['valor_total'] ?? 0), 2, ',', '.') ?></div>
        </div>
        <div class="icon-box purple">Σ</div>
      </div>
      <div class="metric-change info"><?= htmlspecialchars(medicao_mes_label_pt($refYmAtual)) ?> · <?= (int) ($resumoMesAtual['n_total'] ?? 0) ?> OS · <a href="<?= htmlspecialchars($hrefMesAtual) ?>">Abrir mês</a></div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">OS aprovadas (mês atual)</div>
          <div class="metric-value"><?= (int) ($resumoMesAtual['n_aprovada'] ?? 0) ?></div>
        </div>
        <div class="icon-box green">✓</div>
      </div>
      <div class="metric-change success">R$ <?= number_format((float) ($resumoMesAtual['valor_aprovadas'] ?? 0), 2, ',', '.') ?> em itens</div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">OS reprovadas (mês atual)</div>
          <div class="metric-value"><?= (int) ($resumoMesAtual['n_rejeitada'] ?? 0) ?></div>
        </div>
        <div class="icon-box red">✕</div>
      </div>
      <div class="metric-change warning">R$ <?= number_format((float) ($resumoMesAtual['valor_rejeitadas'] ?? 0), 2, ',', '.') ?> · cliente</div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">Acumulado (meses listados)</div>
          <div class="metric-value"><?= $osAcumQtd ?></div>
        </div>
        <div class="icon-box" style="background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;font-weight:800;">∑</div>
      </div>
      <div class="metric-change info">R$ <?= number_format($osAcumValor, 2, ',', '.') ?> em itens · até <?= count($mesesLista) ?> referência(s) de mês</div>
    </div>
  </div>

  <div class="card">
    <div class="panel-head" style="flex-wrap:wrap;gap:10px;">
      <div>
        <h4>Ordens de serviço por mês</h4>
        <span class="panel-sub">Cada OS é ligada a um mês (AAAA-MM), como na medição. O cliente aprova ou rejeita após o envio — sem atribuição a operador.</span>
      </div>
      <a href="os_novo.php" class="btn btn-primary btn-sm">Abrir OS</a>
    </div>
    <div class="panel-body" style="padding-top:0;">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Mês</th>
              <th class="text-right">Qtd. OS</th>
              <th class="text-right">Valor (itens)</th>
              <th class="text-right td-actions">Ações</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($mesesLista)): ?>
              <tr>
                <td colspan="4" class="muted" style="padding:28px;text-align:center;">
                  Nenhuma OS registrada. Use <strong>Nova OS</strong> — o mês de referência vem preenchido com o mês atual (pode alterar).
                </td>
              </tr>
            <?php else: foreach ($mesesLista as $row):
              $ym = (string) ($row['ym'] ?? '');
              $hrefMes = 'os_mes.php?' . http_build_query(['mes' => $ym]);
              ?>
              <tr>
                <td class="td-strong"><?= htmlspecialchars(medicao_mes_label_pt($ym)) ?></td>
                <td class="text-right"><?= (int) ($row['n_os'] ?? 0) ?></td>
                <td class="text-right"><strong>R$ <?= number_format((float) ($row['valor_total'] ?? 0), 2, ',', '.') ?></strong></td>
                <td class="td-actions">
                  <a class="action primary" href="<?= htmlspecialchars($hrefMes) ?>">Ver mês</a>
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
