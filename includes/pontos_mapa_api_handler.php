<?php
declare(strict_types=1);

/**
 * Handler JSON — pontos de iluminação por viewport (bounding box) ou proximidade (pinpoint).
 */

require_once __DIR__ . '/pontos_mapa_acesso.php';
require_once __DIR__ . '/pontos_mapa_cache.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'err' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'err' => 'Não autenticado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$clienteIdParam = (int) ($_GET['cliente_id'] ?? $_GET['escopo_id'] ?? 0);
$escopoRes = pontos_mapa_resolver_escopo($user, $clienteIdParam);
if (!$escopoRes['ok']) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'err' => $escopoRes['err']], JSON_UNESCAPED_UNICODE);
    exit;
}

$escopoId = (int) $escopoRes['escopo_id'];
if (!pontos_mapa_usuario_pode_escopo($user, $escopoId)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'err' => 'Sem permissão para este escopo.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$filtros = pontos_mapa_parse_filtros_request();
$zoom = (int) ($_GET['zoom'] ?? 12);
$zoom = max(1, min(21, $zoom));
$cacheGen = pontos_mapa_cache_generation($escopoId);
$pinpoint = pontos_mapa_filtro_pinpoint_ativo($filtros);

$swLat = NAN;
$swLng = NAN;
$neLat = NAN;
$neLng = NAN;

if (!$pinpoint) {
    $swLat = isset($_GET['sw_lat']) ? (float) $_GET['sw_lat'] : NAN;
    $swLng = isset($_GET['sw_lng']) ? (float) $_GET['sw_lng'] : NAN;
    $neLat = isset($_GET['ne_lat']) ? (float) $_GET['ne_lat'] : NAN;
    $neLng = isset($_GET['ne_lng']) ? (float) $_GET['ne_lng'] : NAN;

    if (!is_finite($swLat) || !is_finite($swLng) || !is_finite($neLat) || !is_finite($neLng)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'err' => 'Coordenadas do bounding box inválidas.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (abs($swLat) > 90 || abs($neLat) > 90 || abs($swLng) > 180 || abs($neLng) > 180) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'err' => 'Coordenadas fora do intervalo válido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$cacheKey = pontos_mapa_cache_build_key(
    $escopoId,
    $cacheGen,
    $zoom,
    $filtros,
    $pinpoint,
    $pinpoint ? null : $swLat,
    $pinpoint ? null : $swLng,
    $pinpoint ? null : $neLat,
    $pinpoint ? null : $neLng
);

$cached = pontos_mapa_cache_read($cacheKey);
if ($cached !== null) {
    pontos_mapa_cache_send_json($cached['payload'], $cached['etag']);
    exit;
}

$t0 = microtime(true);

if ($pinpoint) {
    $resultado = repo_pontos_iluminacao_mapa_proximo(
        $escopoId,
        true,
        (float) $filtros['ref_lat'],
        (float) $filtros['ref_lng'],
        (int) ($filtros['ref_raio_m'] ?? 10),
        $filtros
    );
} else {
    $resultado = repo_pontos_iluminacao_mapa_bounds(
        $escopoId,
        true,
        $swLat,
        $swLng,
        $neLat,
        $neLng,
        $zoom,
        $filtros
    );
}

$ms = (int) round((microtime(true) - $t0) * 1000);
$payload = pontos_mapa_cache_build_payload($resultado, $zoom, $ms, $cacheGen, false);
$body = json_encode($payload, JSON_UNESCAPED_UNICODE);
$etag = $body !== false ? '"' . md5($body) . '"' : '""';
if ($body !== false) {
    pontos_mapa_cache_write($cacheKey, $payload, $etag);
}

pontos_mapa_cache_send_json($payload, $etag);
