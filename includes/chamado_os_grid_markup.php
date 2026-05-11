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
$tipoOpts = chamado_os_opcoes_tipo();

?>
<div class="os-form-layout">
  <div class="os-section">
    <div class="os-section-header">Dados do solicitante</div>
    <div class="os-section-body">
      <div class="form-grid form-grid--os-pane">
        <div class="form-group">
          <label for="os_cpf">CPF</label>
          <input type="text" id="os_cpf" name="contribuinte_cpf" class="input" maxlength="20"
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
                 placeholder="(00) 00000-0000"
                 value="<?= $f('contribuinte_telefone') ?>">
        </div>
      </div>
    </div>
  </div>

  <div class="os-section os-section--local-problema">
    <div class="os-section-header">Endereço e problema no local</div>
    <div class="os-section-body">
      <div class="form-grid form-grid--os-pane">
        <p class="os-pane-sub">Endereço</p>
        <div class="os-addr-grid" role="group" aria-label="Endereço">
          <div class="form-group os-addr-cep">
            <label for="os_cep">CEP</label>
            <input type="text" id="os_cep" name="os_cep" class="input" maxlength="12"
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

        <p class="os-pane-sub os-pane-sub--divider">Classificação</p>
        <div class="form-group">
          <label for="os_origem">Origem da OS</label>
          <select id="os_origem" name="origem_os" class="select">
            <option value="">Selecione...</option>
            <?php foreach ($origOpts as $val => $lab): ?>
              <option value="<?= htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8') ?>"<?= ((string) ($ch_os_vals['origem_os'] ?? '') === (string) $val) ? ' selected' : '' ?>><?= htmlspecialchars($lab, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="os_problema">Problema</label>
          <select id="os_problema" name="problema_os" class="select">
            <option value="">Selecione...</option>
            <?php foreach ($probOpts as $val => $lab): ?>
              <option value="<?= htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8') ?>"<?= ((string) ($ch_os_vals['problema_os'] ?? '') === (string) $val) ? ' selected' : '' ?>><?= htmlspecialchars($lab, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="os_tipo">Tipo de OS</label>
          <select id="os_tipo" name="tipo_os" class="select">
            <option value="">Selecione...</option>
            <?php foreach ($tipoOpts as $val => $lab): ?>
              <option value="<?= htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8') ?>"<?= ((string) ($ch_os_vals['tipo_os'] ?? '') === (string) $val) ? ' selected' : '' ?>><?= htmlspecialchars($lab, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>

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
          <small class="muted" style="display:block;margin-top:8px;">Ao selecionar um ponto, endereço e coordenadas podem ser preenchidos automaticamente.</small>
          <div id="os_ponto_preview" class="os-ponto-preview" hidden>
            <div id="os_ponto_preview_endereco" class="chamado-ponto-endereco os-ponto-preview__endereco" hidden>
              <span class="chamado-ponto-endereco__label">Endereço do ponto</span>
              <div id="os_ponto_preview_endereco_text" class="chamado-ponto-endereco__text"></div>
            </div>
            <p id="os_ponto_sem_coord" class="muted os-ponto-preview__hint" hidden>Coordenadas não cadastradas para este ponto — o Street View não está disponível.</p>
            <div id="os_ponto_streetview_block" class="chamado-ponto-streetview" hidden>
              <div class="chamado-ponto-streetview__head">
                <span class="chamado-ponto-streetview__label">Street View</span>
                <a id="os_ponto_streetview_tab" class="btn btn-ghost btn-sm" href="#" target="_blank" rel="noopener">Abrir em nova aba</a>
              </div>
              <div class="chamado-ponto-streetview__frame-wrap">
                <iframe id="os_ponto_streetview_frame" class="chamado-ponto-streetview__frame" title="Street View do ponto selecionado"></iframe>
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
        <label for="descricao">Descrição</label>
        <textarea id="descricao" name="descricao" class="textarea" rows="8"
                  placeholder="Descreva o problema com o máximo de detalhes possível..."><?= htmlspecialchars((string) $ch_os_descricao, ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>
    </div>
  </div>
</div>
<?php if ($ch_os_mostrar_ponto && !empty($ch_os_pontos_opcoes)): ?>
<script>
(function () {
  function parseCoord(raw) {
    if (raw === null || raw === undefined) return null;
    var s = String(raw).trim().replace(',', '.');
    if (s === '') return null;
    var n = parseFloat(s);
    return isFinite(n) ? n : null;
  }

  function applyPontoOsUi(sel) {
    var wrap = document.getElementById('os_ponto_preview');
    var iframe = document.getElementById('os_ponto_streetview_frame');
    var svBlock = document.getElementById('os_ponto_streetview_block');
    var tab = document.getElementById('os_ponto_streetview_tab');
    var endBlock = document.getElementById('os_ponto_preview_endereco');
    var endText = document.getElementById('os_ponto_preview_endereco_text');
    var hintNoCoord = document.getElementById('os_ponto_sem_coord');
    if (!sel || !wrap || !iframe || !svBlock) return;

    var opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value || opt.value === '0') {
      wrap.hidden = true;
      svBlock.hidden = true;
      iframe.removeAttribute('src');
      if (tab) {
        tab.hidden = true;
        tab.setAttribute('href', '#');
      }
      if (endBlock) endBlock.hidden = true;
      if (endText) endText.textContent = '';
      if (hintNoCoord) hintNoCoord.hidden = true;
      return;
    }

    var end = (opt.getAttribute('data-endereco') || '').trim();
    var lat = parseCoord(opt.getAttribute('data-lat'));
    var lng = parseCoord(opt.getAttribute('data-lng'));

    var log = document.getElementById('os_logradouro');
    if (end && log) log.value = end;
    if (lat !== null) {
      var la = document.getElementById('chamado_latitude');
      if (la) la.value = String(lat);
    }
    if (lng !== null) {
      var lo = document.getElementById('chamado_longitude');
      if (lo) lo.value = String(lng);
    }

    var hasEnd = end !== '';
    var hasSv = lat !== null && lng !== null;

    if (endText && endBlock) {
      if (hasEnd) {
        endText.textContent = end;
        endBlock.hidden = false;
      } else {
        endText.textContent = '';
        endBlock.hidden = true;
      }
    }

    if (hintNoCoord) {
      hintNoCoord.hidden = hasSv || !opt.value || opt.value === '0';
    }

    if (hasSv) {
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
    } else {
      iframe.removeAttribute('src');
      svBlock.hidden = true;
      if (tab) {
        tab.hidden = true;
        tab.setAttribute('href', '#');
      }
    }

    var showPreview = hasEnd || hasSv;
    if (!hasSv && opt.value && opt.value !== '0') {
      showPreview = true;
    }
    wrap.hidden = !showPreview;
  }

  function boot() {
    var sel = document.getElementById('ponto_iluminacao_id');
    if (!sel) return;
    sel.addEventListener('change', function () {
      applyPontoOsUi(sel);
    });
    window.setTimeout(function () {
      applyPontoOsUi(sel);
    }, 0);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
</script>
<?php endif; ?>
