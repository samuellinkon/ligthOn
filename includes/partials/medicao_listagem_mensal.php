<?php
/**
 * Listagem mensal de medição (admin/gestor e portal cliente).
 * Requer: $mesesLista, $clienteId, $escopoEmpresa (admin multi-empresa), $medicaoMostrarImportar (bool).
 */
$medicaoMostrarImportar = (bool) ($medicaoMostrarImportar ?? false);
?>
  <div class="card">
    <div class="panel-head">
      <div>
        <h4>Medições mensais</h4>
        <span class="panel-sub">Chamados por mês de abertura e meses com <strong>importação BM</strong> (mesmo sem chamados no período)</span>
      </div>
      <?php if ($medicaoMostrarImportar): ?>
      <a href="medicao_importar.php" class="btn btn-secondary btn-sm">Importar planilha BM</a>
      <?php endif; ?>
    </div>
    <div class="panel-body" style="padding-top:0;">
      <div class="table-wrap">
        <table class="medicao-meses-table">
          <thead>
            <tr>
              <th>Mês</th>
              <th>Chamados</th>
              <th>Total</th>
              <th class="medicao-data-col">Data início</th>
              <th class="medicao-data-col">Data fim</th>
              <th class="td-actions">Ações</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($mesesLista)): ?>
              <tr>
                <td colspan="6" class="muted" style="padding:28px 20px;text-align:left;">
                  Nenhum mês com chamados nem importação BM — importe a planilha ou registe chamados para ver meses aqui.
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
              $chExportCtx = [
                  'medicao_mes'       => $ym,
                  'periodo_de'        => $periodoResolvido['de'],
                  'periodo_ate'       => $periodoResolvido['ate'],
                  'periodo_limpar'    => false,
                  'cliente_id'        => $clienteId > 0 ? $clienteId : null,
                  'envolvido_user'    => null,
                  'tecnico_user_id'   => null,
                  'local_q'           => null,
              ];
              $hrefChamados = 'chamados.php?' . http_build_query(array_filter([
                  'medicao_mes' => $ym,
                  'periodo_de'  => $periodoResolvido['de'],
                  'periodo_ate' => $periodoResolvido['ate'],
                  'cliente_id'  => ($escopoEmpresa === null && $clienteId > 0) ? $clienteId : null,
              ], static fn ($v) => $v !== null && $v !== ''));
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
                <td><?= (int) ($row['n_chamados'] ?? 0) ?></td>
                <td><strong>R$ <?= number_format((float) ($row['valor_total'] ?? 0), 2, ',', '.') ?></strong></td>
                <td class="medicao-data-cell">
                  <form id="<?= htmlspecialchars($bmFormId, ENT_QUOTES, 'UTF-8') ?>" method="get" action="medicao_export_boletim_bm.php" class="medicao-bm-inline-form">
                    <input type="hidden" name="mes" value="<?= htmlspecialchars($ym) ?>">
                    <input type="hidden" name="export" value="1">
                    <?php if ($escopoEmpresa === null && $clienteId > 0): ?>
                      <input type="hidden" name="cliente_id" value="<?= (int) $clienteId ?>">
                    <?php endif; ?>
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
                  <?php
                    $bmExportComLabels      = true;
                    $bmMostrarClienteHidden = $escopoEmpresa === null && $clienteId > 0;
                    require __DIR__ . '/medicao_bm_export_acoes.php';
                  ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <p class="muted" style="margin:16px 20px 20px;font-size:13px;line-height:1.45;">
        Defina <strong>Data início</strong> e/ou <strong>Data fim</strong> para filtrar medição, boletim BM, medição completa e relatório fotográfico.
        Campos vazios usam o 1.º dia do mês e o fecho do boletim (<strong><?= htmlspecialchars(date('d/m/Y')) ?></strong> no mês corrente).
      </p>
    </div>
  </div>
