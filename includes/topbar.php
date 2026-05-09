<?php
/**
 * Topbar reutilizável.
 * Espera:
 *   $topTitle    - título da tela
 *   $topSubtitle - subtítulo curto
 *   $topAction   - ['label' => '...', 'href' => '...', 'icon' => '+'] (opcional)
 *   $topActions  - lista de ações extras no mesmo grupo (opcional)
 */

if (!isset($topTitle))    $topTitle    = 'Dashboard';
if (!isset($topSubtitle)) $topSubtitle = '';
if (!isset($topAction))   $topAction   = null;
if (!isset($topActions))  $topActions  = [];
?>
<header class="topbar">
  <div class="topbar-start">
    <button class="hamburger" aria-label="Abrir menu"><span></span></button>
    <div class="topbar-title">
      <h2><?= htmlspecialchars($topTitle) ?></h2>
      <?php if ($topSubtitle): ?>
        <p><?= htmlspecialchars($topSubtitle) ?></p>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($topAction || !empty($topActions) || !empty($topbarMinhaContaHref)): ?>
  <div class="top-actions">
    <div class="top-actions-trail">
      <?php if ($topAction): ?>
        <a href="<?= htmlspecialchars($topAction['href']) ?>" class="btn btn-primary">
          <?= htmlspecialchars($topAction['icon'] ?? '+') ?> <?= htmlspecialchars($topAction['label']) ?>
        </a>
      <?php endif; ?>
      <?php foreach ((array) $topActions as $action): ?>
        <a href="<?= htmlspecialchars((string) ($action['href'] ?? '#')) ?>" class="btn <?= htmlspecialchars((string) ($action['class'] ?? 'btn-secondary')) ?>">
          <?= htmlspecialchars((string) ($action['icon'] ?? '')) ?> <?= htmlspecialchars((string) ($action['label'] ?? 'Ação')) ?>
        </a>
      <?php endforeach; ?>
      <?php if (!empty($topbarMinhaContaHref)): ?>
        <a href="<?= htmlspecialchars($topbarMinhaContaHref) ?>"
           class="btn btn-secondary topbar-minha-conta<?= !empty($topbarMinhaContaActive) ? ' is-active' : '' ?>"
           title="Minha conta"
           aria-label="Minha conta — nome, e-mail e senha">
          <svg class="topbar-minha-conta__icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
            <circle cx="12" cy="7" r="4"/>
          </svg>
        </a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</header>
<?php
if (function_exists('render_flash')) {
    render_flash();
}
?>
