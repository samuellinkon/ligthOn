<?php
/**
 * Período e mês de referência — mapa de chamados (dashboard admin/gestor/cliente).
 * Requer: $moduleChamadosMap, $dashQsPreserve, $mapPeriodo, $mapMes, $dashMapaAba;
 * opcional: $dashboardMapPeriodoAba, $dashPainel, $escopoDash, $scopeIdPontos, $modoClienteUnicoAdmin, $pontoMapFiltro.
 */
if (empty($moduleChamadosMap)) {
    return;
}

$dashboardMapPeriodoAba = (string) ($dashboardMapPeriodoAba ?? $dashMapaAba ?? 'chamados');
$mapMesAtual = (string) ($mapMes ?? date('Y-m'));
$mapMesMax = date('Y-m');
?>
<div class="dashboard-map-chamados-period-wrap">
  <div class="dashboard-map-chamados-period dashboard-map-segmented" role="group" aria-label="Período do mapa">
    <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => $dashboardMapPeriodoAba, 'map_periodo' => 'hoje', 'map_mes' => null])) ?>" class="btn btn-sm <?= $mapPeriodo === 'hoje' ? 'btn-primary' : 'btn-secondary' ?>">Dia atual</a>
    <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => $dashboardMapPeriodoAba, 'map_periodo' => 'ontem', 'map_mes' => null])) ?>" class="btn btn-sm <?= $mapPeriodo === 'ontem' ? 'btn-primary' : 'btn-secondary' ?>">Dia anterior</a>
    <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => $dashboardMapPeriodoAba, 'map_periodo' => 'semana', 'map_mes' => null])) ?>" class="btn btn-sm <?= $mapPeriodo === 'semana' ? 'btn-primary' : 'btn-secondary' ?>">Semana atual</a>
    <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => $dashboardMapPeriodoAba, 'map_periodo' => 'mes', 'map_mes' => null])) ?>" class="btn btn-sm <?= $mapPeriodo === 'mes' ? 'btn-primary' : 'btn-secondary' ?>">Mês atual</a>
  </div>
  <form class="dashboard-map-mes-picker-form" method="get" action="index.php" aria-label="Filtrar chamados por mês">
    <input type="hidden" name="dash_mapa" value="<?= htmlspecialchars($dashboardMapPeriodoAba) ?>">
    <input type="hidden" name="map_periodo" value="mes">
    <?php if ($dashPainel === 'admin' && ($escopoDash ?? null) === null && ($scopeIdPontos ?? 0) > 0 && empty($modoClienteUnicoAdmin)): ?>
    <input type="hidden" name="dash_cliente_id" value="<?= (int) $scopeIdPontos ?>">
    <?php endif; ?>
    <?php if (($pontoMapFiltro ?? '') !== ''): ?>
    <input type="hidden" name="ponto_filtro" value="<?= htmlspecialchars((string) $pontoMapFiltro) ?>">
    <?php endif; ?>
    <label class="dashboard-map-mes-picker-label" for="dashboard-map-mes-<?= htmlspecialchars($dashboardMapPeriodoAba) ?>"><span class="dashboard-map-mes-picker-label-text">Mês:</span></label>
    <input
      type="month"
      id="dashboard-map-mes-<?= htmlspecialchars($dashboardMapPeriodoAba) ?>"
      name="map_mes"
      class="input dashboard-map-mes-picker-input"
      value="<?= htmlspecialchars($mapMesAtual) ?>"
      max="<?= htmlspecialchars($mapMesMax) ?>"
      onchange="this.form.submit()"
    >
  </form>
</div>
