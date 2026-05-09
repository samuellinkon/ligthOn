<?php
/**
 * Medição — listagem de medições mensais (atalhos para planilha, boletim e chamados).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/medicao_helpers.php';

$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('medicao');

$pageTitle  = 'Medição';
$basePath   = '../';
$activePage = 'medicao';

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
gestor_assert_escopo_cliente($clienteId, 'medicao.php');

$mesesLista = repo_medicao_resumo_mensal_list($clienteId, 60);

$topTitle    = 'Medição';
$topSubtitle = 'Medições mensais';
$topSearch   = '';
$topAction   = null;

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">

  <div class="card">
    <div class="panel-head">
      <div>
        <h4>Medições mensais</h4>
        <span class="panel-sub">Chamados por mês de abertura e meses com <strong>importação BM</strong> (mesmo sem chamados no período)</span>
      </div>
      <a href="medicao_importar.php" class="btn btn-secondary btn-sm">Importar planilha BM</a>
    </div>
    <div class="panel-body" style="padding-top:0;">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Mês</th>
              <th class="text-right">Chamados</th>
              <th class="text-right">Materiais</th>
              <th class="text-right">Serv. itens</th>
              <th class="text-right">Total</th>
              <th class="text-right td-actions">Ações</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($mesesLista)): ?>
              <tr>
                <td colspan="6" class="muted" style="padding:28px;text-align:center;">
                  Nenhum mês com chamados nem importação BM — importe a planilha ou registe chamados para ver meses aqui.
                </td>
              </tr>
            <?php else: foreach ($mesesLista as $row):
              $ym = (string) ($row['ym'] ?? '');
              $hrefExportXlsx = 'medicao_ver.php?' . http_build_query(['mes' => $ym, 'export' => 'relatorio_xlsx']);
              $hrefVerMedicao = 'medicao_ver.php?' . http_build_query(['mes' => $ym]);
              $hrefChamados = 'chamados.php?' . http_build_query(['medicao_mes' => $ym]);
              ?>
              <tr>
                <td class="td-strong"><?= htmlspecialchars(medicao_mes_label_pt($ym)) ?></td>
                <td class="text-right"><?= (int) ($row['n_chamados'] ?? 0) ?></td>
                <td class="text-right td-mute">R$ <?= number_format((float) ($row['valor_materiais'] ?? 0), 2, ',', '.') ?></td>
                <td class="text-right td-mute">R$ <?= number_format((float) ($row['valor_servicos'] ?? 0), 2, ',', '.') ?></td>
                <td class="text-right"><strong>R$ <?= number_format((float) ($row['valor_total'] ?? 0), 2, ',', '.') ?></strong></td>
                <td class="td-actions">
                  <a class="action primary" href="<?= htmlspecialchars($hrefVerMedicao) ?>">Ver medição</a>
                  <a class="action primary" href="<?= htmlspecialchars($hrefExportXlsx) ?>" target="_blank" rel="noopener">Exportar</a>
                  <a class="action" href="<?= htmlspecialchars($hrefChamados) ?>">Chamados</a>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <p class="muted" style="margin:16px 20px 20px;font-size:13px;line-height:1.45;">
        <strong>Exportar</strong> baixa o relatório detalhado do mês no layout da planilha.
        <strong>Chamados</strong> lista os chamados daquele mês.
      </p>
    </div>
  </div>
</section>

</main>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
