<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';

$user = require_auth('cliente');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_cliente('pontos_iluminacao');

$pageTitle  = 'Pontos de iluminação';
$basePath   = '../';
$activePage = 'pontos_iluminacao';
$userClienteId = (int) ($user['cliente_id'] ?? 0);

if (!db_ok() || $userClienteId <= 0) {
    flash_set('err', 'Banco indisponível ou cliente inválido.');
    header('Location: index.php');
    exit;
}

$pontosPainelEscopoId = repo_cliente_matriz_raiz_id($userClienteId);
if ($pontosPainelEscopoId <= 0) {
    flash_set('err', 'Empresa não vinculada ao seu acesso.');
    header('Location: index.php');
    exit;
}

$loadLeaflet              = true;
$loadLeafletMarkerCluster = true;

$topTitle    = 'Pontos de iluminação';
$topSubtitle = 'Postes cadastrados e situação por chamados.';
$topSearch   = 'Buscar ponto...';
$topAction   = ['label' => 'Novo poste', 'href' => 'ponto_iluminacao_novo.php', 'icon' => '+'];
$topActions  = [];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-cliente.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<?php
$pontosPainelFormAction    = 'pontos_iluminacao.php';
$pontosPainelHiddenQuery   = [];
$pontosPainelHrefNovoPoste = 'ponto_iluminacao_novo.php';
$pontosPainelMostrarRotas  = false;
$pontosPainelHrefRotas     = '';
require __DIR__ . '/../includes/pontos_iluminacao_listagem_painel.php';
?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
