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
if (!isset($ch_os_mapa_apenas)) {
    $ch_os_mapa_apenas = false;
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
$ch_os_preview_inicial_visivel = is_array($ch_os_loc_preview) && !empty($ch_os_loc_preview['show_preview']);
$ch_os_sv_iframe_src = '';
$ch_os_sv_tab_href = '#';
$ch_os_preview_tem_coords = is_array($ch_os_loc_preview)
    && $ch_os_loc_preview['lat'] !== null
    && $ch_os_loc_preview['lng'] !== null;
if ($ch_os_preview_tem_coords) {
    $chOsLa = (float) $ch_os_loc_preview['lat'];
    $chOsLo = (float) $ch_os_loc_preview['lng'];
    $chOsLl = rawurlencode(number_format($chOsLa, 7, '.', '') . ',' . number_format($chOsLo, 7, '.', ''));
    if ($ch_os_mapa_apenas) {
        $ch_os_sv_iframe_src = 'https://www.google.com/maps?q=' . $chOsLl . '&z=17&hl=pt-BR&output=embed';
    } else {
        $ch_os_sv_iframe_src = 'https://www.google.com/maps?cbll=' . $chOsLl . '&cbp=12,0,0,0,0&layer=c&output=svembed&hl=pt-BR';
        $ch_os_sv_tab_href = 'https://www.google.com/maps/@?api=1&map_action=pano&viewpoint=' . $chOsLl;
    }
}

?>
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
          <small class="muted" style="display:block;margin-top:8px;"><?php if ($ch_os_mostrar_preview_mapa): ?>Ao selecionar um ponto, os campos de endereço e coordenadas do chamado são preenchidos com os dados do poste (quando existirem). O Street View e o mapa usam o endereço/coordenadas gravados neste chamado.<?php else: ?>Ao selecionar um ponto, os campos de endereço e coordenadas do chamado são preenchidos com os dados do poste (quando existirem).<?php endif; ?></small>
        </div>
        <?php elseif ($ch_os_mostrar_preview_mapa): ?>
        <p class="muted os-pane-sub" style="margin:0 0 4px;"><?= $ch_os_mapa_apenas
            ? 'O mapa usa a latitude, longitude ou o endereço deste chamado (não exige vínculo com ponto de iluminação).'
            : 'Street View e o mapa abaixo usam a latitude, longitude ou o endereço deste chamado (não exige vínculo com ponto de iluminação).' ?></p>
        <?php endif; ?>
        <?php if ($ch_os_mostrar_preview_mapa): ?>
        <div class="form-group full os-ponto-preview-wrap" style="margin-top:8px;">
          <p class="os-pane-sub" style="margin:0 0 8px;">Localização no mapa</p>
          <div id="os_ponto_preview" class="os-ponto-preview<?= $ch_os_mapa_apenas ? ' os-ponto-preview--mapa-apenas' : '' ?>"<?= $ch_os_preview_inicial_visivel ? '' : ' hidden' ?>
               data-geocode-api="<?= htmlspecialchars((string) $ch_os_geocode_api_url, ENT_QUOTES, 'UTF-8') ?>"
               <?= $ch_os_mapa_apenas ? ' data-mapa-apenas="1"' : '' ?>
               <?php if (is_array($ch_os_loc_preview) && $ch_os_loc_preview['lat'] !== null && $ch_os_loc_preview['lng'] !== null): ?>
               data-initial-lat="<?= htmlspecialchars((string) $ch_os_loc_preview['lat'], ENT_QUOTES, 'UTF-8') ?>"
               data-initial-lng="<?= htmlspecialchars((string) $ch_os_loc_preview['lng'], ENT_QUOTES, 'UTF-8') ?>"
               <?php endif; ?>>
            <div id="os_ponto_preview_endereco" class="chamado-ponto-endereco os-ponto-preview__endereco" hidden>
              <span class="chamado-ponto-endereco__label">Endereço do ponto</span>
              <div id="os_ponto_preview_endereco_text" class="chamado-ponto-endereco__text"></div>
            </div>
            <p id="os_ponto_sem_coord" class="muted os-ponto-preview__hint" hidden>Informe latitude e longitude no chamado, ou preencha o endereço nos campos acima, para ver o mapa.</p>
            <p id="os_ponto_geocode_hint" class="muted os-ponto-preview__hint" style="margin:0 0 8px;"<?= (is_array($ch_os_loc_preview) && ($ch_os_loc_preview['modo'] ?? '') === 'geocode') ? '' : ' hidden' ?>><?= (is_array($ch_os_loc_preview) && ($ch_os_loc_preview['modo'] ?? '') === 'geocode') ? 'A localizar endereço no mapa…' : '' ?></p>
            <?php if ($ch_os_mapa_apenas): ?>
            <?php
              $ch_os_map_mini_visivel = $ch_os_preview_inicial_visivel
                  && ($ch_os_preview_tem_coords || $ch_os_sv_iframe_src === '');
              $ch_os_map_embed_visivel = $ch_os_preview_inicial_visivel
                  && !$ch_os_preview_tem_coords
                  && $ch_os_sv_iframe_src !== '';
            ?>
            <div class="os-ponto-map-only chamado-location-map">
              <div id="os_ponto_map_mini" class="chamado-map-mini"<?= $ch_os_map_mini_visivel ? '' : ' hidden' ?> aria-label="Mapa do chamado"></div>
              <div id="os_ponto_map_embed_wrap" class="os-ponto-map-embed"<?= $ch_os_map_embed_visivel ? '' : ' hidden' ?>>
                <iframe id="os_ponto_map_embed_frame" class="os-ponto-map-embed__frame" title="Mapa do chamado" allowfullscreen loading="lazy"<?= $ch_os_sv_iframe_src !== '' ? ' src="' . htmlspecialchars($ch_os_sv_iframe_src, ENT_QUOTES, 'UTF-8') . '"' : '' ?>></iframe>
              </div>
            </div>
            <?php else: ?>
            <div id="os_ponto_streetview_block" class="chamado-ponto-streetview"<?= $ch_os_preview_inicial_visivel && is_array($ch_os_loc_preview) && ($ch_os_loc_preview['modo'] ?? '') !== 'none' ? '' : ' hidden' ?>>
              <div class="chamado-ponto-streetview__head">
                <div class="chamado-ponto-streetview__head-actions">
                  <button type="button" class="btn btn-sm btn-ghost" id="os_ponto_map_btn">Ver mapa</button>
                  <button type="button" class="btn btn-sm<?= $ch_os_sv_iframe_src === '' ? ' btn-ghost' : ' btn-primary' ?>" id="os_ponto_sv_btn"<?= $ch_os_sv_iframe_src === '' ? ' hidden' : '' ?>>Ver Street View</button>
                </div>
              </div>
              <div class="chamado-ponto-streetview__frame-wrap">
                <iframe id="os_ponto_streetview_frame" class="chamado-ponto-streetview__frame" title="Localização do chamado no mapa" allowfullscreen loading="lazy"<?= $ch_os_sv_iframe_src !== '' ? ' src="' . htmlspecialchars($ch_os_sv_iframe_src, ENT_QUOTES, 'UTF-8') . '"' : '' ?>></iframe>
                <div id="os_ponto_map_mini" class="chamado-map-mini chamado-map-mini--in-frame" hidden aria-label="Mapa interativo do chamado"></div>
              </div>
            </div>
            <?php endif; ?>
          </div>
        </div>
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
<?php if ($ch_os_mostrar_preview_mapa && empty($GLOBALS['crm_leaflet_os_preview_scripts'])): ?>
<?php $GLOBALS['crm_leaflet_os_preview_scripts'] = true; ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<?php include __DIR__ . '/partials/leaflet_basemap_script.php'; ?>
<?php endif; ?>
<?php if ($ch_os_mostrar_preview_mapa): ?>
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
  var osPreviewView = 'street';

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
    refreshPreviewTimer = window.setTimeout(function () {
      refreshPreviewTimer = null;
      refreshChamadoLocPreview();
    }, 500);
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

  function buildOsGoogleMapsEmbedUrl(query) {
    return (
      'https://www.google.com/maps?q=' +
      encodeURIComponent(query) +
      '&z=17&hl=pt-BR&output=embed'
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

  /** Embed Google Maps por endereço (dual-view ou mapa-apenas). */
  function showOsGoogleEmbedByAddress(addrQuery, activeView) {
    if (!addrQuery) return;
    activeView = activeView || 'map';

    var mapMini = document.getElementById('os_ponto_map_mini');
    var embedWrap = document.getElementById('os_ponto_map_embed_wrap');
    var embedFrame = document.getElementById('os_ponto_map_embed_frame');
    var svBlock = document.getElementById('os_ponto_streetview_block');
    var iframe = document.getElementById('os_ponto_streetview_frame');
    var frameWrap = osStreetViewFrameWrap();
    var embedUrl = buildOsGoogleMapsEmbedUrl(addrQuery);

    osPreviewView = activeView;

    if (embedWrap && embedFrame) {
      if (svBlock) svBlock.hidden = true;
      if (mapMini) mapMini.hidden = true;
      embedWrap.hidden = false;
      embedFrame.src = embedUrl;
      setOsGeocodeFallbackHint(true);
      return;
    }

    if (!svBlock || !iframe) return;
    if (embedWrap) embedWrap.hidden = true;
    if (mapMini) mapMini.hidden = true;
    iframe.hidden = false;
    svBlock.hidden = false;
    if (frameWrap) frameWrap.hidden = false;
    iframe.src = embedUrl;
    setOsViewButtons(activeView);
    setOsGeocodeFallbackHint(true);
  }

  function showOsMapaApenas(la, lo, addrQuery) {
    var svBlock = document.getElementById('os_ponto_streetview_block');
    if (svBlock) svBlock.hidden = true;
    if (la !== null && lo !== null) {
      osLastCoords.lat = la;
      osLastCoords.lng = lo;
      setOsGeocodeFallbackHint(false);
    }
    if (la !== null && lo !== null && typeof root.L !== 'undefined') {
      var embedWrap = document.getElementById('os_ponto_map_embed_wrap');
      if (embedWrap) embedWrap.hidden = true;
      showOsLeafletMap(la, lo);
      return;
    }
    if (addrQuery) {
      showOsGoogleEmbedByAddress(addrQuery, 'map');
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

  function buildOsStreetViewEmbedUrl(la, lo) {
    var ll = encodeURIComponent(String(la) + ',' + String(lo));
    return (
      'https://www.google.com/maps?cbll=' +
      ll +
      '&cbp=12,0,0,0,0&layer=c&output=svembed&hl=pt-BR'
    );
  }

  function resolveOsDisplayCoords() {
    if (osLastCoords.lat !== null && osLastCoords.lng !== null) {
      return { lat: osLastCoords.lat, lng: osLastCoords.lng };
    }
    return getChamadoFormLatLng();
  }

  function setOsStreetViewIframeUrl(iframe, url) {
    if (!iframe || !url) return;
    if (iframe.src === url) {
      iframe.removeAttribute('src');
      root.setTimeout(function () {
        iframe.src = url;
      }, 0);
      return;
    }
    iframe.src = url;
  }

  function showOsStreetView(la, lo) {
    var mapEl = document.getElementById('os_ponto_map_mini');
    var svBlock = document.getElementById('os_ponto_streetview_block');
    var iframe = document.getElementById('os_ponto_streetview_frame');
    var frameWrap = osStreetViewFrameWrap();
    if (!svBlock) return;
    osPreviewView = 'street';
    if (mapEl) mapEl.hidden = true;
    if (iframe) iframe.hidden = false;
    svBlock.hidden = false;
    if (frameWrap) frameWrap.hidden = false;
    if (la !== null && lo !== null) {
      osLastCoords.lat = la;
      osLastCoords.lng = lo;
      setOsGeocodeFallbackHint(false);
      if (iframe) {
        setOsStreetViewIframeUrl(iframe, buildOsStreetViewEmbedUrl(la, lo));
      }
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
      showOsStreetView(coordsNow.lat, coordsNow.lng);
      return;
    }
    resolveOsGeocodeApi(buildNominatimStreetLine(), cidade, uf, cepFmt, addrQuery).then(function (res) {
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
        var la = parseFloat(res.hit.lat);
        var lo = parseFloat(res.hit.lon);
        if (!isFinite(la) || !isFinite(lo)) return;
        setChamadoFormCoords(la, lo);
        showOsStreetView(la, lo);
        return;
      }
      showOsGoogleEmbedByAddress(addrQuery, 'street');
    });
  }

  function runMapGeocode(tier, addrSnap, mapaApenas, iframe, svBlock, mapMini) {
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

    if (mapaApenas) {
      showOsMapaApenas(null, null, addrPending);
    } else if (iframe && svBlock) {
      if (addrPending) {
        showOsGoogleEmbedByAddress(addrPending, osPreviewView === 'map' ? 'map' : 'street');
      } else {
        osPreviewView = 'street';
        if (mapMini) mapMini.hidden = true;
        iframe.removeAttribute('src');
        svBlock.hidden = false;
        var frameWrapGeocode = osStreetViewFrameWrap();
        if (frameWrapGeocode) frameWrapGeocode.hidden = false;
      }
    }

    function applyNominatimHit(hit) {
      if (gen !== mapGeocodeGeneration || !hit) return;
      var la = parseFloat(hit.lat);
      var lo = parseFloat(hit.lon);
      if (!isFinite(la) || !isFinite(lo)) return;
      setChamadoFormCoords(la, lo);
      if (mapaApenas) {
        showOsMapaApenas(la, lo, null);
        return;
      }
      osLastCoords.lat = la;
      osLastCoords.lng = lo;
      var hintGeo = document.getElementById('os_ponto_geocode_hint');
      var mapBtnHit = document.getElementById('os_ponto_map_btn');
      var svBtnHit = document.getElementById('os_ponto_sv_btn');
      if (mapBtnHit) mapBtnHit.hidden = false;
      if (svBtnHit) svBtnHit.hidden = false;
      if (hintGeo) hintGeo.hidden = true;
      setOsGeocodeFallbackHint(false);
      if (osPreviewView === 'map') {
        showOsLeafletMap(la, lo);
      } else {
        osPreviewView = 'street';
        showOsStreetView(la, lo);
      }
    }

    function applyGeocodeFallback() {
      if (gen !== mapGeocodeGeneration) return;
      var addrFallback = addrPending || resolveOsAddressQuery();
      if (!addrFallback) return;
      if (mapaApenas) {
        showOsGoogleEmbedByAddress(addrFallback, 'map');
      } else {
        showOsGoogleEmbedByAddress(addrFallback, osPreviewView === 'map' ? 'map' : 'street');
      }
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
    var mapaApenas = wrap && wrap.getAttribute('data-mapa-apenas') === '1';
    var iframe = document.getElementById('os_ponto_streetview_frame');
    var svBlock = document.getElementById('os_ponto_streetview_block');
    var endBlock = document.getElementById('os_ponto_preview_endereco');
    var endText = document.getElementById('os_ponto_preview_endereco_text');
    var hintNoCoord = document.getElementById('os_ponto_sem_coord');
    if (!wrap || (!mapaApenas && (!iframe || !svBlock))) return;

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
    if (mapBtn) mapBtn.hidden = !(hasSv || hasMapaEndereco);
    if (svBtn) svBtn.hidden = !(hasSv || hasMapaEndereco);

    if (hasSv) {
      osLastCoords.lat = lat;
      osLastCoords.lng = lng;
      mapGeocodeGeneration++;
      if (mapaApenas) {
        showOsMapaApenas(lat, lng, null);
      } else if (osPreviewView === 'map' || !svBtn || svBtn.hidden) {
        showOsLeafletMap(lat, lng);
      } else {
        showOsStreetView(lat, lng);
      }
    } else if (hasMapaEndereco) {
      osPreviewView = 'street';
      runMapGeocode(tier, addrGeocode, mapaApenas, iframe, svBlock, mapMini);
    } else {
      mapGeocodeGeneration++;
      osPreviewView = 'street';
      if (!mapaApenas) {
        if (iframe) iframe.removeAttribute('src');
        if (svBlock) svBlock.hidden = true;
        if (mapMini) mapMini.hidden = true;
      } else {
        var embedWrap = document.getElementById('os_ponto_map_embed_wrap');
        if (embedWrap) embedWrap.hidden = true;
        if (mapMini) mapMini.hidden = true;
      }
    }

    var showPreview = hasSv || hasMapaEndereco || (pontoChosen && tier === 0);
    wrap.hidden = !showPreview;
    if (mapMini && !showPreview && !mapaApenas) {
      mapMini.hidden = true;
    }
  }

  function showOsLeafletMap(la, lo) {
    var mapEl = document.getElementById('os_ponto_map_mini');
    var svBlock = document.getElementById('os_ponto_streetview_block');
    var embedWrap = document.getElementById('os_ponto_map_embed_wrap');
    var iframe = document.getElementById('os_ponto_streetview_frame');
    var frameWrap = osStreetViewFrameWrap();
    if (!mapEl || la === null || lo === null) return;
    if (typeof root.L === 'undefined') {
      if (iframe && frameWrap) {
        osPreviewView = 'map';
        if (svBlock) svBlock.hidden = false;
        frameWrap.hidden = false;
        mapEl.hidden = true;
        iframe.hidden = false;
        var ll = encodeURIComponent(String(la) + ',' + String(lo));
        iframe.src = 'https://www.google.com/maps?q=' + ll + '&z=17&hl=pt-BR&output=embed';
        setOsViewButtons('map');
      }
      return;
    }
    osPreviewView = 'map';
    if (svBlock) svBlock.hidden = false;
    if (embedWrap) embedWrap.hidden = true;
    if (frameWrap) frameWrap.hidden = false;
    if (iframe) iframe.hidden = true;
    mapEl.hidden = false;
    setOsGeocodeFallbackHint(false);
    setOsViewButtons('map');
    if (osLeafletMap) {
      osLeafletMap.setView([la, lo], 16);
      root.setTimeout(function () { osLeafletMap.invalidateSize(); }, 50);
      root.setTimeout(function () { osLeafletMap.invalidateSize(); }, 280);
      return;
    }
    osLeafletMap = root.L.map('os_ponto_map_mini', { scrollWheelZoom: false, zoomControl: true });
    if (root.CrmLeafletBasemap) {
      root.CrmLeafletBasemap.addTo(osLeafletMap, { maxZoom: 19 });
    }
    root.L.marker([la, lo]).addTo(osLeafletMap);
    osLeafletMap.setView([la, lo], 16);
    root.setTimeout(function () { osLeafletMap.invalidateSize(); }, 50);
    root.setTimeout(function () { osLeafletMap.invalidateSize(); }, 280);
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
    var wrap = document.getElementById('os_ponto_preview');
    var mapaApenas = wrap && wrap.getAttribute('data-mapa-apenas') === '1';
    var mapBtn = document.getElementById('os_ponto_map_btn');
    var svBtn = document.getElementById('os_ponto_sv_btn');
    if (!mapaApenas && mapBtn) {
      mapBtn.addEventListener('click', function () {
        var c = resolveOsDisplayCoords();
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
          osPreviewView = 'map';
          showOsGoogleEmbedByAddress(addrQuery, 'map');
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
              var hintRate = document.getElementById('os_ponto_geocode_hint');
              if (hintRate) {
                hintRate.hidden = false;
                hintRate.textContent =
                  'Limite temporário do serviço de mapas. Aguarde ~1 minuto e altere um campo do endereço.';
              }
              return;
            }
            if (!res || !res.ok || !res.hit) return;
            var la = parseFloat(res.hit.lat);
            var lo = parseFloat(res.hit.lon);
            if (!isFinite(la) || !isFinite(lo)) return;
            setChamadoFormCoords(la, lo);
            setOsGeocodeFallbackHint(false);
            showOsLeafletMap(la, lo);
          });
        }
      });
    }
    if (!mapaApenas && svBtn) {
      svBtn.addEventListener('click', function () {
        var c = resolveOsDisplayCoords();
        if (c.lat !== null && c.lng !== null) {
          showOsStreetView(c.lat, c.lng);
          return;
        }
        var resolved = resolveOsMapTier();
        if (resolved.addrGeocode) {
          showOsStreetViewByAddress(resolved.addrGeocode);
        }
      });
    }
    wirePontoSelectFill(getPontoSelect());
    if (mapaApenas) {
      var coordsBoot = getChamadoLatLng();
      if (coordsBoot.lat !== null && coordsBoot.lng !== null) {
        showOsMapaApenas(coordsBoot.lat, coordsBoot.lng, null);
      }
    }
    document.addEventListener('crm:os-address-changed', scheduleRefreshChamadoLocPreview);
    ['chamado_latitude', 'chamado_longitude'].forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) return;
      el.addEventListener('input', function () {
        lastMapGeocodeKey = '';
        scheduleRefreshChamadoLocPreview();
        clearTimeout(reverseGeocodeTimer);
        reverseGeocodeTimer = window.setTimeout(reverseGeocodeFromLatLng, 1500);
      });
    });
    ['os_logradouro', 'os_numero', 'os_complemento', 'os_bairro', 'os_cidade', 'os_uf', 'os_cep'].forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) return;
      el.addEventListener('input', function () {
        lastMapGeocodeKey = '';
        scheduleRefreshChamadoLocPreview();
      });
    });
    refreshChamadoLocPreview();
    window.setTimeout(refreshChamadoLocPreview, 120);
    window.setTimeout(refreshChamadoLocPreview, 500);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(window);
</script>
<?php else: ?>
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
