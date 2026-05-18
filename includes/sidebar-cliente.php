<?php
/**
 * Sidebar cliente.
 * Espera: $activePage (string) e $basePath (string).
 */
if (!isset($activePage)) $activePage = '';
if (!isset($basePath))   $basePath   = '../';

$CLIENTE = (function_exists('current_user') ? current_user() : null)
    ?? $MOCK_USER_CLIENTE
    ?? ['nome' => 'Cliente', 'tipo' => 'Cliente', 'iniciais' => 'CL', 'empresa' => ''];

require_once __DIR__ . '/modules.php';
require_once __DIR__ . '/sidebar_nav_icon.php';

$items = [
    ['key' => 'dashboard', 'label' => 'Painel',          'href' => 'cliente/index.php'],
    ['key' => 'pontos_iluminacao', 'label' => 'Iluminação', 'href' => 'cliente/pontos_iluminacao.php'],
    ['key' => 'chamados',  'label' => 'Meus Chamados',  'href' => 'cliente/chamados.php'],
    ['key' => 'medicao',   'label' => 'Medição',        'href' => 'cliente/medicao.php'],
    ['key' => 'catalogo',  'label' => 'Catálogo',       'href' => 'cliente/catalogo.php'],
    ['key' => 'auditoria', 'label' => 'Auditoria',      'href' => 'cliente/auditoria.php'],
    ['key' => 'documentos', 'label' => 'Documentos',    'href' => 'cliente/documentos.php'],
    ['key' => 'meus_dados', 'label' => 'Meus dados',   'href' => 'cliente/meus_dados.php'],
    ['key' => 'suporte',   'label' => 'Suporte',        'href' => 'cliente/suporte.php'],
];

$itemsNav = [];
foreach ($items as $it) {
    $k = (string) ($it['key'] ?? '');
    if ($k !== 'dashboard' && $k !== 'perfil' && $k !== 'meus_dados' && !app_modulo_habilitado('cliente', $k)) {
        continue;
    }
    $itemsNav[] = $it;
}
?>
<aside class="sidebar" id="sidebar">
  <div class="brand">
    <img class="brand-logo-img" src="<?= htmlspecialchars($basePath . (defined('APP_BRAND_SIDEBAR_LOGO') ? APP_BRAND_SIDEBAR_LOGO : (defined('APP_BRAND_ICON') ? APP_BRAND_ICON : 'assets/img/lighton-icon.png'))) ?>" width="64" height="64" alt="<?= htmlspecialchars(defined('APP_BRAND_NAME') ? APP_BRAND_NAME : 'OnLight') ?>">
    <div class="brand-text">
      <small>Portal da prefeitura</small>
      <h1><?= htmlspecialchars(defined('APP_BRAND_NAME') ? APP_BRAND_NAME : 'OnLight') ?></h1>
    </div>
  </div>

  <div class="menu-label">Menu principal</div>
  <nav class="nav">
    <?php foreach ($itemsNav as $it): ?>
      <a href="<?= $basePath . $it['href'] ?>" class="<?= $activePage === $it['key'] ? 'active' : '' ?>">
        <span class="nav-link-main"><?= app_sidebar_nav_icon_svg((string) $it['key']) ?><span class="nav-link-label"><?= htmlspecialchars((string) $it['label']) ?></span></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-footer">
    <div class="user-box">
      <div class="avatar"><?= htmlspecialchars($CLIENTE['iniciais']) ?></div>
      <div>
        <div class="user-name"><?= htmlspecialchars($CLIENTE['nome']) ?></div>
        <div class="user-role"><?= htmlspecialchars($CLIENTE['empresa'] ?: $CLIENTE['tipo']) ?></div>
      </div>
    </div>
    <a href="<?= $basePath ?>logout.php" class="logout" data-confirm="Deseja realmente sair?">Sair</a>
  </div>
</aside>
<?php
$topbarMinhaContaHref   = $basePath . 'cliente/perfil.php';
$topbarMinhaContaActive = ($activePage === 'perfil' || $activePage === 'meus_dados');
