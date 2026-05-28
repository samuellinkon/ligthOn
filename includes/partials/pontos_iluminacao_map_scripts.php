<?php
/**
 * Scripts do mapa de pontos de iluminação (Google ou Leaflet).
 * Requer: $basePath, $pontosMapPins (array), $loadPontosMapGoogle / $loadLeaflet (opcional).
 */
$basePath = $basePath ?? '../';
$pontosMapPins = $pontosMapPins ?? [];
$loadPontosMapGoogle = !empty($loadPontosMapGoogle);
$loadLeaflet = !empty($loadLeaflet) || !$loadPontosMapGoogle;
$assetsRoot = dirname(__DIR__, 2);
?>
<?php if ($loadPontosMapGoogle): ?>
<?php
$crmGmapsJsApiUrl = crm_google_maps_js_api_url('crmPontosIluminacaoMapReady');
$gmapsLoaderJs = $assetsRoot . '/assets/js/crm-google-maps-js-loader.js';
$dashGoogleJs = $assetsRoot . '/assets/js/crm-dashboard-map-google.js';
?>
<?php include __DIR__ . '/leaflet_ponto_popup_assets.php'; ?>
<script src="<?= htmlspecialchars($basePath) ?>assets/js/ponto-marker-status.js?v=<?= (int) @filemtime($assetsRoot . '/assets/js/ponto-marker-status.js') ?>"></script>
<script>
  window.PONTOS_ILUMINACAO_MAP = <?= json_encode($pontosMapPins, JSON_UNESCAPED_UNICODE) ?: '[]' ?>;
  window.CRM_PONTOS_MAP_PROVIDER = 'google';
  window.crmPontosIluminacaoMapReady = function () {
    if (window.CrmDashboardMapGoogle && typeof window.CrmDashboardMapGoogle.initPontos === 'function') {
      window.CrmDashboardMapGoogle.initPontos({ mapElementId: 'pontos-iluminacao-map' });
    }
  };
</script>
<script src="<?= htmlspecialchars($basePath) ?>assets/js/crm-google-maps-js-loader.js?v=<?= (int) @filemtime($gmapsLoaderJs) ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/@googlemaps/markerclusterer@2.5.3/dist/index.min.js" crossorigin=""></script>
<script src="<?= htmlspecialchars($basePath) ?>assets/js/crm-dashboard-map-google.js?v=<?= (int) @filemtime($dashGoogleJs) ?>"></script>
<?php if ($crmGmapsJsApiUrl !== ''): ?>
<script src="<?= htmlspecialchars($crmGmapsJsApiUrl, ENT_QUOTES, 'UTF-8') ?>" async defer></script>
<?php endif; ?>
<?php endif; ?>
<?php if ($loadLeaflet): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<?php include __DIR__ . '/leaflet_basemap_script.php'; ?>
<?php include __DIR__ . '/leaflet_ponto_popup_assets.php'; ?>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js" crossorigin=""></script>
<script>
  window.PONTOS_ILUMINACAO_MAP = <?= json_encode($pontosMapPins, JSON_UNESCAPED_UNICODE) ?: '[]' ?>;
</script>
<script src="<?= htmlspecialchars($basePath) ?>assets/js/ponto-marker-status.js?v=<?= (int) @filemtime($assetsRoot . '/assets/js/ponto-marker-status.js') ?>"></script>
<script src="<?= htmlspecialchars($basePath) ?>assets/js/pontos-iluminacao-map.js?v=<?= (int) @filemtime($assetsRoot . '/assets/js/pontos-iluminacao-map.js') ?>"></script>
<?php endif; ?>
