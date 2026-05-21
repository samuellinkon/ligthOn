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
if (!isset($ch_os_readonly_endereco)) {
    $ch_os_readonly_endereco = false;
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
$probOpts = chamado_os_opcoes_problema();
$chOsReadonlyAddr = !empty($ch_os_readonly_endereco);

?>
<div class="os-form-layout">
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

  <div class="os-section os-section--local-problema">
    <div class="os-section-header">Endereço e problema no local</div>
    <div class="os-section-body">
      <div class="form-grid form-grid--os-pane">
        <p class="os-pane-sub">Endereço</p>
        <?php if ($chOsReadonlyAddr): ?>
        <div class="chamado-os-endereco-readonly" aria-label="Endereço cadastrado">
          <?php
          $enderecoExibir = trim((string) ($ch_os_vals['endereco_completo'] ?? ''));
          if ($enderecoExibir === '') {
              $enderecoExibir = chamado_os_compor_endereco_completo($ch_os_vals) ?? '—';
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
              <option value="<?= htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8') ?>"<?= ((string) ($ch_os_vals['origem_os'] ?? '') === (string) $val) ? ' selected' : '' ?>><?= htmlspecialchars($lab, ENT_QUOTES, 'UTF-8') ?></option>
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
            <option
              value="<?= (int) ($p['id'] ?? 0) ?>"
              data-cliente="<?= (int) ($p['cliente_id'] ?? 0) ?>"
              data-endereco="<?= htmlspecialchars((string) ($p['endereco_completo'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
              data-lat="<?= htmlspecialchars((string) ($p['latitude'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
              data-lng="<?= htmlspecialchars((string) ($p['longitude'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
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
          <small class="muted" style="display:block;margin-top:8px;"><?php if ($ch_os_mostrar_preview_mapa): ?>Ao selecionar um ponto, o endereço (logradouro) pode ser preenchido automaticamente. O Street View e o mapa embutido usam a latitude, longitude ou o endereço preenchidos no chamado — não as coordenadas do cadastro do ponto.<?php else: ?>Ao selecionar um ponto, o endereço (logradouro) pode ser preenchido automaticamente.<?php endif; ?></small>
        </div>
        <?php elseif ($ch_os_mostrar_preview_mapa): ?>
        <p class="muted os-pane-sub" style="margin:0 0 4px;">Street View e o mapa abaixo usam a latitude, longitude ou o endereço deste chamado (não exige vínculo com ponto de iluminação).</p>
        <?php endif; ?>
        <?php if ($ch_os_mostrar_preview_mapa): ?>
        <div class="form-group full" style="margin-top:4px;">
          <div id="os_ponto_preview" class="os-ponto-preview" hidden>
            <div id="os_ponto_preview_endereco" class="chamado-ponto-endereco os-ponto-preview__endereco" hidden>
              <span class="chamado-ponto-endereco__label">Endereço do ponto</span>
              <div id="os_ponto_preview_endereco_text" class="chamado-ponto-endereco__text"></div>
            </div>
            <p id="os_ponto_sem_coord" class="muted os-ponto-preview__hint" hidden>Informe latitude e longitude no chamado, ou preencha o endereço nos campos acima, para ver o Street View ou o mapa.</p>
            <div id="os_ponto_streetview_block" class="chamado-ponto-streetview" hidden>
              <div class="chamado-ponto-streetview__head">
                <span class="chamado-ponto-streetview__label" id="os_ponto_streetview_label">Street View</span>
                <a id="os_ponto_streetview_tab" class="btn btn-ghost btn-sm" href="#" target="_blank" rel="noopener">Abrir em nova aba</a>
              </div>
              <div class="chamado-ponto-streetview__frame-wrap">
                <iframe id="os_ponto_streetview_frame" class="chamado-ponto-streetview__frame" title="Street View da localização do chamado"></iframe>
              </div>
            </div>
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
<?php if ($ch_os_mostrar_preview_mapa): ?>
<script>
(function () {
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

  function getChamadoLatLng() {
    var la = document.getElementById('chamado_latitude');
    var lo = document.getElementById('chamado_longitude');
    return {
      lat: parseCoord(la && la.value),
      lng: parseCoord(lo && lo.value)
    };
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

  /** Só no change do ponto: copia logradouro do cadastro do ponto (não nas digitações de lat/endereço). */
  function fillLogradouroFromPonto(sel) {
    if (!sel) return;
    var opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value || opt.value === '0') return;
    var end = (opt.getAttribute('data-endereco') || '').trim();
    var log = document.getElementById('os_logradouro');
    if (end && log) log.value = end;
  }

  function refreshChamadoLocPreview() {
    var wrap = document.getElementById('os_ponto_preview');
    var iframe = document.getElementById('os_ponto_streetview_frame');
    var svBlock = document.getElementById('os_ponto_streetview_block');
    var tab = document.getElementById('os_ponto_streetview_tab');
    var svLabel = document.getElementById('os_ponto_streetview_label');
    var endBlock = document.getElementById('os_ponto_preview_endereco');
    var endText = document.getElementById('os_ponto_preview_endereco_text');
    var hintNoCoord = document.getElementById('os_ponto_sem_coord');
    if (!wrap || !iframe || !svBlock) return;

    var sel = getPontoSelect();
    var opt = sel ? sel.options[sel.selectedIndex] : null;
    var pontoChosen = !!(sel && opt && opt.value && opt.value !== '0');
    var end = pontoChosen && opt ? (opt.getAttribute('data-endereco') || '').trim() : '';
    var coords = getChamadoLatLng();
    var lat = coords.lat;
    var lng = coords.lng;
    var addrGeocode = buildChamadoEnderecoParaGeocode();

    if (mapGeocodeTimer) {
      clearTimeout(mapGeocodeTimer);
      mapGeocodeTimer = null;
    }

    var hasEnd = end !== '';
    var hasSv = lat !== null && lng !== null;
    var hasMapaEndereco = !hasSv && addrGeocode !== '';

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
      hintNoCoord.hidden = hasSv || hasMapaEndereco || !pontoChosen;
    }

    if (tab) tab.textContent = 'Abrir em nova aba';
    if (svLabel) svLabel.textContent = 'Street View';

    if (hasSv) {
      mapGeocodeGeneration++;
      var embed =
        'https://www.google.com/maps?cbll=' +
        encodeURIComponent(String(lat)) +
        ',' +
        encodeURIComponent(String(lng)) +
        '&cbp=11,0,0,0,0&layer=c&output=svembed';
      var tabUrl =
        'https://www.google.com/maps/@?api=1&map_action=pano&viewpoint=' +
        encodeURIComponent(String(lat)) +
        ',' +
        encodeURIComponent(String(lng));
      iframe.src = embed;
      if (tab) {
        tab.setAttribute('href', tabUrl);
        tab.hidden = false;
      }
      svBlock.hidden = false;
      var svGenFallback = mapGeocodeGeneration;
      var llFb = encodeURIComponent(String(lat) + ',' + String(lng));
      window.setTimeout(function () {
        if (svGenFallback !== mapGeocodeGeneration || !iframe) return;
        if (iframe.src.indexOf('output=svembed') !== -1) {
          iframe.src = 'https://www.google.com/maps?q=' + llFb + '&z=17&hl=pt-BR&output=embed';
          if (svLabel) svLabel.textContent = 'Mapa (fallback)';
          if (tab) {
            tab.setAttribute('href', 'https://www.google.com/maps/search/?api=1&query=' + llFb);
            tab.textContent = 'Abrir no Google Maps';
          }
        }
      }, 4000);
    } else if (hasMapaEndereco) {
      mapGeocodeGeneration++;
      var gen = mapGeocodeGeneration;
      var addrSnap = addrGeocode;
      var streetLine = buildNominatimStreetLine();
      var cidade = osFieldVal('os_cidade');
      var ufRaw = osFieldVal('os_uf');
      var uf = ufRaw.replace(/\./g, '').toUpperCase();
      var qEnc = encodeURIComponent(addrSnap);
      iframe.src =
        'https://www.google.com/maps?q=' + qEnc + '&hl=pt-BR&output=embed';
      if (tab) {
        tab.setAttribute(
          'href',
          'https://www.google.com/maps/search/?api=1&query=' + qEnc
        );
        tab.hidden = false;
        tab.textContent = 'Abrir no Google Maps';
      }
      if (svLabel) svLabel.textContent = 'Mapa (endereço do chamado)';
      svBlock.hidden = false;

      var nomOpts = {
        method: 'GET',
        headers: { 'Accept-Language': 'pt-BR,pt;q=0.9' },
        mode: 'cors',
        credentials: 'omit'
      };
      var nomEmail = 'crm-prefeitura-nominatim%40invalid.local';

      function applyNominatimHit(hit) {
        if (gen !== mapGeocodeGeneration || !iframe || !hit) return;
        var la = parseFloat(hit.lat);
        var lo = parseFloat(hit.lon);
        if (!isFinite(la) || !isFinite(lo)) return;
        var ll = la + ',' + lo;
        var llEnc = encodeURIComponent(ll);
        iframe.src =
          'https://www.google.com/maps?q=' +
          llEnc +
          '&z=17&hl=pt-BR&output=embed';
        if (tab) {
          tab.setAttribute(
            'href',
            'https://www.google.com/maps/search/?api=1&query=' + llEnc
          );
        }
      }

      mapGeocodeTimer = window.setTimeout(function () {
        mapGeocodeTimer = null;
        var urlStruct = '';
        if (streetLine && cidade && uf) {
          urlStruct =
            'https://nominatim.openstreetmap.org/search?format=json&limit=1&email=' +
            nomEmail +
            '&countrycodes=br' +
            '&street=' +
            encodeURIComponent(streetLine) +
            '&city=' +
            encodeURIComponent(cidade) +
            '&state=' +
            encodeURIComponent(uf) +
            '&country=' +
            encodeURIComponent('Brasil');
        }

        function fetchFreeText(q) {
          return fetch(
            'https://nominatim.openstreetmap.org/search?format=json&limit=1&email=' +
              nomEmail +
              '&q=' +
              encodeURIComponent(q),
            nomOpts
          ).then(function (r) {
            return r.ok ? r.json() : [];
          });
        }

        if (urlStruct) {
          fetch(urlStruct, nomOpts)
            .then(function (r) {
              return r.ok ? r.json() : [];
            })
            .then(function (d1) {
              if (gen !== mapGeocodeGeneration) return { skip: true };
              if (d1 && d1[0]) {
                applyNominatimHit(d1[0]);
                return { skip: true };
              }
              return fetchFreeText(addrSnap);
            })
            .then(function (d2) {
              if (gen !== mapGeocodeGeneration || !d2) return;
              if (d2.skip) return;
              if (Array.isArray(d2) && d2[0]) applyNominatimHit(d2[0]);
            })
            .catch(function () {});
        } else {
          fetchFreeText(addrSnap)
            .then(function (d2) {
              if (gen !== mapGeocodeGeneration) return;
              if (d2 && d2[0]) applyNominatimHit(d2[0]);
            })
            .catch(function () {});
        }
      }, 400);
    } else {
      mapGeocodeGeneration++;
      iframe.removeAttribute('src');
      svBlock.hidden = true;
      if (tab) {
        tab.hidden = true;
        tab.setAttribute('href', '#');
      }
    }

    var showPreview = hasSv || hasMapaEndereco || pontoChosen;
    wrap.hidden = !showPreview;
  }

  function boot() {
    var sel = getPontoSelect();
    if (sel) {
      sel.addEventListener('change', function () {
        fillLogradouroFromPonto(sel);
        refreshChamadoLocPreview();
      });
    }
    ['chamado_latitude', 'chamado_longitude', 'os_logradouro', 'os_numero', 'os_complemento', 'os_bairro', 'os_cidade', 'os_uf', 'os_cep'].forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) return;
      el.addEventListener('input', function () {
        refreshChamadoLocPreview();
      });
    });
    window.setTimeout(function () {
      refreshChamadoLocPreview();
    }, 0);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
</script>
<?php else: ?>
<script>
(function () {
  function fillLogradouroFromPonto(sel) {
    if (!sel) return;
    var opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value || opt.value === '0') return;
    var end = (opt.getAttribute('data-endereco') || '').trim();
    var log = document.getElementById('os_logradouro');
    if (end && log) log.value = end;
  }
  function boot() {
    var sel = document.getElementById('ponto_iluminacao_id');
    if (!sel) return;
    sel.addEventListener('change', function () {
      fillLogradouroFromPonto(sel);
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
</script>
<?php endif; ?>
