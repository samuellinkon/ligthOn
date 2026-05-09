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

$cid = (int) ($CLIENTE['cliente_id'] ?? 0);
require_once __DIR__ . '/repository.php';
$sidebarTagsC = [];
if ($cid > 0 && function_exists('db_ok') && db_ok() && function_exists('repo_sidebar_cliente_tags')) {
    $sidebarTagsC = repo_sidebar_cliente_tags(repo_cliente_matriz_raiz_id($cid), true);
}

$zc = static function (string $key) use ($sidebarTagsC): string {
    return isset($sidebarTagsC[$key]) ? $sidebarTagsC[$key] : '0';
};

require_once __DIR__ . '/modules.php';
require_once __DIR__ . '/sidebar_nav_icon.php';

$items = [
    ['key' => 'dashboard', 'label' => 'Painel',     'href' => 'cliente/index.php',    'tag' => 'Home'],
    ['key' => 'chamados',  'label' => 'Meus Chamados', 'href' => 'cliente/chamados.php', 'tag' => $zc('chamados')],
    ['key' => 'medicao',   'label' => 'Medição',       'href' => 'cliente/medicao.php',  'tag' => $zc('medicao')],
    ['key' => 'os',        'label' => 'OS',            'href' => 'cliente/os.php',        'tag' => $zc('os')],
    ['key' => 'pontos_iluminacao', 'label' => 'Iluminação', 'href' => 'cliente/pontos_iluminacao.php', 'tag' => $zc('pontos_iluminacao')],
    ['key' => 'documentos','label' => 'Documentos',    'href' => 'cliente/documentos.php', 'tag' => $zc('documentos')],
    ['key' => 'suporte',   'label' => 'Suporte',       'href' => 'cliente/suporte.php',  'tag' => $zc('suporte')],
];

$itemsNav = [];
foreach ($items as $it) {
    $k = (string) ($it['key'] ?? '');
    if ($k !== 'dashboard' && $k !== 'perfil' && !app_modulo_habilitado('cliente', $k)) {
        continue;
    }
    $itemsNav[] = $it;
}
?>
<aside class="sidebar" id="sidebar">
  <div class="brand">
    <img class="brand-logo-img" src="<?= htmlspecialchars($basePath . (defined('APP_BRAND_SIDEBAR_LOGO') ? APP_BRAND_SIDEBAR_LOGO : (defined('APP_BRAND_ICON') ? APP_BRAND_ICON : 'assets/img/lighton-icon.png'))) ?>" width="64" height="64" alt="<?= htmlspecialchars(defined('APP_BRAND_NAME') ? APP_BRAND_NAME : 'LightOn') ?>">
    <div class="brand-text">
      <small>Portal da prefeitura</small>
      <h1><?= htmlspecialchars(defined('APP_BRAND_NAME') ? APP_BRAND_NAME : 'LightOn') ?></h1>
    </div>
  </div>

  <div class="menu-label">Menu principal</div>
  <nav class="nav">
    <?php foreach ($itemsNav as $it): ?>
      <a href="<?= $basePath . $it['href'] ?>" class="<?= $activePage === $it['key'] ? 'active' : '' ?>">
        <span class="nav-link-main"><?= app_sidebar_nav_icon_svg((string) $it['key']) ?><span class="nav-link-label"><?= htmlspecialchars((string) $it['label']) ?></span></span>
        <span class="tag"><?= htmlspecialchars((string) $it['tag']) ?></span>
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
$topbarMinhaContaActive = ($activePage === 'perfil');
