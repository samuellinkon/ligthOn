<?php
/**
 * Catálogo — portal cliente: mesma funcionalidade do gestor (CRUD, importar, aplicado em chamados).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';

$CLIENTE = require_auth('cliente');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_cliente('catalogo');

$pageTitle  = 'Catálogo';
$basePath   = '../';
$activePage = 'catalogo';

if (!db_ok()) {
    flash_set('err', 'Banco indisponível.');
    header('Location: index.php');
    exit;
}

$cid = (int) ($CLIENTE['cliente_id'] ?? 0);
$catalogoPainelClienteId = $cid > 0 ? repo_cliente_catalogo_dono_id($cid) : 0;

if ($catalogoPainelClienteId <= 0) {
    flash_set('err', 'Empresa não vinculada ao seu acesso.');
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string) ($_POST['acao'] ?? '');
    if ($acao === 'item_salvar') {
        $id    = (int) ($_POST['item_id'] ?? 0);
        $id    = $id > 0 ? $id : null;
        $tipo  = (string) ($_POST['tipo'] ?? 'produto');
        $nome  = trim((string) ($_POST['nome'] ?? ''));
        $cod   = trim((string) ($_POST['codigo'] ?? ''));
        $unid  = trim((string) ($_POST['unidade'] ?? 'UN'));
        $val   = (float) str_replace(',', '.', (string) ($_POST['valor_unitario'] ?? '0'));
        $desc  = trim((string) ($_POST['descricao'] ?? ''));
        $ativo = !empty($_POST['ativo']) ? 1 : 0;
        $est   = (float) str_replace(',', '.', (string) ($_POST['estoque_saldo'] ?? '0'));
        $r = repo_cliente_item_salvar(
            $catalogoPainelClienteId,
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
        if ($iid > 0 && repo_cliente_item_excluir($catalogoPainelClienteId, $iid)) {
            flash_set('ok', 'Item excluído.');
        } else {
            flash_set('err', 'Não foi possível excluir (verifique se o item está em uso em algum chamado).');
        }
    }

    header('Location: catalogo.php');
    exit;
}

$topTitle    = 'Catálogo';
$topSubtitle = 'Produtos e serviços';
$topSearch   = '';
$topAction   = null;

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-cliente.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<?php
$catalogoPainelSomenteLeitura = false;
$catalogoPainelFormAction     = 'catalogo.php';
$catalogoPainelHiddenQuery    = [];
$catalogoPainelHrefImportar   = 'catalogo_importar.php';
$catalogoPainelHrefAplicadoChamados = 'catalogo_chamados_materiais.php';
require __DIR__ . '/../includes/catalogo_listagem_painel.php';
?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
