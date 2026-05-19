<?php
require_once __DIR__ . '/../includes/auth.php';
$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_once __DIR__ . '/../includes/medicao_helpers.php';

$pageTitle  = 'Dashboard Admin';
$basePath   = '../';
$activePage = 'dashboard';

$dashPainel = 'admin';
$dashUser   = $me;
require __DIR__ . '/../includes/dashboard_gestao_bootstrap.php';

$topTitle    = 'Dashboard';
$topSubtitle = 'Visão executiva de chamados e indicadores.';
$topSearch   = 'Buscar chamado ou prefeitura...';
$topAction   = app_modulo_painel_habilitado('chamados', $me)
    ? ['label' => 'Novo chamado', 'href' => 'chamado_novo.php', 'icon' => '+']
    : null;

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
<?php require __DIR__ . '/../includes/partials/dashboard_gestao_conteudo.php'; ?>
</section>

<?php require __DIR__ . '/../includes/partials/dashboard_gestao_scripts.php'; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
