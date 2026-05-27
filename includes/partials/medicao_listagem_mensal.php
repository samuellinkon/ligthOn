<?php
/**
 * Listagem mensal de medição — layout em cards (admin/gestor e portal cliente).
 * Requer: $mesesLista, $clienteId, $escopoEmpresa (admin multi-empresa), $medicaoMostrarImportar (bool).
 * Opcional: $medicaoValidadoCount, $bmExcluirMapa (array ym => preview), $medicaoMostrarExcluirBm (bool).
 */
$medicaoMostrarImportar = (bool) ($medicaoMostrarImportar ?? false);
$medicaoValidadoCount   = (int) ($medicaoValidadoCount ?? 0);
$medicaoMostrarExcluirBm = (bool) ($medicaoMostrarExcluirBm ?? false);
$bmExcluirMapa          = is_array($bmExcluirMapa ?? null) ? $bmExcluirMapa : [];
?>
<div class="medicao-meses-shell">
  <header class="medicao-meses-topbar">
    <div class="medicao-meses-topbar__intro">
      <h2 class="medicao-meses-topbar__title">Medições mensais</h2>
      <p class="medicao-meses-topbar__subtitle">Períodos fechados, valores medidos e exportações do BM.</p>
    </div>
    <?php if ($medicaoMostrarImportar): ?>
    <a href="medicao_importar.php" class="btn btn-secondary btn-sm medicao-meses-topbar__import">Importar planilha BM</a>
    <?php endif; ?>
  </header>

  <?php if (empty($mesesLista)): ?>
  <div class="medicao-meses-empty muted">
    Nenhum mês com chamados <strong>Validado</strong> nem importação BM nesta empresa.
    <?php if ($medicaoValidadoCount > 0): ?>
      Há <?= (int) $medicaoValidadoCount ?> chamado(s) Validado no escopo, mas nenhum agrupável por mês de abertura (verifique datas ou importe a planilha BM).
    <?php else: ?>
      <?php if ($medicaoMostrarImportar): ?>
        Confira em <strong>Configurações</strong> a prefeitura dona do catálogo (deve ser a matriz dos chamados) ou valide chamados antes de medir; também pode <strong>importar a planilha BM</strong>.
      <?php else: ?>
        Valide chamados após o atendimento para que entrem na medição ou aguarde a importação BM pelo gestor.
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div class="medicao-meses-list" role="list">
    <?php foreach ($mesesLista as $row):
        $ym = (string) ($row['ym'] ?? '');
        $bmPrimeiroDia   = $ym . '-01';
        $bmPeriodoAte    = medicao_bm_export_v2_periodo_ate($ym);
        $bmPeriodoDeMin  = medicao_bm_export_v2_periodo_de_min($ym);
        $bmPeriodoAteFmt = date('d/m/Y', strtotime($bmPeriodoAte));
        $bmIdYm          = htmlspecialchars(preg_replace('/\W/', '_', $ym), ENT_QUOTES, 'UTF-8');
        $bmFormId        = 'bm-export-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $ym);
        $periodoResolvido = medicao_resolve_periodo_filtro($ym, $bmPrimeiroDia, $bmPeriodoAte);
        $periodoDeAtual  = $periodoResolvido['de'];
        $periodoAteAtual = $periodoResolvido['ate'];
        $periodoFaixa    = medicao_periodo_faixa_curta($periodoDeAtual, $periodoAteAtual);
        $chExportCtx = [
            'medicao_mes'       => $ym,
            'periodo_de'        => $periodoDeAtual,
            'periodo_ate'       => $periodoAteAtual,
            'periodo_limpar'    => false,
            'cliente_id'        => $clienteId > 0 ? $clienteId : null,
            'envolvido_user'    => null,
            'tecnico_user_id'   => null,
            'local_q'           => null,
        ];
        $hrefChamados = 'chamados.php?' . http_build_query(array_filter([
            'f'           => 'resolvido_bm',
            'medicao_mes' => $ym,
            'periodo_de'  => $periodoDeAtual,
            'periodo_ate' => $periodoAteAtual,
            'cliente_id'  => ($escopoEmpresa === null && $clienteId > 0) ? $clienteId : null,
        ], static fn ($v) => $v !== null && $v !== ''));
        $hrefXlsxDet = adm_chamados_export_url('xlsx_detalhes', '', '', $chExportCtx);
        $hrefPdfAnexos = adm_chamados_export_url('pdf_anexos', '', '', $chExportCtx);
        if (defined('CRM_EXPORT_PDF_DEBUG') && CRM_EXPORT_PDF_DEBUG) {
            $hrefPdfAnexos .= (strpos($hrefPdfAnexos, '?') !== false ? '&' : '?') . 'pdf_debug=1';
        }
        $dateTitle = 'Mês ' . htmlspecialchars($ym) . ' · início mín. ' . date('d/m/Y', strtotime($bmPeriodoDeMin))
            . ' · fecho até ' . htmlspecialchars($bmPeriodoAteFmt);
        $cardId = 'bm-' . htmlspecialchars(str_replace(['\\', '/'], '-', $ym), ENT_QUOTES, 'UTF-8');
        $nChamados = (int) ($row['n_chamados'] ?? 0);
        $valorTotal = (float) ($row['valor_total'] ?? 0);

        $bmExcluirPrev = null;
        if ($medicaoMostrarExcluirBm) {
            $bmExcluirPrevRow = $bmExcluirMapa[$ym] ?? null;
            if (is_array($bmExcluirPrevRow) && !empty($bmExcluirPrevRow['ok'])) {
                $bmExcluirPrev = $bmExcluirPrevRow;
            }
        }
        ?>
    <article
      class="medicao-mes-card"
      role="listitem"
      id="<?= $cardId ?>"
      data-medicao-mes="<?= htmlspecialchars($ym, ENT_QUOTES, 'UTF-8') ?>"
      data-sort-mes="<?= htmlspecialchars($ym, ENT_QUOTES, 'UTF-8') ?>"
      data-sort-chamados="<?= $nChamados ?>"
      data-sort-total="<?= htmlspecialchars((string) $valorTotal, ENT_QUOTES, 'UTF-8') ?>"
      data-sort-datainicio="<?= htmlspecialchars($periodoDeAtual, ENT_QUOTES, 'UTF-8') ?>"
    >
      <div class="medicao-mes-card__row medicao-mes-card__row--primary">
        <h3 class="medicao-mes-card__month"><?= htmlspecialchars(medicao_mes_label_pt($ym)) ?></h3>
        <span class="medicao-mes-card__value tabular-nums">R$ <?= number_format($valorTotal, 2, ',', '.') ?></span>
      </div>

      <div class="medicao-mes-card__row medicao-mes-card__row--meta">
        <span class="medicao-mes-card__stats">
          <span class="medicao-mes-card__chamados"><?= $nChamados ?></span>
          <?= $nChamados === 1 ? 'chamado' : 'chamados' ?>
          <span class="medicao-mes-card__dot" aria-hidden="true">•</span>
          <span class="medicao-mes-card__period-label" data-medicao-period-label><?= htmlspecialchars($periodoFaixa) ?></span>
        </span>
      </div>

      <div class="medicao-mes-card__row medicao-mes-card__row--actions">
        <form
          id="<?= htmlspecialchars($bmFormId, ENT_QUOTES, 'UTF-8') ?>"
          method="get"
          action="medicao_export_boletim_bm.php"
          class="medicao-bm-inline-form medicao-mes-card__bm-form"
        >
          <input type="hidden" name="mes" value="<?= htmlspecialchars($ym) ?>">
          <input type="hidden" name="export" value="1">
          <?php if ($escopoEmpresa === null && $clienteId > 0): ?>
            <input type="hidden" name="cliente_id" value="<?= (int) $clienteId ?>">
          <?php endif; ?>
          <input
            type="date"
            name="periodo_de"
            class="medicao-periodo-de medicao-mes-card__period-input"
            value="<?= htmlspecialchars($periodoDeAtual) ?>"
            min="<?= htmlspecialchars($bmPeriodoDeMin) ?>"
            max="<?= htmlspecialchars($bmPeriodoAte) ?>"
            title="<?= $dateTitle ?>"
            tabindex="-1"
            aria-hidden="true"
          >
          <input
            type="date"
            name="periodo_ate"
            class="medicao-periodo-ate medicao-mes-card__period-input"
            value="<?= htmlspecialchars($periodoAteAtual) ?>"
            min="<?= htmlspecialchars($bmPeriodoDeMin) ?>"
            max="<?= htmlspecialchars($bmPeriodoAte) ?>"
            title="<?= $dateTitle ?>"
            tabindex="-1"
            aria-hidden="true"
          >
        </form>

        <div class="medicao-mes-card__actions-toolbar">
          <div class="medicao-mes-card__actions-main">
            <?php
              $bmExportComLabels = true;
              if ($bmExcluirPrev !== null) {
                  $bmExcluirMes = $ym;
                  $bmExcluirPeriodoDe = $periodoDeAtual;
                  $bmExcluirPeriodoAte = $periodoAteAtual;
                  $bmExcluirClienteId = $clienteId;
                  $bmExcluirEscopoEmpresa = $escopoEmpresa;
              }
              require __DIR__ . '/medicao_bm_export_acoes.php';
            ?>
          </div>
          <div class="medicao-mes-card__actions-period">
            <button
              type="button"
              class="btn btn-secondary btn-sm medicao-mes-card__btn-periodo"
              data-medicao-period-edit
              title="Editar período · <?= htmlspecialchars($periodoFaixa) ?>"
            >Período</button>
            <div class="medicao-mes-card__period-popover" hidden data-medicao-period-popover>
              <p class="medicao-mes-card__period-popover-title">Período do BM</p>
              <div class="medicao-mes-card__period-fields">
                <label class="medicao-mes-card__period-field">
                  <span>Início</span>
                  <input
                    type="date"
                    class="input medicao-mes-card__period-field-de"
                    value="<?= htmlspecialchars($periodoDeAtual) ?>"
                    min="<?= htmlspecialchars($bmPeriodoDeMin) ?>"
                    max="<?= htmlspecialchars($bmPeriodoAte) ?>"
                    title="<?= $dateTitle ?>"
                  >
                </label>
                <label class="medicao-mes-card__period-field">
                  <span>Fim</span>
                  <input
                    type="date"
                    class="input medicao-mes-card__period-field-ate"
                    value="<?= htmlspecialchars($periodoAteAtual) ?>"
                    min="<?= htmlspecialchars($bmPeriodoDeMin) ?>"
                    max="<?= htmlspecialchars($bmPeriodoAte) ?>"
                    title="<?= $dateTitle ?>"
                  >
                </label>
              </div>
              <div class="medicao-mes-card__period-popover-actions">
                <button type="button" class="btn btn-primary btn-sm" data-medicao-period-apply>Aplicar</button>
                <button type="button" class="btn btn-secondary btn-sm" data-medicao-period-cancel>Cancelar</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
