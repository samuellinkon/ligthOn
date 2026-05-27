<?php
/**
 * API JSON — custos adicionais da medição (portal cliente: aprovar/rejeitar).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/repository.php';
require_once __DIR__ . '/../includes/audit_log.php';

header('Content-Type: application/json; charset=UTF-8');

$CLIENTE = require_auth('cliente');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_cliente('medicao');

$uid = (int) ($CLIENTE['id'] ?? 0);
$cid = (int) ($CLIENTE['cliente_id'] ?? 0);
$matrizId = $cid > 0 ? repo_cliente_matriz_raiz_id($cid) : 0;

if ($uid <= 0 || $matrizId <= 0 || !db_ok()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'err' => 'Sessão ou banco indisponível.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!repo_medicao_custos_table_exists()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'err' => 'Funcionalidade indisponível.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'err' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = strtolower(trim((string) ($_POST['action'] ?? '')));
$id     = (int) ($_POST['id'] ?? 0);
$refYm  = trim((string) ($_POST['ref_ym'] ?? ''));

if ($action === 'aprovar') {
    if (!medicao_custo_pode_aprovar($CLIENTE)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'err' => 'Sem permissão para aprovar.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $res = repo_medicao_custo_cliente_aprovar($id, $matrizId, $uid);
    if ($res['ok']) {
        audit_log_registar('medicao.custo_aprovar', 'medicao_custo', $id, $matrizId, [
            'ref_ym' => $refYm,
            'portal' => 1,
        ]);
    }
    echo json_encode([
        'ok'    => $res['ok'],
        'err'   => $res['err'],
        'custo' => isset($res['custo']) ? medicao_custo_para_json($res['custo']) : null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'rejeitar') {
    if (!medicao_custo_pode_aprovar($CLIENTE)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'err' => 'Sem permissão para rejeitar.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $motivo = trim((string) ($_POST['motivo'] ?? ''));
    $res = repo_medicao_custo_cliente_rejeitar($id, $matrizId, $uid, $motivo);
    if ($res['ok']) {
        audit_log_registar('medicao.custo_rejeitar', 'medicao_custo', $id, $matrizId, [
            'ref_ym' => $refYm,
            'portal' => 1,
        ]);
    }
    echo json_encode([
        'ok'    => $res['ok'],
        'err'   => $res['err'],
        'custo' => isset($res['custo']) ? medicao_custo_para_json($res['custo']) : null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'err' => 'Ação inválida.'], JSON_UNESCAPED_UNICODE);
