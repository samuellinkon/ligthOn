<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/medicao_helpers.php';
require_once __DIR__ . '/../includes/modules.php';
$CLIENTE = require_auth('cliente');

$basePath   = '../';
$activePage = 'dashboard';
$pageTitle  = 'Painel';

$dashPainel              = 'cliente';
$dashUser                = $CLIENTE;
$dashMostrarColunaOrgao  = false;
$dashMapResizePrefix     = 'cliente';
require __DIR__ . '/../includes/dashboard_gestao_bootstrap.php';

$topTitle    = 'Dashboard';
$topSubtitle = 'Visão executiva de chamados e indicadores.';
$topSearch   = 'Buscar chamado...';
$topAction   = app_modulo_habilitado('cliente', 'chamados')
    ? ['label' => 'Abrir novo chamado', 'href' => 'chamado_novo.php', 'icon' => '+']
    : null;

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-cliente.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
<?php require __DIR__ . '/../includes/partials/dashboard_gestao_conteudo.php'; ?>
</section>

<?php require __DIR__ . '/../includes/partials/dashboard_gestao_scripts.php'; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
