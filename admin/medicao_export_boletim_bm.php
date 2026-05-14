<?php
/**
 * Export Boletim BM (XLSX no layout assets/templates/bm_boletim_padrao.xlsx).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/medicao_helpers.php';

$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('medicao');
require_once __DIR__ . '/../includes/audit_log.php';

if (!db_ok()) {
    flash_set('err', 'Banco indisponível.');
    header('Location: medicao.php');
    exit;
}

$mesRaw = trim((string) ($_GET['mes'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $mesRaw)) {
    flash_set('err', 'Informe um mês válido (AAAA-MM).');
    header('Location: medicao.php');
    exit;
}

$escopoEmpresa = gestao_scope_cliente_id($me);
if ($escopoEmpresa !== null) {
    $clienteId = $escopoEmpresa;
} else {
    $clienteId = max(0, (int) ($_GET['cliente_id'] ?? 0));
    if ($clienteId <= 0) {
        $clienteId = (int) (repo_catalogo_cliente_id_padrao_admin() ?? 0);
    }
    if ($clienteId <= 0) {
        $empresasFallback = repo_clientes_empresas();
        $clienteId = (int) (($empresasFallback[0]['id'] ?? 0));
    }
}

if ($clienteId <= 0) {
    flash_set('err', 'Empresa inválida.');
    header('Location: medicao.php');
    exit;
}

gestor_assert_escopo_cliente($clienteId, 'medicao.php');

$clienteMatriz = repo_cliente($clienteId);
if (!$clienteMatriz) {
    flash_set('err', 'Empresa não encontrada.');
    header('Location: medicao.php');
    exit;
}

$dataDe  = $mesRaw . '-01';
$dataAte = date('Y-m-t', strtotime($dataDe));
$rel     = repo_medicao_chamados_relatorio($clienteId, $dataDe, $dataAte);

audit_log_registar('medicao.exportar_boletim_bm', 'medicao', null, $clienteId > 0 ? $clienteId : null, [
    'ref_ym' => $mesRaw,
]);

require_once __DIR__ . '/../includes/medicao_export_bm_boletim_xlsx.php';
medicao_export_bm_boletim_xlsx_send($clienteId, $mesRaw, $clienteMatriz, $rel['totais']);
