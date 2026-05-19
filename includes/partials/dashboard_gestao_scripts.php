<?php
/** Scripts Leaflet do dashboard — requer $basePath, $loadLeaflet*, $mapPins, $pontosPinsPass. */
?>
<?php if ($loadLeaflet): ?>
<script src="<?= $basePath ?>assets/js/dashboard-map-resize.js?v=<?= (int) @filemtime(__DIR__ . '/../../assets/js/dashboard-map-resize.js') ?>"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<?php endif; ?>
<?php if ($loadLeafletMarkerCluster): ?>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js" crossorigin=""></script>
<?php endif; ?>
<?php if ($loadLeafletChamados): ?>
<script>
  window.CHAMADOS_MAP_PINS = <?= json_encode($mapPins, JSON_UNESCAPED_UNICODE) ?: '[]' ?>;
</script>
<script src="<?= $basePath ?>assets/js/dashboard-map.js?v=<?= (int) @filemtime(__DIR__ . '/../../assets/js/dashboard-map.js') ?>"></script>
<?php endif; ?>
<?php if ($loadPontosMap): ?>
<script>
  window.PONTOS_ILUMINACAO_MAP = <?= json_encode($pontosPinsPass, JSON_UNESCAPED_UNICODE) ?: '[]' ?>;
</script>
<script src="<?= $basePath ?>assets/js/pontos-iluminacao-map.js?v=<?= (int) @filemtime(__DIR__ . '/../../assets/js/pontos-iluminacao-map.js') ?>"></script>
<?php endif; ?>
<?php if ($loadMapaCombinado): ?>
<script>
  window.DASHBOARD_MAP_COMBINED = {
    chamados: <?= json_encode($mapPins, JSON_UNESCAPED_UNICODE) ?: '[]' ?>,
    pontos: <?= json_encode($pontosPinsPass, JSON_UNESCAPED_UNICODE) ?: '[]' ?>
  };
</script>
<script src="<?= $basePath ?>assets/js/dashboard-map-combined.js?v=<?= (int) @filemtime(__DIR__ . '/../../assets/js/dashboard-map-combined.js') ?>"></script>
<?php endif; ?>
