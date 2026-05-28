<?php
/**
 * Medição — listagem de medições mensais (portal cliente).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/medicao_helpers.php';
require_once __DIR__ . '/../includes/chamados_list_urls.php';

$CLIENTE = require_auth('cliente');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_cliente('medicao');
require_once __DIR__ . '/../includes/audit_log.php';

$pageTitle  = 'Medição';
$basePath   = '../';
$activePage = 'medicao';

if (!db_ok()) {
    flash_set('err', 'Banco indisponível.');
    header('Location: index.php');
    exit;
}

$cid = (int) ($CLIENTE['cliente_id'] ?? 0);
$clienteId = $cid > 0 ? repo_cliente_matriz_raiz_id($cid) : 0;

if ($clienteId <= 0) {
    flash_set('err', 'Empresa não vinculada ao seu acesso.');
    header('Location: index.php');
    exit;
}

$clienteMatriz = repo_cliente($clienteId);
if (!$clienteMatriz) {
    flash_set('err', 'Empresa não encontrada.');
    header('Location: index.php');
    exit;
}

audit_log_registar('medicao.acessar_lista', 'medicao', null, $clienteId > 0 ? $clienteId : null, [
    'empresa' => function_exists('mb_substr') ? mb_substr((string) ($clienteMatriz['empresa'] ?? ''), 0, 120, 'UTF-8') : substr((string) ($clienteMatriz['empresa'] ?? ''), 0, 120),
    'portal'  => 1,
]);

$mesesLista = repo_medicao_resumo_mensal_list($clienteId, 60);

$medicaoValidadoCount = repo_medicao_count_validado_escopo($clienteId);

/** Portal: escopo fixo da matriz; importação BM pelo próprio cliente. */
$escopoEmpresa          = $clienteId;
$medicaoMostrarImportar = true;
$medicaoJsClienteId     = 0;

$topTitle    = 'Medição';
$topSubtitle = 'Medições mensais';
$topSearch   = '';
$topAction   = null;
$loadMedicaoMeses = true;

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-cliente.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content medicao-page">
<?php require __DIR__ . '/../includes/partials/medicao_listagem_mensal.php'; ?>
</section>

</main>
</div>

<?php require __DIR__ . '/../includes/partials/medicao_listagem_scripts.php'; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
