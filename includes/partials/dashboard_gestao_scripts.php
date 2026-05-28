<?php
/** Scripts dos mapas do dashboard — requer $basePath, flags de mapa, $mapPins, $pontosPinsPass. */
$dashMapsAtivos = $dashMapsAtivos ?? ($loadLeafletChamados || $loadPontosMap || $loadMapaCombinado);
?>
<?php if ($dashMapsAtivos): ?>
<script src="<?= $basePath ?>assets/js/dashboard-map-resize.js?v=<?= (int) @filemtime(__DIR__ . '/../../assets/js/dashboard-map-resize.js') ?>"></script>
<?php endif; ?>
<?php if ($loadGoogleMapsJs): ?>
<?php
$crmGmapsJsApiUrl = crm_google_maps_js_api_url('crmGoogleMapsApiReady');
$geocodeCoreJs = __DIR__ . '/../../assets/js/dashboard-map-geocode-core.js';
$gmapsLoaderJs = __DIR__ . '/../../assets/js/crm-google-maps-js-loader.js';
$dashGoogleJs = __DIR__ . '/../../assets/js/crm-dashboard-map-google.js';
?>
<?php if ($loadLeafletChamados || $loadMapaCombinado): ?>
<?php include __DIR__ . '/leaflet_chamado_popup_assets.php'; ?>
<?php endif; ?>
<?php if ($loadPontosMap || $loadMapaCombinado): ?>
<?php include __DIR__ . '/leaflet_ponto_popup_assets.php'; ?>
<script src="<?= $basePath ?>assets/js/ponto-marker-status.js?v=<?= (int) @filemtime(__DIR__ . '/../../assets/js/ponto-marker-status.js') ?>"></script>
<?php endif; ?>
<?php if ($loadLeafletChamados): ?>
<script>
  window.CHAMADOS_MAP_PINS = <?= json_encode($mapPins, JSON_UNESCAPED_UNICODE) ?: '[]' ?>;
  window.CHAMADOS_MAP_GEOCODE_API = <?= json_encode(
      ($dashPainel ?? 'admin') === 'cliente'
          ? $basePath . 'cliente/geocode_nominatim_api.php'
          : $basePath . 'admin/geocode_nominatim_api.php',
      JSON_UNESCAPED_UNICODE
  ) ?>;
  window.CHAMADOS_MAP_PERSIST_API = <?= json_encode(
      ($dashPainel ?? 'admin') === 'cliente'
          ? ''
          : $basePath . 'admin/chamado_map_geocode_persist_api.php',
      JSON_UNESCAPED_UNICODE
  ) ?>;
  <?php if (!empty($mapEmptyMsg)): ?>
  window.CHAMADOS_MAP_EMPTY_MSG = <?= json_encode($mapEmptyMsg, JSON_UNESCAPED_UNICODE) ?>;
  <?php endif; ?>
  <?php if (!empty($mapLoadingMsg)): ?>
  window.CHAMADOS_MAP_LOADING_MSG = <?= json_encode($mapLoadingMsg, JSON_UNESCAPED_UNICODE) ?>;
  <?php endif; ?>
</script>
<?php endif; ?>
<?php if ($loadPontosMap): ?>
<script>
  window.PONTOS_ILUMINACAO_MAP = <?= json_encode($pontosPinsPass, JSON_UNESCAPED_UNICODE) ?: '[]' ?>;
</script>
<?php endif; ?>
<?php if ($loadMapaCombinado): ?>
<script>
  window.DASHBOARD_MAP_COMBINED = {
    chamados: <?= json_encode($mapPins, JSON_UNESCAPED_UNICODE) ?: '[]' ?>,
    pontos: <?= json_encode($pontosPinsPass, JSON_UNESCAPED_UNICODE) ?: '[]' ?>
  };
  window.CHAMADOS_MAP_GEOCODE_API = <?= json_encode(
      ($dashPainel ?? 'admin') === 'cliente'
          ? $basePath . 'cliente/geocode_nominatim_api.php'
          : $basePath . 'admin/geocode_nominatim_api.php',
      JSON_UNESCAPED_UNICODE
  ) ?>;
  window.CHAMADOS_MAP_PERSIST_API = <?= json_encode(
      ($dashPainel ?? 'admin') === 'cliente'
          ? ''
          : $basePath . 'admin/chamado_map_geocode_persist_api.php',
      JSON_UNESCAPED_UNICODE
  ) ?>;
  <?php if (!empty($mapLoadingMsg)): ?>
  window.CHAMADOS_MAP_LOADING_MSG = <?= json_encode($mapLoadingMsg, JSON_UNESCAPED_UNICODE) ?>;
  <?php endif; ?>
</script>
<?php endif; ?>
<script>
  window.CRM_DASHBOARD_MAP_PROVIDER = 'google';
  window.crmGoogleMapsApiReady = function () {
    if (window.CrmDashboardMapsBoot) {
      window.CrmDashboardMapsBoot();
    }
  };
</script>
<script src="<?= $basePath ?>assets/js/dashboard-map-geocode-core.js?v=<?= (int) @filemtime($geocodeCoreJs) ?>"></script>
<script src="<?= $basePath ?>assets/js/crm-google-maps-js-loader.js?v=<?= (int) @filemtime($gmapsLoaderJs) ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/@googlemaps/markerclusterer@2.5.3/dist/index.min.js" crossorigin=""></script>
<script src="<?= $basePath ?>assets/js/crm-dashboard-map-google.js?v=<?= (int) @filemtime($dashGoogleJs) ?>"></script>
<?php if ($crmGmapsJsApiUrl !== ''): ?>
<script src="<?= htmlspecialchars($crmGmapsJsApiUrl, ENT_QUOTES, 'UTF-8') ?>" async defer></script>
<?php endif; ?>
<?php endif; ?>
<?php
$geocodeCoreJsPath = __DIR__ . '/../../assets/js/dashboard-map-geocode-core.js';
?>
<?php if ($loadLeaflet): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<?php include __DIR__ . '/leaflet_basemap_script.php'; ?>
<?php endif; ?>
<?php if ($loadLeafletMarkerCluster): ?>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js" crossorigin=""></script>
<?php endif; ?>
<?php if ($loadLeafletChamados): ?>
<?php include __DIR__ . '/leaflet_chamado_popup_assets.php'; ?>
<script>
  window.CHAMADOS_MAP_PINS = <?= json_encode($mapPins, JSON_UNESCAPED_UNICODE) ?: '[]' ?>;
  window.CHAMADOS_MAP_GEOCODE_API = <?= json_encode(
      ($dashPainel ?? 'admin') === 'cliente'
          ? $basePath . 'cliente/geocode_nominatim_api.php'
          : $basePath . 'admin/geocode_nominatim_api.php',
      JSON_UNESCAPED_UNICODE
  ) ?>;
  window.CHAMADOS_MAP_PERSIST_API = <?= json_encode(
      ($dashPainel ?? 'admin') === 'cliente'
          ? ''
          : $basePath . 'admin/chamado_map_geocode_persist_api.php',
      JSON_UNESCAPED_UNICODE
  ) ?>;
  <?php if (!empty($mapEmptyMsg)): ?>
  window.CHAMADOS_MAP_EMPTY_MSG = <?= json_encode($mapEmptyMsg, JSON_UNESCAPED_UNICODE) ?>;
  <?php endif; ?>
  <?php if (!empty($mapLoadingMsg)): ?>
  window.CHAMADOS_MAP_LOADING_MSG = <?= json_encode($mapLoadingMsg, JSON_UNESCAPED_UNICODE) ?>;
  <?php endif; ?>
</script>
<script src="<?= $basePath ?>assets/js/dashboard-map-geocode-core.js?v=<?= (int) @filemtime($geocodeCoreJsPath) ?>"></script>
<script src="<?= $basePath ?>assets/js/dashboard-map-geocode.js?v=<?= (int) @filemtime(__DIR__ . '/../../assets/js/dashboard-map-geocode.js') ?>"></script>
<script src="<?= $basePath ?>assets/js/dashboard-map.js?v=<?= (int) @filemtime(__DIR__ . '/../../assets/js/dashboard-map.js') ?>"></script>
<?php endif; ?>
<?php if ($loadPontosMap && $loadLeaflet): ?>
<?php include __DIR__ . '/leaflet_ponto_popup_assets.php'; ?>
<script src="<?= $basePath ?>assets/js/ponto-marker-status.js?v=<?= (int) @filemtime(__DIR__ . '/../../assets/js/ponto-marker-status.js') ?>"></script>
<?php endif; ?>
<?php if ($loadPontosMap && $loadLeaflet): ?>
<script>
  window.PONTOS_ILUMINACAO_MAP = <?= json_encode($pontosPinsPass, JSON_UNESCAPED_UNICODE) ?: '[]' ?>;
</script>
<script src="<?= $basePath ?>assets/js/pontos-iluminacao-map.js?v=<?= (int) @filemtime(__DIR__ . '/../../assets/js/pontos-iluminacao-map.js') ?>"></script>
<?php endif; ?>
<?php if ($loadMapaCombinado && !$loadLeafletChamados): ?>
<?php include __DIR__ . '/leaflet_chamado_popup_assets.php'; ?>
<?php endif; ?>
<?php if ($loadMapaCombinado && $loadLeaflet): ?>
<script>
  window.DASHBOARD_MAP_COMBINED = {
    chamados: <?= json_encode($mapPins, JSON_UNESCAPED_UNICODE) ?: '[]' ?>,
    pontos: <?= json_encode($pontosPinsPass, JSON_UNESCAPED_UNICODE) ?: '[]' ?>
  };
  window.CHAMADOS_MAP_GEOCODE_API = <?= json_encode(
      ($dashPainel ?? 'admin') === 'cliente'
          ? $basePath . 'cliente/geocode_nominatim_api.php'
          : $basePath . 'admin/geocode_nominatim_api.php',
      JSON_UNESCAPED_UNICODE
  ) ?>;
  window.CHAMADOS_MAP_PERSIST_API = <?= json_encode(
      ($dashPainel ?? 'admin') === 'cliente'
          ? ''
          : $basePath . 'admin/chamado_map_geocode_persist_api.php',
      JSON_UNESCAPED_UNICODE
  ) ?>;
  <?php if (!empty($mapLoadingMsg)): ?>
  window.CHAMADOS_MAP_LOADING_MSG = <?= json_encode($mapLoadingMsg, JSON_UNESCAPED_UNICODE) ?>;
  <?php endif; ?>
</script>
<script src="<?= $basePath ?>assets/js/dashboard-map-geocode-core.js?v=<?= (int) @filemtime($geocodeCoreJsPath) ?>"></script>
<script src="<?= $basePath ?>assets/js/dashboard-map-geocode.js?v=<?= (int) @filemtime(__DIR__ . '/../../assets/js/dashboard-map-geocode.js') ?>"></script>
<script src="<?= $basePath ?>assets/js/dashboard-map-combined.js?v=<?= (int) @filemtime(__DIR__ . '/../../assets/js/dashboard-map-combined.js') ?>"></script>
<?php endif; ?>
