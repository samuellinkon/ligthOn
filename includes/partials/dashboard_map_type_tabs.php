<?php
/** Tabs Chamados / Pontos / Combinado — dashboard mapas. */
?>
<div class="dashboard-map-type-tabs dashboard-map-type-tabs--primary dashboard-map-segmented" role="group" aria-label="Tipo de mapa">
  <?php if ($moduleChamadosMap): ?>
  <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'chamados'])) ?>" class="btn btn-sm <?= $dashMapaAba === 'chamados' ? 'btn-primary' : 'btn-secondary' ?>">Chamados</a>
  <?php endif; ?>
  <?php if ($modulePontosMap): ?>
  <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'pontos'])) ?>" class="btn btn-sm <?= $dashMapaAba === 'pontos' ? 'btn-primary' : 'btn-secondary' ?>">Pontos</a>
  <?php endif; ?>
  <?php if ($dashMapaCombinadoHabilitado && $moduleChamadosMap && $modulePontosMap && $scopeIdPontos > 0): ?>
  <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'ambos'])) ?>" class="btn btn-sm <?= $dashMapaAba === 'ambos' ? 'btn-primary' : 'btn-secondary' ?>">Mapa combinado</a>
  <?php endif; ?>
</div>
