<?php
declare(strict_types=1);

/**
 * Cache em disco e geração por escopo — API pontos_mapa (viewport).
 */

const PONTOS_MAPA_CACHE_TTL_SEC = 300;
const PONTOS_MAPA_CACHE_GEN_DIR = 'gen';
const PONTOS_MAPA_CACHE_DATA_DIR = 'data';

function pontos_mapa_cache_root_dir(): string
{
    $dir = dirname(__DIR__) . '/storage/cache/pontos_mapa';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    foreach ([PONTOS_MAPA_CACHE_GEN_DIR, PONTOS_MAPA_CACHE_DATA_DIR] as $sub) {
        $path = $dir . '/' . $sub;
        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }
    }

    return $dir;
}

function pontos_mapa_cache_gen_path(int $escopoId): string
{
    return pontos_mapa_cache_root_dir() . '/' . PONTOS_MAPA_CACHE_GEN_DIR . '/' . $escopoId . '.txt';
}

function pontos_mapa_cache_generation(int $escopoId): int
{
    if ($escopoId <= 0) {
        return 1;
    }
    $path = pontos_mapa_cache_gen_path($escopoId);
    if (!is_file($path)) {
        return 1;
    }
    $n = (int) trim((string) file_get_contents($path));

    return $n > 0 ? $n : 1;
}

function pontos_mapa_cache_bump_generation(int $escopoId): void
{
    if ($escopoId <= 0) {
        return;
    }
    $path = pontos_mapa_cache_gen_path($escopoId);
    $next = pontos_mapa_cache_generation($escopoId) + 1;
    file_put_contents($path, (string) $next, LOCK_EX);
}

function pontos_mapa_cache_invalidate_cliente(int $clienteId): void
{
    if ($clienteId <= 0) {
        return;
    }
    if (!function_exists('repo_cliente_matriz_raiz_id')) {
        require_once __DIR__ . '/repository.php';
    }
    $raiz = repo_cliente_matriz_raiz_id($clienteId);
    if ($raiz > 0) {
        pontos_mapa_cache_bump_generation($raiz);
    }
}

/**
 * Arredonda bounds como o JavaScript (pontos-iluminacao-map-viewport.js).
 *
 * @return array{0:float,1:float,2:float,3:float}
 */
function pontos_mapa_cache_round_bounds(float $swLat, float $swLng, float $neLat, float $neLng, int $zoom): array
{
    $z = max(1, min(21, $zoom));
    $prec = $z <= 11 ? 2 : ($z <= 14 ? 3 : 4);
    $f = 10 ** $prec;
    $round = static fn (float $v): float => round($v * $f) / $f;

    return [
        $round(min($swLat, $neLat)),
        $round(min($swLng, $neLng)),
        $round(max($swLat, $neLat)),
        $round(max($swLng, $neLng)),
    ];
}

/**
 * @param array<string, mixed> $filtros
 */
function pontos_mapa_cache_build_key(
    int $escopoId,
    int $gen,
    int $zoom,
    array $filtros,
    bool $pinpoint,
    ?float $swLat = null,
    ?float $swLng = null,
    ?float $neLat = null,
    ?float $neLng = null
): string {
    $parts = ['v2', (string) $escopoId, 'g' . $gen, 'z' . $zoom];
    if ($pinpoint) {
        $parts[] = 'pin';
        $parts[] = sprintf('%.4F', (float) ($filtros['ref_lat'] ?? 0));
        $parts[] = sprintf('%.4F', (float) ($filtros['ref_lng'] ?? 0));
        $parts[] = (string) (int) ($filtros['ref_raio_m'] ?? 10);
    } elseif ($swLat !== null && $swLng !== null && $neLat !== null && $neLng !== null) {
        [$rSwLat, $rSwLng, $rNeLat, $rNeLng] = pontos_mapa_cache_round_bounds($swLat, $swLng, $neLat, $neLng, $zoom);
        $parts[] = 'bb';
        $parts[] = sprintf('%.6F', $rSwLat);
        $parts[] = sprintf('%.6F', $rSwLng);
        $parts[] = sprintf('%.6F', $rNeLat);
        $parts[] = sprintf('%.6F', $rNeLng);
    }
    $filterPayload = [
        'status' => (string) ($filtros['status'] ?? ''),
        'bairro' => (string) ($filtros['bairro'] ?? ''),
        'busca' => (string) ($filtros['busca'] ?? ''),
        'somente_chamados_abertos' => !empty($filtros['somente_chamados_abertos']),
        'force_points' => !empty($filtros['force_points']),
    ];
    $parts[] = md5(json_encode($filterPayload, JSON_UNESCAPED_UNICODE));

    return md5(implode('|', $parts));
}

function pontos_mapa_cache_file_path(string $cacheKey): string
{
    return pontos_mapa_cache_root_dir() . '/' . PONTOS_MAPA_CACHE_DATA_DIR . '/' . $cacheKey . '.json';
}

/**
 * @return array{payload:array<string,mixed>,etag:string,ts:int}|null
 */
function pontos_mapa_cache_read(string $cacheKey): ?array
{
    $path = pontos_mapa_cache_file_path($cacheKey);
    if (!is_file($path)) {
        return null;
    }
    $age = time() - (int) filemtime($path);
    if ($age >= PONTOS_MAPA_CACHE_TTL_SEC) {
        @unlink($path);

        return null;
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['payload'], $decoded['etag'])) {
        return null;
    }
    $payload = $decoded['payload'];
    if (!is_array($payload)) {
        return null;
    }

    return [
        'payload' => $payload,
        'etag' => (string) $decoded['etag'],
        'ts' => (int) ($decoded['ts'] ?? filemtime($path)),
    ];
}

/**
 * @param array<string, mixed> $payload
 */
function pontos_mapa_cache_write(string $cacheKey, array $payload, string $etag): void
{
    $path = pontos_mapa_cache_file_path($cacheKey);
    $envelope = json_encode([
        'payload' => $payload,
        'etag' => $etag,
        'ts' => time(),
    ], JSON_UNESCAPED_UNICODE);
    if ($envelope === false) {
        return;
    }
    @file_put_contents($path, $envelope, LOCK_EX);
}

/**
 * @param array<string, mixed> $payload
 */
function pontos_mapa_cache_build_payload(
    array $resultado,
    int $zoom,
    int $dbMs,
    int $cacheGen,
    bool $fromDisk
): array {
    return [
        'ok' => true,
        'mode' => $resultado['mode'],
        'items' => $resultado['items'],
        'count' => count($resultado['items']),
        'total_in_bounds' => $resultado['total_in_bounds'],
        'total_estimated' => $resultado['total_estimated'],
        'limited' => $resultado['limited'],
        'message' => $resultado['message'],
        'zoom' => $zoom,
        'debug_ms' => $dbMs,
        'cache_gen' => $cacheGen,
        'cached' => $fromDisk,
    ];
}

/**
 * @param array<string, mixed> $payload
 */
function pontos_mapa_cache_send_json(array $payload, ?string $knownEtag = null): void
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'err' => 'Erro ao serializar resposta.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $etag = $knownEtag !== null && $knownEtag !== ''
        ? $knownEtag
        : '"' . md5($body) . '"';
    if ($etag[0] !== '"') {
        $etag = '"' . $etag . '"';
    }

    header('Content-Type: application/json; charset=UTF-8');
    header('ETag: ' . $etag);
    header('Cache-Control: private, max-age=60');

    $ifNone = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($ifNone !== '' && ($ifNone === $etag || $ifNone === trim($etag, '"'))) {
        http_response_code(304);
        exit;
    }

    $accept = (string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '');
    if (str_contains($accept, 'gzip') && function_exists('gzencode')) {
        $gz = gzencode($body, 6);
        if ($gz !== false) {
            header('Content-Encoding: gzip');
            header('Vary: Accept-Encoding');
            echo $gz;
            exit;
        }
    }

    echo $body;
}
