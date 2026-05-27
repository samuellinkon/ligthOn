<?php
/**
 * Exclusão da importação BM de um mês (planilha, chamados gerados e itens órfãos do catálogo).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/medicao_helpers.php';
require_once __DIR__ . '/../includes/repository.php';
require_once __DIR__ . '/../includes/medicao_bm_excluir.php';

$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('medicao');
require_once __DIR__ . '/../includes/audit_log.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: medicao.php');
    exit;
}

if (!db_ok()) {
    flash_set('err', 'Banco indisponível.');
    header('Location: medicao.php');
    exit;
}

$mesRaw = trim((string) ($_POST['mes'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $mesRaw)) {
    flash_set('err', 'Informe um mês válido (AAAA-MM).');
    header('Location: medicao.php');
    exit;
}

$escopoEmpresa = gestao_scope_cliente_id($me);
if ($escopoEmpresa !== null) {
    $clienteIdRaw = $escopoEmpresa;
} else {
    $clienteIdRaw = max(0, (int) ($_POST['cliente_id'] ?? 0));
    if ($clienteIdRaw <= 0) {
        $clienteIdRaw = (int) (repo_catalogo_cliente_id_padrao_admin() ?? 0);
    }
    if ($clienteIdRaw <= 0) {
        $empresasFallback = repo_clientes_empresas();
        $clienteIdRaw = (int) (($empresasFallback[0]['id'] ?? 0));
    }
}
$clienteId = $clienteIdRaw > 0 ? repo_cliente_matriz_raiz_id($clienteIdRaw) : 0;
if ($clienteId <= 0 && $clienteIdRaw > 0) {
    $clienteId = $clienteIdRaw;
}

if ($clienteId <= 0) {
    flash_set('err', 'Empresa inválida.');
    header('Location: medicao.php');
    exit;
}

gestor_assert_escopo_cliente($clienteId, 'medicao.php');

$excluirItens = !empty($_POST['excluir_itens_catalogo']);

$result = repo_medicao_bm_excluir_mes_completo($clienteId, $mesRaw, $excluirItens);

if ($result['ok']) {
    $partes = [];
    if ($result['n_import_removido'] > 0) {
        $partes[] = 'importação BM removida';
    }
    if ($result['n_chamados'] > 0) {
        $partes[] = $result['n_chamados'] . ' chamado(s) excluído(s)';
    }
    if ($result['n_itens'] > 0) {
        $partes[] = $result['n_itens'] . ' item(ns) do catálogo removido(s)';
    }
    $msg = $partes !== []
        ? 'Medição ' . medicao_mes_label_pt($mesRaw) . ': ' . implode('; ', $partes) . '.'
        : 'Nenhum dado foi removido.';
    flash_set('ok', $msg);
    header('Location: medicao.php');
    exit;
}

flash_set('err', $result['erro'] !== '' ? $result['erro'] : 'Não foi possível excluir o BM deste mês.');
$qs = ['mes' => $mesRaw];
if (!empty($_POST['periodo_de']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_POST['periodo_de'])) {
    $qs['periodo_de'] = (string) $_POST['periodo_de'];
}
if (!empty($_POST['periodo_ate']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_POST['periodo_ate'])) {
    $qs['periodo_ate'] = (string) $_POST['periodo_ate'];
}
header('Location: medicao_ver.php?' . http_build_query($qs));
exit;
