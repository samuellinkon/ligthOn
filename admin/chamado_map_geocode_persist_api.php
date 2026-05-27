<?php
/**
 * API JSON — persiste lat/lng no chamado após geocode do mapa (somente se vazios).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/chamado_geo.php';

header('Content-Type: application/json; charset=UTF-8');

$me = require_auth_gestao('../');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'err' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$chamadoId = (int) ($_POST['chamado_id'] ?? 0);
$lat       = isset($_POST['lat']) ? (float) str_replace(',', '.', (string) $_POST['lat']) : NAN;
$lng       = isset($_POST['lng']) ? (float) str_replace(',', '.', (string) $_POST['lng']) : NAN;

if ($chamadoId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'err' => 'Chamado inválido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ch = repo_chamado($chamadoId);
if (!$ch) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'err' => 'Chamado não encontrado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$cid = (int) ($ch['cliente_id'] ?? 0);
if ($cid > 0 && function_exists('gestor_assert_escopo_cliente')) {
    gestor_assert_escopo_cliente($cid, 'index.php');
}

$r = repo_chamado_persist_geocode_se_vazio($chamadoId, $lat, $lng);
if (!$r['ok']) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'err' => $r['err']], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok'      => true,
    'skipped' => !empty($r['skipped']),
], JSON_UNESCAPED_UNICODE);
