<?php
declare(strict_types=1);
/**
 * Bloco «Localização no mapa» (Mapa / Street View).
 * Incluído por chamado_os_grid_markup.php — variáveis já definidas no escopo.
 */
?>
<div class="form-group full os-ponto-preview-wrap" style="margin-top:8px;">
  <p class="os-pane-sub" style="margin:0 0 8px;">Localização no mapa</p>
  <div id="os_ponto_preview" class="os-ponto-preview"<?= $ch_os_preview_inicial_visivel ? '' : ' hidden' ?>
       data-geocode-api="<?= htmlspecialchars((string) $ch_os_geocode_api_url, ENT_QUOTES, 'UTF-8') ?>"
       <?php if ($ch_os_google_maps_api_key !== ''): ?>
       data-google-maps-key="<?= htmlspecialchars($ch_os_google_maps_api_key, ENT_QUOTES, 'UTF-8') ?>"
       <?php endif; ?>
       <?php if (is_array($ch_os_loc_preview) && $ch_os_loc_preview['lat'] !== null && $ch_os_loc_preview['lng'] !== null): ?>
       data-initial-lat="<?= htmlspecialchars((string) $ch_os_loc_preview['lat'], ENT_QUOTES, 'UTF-8') ?>"
       data-initial-lng="<?= htmlspecialchars((string) $ch_os_loc_preview['lng'], ENT_QUOTES, 'UTF-8') ?>"
       <?php endif; ?>
       <?php if ($ch_os_preview_default_view !== null): ?>
       data-preview-default-view="<?= htmlspecialchars($ch_os_preview_default_view, ENT_QUOTES, 'UTF-8') ?>"
       <?php endif; ?>>
    <div id="os_ponto_preview_endereco" class="chamado-ponto-endereco os-ponto-preview__endereco" hidden>
      <span class="chamado-ponto-endereco__label">Endereço do ponto</span>
      <div id="os_ponto_preview_endereco_text" class="chamado-ponto-endereco__text"></div>
    </div>
    <p id="os_ponto_sem_coord" class="muted os-ponto-preview__hint" hidden>Informe latitude e longitude no chamado, ou preencha o endereço nos campos acima, para ver o mapa.</p>
    <p id="os_ponto_geocode_hint" class="muted os-ponto-preview__hint" style="margin:0 0 8px;"<?= (is_array($ch_os_loc_preview) && ($ch_os_loc_preview['modo'] ?? '') === 'geocode') ? '' : ' hidden' ?>><?= (is_array($ch_os_loc_preview) && ($ch_os_loc_preview['modo'] ?? '') === 'geocode') ? 'A localizar endereço no mapa…' : '' ?></p>
    <div id="os_ponto_streetview_block" class="chamado-ponto-streetview"<?= $ch_os_preview_inicial_visivel && is_array($ch_os_loc_preview) && ($ch_os_loc_preview['modo'] ?? '') !== 'none' ? '' : ' hidden' ?>>
      <div class="chamado-ponto-streetview__head">
        <div class="chamado-loc-view-bar chamado-ponto-streetview__head-actions" role="group" aria-label="Visualização do local">
          <button type="button" class="btn btn-sm <?= htmlspecialchars($chOsMapBtnClass, ENT_QUOTES, 'UTF-8') ?>" id="os_ponto_map_btn">Mapa</button>
          <button type="button" class="btn btn-sm <?= htmlspecialchars($chOsSvBtnClass, ENT_QUOTES, 'UTF-8') ?>" id="os_ponto_sv_btn">Street View</button>
          <?php if (!empty($ch_os_use_google_maps_embed)): ?>
          <button type="button" class="btn btn-sm btn-ghost" id="os_ponto_map_refresh_btn" title="Recarregar mapa com latitude e longitude atuais">Atualizar mapa</button>
          <?php endif; ?>
        </div>
      </div>
      <div class="chamado-ponto-streetview__frame-wrap">
        <iframe id="os_ponto_streetview_frame" class="chamado-ponto-streetview__frame" title="Localização do chamado no mapa" allowfullscreen loading="lazy" allow="fullscreen" hidden<?= $ch_os_sv_iframe_src !== '' ? ' data-sv-embed-src="' . htmlspecialchars($ch_os_sv_iframe_src, ENT_QUOTES, 'UTF-8') . '"' : '' ?>></iframe>
        <div id="os_ponto_sv_fallback" class="chamado-sv-embed-fallback" hidden>
          <p class="chamado-sv-embed-fallback__text muted">A visualização embutida do Street View não está disponível neste navegador. Abra o panorama no Google Maps.</p>
          <a class="btn btn-primary btn-sm chamado-sv-embed-fallback__btn" href="#" target="_blank" rel="noopener noreferrer">Abrir Street View no Google Maps</a>
        </div>
        <?php if (!empty($ch_os_use_google_maps_embed)): ?>
        <iframe id="os_ponto_map_embed" class="chamado-map-embed-frame chamado-ponto-streetview__frame" title="Mapa do chamado" allowfullscreen loading="lazy" hidden<?= ($ch_os_map_embed_src ?? '') !== '' ? ' data-embed-src="' . htmlspecialchars((string) $ch_os_map_embed_src, ENT_QUOTES, 'UTF-8') . '"' : '' ?>></iframe>
        <div id="os_ponto_map_fallback" class="chamado-sv-embed-fallback chamado-map-embed-fallback" hidden>
          <p class="chamado-sv-embed-fallback__text muted">O mapa embutido não carregou (verifique a chave e o referrer <code>https://localhost/crm_prefeitura/*</code> no Google Cloud).</p>
          <a class="btn btn-primary btn-sm chamado-sv-embed-fallback__btn" href="#" target="_blank" rel="noopener noreferrer">Abrir no Google Maps</a>
        </div>
        <?php endif; ?>
        <div id="os_ponto_map_mini" class="chamado-map-mini chamado-map-mini--in-frame"<?= !empty($ch_os_use_google_maps_embed) ? ' hidden' : '' ?> hidden aria-label="Mapa interativo do chamado"></div>
      </div>
      <p class="chamado-loc-sv-external-wrap" style="margin:8px 0 0;">
        <a id="os_ponto_sv_external" class="chamado-loc-sv-external muted" href="#" target="_blank" rel="noopener noreferrer" hidden>Abrir Street View no Google Maps</a>
      </p>
    </div>
  </div>
</div>
