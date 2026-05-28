<?php
/**
 * Sidebar operador (mobile-first).
 * Espera: $activePage (string) e $basePath (string).
 */
if (!isset($activePage)) {
    $activePage = '';
}
if (!isset($basePath)) {
    $basePath = '../';
}

$OP = (function_exists('current_user') ? current_user() : null)
    ?? ($GLOBALS['MOCK_USER_OPERADOR'] ?? null)
    ?? ['nome' => 'Operador', 'tipo' => 'Operador', 'iniciais' => 'OP', 'empresa' => ''];

require_once __DIR__ . '/modules.php';
require_once __DIR__ . '/sidebar_nav_icon.php';

$items = [
    ['key' => 'dashboard', 'label' => 'Início',    'href' => 'operador/index.php',    'tag' => ''],
    ['key' => 'chamados',  'label' => 'Chamados', 'href' => 'operador/chamados.php', 'tag' => ''],
];

$itemsNav = [];
foreach ($items as $it) {
    $k = (string) ($it['key'] ?? '');
    if ($k !== 'dashboard' && !app_modulo_habilitado('operador', $k, $OP)) {
        continue;
    }
    $itemsNav[] = $it;
}
?>
<aside class="sidebar" id="sidebar">
  <div class="brand">
    <img class="brand-logo-img" src="<?= htmlspecialchars($basePath . (defined('APP_BRAND_SIDEBAR_LOGO') ? APP_BRAND_SIDEBAR_LOGO : (defined('APP_BRAND_ICON') ? APP_BRAND_ICON : 'assets/img/lighton-icon.png'))) ?>" width="64" height="64" alt="<?= htmlspecialchars(defined('APP_BRAND_NAME') ? APP_BRAND_NAME : 'OnLight') ?>">
    <div class="brand-text">
      <small><?= htmlspecialchars(defined('APP_BRAND_TAGLINE') ? APP_BRAND_TAGLINE : 'Gestão em Iluminação') ?></small>
      <h1><?= htmlspecialchars(defined('APP_BRAND_NAME') ? APP_BRAND_NAME : 'OnLight') ?></h1>
    </div>
  </div>

  <div class="menu-label">Menu principal</div>
  <nav class="nav">
    <?php foreach ($itemsNav as $it): ?>
      <a href="<?= $basePath . $it['href'] ?>"<?= $activePage === $it['key'] ? ' class="active"' : '' ?>>
        <span class="nav-link-main"><?= app_sidebar_nav_icon_svg((string) $it['key']) ?><span class="nav-link-label"><?= htmlspecialchars((string) $it['label']) ?></span></span>
        <?php if (($it['tag'] ?? '') !== ''): ?>
          <span class="tag"><?= htmlspecialchars((string) $it['tag']) ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-footer">
    <?php $sidebarUser = $OP; include __DIR__ . '/sidebar_user_box.php'; ?>
    <a href="<?= $basePath ?>logout.php" class="logout" data-confirm="Deseja realmente sair do sistema?">Sair do sistema</a>
  </div>
</aside>
