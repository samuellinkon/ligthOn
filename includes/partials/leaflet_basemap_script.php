<?php
/** Basemap Leaflet (CARTO Positron) — carregar após leaflet.js. Requer $basePath. */
if (!isset($basePath)) {
    $basePath = '../';
}
$crmBasemapJs = dirname(__DIR__, 2) . '/assets/js/crm-leaflet-basemap.js';
?>
<script src="<?= htmlspecialchars($basePath) ?>assets/js/crm-leaflet-basemap.js?v=<?= (int) @filemtime($crmBasemapJs) ?>"></script>
