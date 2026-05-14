<?php
/**
 * Export Boletim BM (portal cliente) — XLSX no layout assets/templates/bm_boletim_padrao.xlsx.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/medicao_helpers.php';

$me = require_auth('cliente');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_cliente('medicao');
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

$cid = (int) ($me['cliente_id'] ?? 0);
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

$dataDe  = $mesRaw . '-01';
$dataAte = date('Y-m-t', strtotime($dataDe));
$rel     = repo_medicao_chamados_relatorio($clienteId, $dataDe, $dataAte);

audit_log_registar('medicao.exportar_boletim_bm', 'medicao', null, $clienteId > 0 ? $clienteId : null, [
    'ref_ym' => $mesRaw,
    'portal' => 1,
]);

require_once __DIR__ . '/../includes/medicao_export_bm_boletim_xlsx.php';
medicao_export_bm_boletim_xlsx_send($clienteId, $mesRaw, $clienteMatriz, $rel['totais']);
