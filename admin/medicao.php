<?php
/**
 * Medição — listagem de medições mensais (ver mês, chamados e exportações).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/medicao_helpers.php';
require_once __DIR__ . '/../includes/chamados_list_urls.php';

$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('medicao');
require_once __DIR__ . '/../includes/audit_log.php';

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

audit_log_registar('medicao.acessar_lista', 'medicao', null, $clienteId > 0 ? $clienteId : null, [
    'empresa' => function_exists('mb_substr') ? mb_substr((string) ($clienteMatriz['empresa'] ?? ''), 0, 120, 'UTF-8') : substr((string) ($clienteMatriz['empresa'] ?? ''), 0, 120),
]);

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

<section class="content medicao-page">

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
        <table class="medicao-meses-table">
          <thead>
            <tr>
              <th>Mês</th>
              <th>Chamados</th>
              <th>Materiais</th>
              <th>Serv. itens</th>
              <th>Total</th>
              <th class="medicao-bm-col">Boletim BM</th>
              <th class="td-actions">Ações</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($mesesLista)): ?>
              <tr>
                <td colspan="7" class="muted" style="padding:28px 20px;text-align:left;">
                  Nenhum mês com chamados nem importação BM — importe a planilha ou registe chamados para ver meses aqui.
                </td>
              </tr>
            <?php else: foreach ($mesesLista as $row):
              $ym = (string) ($row['ym'] ?? '');
              $hrefVerMedicao = 'medicao_ver.php?' . http_build_query(['mes' => $ym]);
              $hrefChamados = 'chamados.php?' . http_build_query(array_filter([
                  'medicao_mes' => $ym,
                  'cliente_id' => $clienteId > 0 ? $clienteId : null,
              ], static fn ($v) => $v !== null && $v !== ''));
              $bmPrimeiroDia     = $ym . '-01';
              $bmPeriodoAte      = medicao_bm_export_v2_periodo_ate($ym);
              $bmPeriodoAteFmt   = date('d/m/Y', strtotime($bmPeriodoAte));
              $bmIdYm            = htmlspecialchars(preg_replace('/\W/', '_', $ym), ENT_QUOTES, 'UTF-8');
              $bmFormId          = 'bm-export-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $ym);
              $chExportCtx = [
                  'medicao_mes'       => $ym,
                  'periodo_de'        => '',
                  'periodo_ate'       => '',
                  'periodo_limpar'    => false,
                  'cliente_id'        => $clienteId > 0 ? $clienteId : null,
                  'envolvido_user'    => null,
                  'tecnico_user_id'   => null,
                  'local_q'           => null,
              ];
              $hrefXlsxDet = adm_chamados_export_url('xlsx_detalhes', '', '', $chExportCtx);
              $hrefPdfAnexos = adm_chamados_export_url('pdf_anexos', '', '', $chExportCtx);
              if (defined('CRM_EXPORT_PDF_DEBUG') && CRM_EXPORT_PDF_DEBUG) {
                  $hrefPdfAnexos .= (strpos($hrefPdfAnexos, '?') !== false ? '&' : '?') . 'pdf_debug=1';
              }
              ?>
              <tr id="bm-<?= htmlspecialchars(str_replace(['\\', '/'], '-', $ym), ENT_QUOTES, 'UTF-8') ?>">
                <td class="td-strong"><?= htmlspecialchars(medicao_mes_label_pt($ym)) ?></td>
                <td><?= (int) ($row['n_chamados'] ?? 0) ?></td>
                <td class="td-mute">R$ <?= number_format((float) ($row['valor_materiais'] ?? 0), 2, ',', '.') ?></td>
                <td class="td-mute">R$ <?= number_format((float) ($row['valor_servicos'] ?? 0), 2, ',', '.') ?></td>
                <td><strong>R$ <?= number_format((float) ($row['valor_total'] ?? 0), 2, ',', '.') ?></strong></td>
                <td class="medicao-bm-cell">
                  <form id="<?= htmlspecialchars($bmFormId, ENT_QUOTES, 'UTF-8') ?>" method="get" action="medicao_export_boletim_bm.php" class="medicao-bm-inline-form">
                    <input type="hidden" name="mes" value="<?= htmlspecialchars($ym) ?>">
                    <input type="hidden" name="export" value="1">
                    <?php if ($escopoEmpresa === null && $clienteId > 0): ?>
                      <input type="hidden" name="cliente_id" value="<?= (int) $clienteId ?>">
                    <?php endif; ?>
                    <div class="medicao-bm-inline-form__row">
                      <label class="sr-only" for="bm_periodo_<?= $bmIdYm ?>">Início do período medido no CRM (opcional)</label>
                      <input id="bm_periodo_<?= $bmIdYm ?>" type="date" name="periodo_de"
                             class="input medicao-bm-date"
                             value="<?= htmlspecialchars($bmPrimeiroDia) ?>"
                             title="Opcional: vazio = dia 1 de <?= htmlspecialchars($ym) ?>. Não posterior ao fecho (<?= htmlspecialchars($bmPeriodoAteFmt) ?>).">
                    </div>
                  </form>
                </td>
                <td class="td-actions">
                  <div class="actions-inline">
                    <a class="action-icon primary" href="<?= htmlspecialchars($hrefVerMedicao) ?>" title="Ver medição" aria-label="Ver medição">📊</a>
                    <a class="action-icon" href="<?= htmlspecialchars($hrefChamados) ?>" title="Chamados" aria-label="Chamados">💬</a>
                    <button type="submit" form="<?= htmlspecialchars($bmFormId, ENT_QUOTES, 'UTF-8') ?>" class="action-icon excel" title="Excel — boletim BM" aria-label="Excel — boletim BM">📄</button>
                    <a class="action-icon excel" href="<?= htmlspecialchars($hrefXlsxDet) ?>" title="Excel — com detalhes" aria-label="Excel — com detalhes">📋</a>
                    <a class="action-icon pdf" href="<?= htmlspecialchars($hrefPdfAnexos) ?>" title="PDF com anexos" aria-label="PDF com anexos">📎</a>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <p class="muted" style="margin:16px 20px 20px;font-size:13px;line-height:1.45;">
        Em <strong>Boletim BM</strong> escolha a data inicial (opcional) e use o ícone <strong>Excel — boletim BM</strong> (📄) para descarregar o consolidado do mês da linha. O ícone <strong>Excel — com detalhes</strong> (📋) e o PDF usam a listagem em Chamados.
      </p>
    </div>
  </div>
</section>

</main>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
