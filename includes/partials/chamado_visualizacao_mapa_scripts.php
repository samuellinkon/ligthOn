<?php
declare(strict_types=1);
/**
 * Scripts do componente de mapa de visualização do chamado.
 *
 * @var bool $ch_viz_scripts_ativo
 * @var string $basePath
 * @var string $chamadoGeocodeApiUrl
 * @var array<int, array{rootId: string, defaultView?: string, latInputId?: string, lngInputId?: string}>|null $ch_viz_script_inits
 */
if (empty($ch_viz_scripts_ativo)) {
    return;
}
if (!isset($ch_viz_script_inits) || !is_array($ch_viz_script_inits) || $ch_viz_script_inits === []) {
    return;
}

$chVizScriptsBase = htmlspecialchars(rtrim((string) $basePath, '/') . '/', ENT_QUOTES, 'UTF-8');
$chVizGmapsJs = dirname(__DIR__, 2) . '/assets/js/crm-google-maps.js';
$chVizDualJs = dirname(__DIR__, 2) . '/assets/js/chamado-loc-dual-core.js';
$chVizGeocodeJs = dirname(__DIR__, 2) . '/assets/js/dashboard-map-geocode-core.js';
$chVizMapaJs = dirname(__DIR__, 2) . '/assets/js/chamado-visualizacao-mapa.js';
$chVizLoadLeaflet = !crm_google_maps_has_api_key();
$chVizNeedsGeocodeCore = false;
foreach ($ch_viz_script_inits as $_chVizInit) {
    if (!empty($_chVizInit['geocode'])) {
        $chVizNeedsGeocodeCore = true;
        break;
    }
}
?>
<script src="<?= $chVizScriptsBase ?>assets/js/crm-google-maps.js?v=<?= (int) @filemtime($chVizGmapsJs) ?>"></script>
<?php if ($chVizNeedsGeocodeCore): ?>
<script src="<?= $chVizScriptsBase ?>assets/js/dashboard-map-geocode-core.js?v=<?= (int) @filemtime($chVizGeocodeJs) ?>"></script>
<?php endif; ?>
<?php if ($chVizLoadLeaflet): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<?php include __DIR__ . '/leaflet_basemap_script.php'; ?>
<?php endif; ?>
<script src="<?= $chVizScriptsBase ?>assets/js/chamado-loc-dual-core.js?v=<?= (int) @filemtime($chVizDualJs) ?>"></script>
<script src="<?= $chVizScriptsBase ?>assets/js/chamado-visualizacao-mapa.js?v=<?= (int) @filemtime($chVizMapaJs) ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (!window.CrmChamadoVizMapa) return;
  var inits = <?= json_encode(array_values($ch_viz_script_inits), JSON_UNESCAPED_UNICODE) ?>;
  var geocodeApiUrl = <?= json_encode((string) $chamadoGeocodeApiUrl, JSON_UNESCAPED_UNICODE) ?>;
  inits.forEach(function (cfg) {
    if (!cfg || !cfg.rootId) return;
    window.CrmChamadoVizMapa.init({
      rootId: cfg.rootId,
      geocodeApiUrl: geocodeApiUrl,
      defaultView: cfg.defaultView || 'map',
      latInputId: cfg.latInputId || '',
      lngInputId: cfg.lngInputId || '',
      geocode: cfg.geocode || null
    });
  });
});
</script>
