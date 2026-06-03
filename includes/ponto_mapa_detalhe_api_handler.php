<?php
declare(strict_types=1);

/**
 * Handler JSON — detalhe completo de poste para popup do mapa.
 */

require_once __DIR__ . '/pontos_mapa_acesso.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'err' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'err' => 'ID do poste inválido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'err' => 'Não autenticado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!ponto_mapa_detalhe_usuario_pode_acessar($id, $user)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'err' => 'Sem permissão para este poste.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ponto = repo_ponto_iluminacao_mapa_detalhe($id);
if (!$ponto) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'err' => 'Poste não encontrado ou sem coordenadas.'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true, 'ponto' => $ponto], JSON_UNESCAPED_UNICODE);
