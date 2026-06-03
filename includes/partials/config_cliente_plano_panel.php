<?php
/**
 * Painel de plano e uso — Configurações → Prefeitura e catálogo.
 *
 * Espera: $planoClienteId (int), $planoLimites (array), $planoResumo (list)
 */
if (!isset($planoClienteId)) {
    $planoClienteId = 0;
}
if (!isset($planoLimites) || !is_array($planoLimites)) {
    $planoLimites = [];
}
if (!isset($planoResumo) || !is_array($planoResumo)) {
    $planoResumo = [];
}
$presetsJson = json_encode(cliente_plano_presets(), JSON_UNESCAPED_UNICODE);
$codigoAtual = (string) ($planoLimites['plano_codigo'] ?? 'padrao');
$mensAtual = $planoLimites['plano_mensalidade'] ?? null;
?>
<div class="panel-head" style="border-top:1px solid var(--border-soft);">
  <h4>Plano e limites</h4>
  <span class="panel-sub">Limites comerciais da prefeitura (#<?= (int) $planoClienteId ?>). Campo vazio ou marcado como ilimitado = sem teto. Aviso em 80%; bloqueio em 100%.</span>
</div>
<div class="panel-body form form-grid">
  <div class="form-group full">
    <label for="plano_codigo">Preset do plano</label>
    <select id="plano_codigo" name="plano_codigo" class="select" data-plano-preset-select>
      <option value="padrao" <?= $codigoAtual === 'padrao' ? 'selected' : '' ?>>Plano 1 — Padrão (R$ 4.000)</option>
      <option value="expandido" <?= $codigoAtual === 'expandido' ? 'selected' : '' ?>>Plano 2 — Expandido (R$ 6.000)</option>
      <option value="dedicado" <?= $codigoAtual === 'dedicado' ? 'selected' : '' ?>>Plano 3 — Dedicado (sob demanda)</option>
      <option value="personalizado" <?= $codigoAtual === 'personalizado' ? 'selected' : '' ?>>Personalizado</option>
    </select>
    <small class="muted" style="display:block;margin-top:6px;">
      O preset preenche sugestões nos campos. Ao salvar, vale o que estiver no formulário:
      <strong>valor numérico = limite</strong> · <strong>vazio ou «Ilimitado» = sem limite</strong>.
    </small>
  </div>
  <div class="form-group">
    <label for="plano_mensalidade">Mensalidade (R$)</label>
    <input type="text" id="plano_mensalidade" name="plano_mensalidade" class="input" inputmode="decimal"
           value="<?= $mensAtual !== null ? htmlspecialchars(number_format((float) $mensAtual, 2, ',', '')) : '' ?>"
           placeholder="4000,00" data-plano-field>
  </div>
  <?php
  $planoLimiteCampos = [
      'limite_pontos' => ['label' => 'Pontos de iluminação', 'placeholder' => '6000'],
      'limite_chamados_mes' => ['label' => 'Chamados / mês', 'placeholder' => '300'],
      'limite_itens_mes' => ['label' => 'Itens em chamados / mês', 'placeholder' => '200'],
      'limite_storage_mb' => ['label' => 'Armazenamento (MB)', 'placeholder' => '5120', 'hint' => '5120 MB = 5 GB · 15360 MB = 15 GB'],
      'limite_usuarios' => ['label' => 'Usuários ativos', 'placeholder' => '12'],
  ];
  foreach ($planoLimiteCampos as $campoId => $campoMeta):
      $valorAtual = $planoLimites[$campoId] ?? null;
      $ilimitadoAtual = $valorAtual === null;
  ?>
  <div class="form-group">
    <label for="<?= htmlspecialchars($campoId) ?>"><?= htmlspecialchars($campoMeta['label']) ?></label>
    <div class="plano-limite-row">
      <input type="number" min="0" id="<?= htmlspecialchars($campoId) ?>" name="<?= htmlspecialchars($campoId) ?>" class="input"
             value="<?= !$ilimitadoAtual ? (int) $valorAtual : '' ?>"
             placeholder="<?= htmlspecialchars($campoMeta['placeholder']) ?> — vazio = ilimitado"
             data-plano-field data-plano-limit-input>
      <label class="checkbox plano-limite-unlimited" style="margin:0;white-space:nowrap;">
        <input type="checkbox" value="1" data-plano-unlimited-for="<?= htmlspecialchars($campoId) ?>"<?= $ilimitadoAtual ? ' checked' : '' ?>>
        <span>Ilimitado</span>
      </label>
    </div>
    <?php if (!empty($campoMeta['hint'])): ?>
      <small class="muted" style="display:block;margin-top:4px;"><?= htmlspecialchars($campoMeta['hint']) ?></small>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<div class="panel-head" style="border-top:1px solid var(--border-soft);">
  <h4>Uso atual do plano</h4>
  <span class="panel-sub">Contadores em tempo real — mês civil corrente para chamados e itens</span>
</div>
<div class="panel-body">
  <?php if (!$planoResumo): ?>
    <p class="muted" style="margin:0;">Nenhum dado de uso disponível.</p>
  <?php else: ?>
    <div class="plano-uso-grid">
      <?php foreach ($planoResumo as $row): ?>
        <?php
          $nivel = (string) ($row['nivel'] ?? 'ok');
          $pct = $row['pct'];
          $pctBar = $pct !== null ? min(100, (float) $pct) : 0;
        ?>
        <div class="plano-uso-row plano-uso-<?= htmlspecialchars($nivel) ?>">
          <div class="plano-uso-row-head">
            <strong><?= htmlspecialchars((string) ($row['label'] ?? '')) ?></strong>
            <span class="plano-uso-valores">
              <?= htmlspecialchars((string) ($row['uso_fmt'] ?? '')) ?>
              /
              <?= htmlspecialchars((string) ($row['limite_fmt'] ?? '')) ?>
              <?php if ($pct !== null): ?>
                <span class="plano-uso-pct">(<?= htmlspecialchars(number_format((float) $pct, 0, ',', '.')) ?>%)</span>
              <?php endif; ?>
            </span>
          </div>
          <?php if ($pct !== null): ?>
            <div class="plano-uso-bar" aria-hidden="true">
              <span class="plano-uso-bar-fill" style="width:<?= $pctBar ?>%;"></span>
            </div>
          <?php else: ?>
            <p class="muted" style="margin:4px 0 0;font-size:13px;">Sem limite configurado</p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<script>
(function () {
  var presets = <?= $presetsJson ?: '{}' ?>;
  var sel = document.querySelector('[data-plano-preset-select]');
  if (!sel) return;
  var fields = document.querySelectorAll('[data-plano-field]');
  var manual = <?= $codigoAtual === 'personalizado' ? 'true' : 'false' ?>;

  function setUnlimited(input, unlimited) {
    if (!input) return;
    var cb = document.querySelector('[data-plano-unlimited-for="' + input.id + '"]');
    if (cb) cb.checked = !!unlimited;
    if (unlimited) {
      input.value = '';
      input.disabled = true;
    } else {
      input.disabled = false;
    }
  }

  function fillPreset(code) {
    var p = presets[code];
    if (!p) return;
    var map = {
      plano_mensalidade: p.mensalidade != null ? String(p.mensalidade).replace('.', ',') : '',
      limite_pontos: p.limite_pontos,
      limite_chamados_mes: p.limite_chamados_mes,
      limite_itens_mes: p.limite_itens_mes,
      limite_storage_mb: p.limite_storage_mb,
      limite_usuarios: p.limite_usuarios
    };
    Object.keys(map).forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) return;
      if (id.indexOf('limite_') === 0) {
        if (map[id] == null || map[id] === '') {
          setUnlimited(el, true);
        } else {
          setUnlimited(el, false);
          el.value = map[id];
        }
        return;
      }
      el.value = map[id] != null ? map[id] : '';
    });
  }

  document.querySelectorAll('[data-plano-unlimited-for]').forEach(function (cb) {
    var input = document.getElementById(cb.getAttribute('data-plano-unlimited-for'));
    if (!input) return;
    cb.addEventListener('change', function () {
      setUnlimited(input, cb.checked);
      if (cb.checked && sel.value !== 'personalizado') {
        sel.value = 'personalizado';
        manual = true;
      }
    });
    if (cb.checked) {
      setUnlimited(input, true);
    }
  });

  sel.addEventListener('change', function () {
    if (sel.value === 'personalizado') return;
    fillPreset(sel.value);
    manual = false;
  });

  fields.forEach(function (el) {
    el.addEventListener('input', function () {
      if (el.disabled) return;
      var cb = document.querySelector('[data-plano-unlimited-for="' + el.id + '"]');
      if (cb && cb.checked) cb.checked = false;
      if (!manual && sel.value !== 'personalizado') {
        sel.value = 'personalizado';
      }
      manual = true;
    });
  });

  var form = sel.closest('form');
  if (form) {
    form.addEventListener('submit', function () {
      document.querySelectorAll('[data-plano-limit-input]').forEach(function (input) {
        var cb = document.querySelector('[data-plano-unlimited-for="' + input.id + '"]');
        if (cb && cb.checked) {
          input.disabled = false;
          input.value = '';
        }
      });
    });
  }
})();
</script>
