<?php
declare(strict_types=1);

/**
 * Handler JSON compartilhado — geocode via Nominatim (servidor).
 * Incluir após auth na pasta admin/ ou cliente/.
 */

require_once __DIR__ . '/chamado_geo.php';
require_once __DIR__ . '/nominatim_client.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'err' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = strtolower(trim((string) ($_GET['action'] ?? 'resolve')));

if ($action === 'reverse') {
    $lat = isset($_GET['lat']) ? (float) $_GET['lat'] : NAN;
    $lon = isset($_GET['lon']) ? (float) $_GET['lon'] : NAN;
    if (!is_finite($lat) || !is_finite($lon)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'err' => 'Coordenadas inválidas.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $r = nominatim_reverse($lat, $lon);
    if (($r['err'] ?? '') === 'rate_limited') {
        http_response_code(429);
        echo json_encode(['ok' => false, 'err' => 'rate_limited'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!$r['ok'] || !is_array($r['data'])) {
        http_response_code(502);
        echo json_encode(['ok' => false, 'err' => 'reverse_failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => true, 'address' => $r['data']['address'] ?? []], JSON_UNESCAPED_UNICODE);
    exit;
}

$street = trim((string) ($_GET['street'] ?? ''));
$city = trim((string) ($_GET['city'] ?? ''));
$uf = strtoupper(preg_replace('/\./', '', trim((string) ($_GET['state'] ?? $_GET['uf'] ?? ''))));
$postal = trim((string) ($_GET['postalcode'] ?? ''));
$q = trim((string) ($_GET['q'] ?? ''));
$bairro = trim((string) ($_GET['bairro'] ?? ''));
$logradouro = trim((string) ($_GET['logradouro'] ?? ''));
$numero = trim((string) ($_GET['numero'] ?? ''));

if ($street === '' && $q === '' && $logradouro === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'err' => 'Parâmetros insuficientes.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$resolved = chamado_geocode_resolve_os($street, $city, $uf, $postal, $q, $bairro, $logradouro, $numero);

if ($resolved['rate_limited']) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'err' => 'rate_limited'], JSON_UNESCAPED_UNICODE);
    exit;
}

$hit = $resolved['hit'];
if ($hit === null) {
    echo json_encode(['ok' => false, 'err' => 'not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'hit' => [
        'lat' => (float) ($hit['lat'] ?? 0),
        'lon' => (float) ($hit['lon'] ?? 0),
        'display_name' => (string) ($hit['display_name'] ?? ''),
    ],
], JSON_UNESCAPED_UNICODE);
