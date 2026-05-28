<?php
declare(strict_types=1);

/**
 * Grelha estilo Ordem de Serviço (3 painéis: solicitante | local+classificação | descrição).
 *
 * Variáveis esperadas:
 * @var array<string,mixed> $ch_os_vals valores atuais (chaves como no banco)
 * @var string $ch_os_descricao valor do campo descrição
 * @var bool $ch_os_mostrar_ponto se deve exibir select de ponto de iluminação (só novo / quando lista existe)
 * @var array<int,array<string,mixed>> $ch_os_pontos_opcoes opções do select de ponto
 * @var bool $ch_os_mostrar_preview_mapa bloco Street View / mapa embutido (ex.: false em admin/chamado_novo.php)
 * @var string|null $ch_os_modo_include completo (default) | form (sem preview/scripts) | preview (só preview + scripts)
 */

require_once __DIR__ . '/chamado_os_fields.php';
require_once __DIR__ . '/chamado_geo.php';

if (!isset($ch_os_vals) || !is_array($ch_os_vals)) {
    $ch_os_vals = [];
}
if (!isset($ch_os_descricao)) {
    $ch_os_descricao = '';
}
if (!isset($ch_os_mostrar_ponto)) {
    $ch_os_mostrar_ponto = false;
}
if (!isset($ch_os_pontos_opcoes)) {
    $ch_os_pontos_opcoes = [];
}
if (!isset($ch_os_mostrar_preview_mapa)) {
    $ch_os_mostrar_preview_mapa = true;
}
if (!isset($ch_os_ocultar_solicitante)) {
    $ch_os_ocultar_solicitante = false;
}
if (!isset($ch_os_readonly_endereco)) {
    $ch_os_readonly_endereco = chamado_os_endereco_ja_cadastrado($ch_os_vals);
}
if (!isset($ch_os_geocode_api_url)) {
    $ch_os_geocode_api_url = 'geocode_nominatim_api.php';
}
if (!isset($ch_os_preview_default_view)) {
    $ch_os_preview_default_view = null;
}
$ch_os_preview_default_view = in_array($ch_os_preview_default_view, ['map', 'street'], true)
    ? $ch_os_preview_default_view
    : null;
if (!isset($ch_os_ponto_atual)) {
    $ch_os_ponto_atual = null;
    $pidOsMarkup = (int) ($ch_os_vals['ponto_iluminacao_id'] ?? 0);
    if ($pidOsMarkup > 0) {
        foreach ($ch_os_pontos_opcoes as $pOpt) {
            if ((int) ($pOpt['id'] ?? 0) === $pidOsMarkup) {
                $ch_os_ponto_atual = $pOpt;
                break;
            }
        }
    }
}

$ch_os_req = static function (string $label, bool $required = false): string {
    if (!$required) {
        return $label;
    }

    return $label . ' <span class="field-required" aria-hidden="true">*</span>';
};

$f = static function (string $k, string $def = ''): string {
    global $ch_os_vals;
    $v = $ch_os_vals[$k] ?? $def;
    if ($v === null) {
        $v = '';
    }

    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};

$dab = (string) ($ch_os_vals['data_abertura_os'] ?? '');
if (($dab === '' || $dab === '0000-00-00') && !empty($ch_os_vals['data'])) {
    $dab = substr((string) $ch_os_vals['data'], 0, 10);
}
if ($dab === '' || $dab === '0000-00-00') {
    $dab = date('Y-m-d');
}

$origOpts = chamado_os_opcoes_origem();
$origOsValor = chamado_os_origem_valor_form((string) ($ch_os_vals['origem_os'] ?? ''));
$probOpts = chamado_os_opcoes_problema();
$chOsReadonlyAddr = !empty($ch_os_readonly_endereco);

$ch_os_loc_preview = null;
if ($ch_os_mostrar_preview_mapa) {
    $ch_os_loc_preview = chamado_resolver_localizacao_preview($ch_os_vals, is_array($ch_os_ponto_atual) ? $ch_os_ponto_atual : null);
}
$ch_os_google_maps_api_key = crm_google_maps_api_key();
$ch_os_use_google_maps_embed = crm_google_maps_has_api_key();
$ch_os_load_leaflet = !$ch_os_use_google_maps_embed;
$ch_os_preview_inicial_visivel = is_array($ch_os_loc_preview) && !empty($ch_os_loc_preview['show_preview']);
$ch_os_sv_iframe_src = '';
$ch_os_map_embed_src = '';
$ch_os_sv_tab_href = '#';
$ch_os_preview_tem_coords = is_array($ch_os_loc_preview)
    && $ch_os_loc_preview['lat'] !== null
    && $ch_os_loc_preview['lng'] !== null;
if ($ch_os_preview_tem_coords) {
    $chOsLa = (float) $ch_os_loc_preview['lat'];
    $chOsLo = (float) $ch_os_loc_preview['lng'];
    $chOsLl = rawurlencode(number_format($chOsLa, 7, '.', '') . ',' . number_format($chOsLo, 7, '.', ''));
    $ch_os_sv_iframe_src = crm_google_maps_embed_streetview_url($chOsLa, $chOsLo, $ch_os_google_maps_api_key);
    $ch_os_map_embed_src = crm_google_maps_embed_place_url($chOsLa, $chOsLo, 16, $ch_os_google_maps_api_key);
    $ch_os_sv_tab_href = 'https://www.google.com/maps/@?api=1&map_action=pano&viewpoint=' . $chOsLl;
}

if (!isset($ch_os_modo_include)) {
    $ch_os_modo_include = 'completo';
}
if (!in_array($ch_os_modo_include, ['completo', 'form', 'preview'], true)) {
    $ch_os_modo_include = 'completo';
}
$ch_os_emit_form = $ch_os_modo_include !== 'preview';
$ch_os_emit_preview = $ch_os_mostrar_preview_mapa && $ch_os_modo_include !== 'form';
$ch_os_render_preview_inline = $ch_os_emit_preview && $ch_os_modo_include !== 'preview';
$ch_os_render_preview_standalone = $ch_os_emit_preview && $ch_os_modo_include === 'preview';

if (!isset($ch_os_use_visualizacao_mapa_component)) {
    $ch_os_use_visualizacao_mapa_component = false;
}
if (!empty($ch_os_use_visualizacao_mapa_component)) {
    $ch_os_render_preview_inline = false;
    $ch_os_render_preview_standalone = false;
    $ch_os_emit_preview = false;
}

$chOsMapBtnClass = 'btn-secondary';
$chOsSvBtnClass = 'btn-primary';
if ($ch_os_preview_default_view === 'map') {
    $chOsMapBtnClass = 'btn-primary';
    $chOsSvBtnClass = 'btn-secondary';
} elseif ($ch_os_preview_default_view === 'street') {
    $chOsMapBtnClass = 'btn-secondary';
    $chOsSvBtnClass = 'btn-primary';
}

?>
<?php if ($ch_os_emit_form): ?>
<div class="os-form-layout">
  <?php if (empty($ch_os_ocultar_solicitante)): ?>
  <div class="os-section">
    <div class="os-section-header">Dados do solicitante</div>
    <div class="os-section-body">
      <div class="form-grid form-grid--os-pane">
        <div class="form-group">
          <label for="os_cpf">CPF</label>
          <input type="text" id="os_cpf" name="contribuinte_cpf" class="input" maxlength="20"
                 data-crm-mask="cpf" inputmode="numeric" autocomplete="off"
                 placeholder="000.000.000-00" value="<?= $f('contribuinte_cpf') ?>">
        </div>
        <div class="form-group">
          <label for="os_nome">Nome</label>
          <input type="text" id="os_nome" name="contribuinte_nome" class="input" maxlength="200"
                 placeholder="Nome completo"
                 value="<?= $f('contribuinte_nome') ?>">
        </div>
        <div class="form-group">
          <label for="os_data_abertura">Data de abertura</label>
          <input type="date" id="os_data_abertura" name="data_abertura_os" class="input"
                 value="<?= htmlspecialchars($dab, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="form-group">
          <label for="os_tel">Telefone</label>
          <input type="text" id="os_tel" name="contribuinte_telefone" class="input" maxlength="40"
                 data-crm-mask="telefone" inputmode="tel" autocomplete="tel"
                 placeholder="(00) 00000-0000"
                 value="<?= $f('contribuinte_telefone') ?>">
        </div>
        <div class="form-group">
          <label for="os_email">E-mail</label>
          <input type="email" id="os_email" name="contribuinte_email" class="input" maxlength="160"
                 data-crm-mask="email" autocomplete="email"
                 placeholder="nome@exemplo.com.br"
                 value="<?= $f('contribuinte_email') ?>">
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="os-section os-section--local-problema">
    <div class="os-section-header">Endereço e problema no local</div>
    <div class="os-section-body">
      <div class="form-grid form-grid--os-pane">
        <p class="os-pane-sub">Endereço</p>
        <?php if (!$chOsReadonlyAddr && !chamado_tem_endereco_cadastrado($ch_os_vals, $ch_os_ponto_atual)): ?>
        <p class="muted" style="font-size:13px;margin:0 0 10px;line-height:1.45;">
          Nenhum endereço cadastrado neste chamado — preencha os campos abaixo ou selecione um poste de iluminação (alterações gravadas automaticamente).
        </p>
        <?php endif; ?>
        <?php if ($chOsReadonlyAddr): ?>
        <div class="chamado-os-endereco-readonly" aria-label="Endereço cadastrado">
          <?php
          $enderecoExibir = chamado_endereco_efetivo($ch_os_vals, $ch_os_ponto_atual);
          if ($enderecoExibir === '') {
              $enderecoExibir = '—';
          }
          $latExibir = trim((string) ($ch_os_vals['latitude'] ?? ''));
          $lngExibir = trim((string) ($ch_os_vals['longitude'] ?? ''));
          ?>
          <p class="chamado-ponto-endereco__text"><?= htmlspecialchars($enderecoExibir, ENT_QUOTES, 'UTF-8') ?></p>
          <?php if ($latExibir !== '' || $lngExibir !== ''): ?>
          <p class="muted" style="margin-top:8px;font-size:13px;">
            Coordenadas: <?= htmlspecialchars($latExibir !== '' ? $latExibir : '—', ENT_QUOTES, 'UTF-8') ?>
            · <?= htmlspecialchars($lngExibir !== '' ? $lngExibir : '—', ENT_QUOTES, 'UTF-8') ?>
          </p>
          <?php endif; ?>
        </div>
        <?php if ($ch_os_mostrar_preview_mapa): ?>
        <input type="hidden" id="chamado_latitude" value="<?= $f('latitude') ?>">
        <input type="hidden" id="chamado_longitude" value="<?= $f('longitude') ?>">
        <input type="hidden" id="os_cep" value="<?= $f('os_cep') ?>">
        <input type="hidden" id="os_logradouro" value="<?= $f('os_logradouro') ?>">
        <input type="hidden" id="os_numero" value="<?= $f('os_numero') ?>">
        <input type="hidden" id="os_complemento" value="<?= $f('os_complemento') ?>">
        <input type="hidden" id="os_bairro" value="<?= $f('os_bairro') ?>">
        <input type="hidden" id="os_cidade" value="<?= $f('os_cidade') ?>">
        <input type="hidden" id="os_uf" value="<?= $f('os_uf') ?>">
        <?php endif; ?>
        <?php else: ?>
        <div class="os-addr-grid" role="group" aria-label="Endereço">
          <div class="form-group os-addr-cep">
            <label for="os_cep">CEP</label>
            <input type="text" id="os_cep" name="os_cep" class="input" maxlength="12"
                   data-crm-mask="cep" inputmode="numeric" autocomplete="postal-code"
                   placeholder="00000-000" value="<?= $f('os_cep') ?>">
          </div>
          <div class="form-group os-addr-logra">
            <label for="os_logradouro">Endereço</label>
            <input type="text" id="os_logradouro" name="os_logradouro" class="input" maxlength="255"
                   placeholder="Rua, avenida…"
                   value="<?= $f('os_logradouro') ?>">
          </div>
          <div class="form-group os-addr-num">
            <label for="os_numero">Número</label>
            <input type="text" id="os_numero" name="os_numero" class="input" maxlength="32"
                   placeholder="Nº"
                   value="<?= $f('os_numero') ?>">
          </div>
          <div class="form-group os-addr-comp">
            <label for="os_complemento">Complemento</label>
            <input type="text" id="os_complemento" name="os_complemento" class="input" maxlength="160"
                   placeholder="Apto., bloco…"
                   value="<?= $f('os_complemento') ?>">
          </div>
          <div class="form-group os-addr-bairro">
            <label for="os_bairro">Bairro</label>
            <input type="text" id="os_bairro" name="os_bairro" class="input" maxlength="120"
                   placeholder="Bairro"
                   value="<?= $f('os_bairro') ?>">
          </div>
          <div class="form-group os-addr-cidade">
            <label for="os_cidade">Cidade</label>
            <input type="text" id="os_cidade" name="os_cidade" class="input" maxlength="160"
                   placeholder="Cidade"
                   value="<?= $f('os_cidade') ?>">
          </div>
          <div class="form-group os-addr-uf">
            <label for="os_uf">UF</label>
            <input type="text" id="os_uf" name="os_uf" class="input input--uf" maxlength="2"
                   placeholder="SP" value="<?= $f('os_uf') ?>">
          </div>
        </div>
        <?php endif; ?>

        <p class="os-pane-sub os-pane-sub--divider">Classificação</p>
        <div class="form-group">
          <label for="os_origem"><?= $ch_os_req('Origem da OS', true) ?></label>
          <select id="os_origem" name="origem_os" class="select" required>
            <option value="">Selecione...</option>
            <?php foreach ($origOpts as $val => $lab): ?>
              <option value="<?= htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8') ?>"<?= $origOsValor === (string) $val ? ' selected' : '' ?>><?= htmlspecialchars($lab, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="os_problema"><?= $ch_os_req('Problema', true) ?></label>
          <select id="os_problema" name="problema_os" class="select" required>
            <option value="">Selecione...</option>
            <?php foreach ($probOpts as $val => $lab): ?>
              <option value="<?= htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8') ?>"<?= ((string) ($ch_os_vals['problema_os'] ?? '') === (string) $val) ? ' selected' : '' ?>><?= htmlspecialchars($lab, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if (!$chOsReadonlyAddr): ?>
        <p class="os-pane-sub os-pane-sub--divider">Coordenadas e referência</p>
        <div class="form-group">
          <label for="chamado_latitude">Latitude</label>
          <input type="text" id="chamado_latitude" name="latitude" class="input" inputmode="decimal"
                 placeholder="-23.5614" value="<?= $f('latitude') ?>">
        </div>
        <div class="form-group">
          <label for="chamado_longitude">Longitude</label>
          <input type="text" id="chamado_longitude" name="longitude" class="input" inputmode="decimal"
                 placeholder="-46.6559" value="<?= $f('longitude') ?>">
        </div>
        <?php endif; ?>
        <div class="form-group full">
          <label for="os_ref">Ponto de referência</label>
          <input type="text" id="os_ref" name="ponto_referencia" class="input" maxlength="255"
                 placeholder="Ex.: em frente à praça, próximo ao…"
                 value="<?= $f('ponto_referencia') ?>">
        </div>
        <?php if ($ch_os_mostrar_ponto && !empty($ch_os_pontos_opcoes)): ?>
        <div class="form-group full">
          <label for="ponto_iluminacao_id">Ponto de iluminação</label>
          <select id="ponto_iluminacao_id" name="ponto_iluminacao_id" class="select"
                  data-crm-custom-select-search="1"
                  data-crm-custom-select-search-placeholder="Filtrar pontos…">
            <option value="0">— Sem vínculo com ponto —</option>
            <?php foreach ($ch_os_pontos_opcoes as $p): ?>
            <?php
              $__osFillPonto = chamado_os_fill_payload_from_ponto($p);
              $__osFillJson  = htmlspecialchars(
                  json_encode($__osFillPonto, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT),
                  ENT_QUOTES,
                  'UTF-8'
              );
            ?>
            <option
              value="<?= (int) ($p['id'] ?? 0) ?>"
              data-cliente="<?= (int) ($p['cliente_id'] ?? 0) ?>"
              data-endereco="<?= htmlspecialchars((string) ($p['endereco_completo'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
              data-lat="<?= htmlspecialchars((string) ($p['latitude'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
              data-lng="<?= htmlspecialchars((string) ($p['longitude'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
              data-os-fill="<?= $__osFillJson ?>"
              <?= (int) ($ch_os_vals['ponto_iluminacao_id'] ?? 0) === (int) ($p['id'] ?? 0) ? 'selected' : '' ?>>
              <?php
              $__lbl = (string) ($p['codigo_poste'] ?? '');
              if (!empty($p['cliente_empresa'])) {
                  $__lbl .= ' · ' . (string) $p['cliente_empresa'];
              } elseif (!empty($p['bairro'])) {
                  $__lbl .= ' · ' . (string) $p['bairro'];
              }
              echo htmlspecialchars($__lbl);
            ?>
            </option>
            <?php endforeach; ?>
          </select>
          <small class="muted" style="display:block;margin-top:8px;"><?php if ($ch_os_mostrar_preview_mapa): ?>Ao selecionar um ponto, os campos de endereço e coordenadas do chamado são preenchidos com os dados do poste (quando existirem). O mapa usa latitude/longitude ou endereço; o Street View só é carregado se você clicar em «Ver Street View» (nem todo local tem cobertura).<?php else: ?>Ao selecionar um ponto, os campos de endereço e coordenadas do chamado são preenchidos com os dados do poste (quando existirem).<?php endif; ?></small>
        </div>
        <?php elseif ($ch_os_mostrar_preview_mapa && $ch_os_modo_include === 'form'): ?>
        <p class="muted os-pane-sub" style="margin:0 0 4px;">A localização no mapa aparece abaixo. Use os botões Mapa e Street View — muitos locais não têm cobertura de Street View.</p>
        <?php elseif ($ch_os_mostrar_preview_mapa): ?>
        <p class="muted os-pane-sub" style="margin:0 0 4px;">O mapa abaixo usa latitude, longitude ou endereço deste chamado. Use os botões Mapa e Street View — muitos locais não têm cobertura de Street View.</p>
        <?php endif; ?>
        <?php if ($ch_os_render_preview_inline): ?>
        <?php include __DIR__ . '/partials/chamado_os_ponto_preview_block.php'; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="os-section os-section--desc">
    <div class="os-section-header">Descrição do problema</div>
    <div class="os-section-body">
      <div class="form-group" style="margin:0;">
        <label for="descricao"><?= $ch_os_req('Descrição', true) ?></label>
        <textarea id="descricao" name="descricao" class="textarea" rows="8" required
                  placeholder="Descreva o problema com o máximo de detalhes possível..."><?= htmlspecialchars((string) $ch_os_descricao, ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php if ($ch_os_render_preview_standalone): ?>
<p class="muted os-pane-sub" style="margin:0 0 4px;">O mapa abaixo usa latitude, longitude ou endereço deste chamado. Use os botões Mapa e Street View — muitos locais não têm cobertura de Street View.</p>
<?php include __DIR__ . '/partials/chamado_os_ponto_preview_block.php'; ?>
<?php endif; ?>
<?php if ($ch_os_emit_preview && empty($GLOBALS['crm_leaflet_os_preview_scripts'])): ?>
<?php $GLOBALS['crm_leaflet_os_preview_scripts'] = true; ?>
<?php
if (!isset($ch_os_assets_base)) {
    $ch_os_assets_base = '../';
}
?>
<?php
$ch_os_js_base = htmlspecialchars(rtrim((string) $ch_os_assets_base, '/') . '/', ENT_QUOTES, 'UTF-8');
$ch_os_gmaps_js = dirname(__DIR__) . '/assets/js/crm-google-maps.js';
$ch_os_dual_js = dirname(__DIR__) . '/assets/js/chamado-loc-dual-core.js';
?>
<script src="<?= $ch_os_js_base ?>assets/js/crm-google-maps.js?v=<?= (int) @filemtime($ch_os_gmaps_js) ?>"></script>
<?php if ($ch_os_load_leaflet): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<?php include __DIR__ . '/partials/leaflet_basemap_script.php'; ?>
<?php endif; ?>
<script src="<?= $ch_os_js_base ?>assets/js/chamado-loc-dual-core.js?v=<?= (int) @filemtime($ch_os_dual_js) ?>"></script>
<?php endif; ?>
<?php if ($ch_os_emit_preview && empty($GLOBALS['crm_os_ponto_preview_script_emitted'])): ?>
<?php $GLOBALS['crm_os_ponto_preview_script_emitted'] = true; ?>
<script>
(function (root) {
  function parseCoord(raw) {
    if (raw === null || raw === undefined) return null;
    var s = String(raw).trim().replace(',', '.');
    if (s === '') return null;
    var n = parseFloat(s);
    return isFinite(n) ? n : null;
  }

  function getPontoSelect() {
    return document.getElementById('ponto_iluminacao_id');
  }

  var mapGeocodeTimer = null;
  var mapGeocodeGeneration = 0;
  var lastMapGeocodeKey = '';
  var refreshPreviewTimer = null;
  var osLastCoords = { lat: null, lng: null };
  var osLeafletMap = null;
  var osLeafletMarker = null;
  var osPreviewView = 'map';
  /** null = auto (SV se disponível), 'map' | 'street' = escolha manual do usuário */
  var osPreviewUserChoice = null;
  var osStreetViewCheckGen = 0;
  var osSvLayoutGen = 0;
  var osMapLayoutGen = 0;
  var osMapBootDone = false;
  var osMapScheduleTimer = null;
  var osMapPendingCoords = null;
  var osMapLoadInFlight = false;

  function osLocPreviewDbg() {
    var host = (root.location && root.location.hostname) || '';
    if (host !== 'localhost' && host !== '127.0.0.1') return;
    var args = Array.prototype.slice.call(arguments);
    console.log.apply(console, ['[os-loc-preview]'].concat(args));
  }

  function osGetPreviewDefaultView() {
    var wrap = document.getElementById('os_ponto_preview');
    if (!wrap) return null;
    var v = (wrap.getAttribute('data-preview-default-view') || '').trim();
    return v === 'map' || v === 'street' ? v : null;
  }

  function buildMapGeocodeKey(tier, addrSnap) {
    return [
      tier,
      addrSnap,
      buildNominatimStreetLine(),
      osFieldVal('os_cidade'),
      osFieldVal('os_uf'),
      osFieldVal('os_cep').replace(/\D/g, ''),
      osFieldVal('os_numero')
    ].join('\u0001');
  }

  function scheduleRefreshChamadoLocPreview() {
    clearTimeout(refreshPreviewTimer);
    refreshPreviewTimer = root.setTimeout(function () {
      refreshPreviewTimer = null;
      refreshChamadoLocPreview();
    }, 400);
  }

  function osGetGoogleMapsApiKey() {
    var wrap = document.getElementById('os_ponto_preview');
    if (root.CrmChamadoLocDual && root.CrmChamadoLocDual.resolveGoogleMapsApiKey) {
      return root.CrmChamadoLocDual.resolveGoogleMapsApiKey(wrap);
    }
    return wrap ? (wrap.getAttribute('data-google-maps-key') || '').trim() : '';
  }

  function updateOsSvExternalLink(la, lo) {
    var link = document.getElementById('os_ponto_sv_external');
    if (!link || la === null || lo === null) return;
    if (root.CrmChamadoLocDual && root.CrmChamadoLocDual.updateStreetViewExternalLink) {
      root.CrmChamadoLocDual.updateStreetViewExternalLink(link, la, lo);
    } else {
      link.href =
        'https://www.google.com/maps/@?api=1&map_action=pano&viewpoint=' +
        encodeURIComponent(String(la) + ',' + String(lo));
      link.hidden = false;
    }
  }

  function osPreloadBothViews(lat, lng) {
    if (lat === null || lng === null) return;
    var iframe = document.getElementById('os_ponto_streetview_frame');
    var externalLink = document.getElementById('os_ponto_sv_external');
    if (root.CrmChamadoLocDual) {
      root.CrmChamadoLocDual.preloadBothViews(lat, lng, {
        ensureLeaflet: function (la, lo) {
          osEnsureLeafletInitialized(la, lo);
        },
        svFrame: iframe,
        skipSvIframe: true,
        apiKey: osGetGoogleMapsApiKey(),
        externalLinkEl: externalLink
      });
    } else {
      osEnsureLeafletInitialized(lat, lng);
      if (iframe) {
        iframe.setAttribute('data-sv-embed-src', osStreetViewEmbedUrl(lat, lng));
      }
      updateOsSvExternalLink(lat, lng);
    }
  }

  function setOsViewButtons(active) {
    var mapBtn = document.getElementById('os_ponto_map_btn');
    var svBtn = document.getElementById('os_ponto_sv_btn');
    if (mapBtn) {
      mapBtn.classList.toggle('btn-primary', active === 'map');
      mapBtn.classList.toggle('btn-ghost', active !== 'map');
      mapBtn.classList.remove('btn-secondary');
      mapBtn.hidden = false;
    }
    if (svBtn) {
      svBtn.classList.toggle('btn-primary', active === 'street');
      svBtn.classList.toggle('btn-ghost', active !== 'street');
      svBtn.classList.remove('btn-secondary');
      svBtn.hidden = false;
    }
  }

  function osStreetViewFrameWrap() {
    var svBlock = document.getElementById('os_ponto_streetview_block');
    return svBlock ? svBlock.querySelector('.chamado-ponto-streetview__frame-wrap') : null;
  }
  function getChamadoFormLatLng() {
    var la = document.getElementById('chamado_latitude');
    var lo = document.getElementById('chamado_longitude');
    return {
      lat: parseCoord(la && la.value),
      lng: parseCoord(lo && lo.value)
    };
  }

  function getChamadoLatLng() {
    var coords = getChamadoFormLatLng();
    if (coords.lat !== null && coords.lng !== null) {
      return coords;
    }
    var wrap = document.getElementById('os_ponto_preview');
    if (wrap) {
      return {
        lat: parseCoord(wrap.getAttribute('data-initial-lat')),
        lng: parseCoord(wrap.getAttribute('data-initial-lng'))
      };
    }
    return { lat: null, lng: null };
  }

  function getPontoOptionCoords(opt) {
    if (!opt) {
      return { lat: null, lng: null };
    }
    var lat = parseCoord(opt.getAttribute('data-lat'));
    var lng = parseCoord(opt.getAttribute('data-lng'));
    if (lat !== null && lng !== null) {
      return { lat: lat, lng: lng };
    }
    var raw = opt.getAttribute('data-os-fill') || '';
    if (raw) {
      try {
        var data = JSON.parse(raw);
        lat = parseCoord(data.latitude);
        lng = parseCoord(data.longitude);
        if (lat !== null && lng !== null) {
          return { lat: lat, lng: lng };
        }
      } catch (e) {
        /* ignore */
      }
    }
    return { lat: null, lng: null };
  }

  function clearChamadoCoordsFromPonto() {
    var la = document.getElementById('chamado_latitude');
    var lo = document.getElementById('chamado_longitude');
    if (la) la.value = '';
    if (lo) lo.value = '';
  }

  function osFieldVal(id) {
    var el = document.getElementById(id);
    if (!el) return '';
    return String(el.value || '')
      .trim()
      .replace(/\s+/g, ' ');
  }

  /** Número ainda não preenchido / placeholder — não enviar ao geocoder (evita "Avenida Paulista, Nº, ..."). */
  function isPlaceholderNumero(s) {
    s = String(s || '')
      .trim()
      .toLowerCase()
      .replace(/\./g, '');
    if (s === '') return true;
    if (/^n[º°']?o?$/.test(s)) return true;
    if (/^num(ero)?$/.test(s)) return true;
    if (/^s\/?n$/.test(s)) return true;
    if (/^[—\-–_]+$/.test(s)) return true;
    if (s === '#' || s === '0') return true;
    return false;
  }

  /** Complemento tipo "de 612 a 1510 - lado par" = faixa ao longo da via (não é apto/sala); não misturar com número da OS no geocoder. */
  function complementoEhFaixaNumeracao(comp) {
    return /\bde\s*\d+\s*a\s*\d+/i.test(String(comp || '').trim());
  }

  /**
   * Endereço só para geocódigo / iframe / Nominatim: sem complemento de faixa; sem "Nº" falso.
   * Igual ao que o Google Maps costuma usar para um pin (logradouro + número + cidade/UF).
   */
  function buildChamadoEnderecoParaGeocode() {
    var log = osFieldVal('os_logradouro');
    if (!log) return '';
    var num = osFieldVal('os_numero');
    var comp = osFieldVal('os_complemento');
    var bairro = osFieldVal('os_bairro');
    var cidade = osFieldVal('os_cidade');
    var uf = osFieldVal('os_uf').replace(/\./g, '').toUpperCase();
    var cepRaw = osFieldVal('os_cep').replace(/\D/g, '');

    var omitComp = complementoEhFaixaNumeracao(comp);

    var headParts = [log];
    if (!isPlaceholderNumero(num)) {
      headParts.push(num);
    }
    if (comp && !omitComp) {
      headParts.push(comp);
    }
    var head = headParts.join(', ');

    var tail = [];
    if (bairro) tail.push(bairro);
    if (cidade && uf) {
      tail.push(cidade + ' - ' + uf);
    } else if (cidade) {
      tail.push(cidade);
    } else if (uf) {
      tail.push(uf);
    }
    if (cepRaw.length === 8) {
      tail.push(cepRaw.replace(/^(\d{5})(\d{3})$/, '$1-$2'));
    }

    var full = [head].concat(tail).join(', ');
    if (full.indexOf('Brasil') === -1 && full.indexOf('Brazil') === -1) {
      full += ', Brasil';
    }
    return full;
  }

  /** Endereço para geocode tier 3 (sem CEP no texto). */
  function buildChamadoEnderecoSemCep() {
    var log = osFieldVal('os_logradouro');
    if (!log) return '';
    var num = osFieldVal('os_numero');
    var comp = osFieldVal('os_complemento');
    var bairro = osFieldVal('os_bairro');
    var cidade = osFieldVal('os_cidade');
    var uf = osFieldVal('os_uf').replace(/\./g, '').toUpperCase();
    var omitComp = complementoEhFaixaNumeracao(comp);
    var headParts = [log];
    if (!isPlaceholderNumero(num)) {
      headParts.push(num);
    }
    if (comp && !omitComp) {
      headParts.push(comp);
    }
    var head = headParts.join(', ');
    var tail = [];
    if (bairro) tail.push(bairro);
    if (cidade && uf) {
      tail.push(cidade + ' - ' + uf);
    } else if (cidade) {
      tail.push(cidade);
    } else if (uf) {
      tail.push(uf);
    }
    var full = [head].concat(tail).join(', ');
    if (full.indexOf('Brasil') === -1 && full.indexOf('Brazil') === -1) {
      full += ', Brasil';
    }
    return full;
  }

  /** Tier 2 — 1ª tentativa: CEP + número + logradouro (pin mais preciso para Street View). */
  function buildChamadoEnderecoCepNumeroPrioritario() {
    var log = osFieldVal('os_logradouro');
    var num = osFieldVal('os_numero');
    if (!log || isPlaceholderNumero(num)) return '';
    var cepRaw = osFieldVal('os_cep').replace(/\D/g, '');
    if (cepRaw.length !== 8) return '';
    var cepFmt = cepRaw.replace(/^(\d{5})(\d{3})$/, '$1-$2');
    var bairro = osFieldVal('os_bairro');
    var cidade = osFieldVal('os_cidade');
    var uf = osFieldVal('os_uf').replace(/\./g, '').toUpperCase();
    var parts = [cepFmt, String(num).trim(), log];
    if (bairro) parts.push(bairro);
    if (cidade && uf) {
      parts.push(cidade + ' - ' + uf);
    } else if (cidade) {
      parts.push(cidade);
    } else if (uf) {
      parts.push(uf);
    }
    var full = parts.join(', ');
    if (full.indexOf('Brasil') === -1 && full.indexOf('Brazil') === -1) {
      full += ', Brasil';
    }
    return full;
  }

  function buildChamadoEnderecoCepPrioritario() {
    return buildChamadoEnderecoCepNumeroPrioritario() || buildChamadoEnderecoParaGeocode();
  }

  function setChamadoFormCoords(la, lo) {
    var laEl = document.getElementById('chamado_latitude');
    var loEl = document.getElementById('chamado_longitude');
    if (laEl && la != null) laEl.value = String(la);
    if (loEl && lo != null) loEl.value = String(lo);
    if (la != null && lo != null) {
      var iframeSv = document.getElementById('os_ponto_streetview_frame');
      if (iframeSv) {
        iframeSv.setAttribute('data-sv-embed-src', osStreetViewEmbedUrl(la, lo));
        if (osPreviewView === 'street') {
          osScheduleStreetViewIframeLoad(la, lo);
        }
      }
      updateOsSvExternalLink(la, lo);
    }
  }

  /**
   * Cascata 0=ponto, 1=lat/lng form, 2=CEP+endereço, 3=só endereço, -1=nada.
   */
  function resolveOsMapTier() {
    var sel = getPontoSelect();
    var opt = sel ? sel.options[sel.selectedIndex] : null;
    var pontoChosen = !!(sel && opt && opt.value && opt.value !== '0');

    if (pontoChosen) {
      var pc = getPontoOptionCoords(opt);
      if (pc.lat !== null && pc.lng !== null) {
        return {
          tier: 0,
          lat: pc.lat,
          lng: pc.lng,
          addrGeocode: '',
          pontoChosen: true
        };
      }
      return { tier: -1, lat: null, lng: null, addrGeocode: '', pontoChosen: true };
    }

    var fc = getChamadoFormLatLng();
    if (fc.lat !== null && fc.lng !== null) {
      return {
        tier: 1,
        lat: fc.lat,
        lng: fc.lng,
        addrGeocode: '',
        pontoChosen: false
      };
    }

    if (!osFieldVal('os_logradouro')) {
      return { tier: -1, lat: null, lng: null, addrGeocode: '', pontoChosen: false };
    }

    if (osFieldVal('os_cep').replace(/\D/g, '').length === 8) {
      return {
        tier: 2,
        lat: null,
        lng: null,
        addrGeocode: buildChamadoEnderecoCepNumeroPrioritario() || buildChamadoEnderecoParaGeocode(),
        pontoChosen: false
      };
    }

    var addrSemCep = buildChamadoEnderecoSemCep();
    if (addrSemCep) {
      return {
        tier: 3,
        lat: null,
        lng: null,
        addrGeocode: addrSemCep,
        pontoChosen: false
      };
    }

    return { tier: -1, lat: null, lng: null, addrGeocode: '', pontoChosen: false };
  }

  /** Linha "street" para Nominatim: número + logradouro (ex.: "100 Avenida Paulista"). */
  function buildNominatimStreetLine() {
    var log = osFieldVal('os_logradouro');
    if (!log) return '';
    var num = osFieldVal('os_numero');
    if (!isPlaceholderNumero(num)) {
      return String(num).trim() + ' ' + log;
    }
    return log;
  }

  /** Ao vincular ponto: preenche endereço estruturado e coordenadas do chamado. */
  function fillEnderecoFromPonto(sel) {
    if (!sel) return;
    var opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value || opt.value === '0') return;
    var raw = opt.getAttribute('data-os-fill') || '';
    var data = null;
    if (raw) {
      try {
        data = JSON.parse(raw);
      } catch (e) {
        data = null;
      }
    }
    if (!data || typeof data !== 'object') {
      var end = (opt.getAttribute('data-endereco') || '').trim();
      var log = document.getElementById('os_logradouro');
      if (end && log) log.value = end;
      return;
    }
    var fieldMap = {
      os_cep: 'os_cep',
      os_logradouro: 'os_logradouro',
      os_numero: 'os_numero',
      os_complemento: 'os_complemento',
      os_bairro: 'os_bairro',
      os_cidade: 'os_cidade',
      os_uf: 'os_uf',
      ponto_referencia: 'os_ref',
      latitude: 'chamado_latitude',
      longitude: 'chamado_longitude'
    };
    Object.keys(fieldMap).forEach(function (key) {
      var v = data[key];
      if (v === undefined || v === null || v === '') return;
      var el = document.getElementById(fieldMap[key]);
      if (!el) return;
      el.value = String(v);
      if (key !== 'os_cep') {
        el.dispatchEvent(new Event('input', { bubbles: true }));
      }
    });
    if (typeof refreshChamadoLocPreview === 'function') {
      refreshChamadoLocPreview();
    }
  }

  function chamadoEnderecoCamposVazios() {
    return (
      !osFieldVal('os_logradouro') &&
      !osFieldVal('os_cep') &&
      !osFieldVal('os_bairro') &&
      !osFieldVal('os_cidade')
    );
  }

  function resolveOsAddressQuery() {
    return (
      buildChamadoEnderecoCepNumeroPrioritario() ||
      buildChamadoEnderecoParaGeocode() ||
      buildChamadoEnderecoSemCep()
    );
  }

  function setOsGeocodeFallbackHint(visible) {
    var hintGeo = document.getElementById('os_ponto_geocode_hint');
    if (!hintGeo) return;
    if (visible) {
      hintGeo.hidden = false;
      hintGeo.textContent =
        'Localização via Google Maps (endereço não encontrado no OpenStreetMap).';
    } else {
      hintGeo.hidden = true;
    }
  }

  var CRM_UF_NOME = {
    AC: 'Acre', AL: 'Alagoas', AP: 'Amapá', AM: 'Amazonas', BA: 'Bahia', CE: 'Ceará',
    DF: 'Distrito Federal', ES: 'Espírito Santo', GO: 'Goiás', MA: 'Maranhão', MT: 'Mato Grosso',
    MS: 'Mato Grosso do Sul', MG: 'Minas Gerais', PA: 'Pará', PB: 'Paraíba', PR: 'Paraná',
    PE: 'Pernambuco', PI: 'Piauí', RJ: 'Rio de Janeiro', RN: 'Rio Grande do Norte',
    RS: 'Rio Grande do Sul', RO: 'Rondônia', RR: 'Roraima', SC: 'Santa Catarina',
    SP: 'São Paulo', SE: 'Sergipe', TO: 'Tocantins'
  };

  function osUfNome(uf) {
    uf = String(uf || '').replace(/\./g, '').toUpperCase();
    return CRM_UF_NOME[uf] || uf;
  }

  /** Evita aceitar "53416-020, Brasil" → bairro "020" em Lavras do Sul/RS. */
  function nominatimHitMatchesContext(hit, cidade, uf) {
    if (!hit) return false;
    cidade = String(cidade || '').trim();
    uf = String(uf || '').replace(/\./g, '').toUpperCase();
    if (!cidade && !uf) return true;
    var dn = String(hit.display_name || '').toLowerCase();
    if (!dn) return false;
    var ufNome = osUfNome(uf).toLowerCase();
    if (uf) {
      var okUf = dn.indexOf(uf.toLowerCase()) >= 0 || (ufNome && dn.indexOf(ufNome) >= 0);
      if (!okUf && hit.address && hit.address.state) {
        var st = String(hit.address.state).toLowerCase();
        okUf = st.indexOf(ufNome) >= 0 || st === uf.toLowerCase();
      }
      if (!okUf) return false;
    }
    if (cidade && dn.indexOf(cidade.toLowerCase()) < 0) {
      if (hit.address) {
        var ct = String(
          hit.address.city || hit.address.town || hit.address.municipality || ''
        ).toLowerCase();
        if (ct && ct.indexOf(cidade.toLowerCase()) < 0) return false;
      } else {
        return false;
      }
    }
    return true;
  }

  var geocodeApiUrl = '';

  function getGeocodeApiUrl() {
    if (geocodeApiUrl) return geocodeApiUrl;
    var wrap = document.getElementById('os_ponto_preview');
    geocodeApiUrl =
      (wrap && wrap.getAttribute('data-geocode-api')) || 'geocode_nominatim_api.php';
    return geocodeApiUrl;
  }

  function resolveOsGeocodeApi(streetLine, cidade, uf, cepFmt, fallbackQ) {
    var params = new URLSearchParams();
    if (streetLine) params.set('street', streetLine);
    if (cidade) params.set('city', cidade);
    if (uf) params.set('state', uf);
    if (cepFmt) params.set('postalcode', cepFmt);
    if (fallbackQ) params.set('q', fallbackQ);
    var bairro = osFieldVal('os_bairro');
    var log = osFieldVal('os_logradouro');
    var num = osFieldVal('os_numero');
    if (bairro) params.set('bairro', bairro);
    if (log) params.set('logradouro', log);
    if (num && !isPlaceholderNumero(num)) params.set('numero', num);
    return fetch(getGeocodeApiUrl() + '?' + params.toString(), {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    })
      .then(function (r) {
        if (r.status === 429) {
          return { ok: false, err: 'rate_limited' };
        }
        return r.json();
      })
      .catch(function () {
        return { ok: false, err: 'network' };
      });
  }

  function geocodeHitScore(hit) {
    var score = 0;
    var cls = String(hit.class || '');
    var typ = String(hit.type || '');
    var adt = String(hit.addresstype || '');
    if (cls === 'building' || typ === 'house' || adt === 'building' || adt === 'house') score += 12;
    if (hit.address && hit.address.house_number) score += 10;
    if (cls === 'place' || adt === 'place') score += 6;
    if (cls === 'highway' || adt === 'road') score += 2;
    return score;
  }

  function pickHitInContext(hits, cidade, uf) {
    if (!hits || !hits.length) return null;
    var best = null;
    var bestScore = -1;
    var i;
    for (i = 0; i < hits.length; i++) {
      if (!nominatimHitMatchesContext(hits[i], cidade, uf)) continue;
      var sc = geocodeHitScore(hits[i]);
      if (sc > bestScore) {
        bestScore = sc;
        best = hits[i];
      }
    }
    return best;
  }

  function resolveOsDisplayCoords() {
    if (osLastCoords.lat !== null && osLastCoords.lng !== null) {
      return { lat: osLastCoords.lat, lng: osLastCoords.lng };
    }
    return getChamadoFormLatLng();
  }

  function osStreetViewEmbedUrl(la, lo) {
    var apiKey = osGetGoogleMapsApiKey();
    if (root.CrmChamadoLocDual && root.CrmChamadoLocDual.streetViewEmbedUrl) {
      return root.CrmChamadoLocDual.streetViewEmbedUrl(la, lo, { apiKey: apiKey });
    }
    var ll = encodeURIComponent(String(la) + ',' + String(lo));
    return (
      'https://www.google.com/maps?cbll=' +
      ll +
      '&cbp=11,0,0,0,0&layer=c&output=svembed&hl=pt-BR'
    );
  }

  function osResetStreetViewEmbedForReload() {
    var iframe = document.getElementById('os_ponto_streetview_frame');
    var fallback = document.getElementById('os_ponto_sv_fallback');
    if (iframe && root.CrmChamadoLocDual && root.CrmChamadoLocDual.cancelSvEmbedWatch) {
      root.CrmChamadoLocDual.cancelSvEmbedWatch(iframe);
    }
    if (fallback && root.CrmChamadoLocDual && root.CrmChamadoLocDual.hideSvEmbedFallback) {
      root.CrmChamadoLocDual.hideSvEmbedFallback(fallback);
    }
    if (iframe) {
      iframe.hidden = true;
      iframe.removeAttribute('src');
    }
  }

  function osLoadStreetViewIframe(la, lo) {
    var iframe = document.getElementById('os_ponto_streetview_frame');
    var fallback = document.getElementById('os_ponto_sv_fallback');
    if (!iframe || la === null || lo === null) return;
    if (root.CrmChamadoLocDual && root.CrmChamadoLocDual.loadSvIframe) {
      root.CrmChamadoLocDual.loadSvIframe(iframe, la, lo, {
        apiKey: osGetGoogleMapsApiKey(),
        setSvIframeSrc: setOsStreetViewIframeUrl,
        fallbackEl: fallback
      });
      return;
    }
    var svUrl = osStreetViewEmbedUrl(la, lo);
    iframe.setAttribute('data-sv-embed-src', svUrl);
    iframe.hidden = false;
    setOsStreetViewIframeUrl(iframe, svUrl);
  }

  function osShouldAbortStreetViewLoad(gen) {
    if (gen !== osSvLayoutGen) {
      osLocPreviewDbg('abort SV load: gen mismatch', { gen: gen, current: osSvLayoutGen });
      return true;
    }
    if (osPreviewView === 'map') {
      osLocPreviewDbg('abort SV load: previewView=map', { userChoice: osPreviewUserChoice });
      return true;
    }
    return false;
  }

  function osShouldAbortMapEmbedLoad(gen) {
    if (gen !== osMapLayoutGen) {
      osLocPreviewDbg('abort map load: gen mismatch', { gen: gen, current: osMapLayoutGen });
      return true;
    }
    if (osPreviewView === 'street' || osPreviewUserChoice === 'street') {
      osLocPreviewDbg('abort map load: previewView=street', { userChoice: osPreviewUserChoice });
      return true;
    }
    return false;
  }

  function osMapEmbedNeedsReload(mapIframe, lat, lng) {
    if (!mapIframe) return true;
    if (mapIframe.getAttribute('data-map-needs-reload') === '1') return true;
    if (osMapScheduleTimer || osMapLoadInFlight) return false;
    var src = mapIframe.getAttribute('src');
    if (!src) return true;
    var eLa = parseCoord(mapIframe.getAttribute('data-embed-lat'));
    var eLo = parseCoord(mapIframe.getAttribute('data-embed-lng'));
    if (lat != null && lng != null && eLa === lat && eLo === lng && !mapIframe.hidden) {
      return false;
    }
    if (lat != null && lng != null && (eLa !== lat || eLo !== lng)) return true;
    return false;
  }

  function osLoadGoogleMapIframe(la, lo) {
    var mapIframe = document.getElementById('os_ponto_map_embed');
    var frameWrap = osStreetViewFrameWrap();
    var mapFallback = document.getElementById('os_ponto_map_fallback');
    if (!mapIframe || la === null || lo === null) return;
    mapIframe.removeAttribute('data-map-needs-reload');
    mapIframe.setAttribute('data-embed-lat', String(la));
    mapIframe.setAttribute('data-embed-lng', String(lo));
    mapIframe.hidden = false;
    if (mapFallback) mapFallback.hidden = true;
    if (root.CrmChamadoLocDual && root.CrmChamadoLocDual.loadMapEmbedIframe) {
      root.CrmChamadoLocDual.loadMapEmbedIframe(mapIframe, la, lo, {
        apiKey: osGetGoogleMapsApiKey(),
        zoom: 16,
        frameWrapEl: frameWrap,
        fallbackEl: mapFallback
      });
    }
    osMapLoadInFlight = false;
  }

  function osScheduleMapEmbedLoadNow(la, lo) {
    if (la === null || lo === null) return;
    if (osPreviewUserChoice === 'street') return;
    osLocPreviewDbg('schedule map iframe', { la: la, lo: lo, gen: osMapLayoutGen + 1 });
    osMapLayoutGen++;
    var gen = osMapLayoutGen;
    var frameWrap = osStreetViewFrameWrap();
    var mapIframe = document.getElementById('os_ponto_map_embed');
    var svBlock = document.getElementById('os_ponto_streetview_block');
    if (svBlock) svBlock.hidden = false;
    if (frameWrap) frameWrap.hidden = false;
    if (root.CrmChamadoLocDual && root.CrmChamadoLocDual.scheduleMapEmbedAfterLayout) {
      root.CrmChamadoLocDual.scheduleMapEmbedAfterLayout({
        lat: la,
        lng: lo,
        frameWrapEl: frameWrap,
        mapIframe: mapIframe,
        loadEmbed: function (plat, plng) {
          if (osShouldAbortMapEmbedLoad(gen)) return;
          osLocPreviewDbg('load map iframe', { la: plat, lo: plng, gen: gen });
          osLoadGoogleMapIframe(plat, plng);
        }
      });
      return;
    }
    if (root.CrmChamadoLocDual && root.CrmChamadoLocDual.scheduleEmbedAfterLayout) {
      root.CrmChamadoLocDual.scheduleEmbedAfterLayout({
        lat: la,
        lng: lo,
        frameWrapEl: frameWrap,
        mapIframe: mapIframe,
        hideMapAfterWarm: false,
        loadEmbed: function (plat, plng) {
          if (osShouldAbortMapEmbedLoad(gen)) return;
          osLoadGoogleMapIframe(plat, plng);
        }
      });
      return;
    }
    if (frameWrap) {
      frameWrap.hidden = false;
      void frameWrap.offsetHeight;
    }
    root.requestAnimationFrame(function () {
      root.requestAnimationFrame(function () {
        if (osShouldAbortMapEmbedLoad(gen)) return;
        osLoadGoogleMapIframe(la, lo);
      });
    });
  }

  function osScheduleMapEmbedLoad(la, lo, opts) {
    opts = opts || {};
    if (la === null || lo === null) return;
    if (osPreviewUserChoice === 'street' && !opts.force) return;
    osMapLoadInFlight = true;
    osMapPendingCoords = { lat: la, lng: lo };
    clearTimeout(osMapScheduleTimer);
    var delay = opts.immediate ? 0 : 80;
    osMapScheduleTimer = root.setTimeout(function () {
      osMapScheduleTimer = null;
      var pending = osMapPendingCoords;
      osMapPendingCoords = null;
      if (!pending) return;
      osScheduleMapEmbedLoadNow(pending.lat, pending.lng);
    }, delay);
  }

  function osForceRefreshMapEmbed() {
    var c = resolveOsDisplayCoords();
    var hint = document.getElementById('os_ponto_geocode_hint');
    if (c.lat === null || c.lng === null) {
      if (hint) {
        hint.hidden = false;
        hint.textContent = 'Informe latitude e longitude para atualizar o mapa.';
      }
      return;
    }
    if (hint) hint.hidden = true;
    osPreviewUserChoice = 'map';
    osPreviewView = 'map';
    var svIframe = document.getElementById('os_ponto_streetview_frame');
    var fallback = document.getElementById('os_ponto_sv_fallback');
    var mapIframe = document.getElementById('os_ponto_map_embed');
    var mapEl = document.getElementById('os_ponto_map_mini');
    if (svIframe && root.CrmChamadoLocDual && root.CrmChamadoLocDual.cancelSvEmbedWatch) {
      root.CrmChamadoLocDual.cancelSvEmbedWatch(svIframe);
      svIframe.hidden = true;
      svIframe.removeAttribute('src');
    }
    if (fallback && root.CrmChamadoLocDual && root.CrmChamadoLocDual.hideSvEmbedFallback) {
      root.CrmChamadoLocDual.hideSvEmbedFallback(fallback);
    }
    if (mapEl) mapEl.hidden = true;
    if (mapIframe) {
      if (root.CrmGoogleMaps && root.CrmGoogleMaps.cancelEmbedWatch) {
        root.CrmGoogleMaps.cancelEmbedWatch(mapIframe);
      }
      mapIframe.removeAttribute('src');
      mapIframe.removeAttribute('data-embed-lat');
      mapIframe.removeAttribute('data-embed-lng');
      mapIframe.setAttribute('data-map-needs-reload', '1');
    }
    var svBlock = document.getElementById('os_ponto_streetview_block');
    if (svBlock) svBlock.hidden = false;
    setOsViewButtons('map');
    osLastCoords.lat = c.lat;
    osLastCoords.lng = c.lng;
    osScheduleMapEmbedLoad(c.lat, c.lng, { force: true, immediate: true });
  }

  function osSyncMapRefreshButton() {
    var btn = document.getElementById('os_ponto_map_refresh_btn');
    if (!btn) return;
    var canLocate = resolveOsMapTier().tier !== -1;
    btn.disabled = !canLocate;
    btn.hidden = !osUseGoogleMapsEmbed();
  }

  function osBootMapEmbedOnce() {
    if (osMapBootDone) return;
    if (!osUseGoogleMapsEmbed() || osGetPreviewDefaultView() !== 'map') return;
    if (osPreviewUserChoice === 'street') return;
    var c = getChamadoLatLng();
    if (c.lat === null || c.lng === null) return;
    var mapIframe = document.getElementById('os_ponto_map_embed');
    if (mapIframe && !osMapEmbedNeedsReload(mapIframe, c.lat, c.lng)) {
      osMapBootDone = true;
      return;
    }
    osMapBootDone = true;
    showOsGoogleMapEmbed(c.lat, c.lng);
  }

  function osScheduleStreetViewIframeLoad(la, lo) {
    if (la === null || lo === null) return;
    if (osPreviewView === 'map') {
      osLocPreviewDbg('schedule SV skipped: previewView=map', { la: la, lo: lo });
      return;
    }
    osLocPreviewDbg('schedule SV iframe', { la: la, lo: lo, gen: osSvLayoutGen + 1 });
    osSvLayoutGen++;
    var gen = osSvLayoutGen;
    var mapEl = document.getElementById('os_ponto_map_mini');
    var frameWrap = osStreetViewFrameWrap();
    if (root.CrmChamadoLocDual && root.CrmChamadoLocDual.scheduleSvIframeAfterLayout) {
      root.CrmChamadoLocDual.scheduleSvIframeAfterLayout({
        lat: la,
        lng: lo,
        frameWrapEl: frameWrap,
        mapEl: osUseGoogleMapsEmbed() ? null : mapEl,
        mapIframe: document.getElementById('os_ponto_map_embed'),
        hideMapAfterWarm: true,
        ensureLeaflet: function (plat, plng) {
          if (!osUseGoogleMapsEmbed()) osEnsureLeafletInitialized(plat, plng);
        },
        invalidateMap: function () {
          osInvalidateLeafletMap();
        },
        loadIframe: function (plat, plng) {
          if (osShouldAbortStreetViewLoad(gen)) return;
          osLocPreviewDbg('load SV iframe', { la: plat, lo: plng, gen: gen });
          osLoadStreetViewIframe(plat, plng);
        }
      });
      return;
    }
    osEnsureLeafletInitialized(la, lo);
    if (frameWrap) {
      frameWrap.hidden = false;
      void frameWrap.offsetHeight;
    }
    if (mapEl) {
      mapEl.hidden = false;
      osInvalidateLeafletMap();
      void mapEl.offsetHeight;
      mapEl.hidden = true;
    }
    root.requestAnimationFrame(function () {
      root.requestAnimationFrame(function () {
        if (osShouldAbortStreetViewLoad(gen)) return;
        osLoadStreetViewIframe(la, lo);
      });
    });
  }

  function setOsStreetViewIframeUrl(iframe, url) {
    if (!iframe || !url) return;
    if (iframe.getAttribute('src') === url) {
      iframe.removeAttribute('src');
      root.setTimeout(function () {
        iframe.src = url;
      }, 0);
      return;
    }
    iframe.src = url;
  }

  function osInvalidateLeafletMap() {
    if (!osLeafletMap) return;
    root.requestAnimationFrame(function () {
      osLeafletMap.invalidateSize();
    });
    root.setTimeout(function () {
      osLeafletMap.invalidateSize();
    }, 80);
    root.setTimeout(function () {
      osLeafletMap.invalidateSize();
    }, 320);
  }

  function osAddLeafletBasemap(map) {
    if (root.CrmLeafletBasemap) {
      root.CrmLeafletBasemap.addTo(map, { maxZoom: 19 });
    } else if (root.L.tileLayer) {
      root.L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
        maxZoom: 19,
        subdomains: 'abcd',
        attribution:
          '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>'
      }).addTo(map);
    }
  }

  function osEnsureLeafletInitialized(la, lo) {
    var mapEl = document.getElementById('os_ponto_map_mini');
    if (!mapEl || la === null || lo === null || typeof root.L === 'undefined') return;
    if (osLeafletMap) {
      osLeafletMap.setView([la, lo], 16);
      if (osLeafletMarker) {
        osLeafletMarker.setLatLng([la, lo]);
      }
      return;
    }
    osLeafletMap = root.L.map('os_ponto_map_mini', { scrollWheelZoom: false, zoomControl: true });
    osAddLeafletBasemap(osLeafletMap);
    osLeafletMarker = root.L.marker([la, lo]).addTo(osLeafletMap);
    osLeafletMap.setView([la, lo], 16);
  }

  function resolveOsStreetViewAvailable(lat, lng) {
    return fetch(
      getGeocodeApiUrl() +
        '?action=streetview_check&lat=' +
        encodeURIComponent(String(lat)) +
        '&lon=' +
        encodeURIComponent(String(lng)),
      {
        method: 'GET',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      }
    )
      .then(function (r) {
        return r.ok ? r.json() : { ok: false };
      })
      .then(function (res) {
        return !!(res && res.ok && res.available);
      })
      .catch(function () {
        return false;
      });
  }

  function osApplyCoordsPreview(lat, lng) {
    osPreloadBothViews(lat, lng);
    if (osPreviewUserChoice === 'map') {
      root.requestAnimationFrame(function () {
        if (osPreviewUserChoice === 'street') return;
        showOsLeafletMap(lat, lng);
      });
      return;
    }
    if (osPreviewUserChoice === 'street') {
      root.requestAnimationFrame(function () {
        if (osPreviewUserChoice === 'map') return;
        showOsStreetView(lat, lng);
      });
      return;
    }
    if (osGetPreviewDefaultView() === 'map') {
      root.requestAnimationFrame(function () {
        if (osPreviewUserChoice === 'street') return;
        showOsLeafletMap(lat, lng);
      });
      return;
    }
    osStreetViewCheckGen++;
    var gen = osStreetViewCheckGen;
    resolveOsStreetViewAvailable(lat, lng).then(function (available) {
      if (gen !== osStreetViewCheckGen) return;
      root.requestAnimationFrame(function () {
        if (available) {
          osPreviewView = 'street';
          showOsStreetView(lat, lng);
        } else {
          osPreviewView = 'map';
          showOsLeafletMap(lat, lng);
        }
      });
    });
  }

  function showOsStreetView(la, lo) {
    var mapEl = document.getElementById('os_ponto_map_mini');
    var mapIframe = document.getElementById('os_ponto_map_embed');
    var svBlock = document.getElementById('os_ponto_streetview_block');
    var iframe = document.getElementById('os_ponto_streetview_frame');
    var fallback = document.getElementById('os_ponto_sv_fallback');
    var frameWrap = osStreetViewFrameWrap();
    if (!svBlock) return;
    osLocPreviewDbg('showOsStreetView', {
      la: la,
      lo: lo,
      userChoice: osPreviewUserChoice,
      previewView: osPreviewView
    });
    osPreviewUserChoice = 'street';
    osSvLayoutGen++;
    osPreviewView = 'street';
    if (mapEl) mapEl.hidden = true;
    if (mapIframe) {
      if (root.CrmGoogleMaps && root.CrmGoogleMaps.cancelEmbedWatch) {
        root.CrmGoogleMaps.cancelEmbedWatch(mapIframe);
      }
      mapIframe.hidden = true;
      mapIframe.removeAttribute('src');
    }
    if (fallback && root.CrmChamadoLocDual && root.CrmChamadoLocDual.hideSvEmbedFallback) {
      root.CrmChamadoLocDual.hideSvEmbedFallback(fallback);
    }
    svBlock.hidden = false;
    if (frameWrap) frameWrap.hidden = false;
    if (la !== null && lo !== null) {
      osLastCoords.lat = la;
      osLastCoords.lng = lo;
      setOsGeocodeFallbackHint(false);
      osScheduleStreetViewIframeLoad(la, lo);
      updateOsSvExternalLink(la, lo);
    }
    setOsViewButtons('street');
    var mapBtnHit = document.getElementById('os_ponto_map_btn');
    var svBtnHit = document.getElementById('os_ponto_sv_btn');
    if (mapBtnHit) mapBtnHit.hidden = false;
    if (svBtnHit) svBtnHit.hidden = false;
  }

  function showOsStreetViewByAddress(addrQuery) {
    var cidade = osFieldVal('os_cidade');
    var uf = osFieldVal('os_uf').replace(/\./g, '').toUpperCase();
    var cepRaw = osFieldVal('os_cep').replace(/\D/g, '');
    var cepFmt = cepRaw.length === 8 ? cepRaw.replace(/^(\d{5})(\d{3})$/, '$1-$2') : '';
    if (!addrQuery) return;
    var coordsNow = resolveOsDisplayCoords();
    if (coordsNow.lat !== null && coordsNow.lng !== null) {
      resolveOsStreetViewAvailable(coordsNow.lat, coordsNow.lng).then(function (available) {
        if (available) {
          showOsStreetView(coordsNow.lat, coordsNow.lng);
        } else {
          showOsLeafletMap(coordsNow.lat, coordsNow.lng);
        }
      });
      return;
    }
    var hintGeo = document.getElementById('os_ponto_geocode_hint');
    if (hintGeo) {
      hintGeo.hidden = false;
      hintGeo.textContent = 'A localizar endereço no mapa…';
    }
    resolveOsGeocodeApi(buildNominatimStreetLine(), cidade, uf, cepFmt, addrQuery).then(function (res) {
      if (res && res.err === 'rate_limited') {
        if (hintGeo) {
          hintGeo.hidden = false;
          hintGeo.textContent =
            'Limite temporário do serviço de mapas. Aguarde ~1 minuto e altere um campo do endereço.';
        }
        return;
      }
      if (res && res.ok && res.hit) {
        var la = parseFloat(res.hit.lat);
        var lo = parseFloat(res.hit.lon);
        if (!isFinite(la) || !isFinite(lo)) return;
        setChamadoFormCoords(la, lo);
        osLastCoords.lat = la;
        osLastCoords.lng = lo;
        if (hintGeo) hintGeo.hidden = true;
        setOsGeocodeFallbackHint(false);
        resolveOsStreetViewAvailable(la, lo).then(function (available) {
          if (available) {
            showOsStreetView(la, lo);
          } else {
            showOsLeafletMap(la, lo);
          }
        });
        return;
      }
      if (hintGeo) {
        hintGeo.hidden = false;
        hintGeo.textContent = 'Endereço não localizado no mapa. Confira CEP, número e cidade.';
      }
    });
  }

  function runMapGeocode(tier, addrSnap, iframe, svBlock, mapMini) {
    if (!addrSnap) return;

    var geocodeKey = buildMapGeocodeKey(tier, addrSnap);
    if (geocodeKey === lastMapGeocodeKey) return;
    lastMapGeocodeKey = geocodeKey;

    mapGeocodeGeneration++;
    var gen = mapGeocodeGeneration;
    var streetLine = buildNominatimStreetLine();
    var cidade = osFieldVal('os_cidade');
    var bairro = osFieldVal('os_bairro');
    var uf = osFieldVal('os_uf').replace(/\./g, '').toUpperCase();
    var cepRaw = osFieldVal('os_cep').replace(/\D/g, '');
    var cepFmt = cepRaw.length === 8 ? cepRaw.replace(/^(\d{5})(\d{3})$/, '$1-$2') : '';
    var addrFull = tier === 2 ? buildChamadoEnderecoParaGeocode() : addrSnap;
    var addrCepNumero = tier === 2 ? buildChamadoEnderecoCepNumeroPrioritario() : '';
    var addrCepCtx =
      cepFmt && cidade && uf ? cepFmt + ', ' + cidade + ' - ' + uf + ', Brasil' : '';

    var addrPending = addrSnap || addrFull;

    if (iframe && svBlock) {
      var hintGeoPending = document.getElementById('os_ponto_geocode_hint');
      if (addrPending && hintGeoPending) {
        hintGeoPending.hidden = false;
        hintGeoPending.textContent = 'A localizar endereço no mapa…';
      }
      osPreviewView = 'map';
      if (mapMini) mapMini.hidden = true;
      if (iframe) {
        iframe.hidden = true;
        iframe.removeAttribute('src');
      }
      svBlock.hidden = false;
      var frameWrapGeocode = osStreetViewFrameWrap();
      if (frameWrapGeocode) frameWrapGeocode.hidden = false;
    }

    function applyNominatimHit(hit) {
      if (gen !== mapGeocodeGeneration || !hit) return;
      var la = parseFloat(hit.lat);
      var lo = parseFloat(hit.lon);
      if (!isFinite(la) || !isFinite(lo)) return;
      setChamadoFormCoords(la, lo);
      osLastCoords.lat = la;
      osLastCoords.lng = lo;
      var hintGeo = document.getElementById('os_ponto_geocode_hint');
      var mapBtnHit = document.getElementById('os_ponto_map_btn');
      var svBtnHit = document.getElementById('os_ponto_sv_btn');
      if (mapBtnHit) mapBtnHit.hidden = false;
      if (svBtnHit) svBtnHit.hidden = false;
      if (hintGeo) hintGeo.hidden = true;
      setOsGeocodeFallbackHint(false);
      osApplyCoordsPreview(la, lo);
    }

    function applyGeocodeFallback() {
      if (gen !== mapGeocodeGeneration) return;
      var hintGeoFail = document.getElementById('os_ponto_geocode_hint');
      if (hintGeoFail) {
        hintGeoFail.hidden = false;
        hintGeoFail.textContent = 'Endereço não localizado no mapa. Confira CEP, número e cidade.';
      }
      setOsGeocodeFallbackHint(false);
    }

    mapGeocodeTimer = window.setTimeout(function () {
      mapGeocodeTimer = null;
      var fallbackQ = '';
      if (addrCepNumero && addrCepNumero !== streetLine) {
        fallbackQ = addrCepNumero;
      } else if (!streetLine && (addrSnap || addrFull)) {
        fallbackQ = addrSnap || addrFull;
      }
      resolveOsGeocodeApi(streetLine, cidade, uf, cepFmt, fallbackQ).then(function (res) {
        if (gen !== mapGeocodeGeneration) return;
        if (res && res.err === 'rate_limited') {
          var hintRate = document.getElementById('os_ponto_geocode_hint');
          if (hintRate) {
            hintRate.hidden = false;
            hintRate.textContent =
              'Limite temporário do serviço de mapas. Aguarde ~1 minuto e altere um campo do endereço.';
          }
          return;
        }
        if (res && res.ok && res.hit) {
          applyNominatimHit({ lat: res.hit.lat, lon: res.hit.lon });
          return;
        }
        applyGeocodeFallback();
      });
    }, 700);
  }

  function refreshChamadoLocPreview() {
    var wrap = document.getElementById('os_ponto_preview');
    var iframe = document.getElementById('os_ponto_streetview_frame');
    var svBlock = document.getElementById('os_ponto_streetview_block');
    var endBlock = document.getElementById('os_ponto_preview_endereco');
    var endText = document.getElementById('os_ponto_preview_endereco_text');
    var hintNoCoord = document.getElementById('os_ponto_sem_coord');
    if (!wrap || !iframe || !svBlock) return;

    if (mapGeocodeTimer) {
      clearTimeout(mapGeocodeTimer);
      mapGeocodeTimer = null;
    }
    if (refreshPreviewTimer) {
      clearTimeout(refreshPreviewTimer);
      refreshPreviewTimer = null;
    }

    var sel = getPontoSelect();
    var opt = sel ? sel.options[sel.selectedIndex] : null;
    var resolved = resolveOsMapTier();
    var tier = resolved.tier;
    var pontoChosen = resolved.pontoChosen;
    var end = pontoChosen && opt ? (opt.getAttribute('data-endereco') || '').trim() : '';
    var lat = resolved.lat;
    var lng = resolved.lng;
    var addrGeocode = resolved.addrGeocode;

    var hasEnd = end !== '';
    var hasSv = lat !== null && lng !== null;
    var hasMapaEndereco = (tier === 2 || tier === 3) && addrGeocode !== '';

    if (endText && endBlock) {
      if (pontoChosen && hasEnd) {
        endText.textContent = end;
        endBlock.hidden = false;
      } else {
        endText.textContent = '';
        endBlock.hidden = true;
      }
    }

    if (hintNoCoord) {
      hintNoCoord.hidden = hasSv || hasMapaEndereco || (pontoChosen && tier === 0);
    }

    var mapBtn = document.getElementById('os_ponto_map_btn');
    var svBtn = document.getElementById('os_ponto_sv_btn');
    var mapMini = document.getElementById('os_ponto_map_mini');
    var canLocate = tier !== -1;
    if (mapBtn) mapBtn.hidden = !canLocate;
    if (svBtn) svBtn.hidden = !canLocate;

    var showPreview = canLocate || (pontoChosen && tier === 0);
    wrap.hidden = !showPreview;
    if (svBlock) {
      if (showPreview && (hasSv || hasMapaEndereco)) {
        svBlock.hidden = false;
      } else if (!showPreview) {
        svBlock.hidden = true;
      }
    }

    if (hasSv) {
      var prevLat = osLastCoords.lat;
      var prevLng = osLastCoords.lng;
      var coordsUnchanged = prevLat === lat && prevLng === lng;
      osLastCoords.lat = lat;
      osLastCoords.lng = lng;
      mapGeocodeGeneration++;
      osStreetViewCheckGen++;
      if (showPreview) {
        var mapIframeRefresh = document.getElementById('os_ponto_map_embed');
        var skipMapUnchanged =
          (osPreviewUserChoice === 'map' || (osPreviewUserChoice === null && osGetPreviewDefaultView() === 'map')) &&
          osPreviewView === 'map' &&
          coordsUnchanged &&
          osUseGoogleMapsEmbed() &&
          !osMapEmbedNeedsReload(mapIframeRefresh, lat, lng);
        if (
          osPreviewUserChoice === 'street' &&
          osPreviewView === 'street' &&
          coordsUnchanged
        ) {
          osLocPreviewDbg('refresh skip: SV ativo, coords iguais', {
            tier: tier,
            lat: lat,
            lng: lng
          });
          setOsViewButtons('street');
        } else if (skipMapUnchanged) {
          osLocPreviewDbg('refresh skip: mapa ativo, coords iguais', {
            tier: tier,
            lat: lat,
            lng: lng
          });
          setOsViewButtons('map');
        } else {
          osLocPreviewDbg('refresh re-apply preview', {
            tier: tier,
            hasSv: hasSv,
            userChoice: osPreviewUserChoice,
            previewView: osPreviewView,
            coordsUnchanged: coordsUnchanged
          });
          if (osPreviewUserChoice === 'street' && coordsUnchanged === false) {
            osResetStreetViewEmbedForReload();
          } else if (osPreviewUserChoice === 'street' && osPreviewView !== 'street') {
            osResetStreetViewEmbedForReload();
          }
          osApplyCoordsPreview(lat, lng);
        }
      }
    } else if (hasMapaEndereco) {
      osPreviewView = 'map';
      if (showPreview) {
        runMapGeocode(tier, addrGeocode, iframe, svBlock, mapMini);
      }
    } else {
      mapGeocodeGeneration++;
      osPreviewView = 'map';
      if (iframe) iframe.removeAttribute('src');
      if (svBlock) svBlock.hidden = true;
      if (mapMini) mapMini.hidden = true;
    }

    if (showPreview && hasSv && osPreviewView === 'map') {
      osInvalidateLeafletMap();
    }
    if (mapMini && !showPreview) {
      mapMini.hidden = true;
    }
    osSyncMapRefreshButton();
  }

  function osUseGoogleMapsEmbed() {
    if (root.CrmGoogleMaps && root.CrmGoogleMaps.hasApiKey) {
      return root.CrmGoogleMaps.hasApiKey(document.getElementById('os_ponto_preview'));
    }
    return osGetGoogleMapsApiKey() !== '';
  }

  function showOsGoogleMapEmbed(la, lo) {
    var mapIframe = document.getElementById('os_ponto_map_embed');
    var mapEl = document.getElementById('os_ponto_map_mini');
    var svBlock = document.getElementById('os_ponto_streetview_block');
    var svIframe = document.getElementById('os_ponto_streetview_frame');
    var fallback = document.getElementById('os_ponto_sv_fallback');
    var frameWrap = osStreetViewFrameWrap();
    if (la === null || lo === null) return;
    if (osPreviewUserChoice === 'street') return;
    osPreviewView = 'map';
    if (svIframe && root.CrmChamadoLocDual && root.CrmChamadoLocDual.cancelSvEmbedWatch) {
      root.CrmChamadoLocDual.cancelSvEmbedWatch(svIframe);
      svIframe.hidden = true;
      svIframe.removeAttribute('src');
    }
    if (fallback && root.CrmChamadoLocDual && root.CrmChamadoLocDual.hideSvEmbedFallback) {
      root.CrmChamadoLocDual.hideSvEmbedFallback(fallback);
    }
    if (svBlock) svBlock.hidden = false;
    if (frameWrap) frameWrap.hidden = false;
    if (mapEl) mapEl.hidden = true;
    setOsGeocodeFallbackHint(false);
    setOsViewButtons('map');
    if (mapIframe && osUseGoogleMapsEmbed()) {
      osScheduleMapEmbedLoad(la, lo);
      return;
    }
    showOsLeafletMap(la, lo, true);
  }

  function showOsLeafletMap(la, lo, forceLeaflet) {
    var mapEl = document.getElementById('os_ponto_map_mini');
    var mapIframe = document.getElementById('os_ponto_map_embed');
    var svBlock = document.getElementById('os_ponto_streetview_block');
    var iframe = document.getElementById('os_ponto_streetview_frame');
    var fallback = document.getElementById('os_ponto_sv_fallback');
    var frameWrap = osStreetViewFrameWrap();
    if (la === null || lo === null) return;
    if (!forceLeaflet && osUseGoogleMapsEmbed()) {
      showOsGoogleMapEmbed(la, lo);
      return;
    }
    if (!mapEl) return;
    if (osPreviewUserChoice === 'street') {
      osLocPreviewDbg('showOsLeafletMap skipped: userChoice=street', { la: la, lo: lo });
      return;
    }
    osLocPreviewDbg('showOsLeafletMap', { la: la, lo: lo, userChoice: osPreviewUserChoice });
    osSvLayoutGen++;
    osPreviewView = 'map';
    if (mapIframe) {
      mapIframe.hidden = true;
      mapIframe.removeAttribute('src');
    }
    if (iframe) {
      if (root.CrmChamadoLocDual && root.CrmChamadoLocDual.cancelSvEmbedWatch) {
        root.CrmChamadoLocDual.cancelSvEmbedWatch(iframe);
      }
      iframe.hidden = true;
      iframe.removeAttribute('src');
    }
    if (fallback && root.CrmChamadoLocDual && root.CrmChamadoLocDual.hideSvEmbedFallback) {
      root.CrmChamadoLocDual.hideSvEmbedFallback(fallback);
    }
    if (svBlock) svBlock.hidden = false;
    if (frameWrap) frameWrap.hidden = false;
    mapEl.hidden = false;
    setOsGeocodeFallbackHint(false);
    setOsViewButtons('map');
    if (typeof root.L === 'undefined') {
      return;
    }
    osPreloadBothViews(la, lo);
    root.requestAnimationFrame(function () {
      if (osPreviewUserChoice === 'street') return;
      osEnsureLeafletInitialized(la, lo);
      osInvalidateLeafletMap();
    });
  }

  function wirePontoSelectFill(sel) {
    if (!sel) return;
    function onPontoChange() {
      lastMapGeocodeKey = '';
      if (!sel.value || sel.value === '0') {
        clearChamadoCoordsFromPonto();
      } else {
        fillEnderecoFromPonto(sel);
      }
      refreshChamadoLocPreview();
    }
    sel.addEventListener('change', onPontoChange);
    function tryFillFromPonto() {
      if (sel.value && sel.value !== '0' && chamadoEnderecoCamposVazios()) {
        fillEnderecoFromPonto(sel);
        refreshChamadoLocPreview();
      }
    }
    tryFillFromPonto();
    window.setTimeout(tryFillFromPonto, 120);
    window.setTimeout(tryFillFromPonto, 400);
  }

  var reverseGeocodeTimer = null;
  var reverseGeocodeGen = 0;

  function applyReverseAddress(addr) {
    if (!addr) return;
    var road = addr.road || addr.pedestrian || addr.footway || '';
    var num = addr.house_number || '';
    var log = (road + (num ? ', ' + num : '')).trim();
    if (log) {
      var elLog = document.getElementById('os_logradouro');
      if (elLog && !String(elLog.value || '').trim()) elLog.value = log;
    }
    var map = { os_bairro: 'suburb', os_cidade: 'city', os_uf: 'state' };
    Object.keys(map).forEach(function (fid) {
      var el = document.getElementById(fid);
      if (!el || String(el.value || '').trim()) return;
      var key = map[fid];
      if (addr[key]) el.value = String(addr[key]);
    });
    if (addr.postcode) {
      var cepEl = document.getElementById('os_cep');
      if (cepEl && !String(cepEl.value || '').trim()) cepEl.value = String(addr.postcode).replace(/\D/g, '').slice(0, 8);
    }
    refreshChamadoLocPreview();
  }

  function reverseGeocodeFromLatLng() {
    var coords = getChamadoLatLng();
    if (coords.lat == null || coords.lng == null) return;
    reverseGeocodeGen++;
    var gen = reverseGeocodeGen;
    var url =
      getGeocodeApiUrl() +
      '?action=reverse&lat=' +
      encodeURIComponent(String(coords.lat)) +
      '&lon=' +
      encodeURIComponent(String(coords.lng));
    fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    })
      .then(function (r) {
        if (r.status === 429) return null;
        return r.ok ? r.json() : null;
      })
      .then(function (data) {
        if (gen !== reverseGeocodeGen || !data || !data.ok || !data.address) return;
        applyReverseAddress(data.address);
      })
      .catch(function () {});
  }

  function boot() {
    var mapBtn = document.getElementById('os_ponto_map_btn');
    var svBtn = document.getElementById('os_ponto_sv_btn');
    if (mapBtn) {
      mapBtn.addEventListener('click', function () {
        osPreviewUserChoice = 'map';
        var c = resolveOsDisplayCoords();
        osLocPreviewDbg('map click', {
          lat: c.lat,
          lng: c.lng,
          previewView: osPreviewView,
          userChoice: osPreviewUserChoice
        });
        if (c.lat !== null && c.lng !== null) {
          showOsLeafletMap(c.lat, c.lng);
          return;
        }
        var resolved = resolveOsMapTier();
        if (resolved.lat !== null && resolved.lng !== null) {
          showOsLeafletMap(resolved.lat, resolved.lng);
          return;
        }
        var addrQuery = resolved.addrGeocode || resolveOsAddressQuery();
        if (addrQuery) {
          var hintGeoBtn = document.getElementById('os_ponto_geocode_hint');
          if (hintGeoBtn) {
            hintGeoBtn.hidden = false;
            hintGeoBtn.textContent = 'A localizar endereço no mapa…';
          }
          var cepFmtBtn =
            osFieldVal('os_cep').replace(/\D/g, '').length === 8
              ? osFieldVal('os_cep').replace(/\D/g, '').replace(/^(\d{5})(\d{3})$/, '$1-$2')
              : '';
          resolveOsGeocodeApi(
            buildNominatimStreetLine(),
            osFieldVal('os_cidade'),
            osFieldVal('os_uf').replace(/\./g, '').toUpperCase(),
            cepFmtBtn,
            addrQuery
          ).then(function (res) {
            if (res && res.err === 'rate_limited') {
              if (hintGeoBtn) {
                hintGeoBtn.hidden = false;
                hintGeoBtn.textContent =
                  'Limite temporário do serviço de mapas. Aguarde ~1 minuto e altere um campo do endereço.';
              }
              return;
            }
            if (!res || !res.ok || !res.hit) {
              if (hintGeoBtn) {
                hintGeoBtn.hidden = false;
                hintGeoBtn.textContent = 'Endereço não localizado no mapa. Confira CEP, número e cidade.';
              }
              return;
            }
            var la = parseFloat(res.hit.lat);
            var lo = parseFloat(res.hit.lon);
            if (!isFinite(la) || !isFinite(lo)) return;
            setChamadoFormCoords(la, lo);
            osLastCoords.lat = la;
            osLastCoords.lng = lo;
            setOsGeocodeFallbackHint(false);
            if (hintGeoBtn) hintGeoBtn.hidden = true;
            showOsLeafletMap(la, lo);
          });
        }
      });
    }
    if (svBtn) {
      svBtn.addEventListener('click', function () {
        osPreviewUserChoice = 'street';
        var c = resolveOsDisplayCoords();
        osLocPreviewDbg('sv click', {
          lat: c.lat,
          lng: c.lng,
          previewView: osPreviewView,
          userChoice: osPreviewUserChoice
        });
        if (c.lat !== null && c.lng !== null) {
          showOsStreetView(c.lat, c.lng);
          return;
        }
        var resolved = resolveOsMapTier();
        if (resolved.lat !== null && resolved.lng !== null) {
          showOsStreetView(resolved.lat, resolved.lng);
          return;
        }
        var addrQuerySv = resolved.addrGeocode || resolveOsAddressQuery();
        if (addrQuerySv) {
          showOsStreetViewByAddress(addrQuerySv);
        }
      });
    }
    wirePontoSelectFill(getPontoSelect());
    var coordsBootMap = getChamadoLatLng();
    if (coordsBootMap.lat !== null && coordsBootMap.lng !== null) {
      osPreviewView = 'map';
      var wrapBoot = document.getElementById('os_ponto_preview');
      if (wrapBoot) wrapBoot.hidden = false;
      var svBlockBoot = document.getElementById('os_ponto_streetview_block');
      if (svBlockBoot) svBlockBoot.hidden = false;
    }
    if (root.CrmChamadoLocDual) {
      root.CrmChamadoLocDual.wireLocationFieldObserver({
        debounceMs: 400,
        onBeforeTrigger: function () {
          lastMapGeocodeKey = '';
        },
        shouldTrigger: function () {
          return resolveOsMapTier().tier !== -1;
        },
        onTrigger: function () {
          scheduleRefreshChamadoLocPreview();
        }
      });
    }
    ['chamado_latitude', 'chamado_longitude'].forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) return;
      el.addEventListener('input', function () {
        osPreviewUserChoice = null;
        clearTimeout(reverseGeocodeTimer);
        reverseGeocodeTimer = root.setTimeout(reverseGeocodeFromLatLng, 1500);
      });
    });
    var mapRefreshBtn = document.getElementById('os_ponto_map_refresh_btn');
    if (mapRefreshBtn) {
      mapRefreshBtn.addEventListener('click', function () {
        osForceRefreshMapEmbed();
      });
    }
    osSyncMapRefreshButton();
    refreshChamadoLocPreview();
    window.setTimeout(function () {
      refreshChamadoLocPreview();
      osSyncMapRefreshButton();
    }, 120);
    root.setTimeout(function () {
      osBootMapEmbedOnce();
      osSyncMapRefreshButton();
    }, 350);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(window);
</script>
<?php elseif ($ch_os_emit_form): ?>
<script>
(function () {
  function osFieldVal(id) {
    var el = document.getElementById(id);
    if (!el) return '';
    return String(el.value || '')
      .trim()
      .replace(/\s+/g, ' ');
  }

  function chamadoEnderecoCamposVazios() {
    return (
      !osFieldVal('os_logradouro') &&
      !osFieldVal('os_cep') &&
      !osFieldVal('os_bairro') &&
      !osFieldVal('os_cidade')
    );
  }

  function fillEnderecoFromPonto(sel) {
    if (!sel) return;
    var opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value || opt.value === '0') return;
    var raw = opt.getAttribute('data-os-fill') || '';
    var data = null;
    if (raw) {
      try {
        data = JSON.parse(raw);
      } catch (e) {
        data = null;
      }
    }
    if (!data || typeof data !== 'object') {
      var end = (opt.getAttribute('data-endereco') || '').trim();
      var log = document.getElementById('os_logradouro');
      if (end && log) log.value = end;
      return;
    }
    var fieldMap = {
      os_cep: 'os_cep',
      os_logradouro: 'os_logradouro',
      os_numero: 'os_numero',
      os_complemento: 'os_complemento',
      os_bairro: 'os_bairro',
      os_cidade: 'os_cidade',
      os_uf: 'os_uf',
      ponto_referencia: 'os_ref',
      latitude: 'chamado_latitude',
      longitude: 'chamado_longitude'
    };
    Object.keys(fieldMap).forEach(function (key) {
      var v = data[key];
      if (v === undefined || v === null || v === '') return;
      var el = document.getElementById(fieldMap[key]);
      if (!el) return;
      el.value = String(v);
      if (key !== 'os_cep') {
        el.dispatchEvent(new Event('input', { bubbles: true }));
      }
    });
  }
  function wirePontoSelectFill(sel) {
    if (!sel) return;
    sel.addEventListener('change', function () {
      fillEnderecoFromPonto(sel);
    });
    function tryFillFromPonto() {
      if (sel.value && sel.value !== '0' && chamadoEnderecoCamposVazios()) {
        fillEnderecoFromPonto(sel);
      }
    }
    tryFillFromPonto();
    window.setTimeout(tryFillFromPonto, 120);
    window.setTimeout(tryFillFromPonto, 400);
  }

  function boot() {
    wirePontoSelectFill(document.getElementById('ponto_iluminacao_id'));
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
</script>
<?php endif; ?>
