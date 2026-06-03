<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';

$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('pontos_iluminacao');

$pageTitle  = 'Pontos de iluminação';
$basePath   = '../';
$activePage = 'pontos_iluminacao';

if (!db_ok()) {
    flash_set('err', 'Banco indisponível.');
    header('Location: clientes.php');
    exit;
}

$escopoEmpresa = gestao_scope_cliente_id($me);
$clienteIdUrl = (int) ($_GET['cliente_id'] ?? 0);
if ($escopoEmpresa !== null) {
    $scopeId = $escopoEmpresa;
} else {
    $scopeId = $clienteIdUrl;
    if ($scopeId <= 0) {
        $scopeId = (int) (repo_catalogo_cliente_id_padrao_admin() ?? 0);
    }
    if ($scopeId > 0) {
        $scopeId = repo_cliente_matriz_raiz_id($scopeId);
    }
    if ($scopeId <= 0) {
        $empresas = repo_clientes_empresas();
        $scopeId = (int) ($empresas[0]['id'] ?? 0);
    }
}

if ($scopeId <= 0) {
    flash_set('err', 'Cadastre uma empresa ou cliente antes de acompanhar pontos.');
    header('Location: clientes.php');
    exit;
}
gestor_assert_escopo_cliente($scopeId, 'clientes.php');

require_once __DIR__ . '/../includes/chamado_geo.php';

$loadPontosMapGoogle      = crm_google_maps_has_api_key();
$loadLeaflet              = !$loadPontosMapGoogle;
$loadLeafletMarkerCluster = $loadLeaflet;
$loadPontosIluminacaoPageLoader = true;

$topTitle    = 'Pontos de iluminação';
$topSubtitle = 'Postes cadastrados e situação por chamados.';
$topSearch   = 'Buscar ponto...';
$clienteNovoPonto = $clienteIdUrl > 0 ? $clienteIdUrl : $scopeId;
$topAction   = ['label' => 'Novo poste', 'href' => 'ponto_iluminacao_novo.php?cliente_id=' . (int) $clienteNovoPonto, 'icon' => '+'];
$topActions  = [
    ['label' => 'Importar parque', 'href' => 'pontos_iluminacao_importar.php?cliente_id=' . (int) $clienteNovoPonto, 'icon' => '⇪'],
];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<?php
$pontosPainelEscopoId       = $scopeId;
$pontosPainelFormAction     = 'pontos_iluminacao.php';
$pontosPainelHiddenQuery    = ['cliente_id' => (string) (int) $scopeId];
$pontosPainelHrefNovoPoste  = 'ponto_iluminacao_novo.php?cliente_id=' . (int) $clienteNovoPonto;
$pontosPainelMostrarRotas   = true;
$pontosPainelHrefRotas      = 'pontos_iluminacao_rotas.php?cliente_id=' . (int) $scopeId;
require __DIR__ . '/../includes/pontos_iluminacao_listagem_painel.php';
?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
