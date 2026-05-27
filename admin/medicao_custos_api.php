<?php
/**
 * API JSON — custos adicionais da medição (gestão).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/repository.php';
require_once __DIR__ . '/../includes/audit_log.php';

header('Content-Type: application/json; charset=UTF-8');

$me = require_auth_gestao('../');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('medicao');

$uid = (int) ($me['id'] ?? 0);
if ($uid <= 0 || !db_ok()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'err' => 'Sessão ou banco indisponível.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!repo_medicao_custos_table_exists()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'err' => 'Execute a migration 054 (medicao_custos).'], JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $action = strtolower(trim((string) ($_GET['action'] ?? '')));
    $matrizId = (int) ($_GET['cliente_id'] ?? 0);
    if ($matrizId <= 0) {
        $escopo = gestao_scope_cliente_id($me);
        if ($escopo !== null) {
            $matrizId = repo_cliente_matriz_raiz_id($escopo);
        } else {
            $matrizId = repo_cliente_matriz_raiz_id((int) (repo_catalogo_cliente_id_padrao_admin() ?? 0));
        }
    } else {
        $matrizId = repo_cliente_matriz_raiz_id($matrizId);
    }
    if ($matrizId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'err' => 'Empresa inválida.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    gestor_assert_escopo_cliente($matrizId, 'medicao_custos_api.php');

    if ($action === 'buscar_itens') {
        if (!medicao_custo_pode_criar($me) && !medicao_custo_pode_editar($me)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'err' => 'Sem permissão.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $q = trim((string) ($_GET['q'] ?? ''));
        echo json_encode([
            'ok'    => true,
            'itens' => repo_medicao_custo_buscar_itens_catalogo($matrizId, $q),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'err' => 'Ação inválida.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'err' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = strtolower(trim((string) ($_POST['action'] ?? '')));
$matrizId = (int) ($_POST['cliente_id'] ?? 0);
if ($matrizId <= 0) {
    $escopo = gestao_scope_cliente_id($me);
    if ($escopo !== null) {
        $matrizId = repo_cliente_matriz_raiz_id($escopo);
    } else {
        $matrizId = repo_cliente_matriz_raiz_id((int) (repo_catalogo_cliente_id_padrao_admin() ?? 0));
    }
}
if ($matrizId > 0) {
    $matrizId = repo_cliente_matriz_raiz_id($matrizId);
}
if ($matrizId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'err' => 'Informe a empresa.'], JSON_UNESCAPED_UNICODE);
    exit;
}
gestor_assert_escopo_cliente($matrizId, 'medicao_custos_api.php');

$refYm  = trim((string) ($_POST['ref_ym'] ?? ''));
$perfil = medicao_custo_user_perfil($me);

if ($action === 'criar') {
    if (!medicao_custo_pode_criar($me)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'err' => 'Sem permissão para criar custos adicionais.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $res = repo_medicao_custo_criar($matrizId, array_merge($_POST, ['ref_ym' => $refYm]), $uid);
    if ($res['ok']) {
        audit_log_registar('medicao.custo_criar', 'medicao_custo', (int) ($res['custo']['id'] ?? 0), $matrizId, [
            'ref_ym' => $refYm,
            'valor'  => (float) ($res['custo']['valor_total'] ?? 0),
        ]);
    }
    echo json_encode([
        'ok'    => $res['ok'],
        'err'   => $res['err'],
        'custo' => isset($res['custo']) ? medicao_custo_para_json($res['custo']) : null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'atualizar') {
    if (!medicao_custo_pode_editar($me)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'err' => 'Sem permissão para editar.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $id = (int) ($_POST['id'] ?? 0);
    $res = repo_medicao_custo_atualizar($id, $matrizId, $_POST, $uid);
    if ($res['ok']) {
        audit_log_registar('medicao.custo_atualizar', 'medicao_custo', $id, $matrizId, ['ref_ym' => $refYm]);
    }
    echo json_encode([
        'ok'    => $res['ok'],
        'err'   => $res['err'],
        'custo' => isset($res['custo']) ? medicao_custo_para_json($res['custo']) : null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'excluir') {
    if (!medicao_custo_pode_editar($me)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'err' => 'Sem permissão para excluir.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $id = (int) ($_POST['id'] ?? 0);
    $res = repo_medicao_custo_excluir($id, $matrizId);
    if ($res['ok']) {
        audit_log_registar('medicao.custo_excluir', 'medicao_custo', $id, $matrizId, ['ref_ym' => $refYm]);
    }
    echo json_encode(['ok' => $res['ok'], 'err' => $res['err']], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'aprovar') {
    if (!medicao_custo_pode_aprovar($me)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'err' => 'Sem permissão para aprovar.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $id = (int) ($_POST['id'] ?? 0);
    $res = repo_medicao_custo_cliente_aprovar($id, $matrizId, $uid);
    if ($res['ok']) {
        audit_log_registar('medicao.custo_aprovar', 'medicao_custo', $id, $matrizId, [
            'ref_ym' => $refYm,
            'painel' => $perfil,
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
    if (!medicao_custo_pode_aprovar($me)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'err' => 'Sem permissão para rejeitar.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $id = (int) ($_POST['id'] ?? 0);
    $motivo = trim((string) ($_POST['motivo'] ?? ''));
    $res = repo_medicao_custo_cliente_rejeitar($id, $matrizId, $uid, $motivo);
    if ($res['ok']) {
        audit_log_registar('medicao.custo_rejeitar', 'medicao_custo', $id, $matrizId, [
            'ref_ym' => $refYm,
            'painel' => $perfil,
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
