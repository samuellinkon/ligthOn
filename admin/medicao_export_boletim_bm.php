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

$primeiroMesDia   = $mesRaw . '-01';
$periodoAteMes    = medicao_bm_export_v2_periodo_ate($mesRaw);
$fazerDownload    = (($_GET['export'] ?? '') === '1');
$periodoParam     = trim((string) ($_GET['periodo_de'] ?? ''));

if (!$fazerDownload) {
    header('Location: medicao.php');
    exit;
}

$periodoDe = preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodoParam) ? $periodoParam : $primeiroMesDia;

if ($periodoDe > $periodoAteMes) {
    flash_set(
        'err',
        'A data inicial não pode ser posterior ao fecho do boletim (' . date('d/m/Y', strtotime($periodoAteMes)) . ').'
    );
    header('Location: medicao.php');
    exit;
}

audit_log_registar('medicao.exportar_boletim_bm', 'medicao', null, $clienteId > 0 ? $clienteId : null, [
    'ref_ym'        => $mesRaw,
    'periodo_de'    => $periodoDe,
    'periodo_ate'   => $periodoAteMes,
    'exportadora'   => 'v2_consolidado',
]);

require_once __DIR__ . '/../includes/medicao_export_bm_boletim_xlsx.php';
medicao_export_bm_boletim_v2_xlsx_send($clienteId, $mesRaw, $clienteMatriz, $periodoDe, $periodoAteMes);
