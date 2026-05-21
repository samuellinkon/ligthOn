<?php
/**
 * Modal — solicitar novo item devolutivo (gestor/admin ou operador).
 *
 * Variáveis antes do include:
 *   $devolutivoModalId, $devolutivoModalOpenAttr, $devolutivoModalCloseAttr, $devolutivoFieldPrefix
 *   $devolutivoModoGestor (bool) — true: gestor cria item + lança recolhido (sem aprovação)
 */
$devolutivoModalId         = (string) ($devolutivoModalId ?? 'ch-solicitar-devolutivo-modal');
$devolutivoModalOpenAttr   = (string) ($devolutivoModalOpenAttr ?? 'data-ch-solicitar-devolutivo-open');
$devolutivoModalCloseAttr  = (string) ($devolutivoModalCloseAttr ?? 'data-ch-solicitar-devolutivo-close');
$devolutivoFieldPrefix     = (string) ($devolutivoFieldPrefix ?? 'ch');
$devolutivoModoGestor      = !empty($devolutivoModoGestor);
$devolutivoTitulo          = $devolutivoModoGestor ? 'Novo item devolutivo' : 'Solicitar novo item devolutivo';
$devolutivoSubmitLabel     = $devolutivoModoGestor ? 'Criar e lançar recolhimento' : 'Enviar solicitação';
$devolutivoTitleId         = $devolutivoFieldPrefix . '-sol-dev-title';
$devolutivoFormId          = $devolutivoFieldPrefix . '-sol-dev-form';
$fNome                     = $devolutivoFieldPrefix . '_item_devolutivo_nome';
$fCodigo                   = $devolutivoFieldPrefix . '_item_devolutivo_codigo';
$fQtd                      = $devolutivoFieldPrefix . '_item_devolutivo_qtd';
$fObs                      = $devolutivoFieldPrefix . '_item_devolutivo_obs';
?>
<div id="<?= htmlspecialchars($devolutivoModalId, ENT_QUOTES, 'UTF-8') ?>" class="chamado-mat-modal" hidden aria-hidden="true">
  <button type="button" class="chamado-mat-modal__scrim" <?= $devolutivoModalCloseAttr ?> tabindex="-1" aria-label="Fechar"></button>
  <div class="chamado-mat-modal__box chamado-mat-modal__box--form" role="dialog" aria-modal="true" aria-labelledby="<?= htmlspecialchars($devolutivoTitleId, ENT_QUOTES, 'UTF-8') ?>">
    <header class="chamado-mat-modal__head">
      <h3 id="<?= htmlspecialchars($devolutivoTitleId, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($devolutivoTitulo, ENT_QUOTES, 'UTF-8') ?></h3>
      <button type="button" class="btn btn-ghost btn-sm" <?= $devolutivoModalCloseAttr ?> aria-label="Fechar">✕</button>
    </header>
    <div class="chamado-mat-modal__body">
      <form id="<?= htmlspecialchars($devolutivoFormId, ENT_QUOTES, 'UTF-8') ?>" method="post" class="chamado-sol-dev-form"<?= $devolutivoModoGestor ? ' data-ch-sol-dev-ajax="1"' : '' ?>>
        <input type="hidden" name="acao" value="solicitar_item_devolutivo">
        <div class="form-group">
          <label for="<?= htmlspecialchars($fNome, ENT_QUOTES, 'UTF-8') ?>">Nome do item *</label>
          <input type="text" id="<?= htmlspecialchars($fNome, ENT_QUOTES, 'UTF-8') ?>" name="item_devolutivo_nome" class="input" required maxlength="200">
        </div>
        <div class="form-group">
          <label for="<?= htmlspecialchars($fCodigo, ENT_QUOTES, 'UTF-8') ?>">Código sugerido</label>
          <input type="text" id="<?= htmlspecialchars($fCodigo, ENT_QUOTES, 'UTF-8') ?>" name="item_devolutivo_codigo" class="input" maxlength="80">
        </div>
        <div class="form-group">
          <label for="<?= htmlspecialchars($fQtd, ENT_QUOTES, 'UTF-8') ?>">Quantidade</label>
          <input type="text" id="<?= htmlspecialchars($fQtd, ENT_QUOTES, 'UTF-8') ?>" name="item_devolutivo_qtd" class="input" value="1" inputmode="decimal">
        </div>
        <div class="form-group">
          <label for="<?= htmlspecialchars($fObs, ENT_QUOTES, 'UTF-8') ?>">Observação</label>
          <textarea id="<?= htmlspecialchars($fObs, ENT_QUOTES, 'UTF-8') ?>" name="item_devolutivo_obs" class="textarea" rows="3"></textarea>
        </div>
        <footer class="chamado-mat-modal__foot">
          <button type="button" class="btn btn-secondary" <?= $devolutivoModalCloseAttr ?>>Cancelar</button>
          <button type="submit" class="btn btn-primary" data-ch-sol-dev-submit><?= htmlspecialchars($devolutivoSubmitLabel, ENT_QUOTES, 'UTF-8') ?></button>
        </footer>
      </form>
    </div>
  </div>
</div>
<script>
(function () {
  var modal = document.getElementById(<?= json_encode($devolutivoModalId) ?>);
  if (!modal) return;
  var openAttr = <?= json_encode($devolutivoModalOpenAttr) ?>;
  var closeAttr = <?= json_encode($devolutivoModalCloseAttr) ?>;
  function open() {
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    var first = modal.querySelector('input:not([type="hidden"])');
    if (first) window.setTimeout(function () { first.focus(); }, 80);
  }
  function close() {
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }
  document.querySelectorAll('[' + openAttr + ']').forEach(function (b) {
    b.addEventListener('click', open);
  });
  modal.querySelectorAll('[' + closeAttr + ']').forEach(function (b) {
    b.addEventListener('click', close);
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && !modal.hidden) close();
  });
  modal._chSolDevClose = close;
})();
</script>
