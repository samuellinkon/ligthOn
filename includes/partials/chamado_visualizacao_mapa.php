<?php
declare(strict_types=1);
/**
 * Mapa / Street View isolado para visualização do chamado (admin, cliente, operador).
 * IDs configuráveis via id_prefix para várias instâncias na mesma página.
 *
 * @var array<string, mixed>|null $ch_viz_mapa
 */
if (!function_exists('crm_google_maps_embed_place_url')) {
    require_once __DIR__ . '/../chamado_geo.php';
}

$chViz = array_merge([
    'lat' => null,
    'lng' => null,
    'google_maps_api_key' => '',
    'default_view' => 'map',
    'geocode_api' => 'geocode_nominatim_api.php',
    'lat_input_id' => 'chamado_latitude',
    'lng_input_id' => 'chamado_longitude',
    'id_prefix' => 'chamado-viz',
    'hide_section_title' => false,
    'wrapper_class' => '',
    'aria_label' => 'Visualização do local do chamado',
], is_array($ch_viz_mapa ?? null) ? $ch_viz_mapa : []);

$chVizPrefix = preg_replace('/[^a-z0-9\-]/', '', (string) ($chViz['id_prefix'] ?? 'chamado-viz'));
if ($chVizPrefix === '') {
    $chVizPrefix = 'chamado-viz';
}
$chVizId = static function (string $suffix) use ($chVizPrefix): string {
    return $chVizPrefix . '-' . $suffix;
};

$chVizLa = $chViz['lat'];
$chVizLo = $chViz['lng'];
$chVizTemCoords = $chVizLa !== null && $chVizLo !== null
    && is_finite((float) $chVizLa) && is_finite((float) $chVizLo);
$chVizGoogleKey = trim((string) ($chViz['google_maps_api_key'] ?? ''));
if ($chVizGoogleKey === '') {
    $chVizGoogleKey = crm_google_maps_api_key();
}
$chVizUseGoogle = $chVizGoogleKey !== '';
$chVizMapEmbedSrc = '';
if ($chVizTemCoords && $chVizUseGoogle) {
    $chVizMapEmbedSrc = crm_google_maps_embed_place_url((float) $chVizLa, (float) $chVizLo, 16, $chVizGoogleKey);
}
$chVizDefaultView = in_array($chViz['default_view'] ?? '', ['map', 'street'], true)
    ? (string) $chViz['default_view']
    : 'map';
$chVizMapBtnClass = $chVizDefaultView === 'map' ? 'btn-primary' : 'btn-secondary';
$chVizSvBtnClass = $chVizDefaultView === 'street' ? 'btn-primary' : 'btn-secondary';
$chVizWrapClass = trim('form-group full chamado-viz-loc-wrap ' . (string) ($chViz['wrapper_class'] ?? ''));
$chVizRootId = $chVizId('loc');
?>
<div class="<?= htmlspecialchars($chVizWrapClass, ENT_QUOTES, 'UTF-8') ?>" style="margin-top:8px;">
  <?php if (empty($chViz['hide_section_title'])): ?>
  <p class="os-pane-sub" style="margin:0 0 8px;">Localização no mapa</p>
  <?php endif; ?>
  <div id="<?= htmlspecialchars($chVizRootId, ENT_QUOTES, 'UTF-8') ?>" class="chamado-viz-loc os-ponto-preview"
       data-geocode-api="<?= htmlspecialchars((string) $chViz['geocode_api'], ENT_QUOTES, 'UTF-8') ?>"
       <?php if ($chVizGoogleKey !== ''): ?>
       data-google-maps-key="<?= htmlspecialchars($chVizGoogleKey, ENT_QUOTES, 'UTF-8') ?>"
       <?php endif; ?>
       <?php if ($chVizTemCoords): ?>
       data-lat="<?= htmlspecialchars((string) $chVizLa, ENT_QUOTES, 'UTF-8') ?>"
       data-lng="<?= htmlspecialchars((string) $chVizLo, ENT_QUOTES, 'UTF-8') ?>"
       <?php endif; ?>
       data-preview-default-view="<?= htmlspecialchars($chVizDefaultView, ENT_QUOTES, 'UTF-8') ?>"
       data-lat-input-id="<?= htmlspecialchars((string) $chViz['lat_input_id'], ENT_QUOTES, 'UTF-8') ?>"
       data-lng-input-id="<?= htmlspecialchars((string) $chViz['lng_input_id'], ENT_QUOTES, 'UTF-8') ?>">
    <p id="<?= htmlspecialchars($chVizId('sem-coord'), ENT_QUOTES, 'UTF-8') ?>" class="muted os-ponto-preview__hint"<?= $chVizTemCoords ? ' hidden' : '' ?>>Informe latitude e longitude no chamado para ver o mapa.</p>
    <p id="<?= htmlspecialchars($chVizId('geocode-hint'), ENT_QUOTES, 'UTF-8') ?>" class="muted os-ponto-preview__hint" style="margin:0 0 8px;" hidden></p>
    <div id="<?= htmlspecialchars($chVizId('streetview-block'), ENT_QUOTES, 'UTF-8') ?>" class="chamado-ponto-streetview"<?= $chVizTemCoords ? '' : ' hidden' ?>>
      <div class="chamado-ponto-streetview__head">
        <div class="chamado-loc-view-bar chamado-ponto-streetview__head-actions" role="group" aria-label="<?= htmlspecialchars((string) $chViz['aria_label'], ENT_QUOTES, 'UTF-8') ?>">
          <button type="button" class="btn btn-sm <?= htmlspecialchars($chVizMapBtnClass, ENT_QUOTES, 'UTF-8') ?>" id="<?= htmlspecialchars($chVizId('map-btn'), ENT_QUOTES, 'UTF-8') ?>">Mapa</button>
          <button type="button" class="btn btn-sm <?= htmlspecialchars($chVizSvBtnClass, ENT_QUOTES, 'UTF-8') ?>" id="<?= htmlspecialchars($chVizId('sv-btn'), ENT_QUOTES, 'UTF-8') ?>">Street View</button>
          <?php if ($chVizUseGoogle): ?>
          <button type="button" class="btn btn-sm btn-ghost" id="<?= htmlspecialchars($chVizId('map-refresh-btn'), ENT_QUOTES, 'UTF-8') ?>" title="Recarregar mapa com latitude e longitude atuais">Atualizar mapa</button>
          <?php endif; ?>
        </div>
      </div>
      <div class="chamado-ponto-streetview__frame-wrap">
        <iframe id="<?= htmlspecialchars($chVizId('sv-frame'), ENT_QUOTES, 'UTF-8') ?>" class="chamado-ponto-streetview__frame" title="Street View do chamado" allowfullscreen loading="lazy" allow="fullscreen" hidden></iframe>
        <div id="<?= htmlspecialchars($chVizId('sv-fallback'), ENT_QUOTES, 'UTF-8') ?>" class="chamado-sv-embed-fallback" hidden>
          <p class="chamado-sv-embed-fallback__text muted">A visualização embutida do Street View não está disponível neste navegador. Abra o panorama no Google Maps.</p>
          <a class="btn btn-primary btn-sm chamado-sv-embed-fallback__btn" href="#" target="_blank" rel="noopener noreferrer">Abrir Street View no Google Maps</a>
        </div>
        <?php if ($chVizUseGoogle): ?>
        <iframe id="<?= htmlspecialchars($chVizId('map-embed'), ENT_QUOTES, 'UTF-8') ?>" class="chamado-map-embed-frame chamado-ponto-streetview__frame" title="Mapa do chamado" allowfullscreen loading="lazy" hidden<?= $chVizMapEmbedSrc !== '' ? ' data-embed-src="' . htmlspecialchars($chVizMapEmbedSrc, ENT_QUOTES, 'UTF-8') . '"' : '' ?>></iframe>
        <div id="<?= htmlspecialchars($chVizId('map-fallback'), ENT_QUOTES, 'UTF-8') ?>" class="chamado-sv-embed-fallback chamado-map-embed-fallback" hidden>
          <p class="chamado-sv-embed-fallback__text muted">O mapa embutido não carregou (verifique a chave e o referrer no Google Cloud).</p>
          <a class="btn btn-primary btn-sm chamado-sv-embed-fallback__btn" href="#" target="_blank" rel="noopener noreferrer">Abrir no Google Maps</a>
        </div>
        <?php endif; ?>
        <div id="<?= htmlspecialchars($chVizId('map-mini'), ENT_QUOTES, 'UTF-8') ?>" class="chamado-map-mini chamado-map-mini--in-frame"<?= $chVizUseGoogle ? ' hidden' : '' ?> hidden aria-label="Mapa interativo do chamado"></div>
      </div>
      <p class="chamado-loc-sv-external-wrap" style="margin:8px 0 0;">
        <a id="<?= htmlspecialchars($chVizId('sv-external'), ENT_QUOTES, 'UTF-8') ?>" class="chamado-loc-sv-external muted" href="#" target="_blank" rel="noopener noreferrer" hidden>Abrir Street View no Google Maps</a>
      </p>
    </div>
  </div>
</div>
