<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';

$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';

if (!defined('CRM_ADMIN_CATALOGO_ENTRY')) {
    define('CRM_ADMIN_CATALOGO_ENTRY', 'cliente_itens.php');
}
$catalogoAdminScript = CRM_ADMIN_CATALOGO_ENTRY;

$pageTitle  = 'Catálogo';
$basePath   = '../';
$activePage = 'catalogo';

if (!db_ok()) {
    flash_set('err', 'Banco indisponível.');
    header('Location: clientes.php');
    exit;
}

$escopoEmpresa = gestao_scope_cliente_id($me);
$empresas = $escopoEmpresa !== null
    ? (($c0 = repo_cliente($escopoEmpresa)) ? [$c0] : [])
    : repo_clientes_empresas();

$reqClienteId = (int) ($_GET['cliente_id'] ?? $_GET['id'] ?? 0);
if ($escopoEmpresa !== null) {
    $clienteId = $escopoEmpresa;
} elseif ($reqClienteId > 0) {
    $clienteId = repo_cliente_catalogo_dono_id($reqClienteId);
} else {
    $clienteId = 0;
    $pid = repo_catalogo_cliente_id_padrao_admin();
    if ($pid !== null && $pid > 0) {
        $clienteId = $pid;
    }
    if ($clienteId <= 0) {
        $clienteId = (int) ($empresas[0]['id'] ?? 0);
    }
    if ($clienteId <= 0) {
        $fallback = repo_catalogo_cliente_id_padrao_admin();
        if ($fallback !== null && $fallback > 0) {
            $clienteId = $fallback;
        }
    }
}

if ($clienteId <= 0) {
    flash_set('err', 'Cadastre uma empresa raiz antes de continuar.');
    header('Location: clientes.php');
    exit;
}

$cliente = repo_cliente($clienteId);
if (!$cliente) {
    flash_set('err', 'Empresa não encontrada.');
    header('Location: clientes.php');
    exit;
}
gestor_assert_escopo_cliente($clienteId, $catalogoAdminScript);

require_modulo_admin('catalogo');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string) ($_POST['acao'] ?? '');
    if ($acao === 'item_salvar') {
        $id    = (int) ($_POST['item_id'] ?? 0);
        $id    = $id > 0 ? $id : null;
        $tipo  = (string) ($_POST['tipo'] ?? 'produto');
        $nome  = trim((string) ($_POST['nome'] ?? ''));
        $cod   = trim((string) ($_POST['codigo'] ?? ''));
        $unid  = trim((string) ($_POST['unidade'] ?? 'UN'));
        $valRaw = trim((string) ($_POST['valor_unitario'] ?? '0'));
        $valRaw = str_replace([' ', '.'], '', $valRaw);
        $valRaw = str_replace(',', '.', $valRaw);
        $val    = round((float) $valRaw, 2, PHP_ROUND_HALF_UP);
        $desc   = trim((string) ($_POST['descricao'] ?? ''));
        $ativo  = 1;
        $est   = (float) str_replace(',', '.', (string) ($_POST['estoque_saldo'] ?? '0'));
        $r = repo_cliente_item_salvar(
            $clienteId,
            $id,
            $tipo,
            $nome,
            $cod !== '' ? $cod : null,
            $unid,
            $val,
            $desc !== '' ? $desc : null,
            $ativo,
            $est
        );
        flash_set($r['ok'] ? 'ok' : 'err', $r['ok'] ? 'Item salvo no catálogo.' : $r['err']);
    } elseif ($acao === 'item_excluir') {
        $iid = (int) ($_POST['item_id'] ?? 0);
        if ($iid > 0 && repo_cliente_item_excluir($clienteId, $iid)) {
            flash_set('ok', 'Item excluído.');
        } else {
            flash_set('err', 'Não foi possível excluir (verifique se o item está em uso em algum chamado).');
        }
    }

    header('Location: ' . $catalogoAdminScript . '?cliente_id=' . $clienteId);
    exit;
}

$topTitle    = 'Catálogo';
$topSubtitle = 'Produtos e serviços';
$topSearch   = '';
$topAction   = ['label' => 'Voltar ao cliente', 'href' => 'cliente_detalhe.php?id=' . $clienteId, 'icon' => '←'];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<?php
$catalogoPainelClienteId     = $clienteId;
$catalogoPainelSomenteLeitura = false;
$catalogoPainelFormAction    = $catalogoAdminScript;
$catalogoPainelHiddenQuery   = ['cliente_id' => (string) $clienteId];
require __DIR__ . '/../includes/catalogo_listagem_painel.php';
?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
