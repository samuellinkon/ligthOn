<?php
/**
 * Timeline do histórico do chamado.
 * Espera: $chamadoHistorico (list), opcional $chamadoHistoricoEmptyMsg
 */
if (!isset($chamadoHistorico) || !is_array($chamadoHistorico)) {
    $chamadoHistorico = [];
}
$chamadoHistoricoEmptyMsg = $chamadoHistoricoEmptyMsg ?? 'Nenhum evento registrado para este chamado ainda.';
?>
<div class="chamado-historico" role="log" aria-label="Histórico do chamado">
  <?php if ($chamadoHistorico === []): ?>
    <div class="chamado-historico-empty">
      <p><?= htmlspecialchars($chamadoHistoricoEmptyMsg) ?></p>
      <small class="muted">Criação, edições, mensagens, fotos, itens e aprovações aparecem aqui com o nome de quem executou cada ação.</small>
    </div>
  <?php else: ?>
    <ol class="chamado-historico-timeline">
      <?php foreach ($chamadoHistorico as $ev): ?>
        <?php
          $tone = htmlspecialchars((string) ($ev['tone'] ?? 'info'));
          $icone = htmlspecialchars((string) ($ev['icone'] ?? 'info'));
          $ator = trim((string) ($ev['ator'] ?? 'Utilizador não identificado'));
          $perfilLbl = trim((string) ($ev['perfil_label'] ?? ''));
          if ($ator === '') {
              $ator = 'Utilizador não identificado';
          }
        ?>
        <li class="chamado-historico-item chamado-historico-item--<?= $tone ?>">
          <span class="chamado-historico-item__dot chamado-historico-item__dot--<?= $icone ?>" aria-hidden="true"></span>
          <div class="chamado-historico-item__body">
            <div class="chamado-historico-item__head">
              <strong class="chamado-historico-item__title"><?= htmlspecialchars((string) ($ev['titulo'] ?? 'Evento')) ?></strong>
              <time class="chamado-historico-item__time" datetime="<?= htmlspecialchars((string) ($ev['ts'] ?? '')) ?>">
                <?= htmlspecialchars((string) ($ev['ts_label'] ?? '')) ?>
              </time>
            </div>
            <div class="chamado-historico-item__actor">
              <span class="chamado-historico-item__actor-label">Por</span>
              <strong class="chamado-historico-item__actor-name"><?= htmlspecialchars($ator) ?></strong>
              <?php if ($perfilLbl !== ''): ?>
                <span class="chamado-historico-item__badge"><?= htmlspecialchars($perfilLbl) ?></span>
              <?php endif; ?>
            </div>
            <?php if (!empty($ev['detalhe'])): ?>
              <p class="chamado-historico-item__detail"><?= htmlspecialchars((string) $ev['detalhe']) ?></p>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>
</div>
