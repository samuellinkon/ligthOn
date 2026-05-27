<?php
/**
 * API JSON — notificações do operador logado (mesmo contrato que admin/notificacoes_api.php).
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/repository.php';

header('Content-Type: application/json; charset=UTF-8');

$user = require_auth('operador', '../');
$uid  = (int) ($user['id'] ?? 0);

if ($uid <= 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'err' => 'Sessão inválida.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!db_ok() || !repo_notificacoes_table_exists()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'err' => 'Notificações indisponíveis.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $action = strtolower(trim((string) ($_GET['action'] ?? 'list')));
    if ($action === 'count') {
        echo json_encode([
            'ok'     => true,
            'unread' => repo_notificacoes_count_unread($uid),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'list') {
        $limit = (int) ($_GET['limit'] ?? 40);
        echo json_encode([
            'ok'    => true,
            'items' => repo_notificacoes_list_for_user($uid, $limit),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'err' => 'Ação inválida.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST') {
    $action = strtolower(trim((string) ($_POST['action'] ?? '')));
    if ($action === 'read_one') {
        $nid = (int) ($_POST['id'] ?? 0);
        if ($nid <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'err' => 'ID inválido.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $ok = repo_notificacao_marcar_lida($nid, $uid);
        echo json_encode([
            'ok'     => $ok,
            'unread' => repo_notificacoes_count_unread($uid),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'read_all') {
        $n = repo_notificacoes_marcar_todas_lidas($uid);
        echo json_encode([
            'ok'      => true,
            'updated' => $n,
            'unread'  => 0,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'err' => 'Ação inválida.'], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'err' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
