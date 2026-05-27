<?php
/**
 * Export Boletim BM v2 — XLSX físico + valores (período personalizado no mês).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/medicao_helpers.php';

$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('medicao');
require_once __DIR__ . '/../includes/repository.php';
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
    $clienteIdRaw = $escopoEmpresa;
} else {
    $clienteIdRaw = max(0, (int) ($_GET['cliente_id'] ?? 0));
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

$clienteMatriz = repo_cliente($clienteId);
if (!$clienteMatriz) {
    flash_set('err', 'Empresa não encontrada.');
    header('Location: medicao.php');
    exit;
}

$fazerDownload    = (($_GET['export'] ?? '') === '1');
$periodoParamDe   = trim((string) ($_GET['periodo_de'] ?? ''));
$periodoParamAte  = trim((string) ($_GET['periodo_ate'] ?? ''));

if (!$fazerDownload) {
    header('Location: medicao.php');
    exit;
}

$resolved = medicao_resolve_periodo_filtro($mesRaw, $periodoParamDe, $periodoParamAte);
if (!$resolved['ok']) {
    flash_set('err', $resolved['err']);
    header('Location: medicao.php');
    exit;
}
$periodoDe  = $resolved['de'];
$periodoAte = $resolved['ate'];

audit_log_registar('medicao.exportar_boletim_bm', 'medicao', null, $clienteId > 0 ? $clienteId : null, [
    'ref_ym'        => $mesRaw,
    'periodo_de'    => $periodoDe,
    'periodo_ate'   => $periodoAte,
    'exportadora'   => 'v2_consolidado',
]);

require_once __DIR__ . '/../includes/medicao_export_bm_boletim_xlsx.php';
try {
    medicao_export_bm_boletim_v2_xlsx_send($clienteId, $mesRaw, $clienteMatriz, $periodoDe, $periodoAte);
} catch (Throwable $e) {
    if (function_exists('app_debug_mode') && app_debug_mode()) {
        error_log('medicao_export_boletim_bm: ' . $e->getMessage());
    }
    $msg = 'Falha ao gerar o boletim BM. Tente novamente.';
    if (function_exists('app_debug_mode') && app_debug_mode()) {
        $msg .= ' ' . $e->getMessage();
    }
    flash_set('err', $msg);
    header('Location: medicao.php');
    exit;
}
