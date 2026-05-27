<?php
declare(strict_types=1);

/**
 * Cliente HTTP Nominatim com cache em disco e limite global (~1 req/s).
 * Uso apenas via geocode_nominatim_api.php (não chamar do browser).
 */

require_once __DIR__ . '/chamado_geo.php';

const NOMINATIM_BASE_URL = 'https://nominatim.openstreetmap.org';
const NOMINATIM_USER_AGENT = 'CrmPrefeituraIluminacao/1.0 (municipal CRM; contact: crm-prefeitura-nominatim@localhost)';
const NOMINATIM_MIN_INTERVAL_SEC = 1.15;
const NOMINATIM_CACHE_TTL_SEC = 86400;

function nominatim_storage_dir(): string
{
    $dir = dirname(__DIR__) . '/storage/cache/nominatim';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    return $dir;
}

function nominatim_rate_limit_wait(): void
{
    $dir = nominatim_storage_dir();
    $lockPath = $dir . '/rate.lock';
    $fp = @fopen($lockPath, 'c+');
    if ($fp === false) {
        return;
    }
    flock($fp, LOCK_EX);
    $lastFile = $dir . '/last_ts.txt';
    $last = 0.0;
    if (is_file($lastFile)) {
        $last = (float) trim((string) file_get_contents($lastFile));
    }
    $now = microtime(true);
    $wait = NOMINATIM_MIN_INTERVAL_SEC - ($now - $last);
    if ($wait > 0) {
        usleep((int) round($wait * 1_000_000));
    }
    file_put_contents($lastFile, (string) microtime(true));
    flock($fp, LOCK_UN);
    fclose($fp);
}

/**
 * @return array{ok:bool,status:int,hits:array<int,array<string,mixed>>,cached:bool,err?:string}
 */
/**
 * @return array<int, array<string, mixed>>|null
 */
function nominatim_read_cache_file(string $cachePath, bool $allowStale = false): ?array
{
    if (!is_file($cachePath)) {
        return null;
    }
    $age = time() - (int) filemtime($cachePath);
    if (!$allowStale && $age >= NOMINATIM_CACHE_TTL_SEC) {
        return null;
    }
    $raw = file_get_contents($cachePath);
    if (!is_string($raw)) {
        return null;
    }
    $hits = json_decode($raw, true);

    return is_array($hits) ? $hits : null;
}

function nominatim_http_get(string $pathQuery): array
{
    $url = NOMINATIM_BASE_URL . $pathQuery;
    $cacheKey = md5($url);
    $cachePath = nominatim_storage_dir() . '/' . $cacheKey . '.json';
    $fresh = nominatim_read_cache_file($cachePath, false);
    if ($fresh !== null) {
        return ['ok' => true, 'status' => 200, 'hits' => $fresh, 'cached' => true];
    }

    nominatim_rate_limit_wait();

    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: " . NOMINATIM_USER_AGENT . "\r\n"
                . "Accept: application/json\r\n"
                . "Accept-Language: pt-BR,pt;q=0.9\r\n",
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', (string) $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }

    if ($status === 429) {
        $stale = nominatim_read_cache_file($cachePath, true);
        if ($stale !== null) {
            return ['ok' => true, 'status' => 200, 'hits' => $stale, 'cached' => true, 'stale' => true];
        }

        return ['ok' => false, 'status' => 429, 'hits' => [], 'cached' => false, 'err' => 'rate_limited'];
    }

    if ($body === false || $status < 200 || $status >= 300) {
        return ['ok' => false, 'status' => $status > 0 ? $status : 502, 'hits' => [], 'cached' => false];
    }

    $hits = json_decode($body, true);
    if (!is_array($hits)) {
        return ['ok' => false, 'status' => 502, 'hits' => [], 'cached' => false];
    }

    file_put_contents($cachePath, json_encode($hits, JSON_UNESCAPED_UNICODE));

    return ['ok' => true, 'status' => 200, 'hits' => $hits, 'cached' => false];
}

/**
 * @return array{ok:bool,status:int,hits:array<int,array<string,mixed>>,cached:bool,err?:string}
 */
function nominatim_search_structured(string $street, string $city, string $stateUf, string $postalcode = ''): array
{
    $street = trim($street);
    $city = trim($city);
    $stateUf = strtoupper(preg_replace('/\./', '', trim($stateUf)));
    if ($street === '' || $city === '' || $stateUf === '') {
        return ['ok' => true, 'status' => 200, 'hits' => [], 'cached' => false];
    }

    $params = [
        'format' => 'json',
        'limit' => '5',
        'countrycodes' => 'br',
        'street' => $street,
        'city' => $city,
        'state' => chamado_geo_uf_nome($stateUf),
        'country' => 'Brasil',
    ];
    if ($postalcode !== '') {
        $params['postalcode'] = $postalcode;
    }

    return nominatim_http_get('/search?' . http_build_query($params));
}

/**
 * @return array{ok:bool,status:int,hits:array<int,array<string,mixed>>,cached:bool,err?:string}
 */
function nominatim_search_free_text(string $q): array
{
    $q = trim($q);
    if ($q === '') {
        return ['ok' => true, 'status' => 200, 'hits' => [], 'cached' => false];
    }

    $params = [
        'format' => 'json',
        'limit' => '5',
        'countrycodes' => 'br',
        'q' => $q,
    ];

    return nominatim_http_get('/search?' . http_build_query($params));
}

/**
 * @return array{ok:bool,status:int,data:?array<string,mixed>,cached:bool,err?:string}
 */
function nominatim_reverse(float $lat, float $lon): array
{
    $params = [
        'format' => 'json',
        'lat' => (string) $lat,
        'lon' => (string) $lon,
        'zoom' => '18',
        'addressdetails' => '1',
    ];
    $path = '/reverse?' . http_build_query($params);
    $url = NOMINATIM_BASE_URL . $path;
    $cachePath = nominatim_storage_dir() . '/' . md5($url) . '.json';
    if (is_file($cachePath) && (time() - (int) filemtime($cachePath)) < NOMINATIM_CACHE_TTL_SEC) {
        $decoded = json_decode((string) file_get_contents($cachePath), true);
        if (is_array($decoded) && isset($decoded['lat'])) {
            return ['ok' => true, 'status' => 200, 'data' => $decoded, 'cached' => true];
        }
    }

    nominatim_rate_limit_wait();

    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: " . NOMINATIM_USER_AGENT . "\r\n"
                . "Accept: application/json\r\n"
                . "Accept-Language: pt-BR,pt;q=0.9\r\n",
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', (string) $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }
    if ($status === 429) {
        return ['ok' => false, 'status' => 429, 'data' => null, 'cached' => false, 'err' => 'rate_limited'];
    }
    if ($body === false || $status < 200 || $status >= 300) {
        return ['ok' => false, 'status' => $status > 0 ? $status : 502, 'data' => null, 'cached' => false];
    }
    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['lat'])) {
        return ['ok' => false, 'status' => 502, 'data' => null, 'cached' => false];
    }
    file_put_contents($cachePath, json_encode($data, JSON_UNESCAPED_UNICODE));

    return ['ok' => true, 'status' => 200, 'data' => $data, 'cached' => false];
}
