<?php
if (!function_exists('chamado_google_maps_embed_api_key')) {
    require_once __DIR__ . '/../chamado_geo.php';
}

/**
 * Preview dual: mapa Leaflet + Street View (barra de alternância).
 *
 * @var array<string, mixed> $chamadoLocPreview
 *   container_id, container_class,
 *   map_btn_id, street_btn_id,
 *   map_id, map_class,
 *   sv_wrap_id, sv_wrap_class,
 *   sv_frame_id, sv_label_id, show_sv_label,
 *   sv_external_id, sv_fallback_id, google_maps_api_key
 */
$chLoc = array_merge([
    'container_id'   => 'chamado-loc-preview',
    'container_class'=> '',
    'map_btn_id'     => 'chamado-loc-map-btn',
    'street_btn_id'  => 'chamado-loc-sv-btn',
    'map_id'         => 'chamado-map-mini',
    'map_class'      => 'chamado-map-mini',
    'sv_wrap_id'     => 'chamado-loc-sv-wrap',
    'sv_wrap_class'  => 'chamado-ponto-streetview chamado-loc-sv-admin chamado-location-map',
    'sv_frame_id'    => 'chamado-loc-sv-frame',
    'sv_label_id'    => 'chamado-loc-sv-label',
    'sv_external_id' => 'chamado-loc-sv-external',
    'sv_fallback_id' => 'chamado-loc-sv-fallback',
    'google_maps_api_key' => '',
    'show_sv_label'  => false,
], $chamadoLocPreview ?? []);

if (($chLoc['google_maps_api_key'] ?? '') === '') {
    $chLoc['google_maps_api_key'] = crm_google_maps_api_key();
}

$chLocUseGoogleEmbed = crm_google_maps_has_api_key();
$chLocMapEmbedSrc = '';
$chLocInitLa = $chamadoLocPreview['initial_lat'] ?? null;
$chLocInitLo = $chamadoLocPreview['initial_lng'] ?? null;
if ($chLocUseGoogleEmbed && $chLocInitLa !== null && $chLocInitLo !== null) {
    $chLocInitLa = (float) $chLocInitLa;
    $chLocInitLo = (float) $chLocInitLo;
    if (is_finite($chLocInitLa) && is_finite($chLocInitLo)) {
        $chLocMapEmbedSrc = crm_google_maps_embed_place_url($chLocInitLa, $chLocInitLo, 16);
    }
}

$chLocContainerClass = trim('chamado-loc-preview-wrap ' . (string) ($chLoc['container_class'] ?? ''));
$chLocGoogleKey = trim((string) ($chLoc['google_maps_api_key'] ?? ''));
$chLocMapEmbedId = trim((string) ($chLoc['map_embed_id'] ?? ($chLoc['map_id'] . '-embed')));
?>
<div id="<?= htmlspecialchars((string) $chLoc['container_id'], ENT_QUOTES, 'UTF-8') ?>" class="<?= htmlspecialchars($chLocContainerClass, ENT_QUOTES, 'UTF-8') ?>"<?= $chLocGoogleKey !== '' ? ' data-google-maps-key="' . htmlspecialchars($chLocGoogleKey, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
  <div class="chamado-loc-view-bar" role="group" aria-label="Visualização do local">
    <button type="button" class="btn btn-sm btn-secondary" id="<?= htmlspecialchars((string) $chLoc['map_btn_id'], ENT_QUOTES, 'UTF-8') ?>">Mapa</button>
    <button type="button" class="btn btn-sm btn-primary" id="<?= htmlspecialchars((string) $chLoc['street_btn_id'], ENT_QUOTES, 'UTF-8') ?>">Street View</button>
    <?php if ($chLocUseGoogleEmbed): ?>
    <button type="button" class="btn btn-sm btn-ghost" id="<?= htmlspecialchars((string) ($chLoc['map_refresh_btn_id'] ?? 'chamado-loc-map-refresh-btn'), ENT_QUOTES, 'UTF-8') ?>" title="Recarregar mapa com latitude e longitude atuais">Atualizar mapa</button>
    <?php endif; ?>
  </div>
  <?php if ($chLocUseGoogleEmbed): ?>
  <div id="<?= htmlspecialchars((string) $chLoc['sv_wrap_id'], ENT_QUOTES, 'UTF-8') ?>" class="<?= htmlspecialchars((string) $chLoc['sv_wrap_class'], ENT_QUOTES, 'UTF-8') ?>" hidden>
    <div class="chamado-ponto-streetview__frame-wrap">
      <iframe id="<?= htmlspecialchars($chLocMapEmbedId, ENT_QUOTES, 'UTF-8') ?>" class="chamado-map-embed-frame chamado-ponto-streetview__frame" title="Mapa do chamado" allowfullscreen loading="lazy" hidden<?= $chLocMapEmbedSrc !== '' ? ' data-embed-src="' . htmlspecialchars($chLocMapEmbedSrc, ENT_QUOTES, 'UTF-8') . '"' : '' ?>></iframe>
      <div id="<?= htmlspecialchars((string) ($chLoc['map_fallback_id'] ?? 'chamado-loc-map-fallback'), ENT_QUOTES, 'UTF-8') ?>" class="chamado-sv-embed-fallback chamado-map-embed-fallback" hidden>
        <p class="chamado-sv-embed-fallback__text muted">O mapa embutido não carregou. Abra no Google Maps.</p>
        <a class="btn btn-primary btn-sm chamado-sv-embed-fallback__btn" href="#" target="_blank" rel="noopener noreferrer">Abrir no Google Maps</a>
      </div>
      <iframe id="<?= htmlspecialchars((string) $chLoc['sv_frame_id'], ENT_QUOTES, 'UTF-8') ?>" class="chamado-ponto-streetview__frame" title="Street View do chamado" allowfullscreen loading="lazy" allow="fullscreen" hidden></iframe>
      <div id="<?= htmlspecialchars((string) $chLoc['sv_fallback_id'], ENT_QUOTES, 'UTF-8') ?>" class="chamado-sv-embed-fallback" hidden>
        <p class="chamado-sv-embed-fallback__text muted">A visualização embutida do Street View não está disponível neste navegador. Abra o panorama no Google Maps.</p>
        <a class="btn btn-primary btn-sm chamado-sv-embed-fallback__btn" href="#" target="_blank" rel="noopener noreferrer">Abrir Street View no Google Maps</a>
      </div>
    </div>
    <p class="chamado-loc-sv-external-wrap" style="margin:8px 0 0;">
      <a id="<?= htmlspecialchars((string) $chLoc['sv_external_id'], ENT_QUOTES, 'UTF-8') ?>" class="chamado-loc-sv-external muted" href="#" target="_blank" rel="noopener noreferrer" hidden>Abrir Street View no Google Maps</a>
    </p>
  </div>
  <div id="<?= htmlspecialchars((string) $chLoc['map_id'], ENT_QUOTES, 'UTF-8') ?>" class="<?= htmlspecialchars((string) $chLoc['map_class'], ENT_QUOTES, 'UTF-8') ?>" hidden aria-hidden="true"></div>
  <?php else: ?>
  <div id="<?= htmlspecialchars((string) $chLoc['map_id'], ENT_QUOTES, 'UTF-8') ?>" class="<?= htmlspecialchars((string) $chLoc['map_class'], ENT_QUOTES, 'UTF-8') ?>" hidden aria-label="Mapa do chamado"></div>
  <div id="<?= htmlspecialchars((string) $chLoc['sv_wrap_id'], ENT_QUOTES, 'UTF-8') ?>" class="<?= htmlspecialchars((string) $chLoc['sv_wrap_class'], ENT_QUOTES, 'UTF-8') ?>" hidden>
    <?php if (!empty($chLoc['show_sv_label'])): ?>
    <div class="chamado-ponto-streetview__head">
      <span id="<?= htmlspecialchars((string) $chLoc['sv_label_id'], ENT_QUOTES, 'UTF-8') ?>" class="chamado-ponto-streetview__label">Street View</span>
    </div>
    <?php endif; ?>
    <div class="chamado-ponto-streetview__frame-wrap">
      <iframe id="<?= htmlspecialchars((string) $chLoc['sv_frame_id'], ENT_QUOTES, 'UTF-8') ?>" class="chamado-ponto-streetview__frame" title="Street View do chamado" allowfullscreen loading="lazy" allow="fullscreen" hidden></iframe>
      <div id="<?= htmlspecialchars((string) $chLoc['sv_fallback_id'], ENT_QUOTES, 'UTF-8') ?>" class="chamado-sv-embed-fallback" hidden>
        <p class="chamado-sv-embed-fallback__text muted">A visualização embutida do Street View não está disponível neste navegador. Abra o panorama no Google Maps.</p>
        <a class="btn btn-primary btn-sm chamado-sv-embed-fallback__btn" href="#" target="_blank" rel="noopener noreferrer">Abrir Street View no Google Maps</a>
      </div>
    </div>
    <p class="chamado-loc-sv-external-wrap" style="margin:8px 0 0;">
      <a id="<?= htmlspecialchars((string) $chLoc['sv_external_id'], ENT_QUOTES, 'UTF-8') ?>" class="chamado-loc-sv-external muted" href="#" target="_blank" rel="noopener noreferrer" hidden>Abrir Street View no Google Maps</a>
    </p>
  </div>
  <?php endif; ?>
</div>
