<?php
/**
 * Export Boletim BM v2 — portal cliente (mesmo relatório físico + valores).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/medicao_helpers.php';

$me = require_auth('cliente');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_cliente('medicao');
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

$cid       = (int) ($me['cliente_id'] ?? 0);
$clienteId = $cid > 0 ? repo_cliente_matriz_raiz_id($cid) : 0;

if ($clienteId <= 0) {
    flash_set('err', 'Empresa não vinculada ao seu acesso.');
    header('Location: medicao.php');
    exit;
}

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
    'ref_ym'       => $mesRaw,
    'portal'       => 1,
    'periodo_de'   => $periodoDe,
    'periodo_ate'  => $periodoAte,
    'exportadora'  => 'v2_consolidado',
]);

require_once __DIR__ . '/../includes/medicao_export_bm_boletim_xlsx.php';
medicao_export_bm_boletim_v2_xlsx_send($clienteId, $mesRaw, $clienteMatriz, $periodoDe, $periodoAte);
