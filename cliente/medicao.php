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

<section class="content">

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
              <th class="td-actions">Ações</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($mesesLista)): ?>
              <tr>
                <td colspan="6" class="muted" style="padding:28px;text-align:center;">
                  Nenhum mês com chamados nem importação BM — quando houver medições ou importação, os meses aparecem aqui.
                </td>
              </tr>
            <?php else: foreach ($mesesLista as $row):
              $ym = (string) ($row['ym'] ?? '');
              $hrefVerMedicao = 'medicao_ver.php?' . http_build_query(['mes' => $ym]);
              $hrefChamados = 'chamados.php?' . http_build_query(['medicao_mes' => $ym]);
              $hrefBoletimBm = 'medicao_export_boletim_bm.php?' . http_build_query(['mes' => $ym]);
              $chExportCtx = [
                  'medicao_mes'       => $ym,
                  'periodo_de'        => '',
                  'periodo_ate'       => '',
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
              ?>
              <tr>
                <td class="td-strong"><?= htmlspecialchars(medicao_mes_label_pt($ym)) ?></td>
                <td class="text-right"><?= (int) ($row['n_chamados'] ?? 0) ?></td>
                <td class="text-right td-mute">R$ <?= number_format((float) ($row['valor_materiais'] ?? 0), 2, ',', '.') ?></td>
                <td class="text-right td-mute">R$ <?= number_format((float) ($row['valor_servicos'] ?? 0), 2, ',', '.') ?></td>
                <td class="text-right"><strong>R$ <?= number_format((float) ($row['valor_total'] ?? 0), 2, ',', '.') ?></strong></td>
                <td class="td-actions">
                  <div class="actions-inline">
                    <a class="action-icon primary" href="<?= htmlspecialchars($hrefVerMedicao) ?>" title="Ver medição" aria-label="Ver medição">📊</a>
                    <a class="action-icon" href="<?= htmlspecialchars($hrefChamados) ?>" title="Chamados" aria-label="Chamados">💬</a>
                    <a class="action-icon excel" href="<?= htmlspecialchars($hrefBoletimBm) ?>" title="Excel — boletim BM" aria-label="Excel — boletim BM">📄</a>
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
        O ícone <strong>Excel — boletim BM</strong> gera a planilha no layout do boletim de medição (modelo BM), com as quantidades do catálogo lançadas nos chamados do mês.
        Os ícones <strong>Excel — com detalhes</strong> e <strong>PDF com anexos</strong> usam a exportação da listagem em Chamados.
      </p>
    </div>
  </div>
</section>

</main>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
