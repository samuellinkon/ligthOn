<?php
/**
 * Sidebar admin.
 * Espera: $activePage (string) e $basePath (string).
 */
if (!isset($activePage)) $activePage = '';
if (!isset($basePath))   $basePath   = '../';

$ADMIN = (function_exists('current_user') ? current_user() : null)
    ?? $MOCK_USER_ADMIN
    ?? ['nome' => 'Administrador', 'tipo' => 'Admin', 'iniciais' => 'AD'];

require_once __DIR__ . '/modules.php';
require_once __DIR__ . '/sidebar_nav_icon.php';

$hrefCatalogo = 'admin/catalogo.php';
if (function_exists('db_ok') && db_ok() && function_exists('repo_clientes_empresas')) {
    require_once __DIR__ . '/repository.php';
    $cidNav = null;
    if (function_exists('gestao_scope_cliente_id')) {
        $gs = gestao_scope_cliente_id($ADMIN);
        if ($gs !== null && $gs > 0) {
            $cidNav = $gs;
        }
    }
    if ($cidNav === null && function_exists('repo_catalogo_cliente_id_padrao_admin')) {
        $cidNav = repo_catalogo_cliente_id_padrao_admin();
    }
    if ($cidNav === null) {
        $emps = repo_clientes_empresas();
        if (!empty($emps[0]['id'])) {
            $cidNav = (int) $emps[0]['id'];
        }
    }
    if ($cidNav !== null && $cidNav > 0) {
        $hrefCatalogo = 'admin/catalogo.php?cliente_id=' . $cidNav;
    }
}

$items = [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => 'admin/index.php'],
    ['key' => 'clientes',  'label' => 'Cliente',  'href' => 'admin/clientes.php'],
    ['key' => 'usuarios',  'label' => 'Usuários',  'href' => 'admin/usuarios.php', 'admin_only' => true],
    ['key' => 'pontos_iluminacao', 'label' => 'Iluminação', 'href' => 'admin/pontos_iluminacao.php'],
    ['key' => 'chamados',  'label' => 'Chamados',  'href' => 'admin/chamados.php'],
    ['key' => 'medicao',   'label' => 'Medição',   'href' => 'admin/medicao.php'],
    ['key' => 'catalogo',  'label' => 'Catálogo', 'href' => $hrefCatalogo],
    ['key' => 'relatorio_financeiro', 'label' => 'Relatório financeiro', 'href' => 'admin/relatorio_financeiro.php'],
    ['key' => 'auditoria', 'label' => 'Auditoria', 'href' => 'admin/auditoria.php'],
    ['key' => 'configuracoes','label' => 'Avançado','href' => 'admin/configuracoes.php', 'admin_only' => true],
    ['key' => 'suporte',   'label' => 'Suporte',   'href' => 'admin/suporte.php'],
];

$itemsNav = [];
foreach ($items as $it) {
    if (!empty($it['super_only']) && empty($ADMIN['is_super_admin'])) {
        continue;
    }
    if (!empty($it['admin_only']) && ($ADMIN['perfil'] ?? '') !== 'admin') {
        continue;
    }
    $k = (string) ($it['key'] ?? '');
    if (!app_modulo_painel_habilitado($k, $ADMIN)) {
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
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-footer">
    <?php $sidebarUser = $ADMIN; include __DIR__ . '/sidebar_user_box.php'; ?>
    <a href="<?= $basePath ?>logout.php" class="logout" data-confirm="Deseja realmente sair do sistema?">Sair do sistema</a>
  </div>
</aside>
<?php
/** Link «Minha conta» no topbar (admin): definido aqui para ficar disponível no include seguinte. */
$topbarMinhaContaHref   = $basePath . 'admin/perfil.php';
$topbarMinhaContaActive = ($activePage === 'perfil');
