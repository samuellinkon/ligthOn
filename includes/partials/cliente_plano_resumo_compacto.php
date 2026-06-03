<?php
/**
 * Modal read-only do plano — detalhe do cliente / Meus dados.
 *
 * Espera: $planoClienteRaizId (int), $isPortal (bool, opcional)
 */
if (!isset($planoClienteRaizId)) {
    $planoClienteRaizId = 0;
}
$planoClienteRaizId = (int) $planoClienteRaizId;
if ($planoClienteRaizId <= 0) {
    return;
}

require_once __DIR__ . '/../cliente_plano_limites.php';
if (!repo_cliente_plano_columns_exists()) {
    return;
}

$planoLimitesResumo = cliente_plano_limites($planoClienteRaizId);
$planoMetricasResumo = cliente_plano_resumo_metricas($planoClienteRaizId);
$planoCodigoResumo = (string) ($planoLimitesResumo['plano_codigo'] ?? 'padrao');
$planoLabelResumo = cliente_plano_codigo_label($planoCodigoResumo);
$planoMensResumo = $planoLimitesResumo['plano_mensalidade'] ?? null;

$cuPlano = function_exists('current_user') ? current_user() : null;
$isSuperAdminPlano = !empty($cuPlano['is_super_admin']);
$cfgPlanoHref = ($isPortal ?? false)
    ? '../admin/configuracoes.php?tab=geral&secao=clientes'
    : 'configuracoes.php?tab=geral&secao=clientes';

$metricLabelsShort = [
    'pontos' => 'Pontos',
    'chamados_mes' => 'Chamados',
    'itens_mes' => 'Itens',
    'storage' => 'Arquivos',
    'usuarios' => 'Usuários',
];

$planoModalJs = dirname(__DIR__, 2) . '/assets/js/cliente-plano-modal.js';
?>
<style>
  .cliente-plano-modal__head-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 10px;
    margin-top: 4px;
    font-size: 13px;
    color: var(--muted, #64748b);
    line-height: 1.45;
  }
  .cliente-plano-modal__head-meta strong {
    color: var(--text, #1e293b);
    font-weight: 600;
  }
  .cliente-plano-modal__metrics {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }
  .cliente-plano-modal__metric {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    justify-content: space-between;
    gap: 4px 12px;
    padding: 10px 12px;
    border: 1px solid var(--border-soft, #e8e8ef);
    border-radius: 10px;
    font-size: 13px;
    color: var(--muted, #64748b);
  }
  .cliente-plano-modal__metric-label {
    font-weight: 600;
    color: var(--text, #334155);
  }
  .cliente-plano-modal__metric-value {
    font-weight: 600;
    color: var(--text, #1e293b);
    text-align: right;
  }
  .cliente-plano-modal__metric.is-warn .cliente-plano-modal__metric-value {
    color: #b45309;
  }
  .cliente-plano-modal__metric.is-danger .cliente-plano-modal__metric-value {
    color: #b91c1c;
  }
  .cliente-plano-modal__foot {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    padding: 12px 20px 16px;
    border-top: 1px solid var(--border-soft, var(--border));
  }
  .topbar-plano-btn {
    position: relative;
  }
  .topbar-plano-btn__dot {
    position: absolute;
    top: 6px;
    right: 6px;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #f59e0b;
    border: 1.5px solid #fff;
    pointer-events: none;
  }
</style>
<div id="cliente-plano-modal" class="chamado-mat-modal cliente-plano-modal" hidden aria-hidden="true">
  <button type="button" class="chamado-mat-modal__scrim" data-plano-modal-close tabindex="-1" aria-label="Fechar"></button>
  <div class="chamado-mat-modal__box chamado-mat-modal__box--pick" role="dialog" aria-modal="true" aria-labelledby="cliente-plano-modal-title">
    <header class="chamado-mat-modal__head">
      <div>
        <h3 id="cliente-plano-modal-title">Plano</h3>
        <div class="cliente-plano-modal__head-meta">
          <strong><?= htmlspecialchars($planoLabelResumo) ?></strong>
          <?php if ($planoMensResumo !== null): ?>
            <span class="muted">·</span>
            <span>R$ <?= htmlspecialchars(number_format((float) $planoMensResumo, 2, ',', '.')) ?>/mês</span>
          <?php endif; ?>
        </div>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" data-plano-modal-close aria-label="Fechar">✕</button>
    </header>
    <div class="chamado-mat-modal__body">
      <div class="cliente-plano-modal__metrics">
        <?php foreach ($planoMetricasResumo as $row): ?>
          <?php
            $key = (string) ($row['key'] ?? '');
            $short = $metricLabelsShort[$key] ?? (string) ($row['label'] ?? $key);
            $nivel = (string) ($row['nivel'] ?? 'ok');
            $cls = $nivel === 'danger' ? 'is-danger' : ($nivel === 'warn' ? 'is-warn' : '');
            $limiteFmt = (string) ($row['limite_fmt'] ?? '');
            $usoFmt = (string) ($row['uso_fmt'] ?? '');
            $pct = $row['pct'];
            $valorTxt = $limiteFmt === 'Ilimitado'
                ? $usoFmt . ' / Ilimitado'
                : $usoFmt . ' / ' . $limiteFmt;
            if ($pct !== null && $nivel !== 'ok') {
                $valorTxt .= ' (' . number_format((float) $pct, 0, ',', '.') . '%)';
            }
          ?>
          <div class="cliente-plano-modal__metric <?= htmlspecialchars($cls) ?>">
            <span class="cliente-plano-modal__metric-label"><?= htmlspecialchars($short) ?></span>
            <span class="cliente-plano-modal__metric-value"><?= htmlspecialchars($valorTxt) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <footer class="cliente-plano-modal__foot">
      <?php if ($isSuperAdminPlano): ?>
        <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($cfgPlanoHref) ?>">Ajustar plano</a>
      <?php endif; ?>
      <button type="button" class="btn btn-primary btn-sm" data-plano-modal-close>Fechar</button>
    </footer>
  </div>
</div>
<script src="<?= htmlspecialchars($basePath ?? '') ?>assets/js/cliente-plano-modal.js?v=<?= (int) @filemtime($planoModalJs) ?>"></script>
