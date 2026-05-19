<?php
/**
 * Ações de exportação BM (ícones ou rótulos) — listagem e detalhe.
 *
 * Variáveis:
 *   $bmFormId, $bmMes, $bmClienteId, $bmMostrarClienteHidden (bool)
 *   $hrefChamados, $hrefXlsxDet, $hrefPdfAnexos
 *   $bmExportComLabels (bool) — botões com texto (página de detalhe)
 */
$bmFormId                 = (string) ($bmFormId ?? 'bm-export');
$bmMes                    = (string) ($bmMes ?? '');
$bmClienteId              = (int) ($bmClienteId ?? 0);
$bmMostrarClienteHidden   = (bool) ($bmMostrarClienteHidden ?? false);
$hrefChamados             = (string) ($hrefChamados ?? 'chamados.php');
$hrefXlsxDet              = (string) ($hrefXlsxDet ?? '');
$hrefPdfAnexos            = (string) ($hrefPdfAnexos ?? '');
$bmExportAction           = (string) ($bmExportAction ?? 'medicao_export_boletim_bm.php');
$bmExportComLabels        = (bool) ($bmExportComLabels ?? false);
?>
<div class="actions-inline<?= $bmExportComLabels ? ' medicao-export-actions--labeled' : '' ?>">
  <?php if ($bmExportComLabels): ?>
    <a class="btn btn-secondary btn-sm js-medicao-periodo-link" href="<?= htmlspecialchars($hrefChamados, ENT_QUOTES, 'UTF-8') ?>" data-link-kind="chamados">Chamados</a>
    <button type="submit" form="<?= htmlspecialchars($bmFormId, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary btn-sm">BM</button>
    <a class="btn btn-secondary btn-sm js-medicao-periodo-link" href="<?= htmlspecialchars($hrefXlsxDet, ENT_QUOTES, 'UTF-8') ?>" data-link-kind="xlsx_detalhes">BM completo</a>
    <a class="btn btn-secondary btn-sm js-medicao-periodo-link" href="<?= htmlspecialchars($hrefPdfAnexos, ENT_QUOTES, 'UTF-8') ?>" data-link-kind="pdf_anexos" target="_blank" rel="noopener">Rel. fotográfico</a>
  <?php else: ?>
    <a class="action-icon js-medicao-periodo-link" href="<?= htmlspecialchars($hrefChamados, ENT_QUOTES, 'UTF-8') ?>" data-link-kind="chamados" title="Chamados" aria-label="Chamados">💬</a>
    <button type="submit" form="<?= htmlspecialchars($bmFormId, ENT_QUOTES, 'UTF-8') ?>" class="action-icon excel" title="Excel — boletim BM" aria-label="Excel — boletim BM">📄</button>
    <a class="action-icon excel js-medicao-periodo-link" href="<?= htmlspecialchars($hrefXlsxDet, ENT_QUOTES, 'UTF-8') ?>" data-link-kind="xlsx_detalhes" title="Excel — medição completa" aria-label="Excel — medição completa">📋</a>
    <a class="action-icon pdf js-medicao-periodo-link" href="<?= htmlspecialchars($hrefPdfAnexos, ENT_QUOTES, 'UTF-8') ?>" data-link-kind="pdf_anexos" title="Relatório Fotográfico" aria-label="Relatório Fotográfico" target="_blank" rel="noopener">📎</a>
  <?php endif; ?>
</div>
