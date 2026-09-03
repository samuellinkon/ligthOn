<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';

$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('pontos_iluminacao');

if (!db_ok()) {
    flash_set('err', 'Banco indisponível.');
    header('Location: pontos_iluminacao.php');
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
    flash_set('err', 'Cadastre uma empresa antes de exportar o parque.');
    header('Location: clientes.php');
    exit;
}
gestor_assert_escopo_cliente($scopeId, 'pontos_iluminacao.php');

@ini_set('memory_limit', '512M');
@ini_set('max_execution_time', '180');

$pontos = repo_pontos_iluminacao_list($scopeId, true, '', '');
if ($pontos === []) {
    flash_set('err', 'Nenhum poste cadastrado para exportar.');
    header('Location: pontos_iluminacao.php?cliente_id=' . (int) $scopeId);
    exit;
}

if (function_exists('audit_log_registar')) {
    require_once __DIR__ . '/../includes/audit_log.php';
    audit_log_registar('pontos.exportar_xlsx', 'ponto_iluminacao', null, $scopeId, [
        'total' => count($pontos),
    ]);
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

require_once __DIR__ . '/../includes/pontos_iluminacao_export_xlsx.php';
pontos_iluminacao_export_xlsx_send($pontos, 'parque_iluminacao');
