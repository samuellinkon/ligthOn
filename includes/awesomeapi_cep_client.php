<?php
declare(strict_types=1);

/**
 * Geocódigo por CEP via AwesomeAPI (coordenadas do logradouro nos Correios).
 * Útil quando Nominatim devolve o trecho errado da mesma rua.
 */

const AWESOMEAPI_CEP_BASE = 'https://cep.awesomeapi.com.br/json/';
const AWESOMEAPI_CEP_CACHE_TTL_SEC = 86400 * 30;

function awesomeapi_cep_storage_dir(): string
{
    $dir = dirname(__DIR__) . '/storage/cache/awesomeapi_cep';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    return $dir;
}

/**
 * @return array{
 *   cep:string,address:string,district:string,city:string,state:string,
 *   lat:float,lng:float,address_type?:string
 * }|null
 */
function awesomeapi_cep_lookup(string $cep): ?array
{
    $digits = preg_replace('/\D/', '', $cep);
    if (strlen($digits) !== 8) {
        return null;
    }

    $cachePath = awesomeapi_cep_storage_dir() . '/' . $digits . '.json';
    if (is_file($cachePath) && (time() - (int) filemtime($cachePath)) < AWESOMEAPI_CEP_CACHE_TTL_SEC) {
        $cached = json_decode((string) file_get_contents($cachePath), true);
        if (is_array($cached) && isset($cached['lat'], $cached['lng'])) {
            return $cached;
        }
    }

    $url = AWESOMEAPI_CEP_BASE . $digits;
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 10,
            'header'  => "Accept: application/json\r\n"
                . "User-Agent: CrmPrefeituraIluminacao/1.0\r\n",
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || isset($data['status']) || isset($data['code'])) {
        return null;
    }

    $lat = isset($data['lat']) ? (float) str_replace(',', '.', (string) $data['lat']) : NAN;
    $lng = isset($data['lng']) ? (float) str_replace(',', '.', (string) $data['lng']) : NAN;
    if (!is_finite($lat) || !is_finite($lng) || ($lat == 0.0 && $lng == 0.0)) {
        return null;
    }

    $out = [
        'cep'          => $digits,
        'address'      => trim((string) ($data['address'] ?? '')),
        'district'     => trim((string) ($data['district'] ?? '')),
        'city'         => trim((string) ($data['city'] ?? '')),
        'state'        => strtoupper(trim((string) ($data['state'] ?? ''))),
        'lat'          => $lat,
        'lng'          => $lng,
        'address_type' => trim((string) ($data['address_type'] ?? '')),
    ];
    @file_put_contents($cachePath, json_encode($out, JSON_UNESCAPED_UNICODE));

    return $out;
}

/**
 * Converte lookup AwesomeAPI em hit no formato Nominatim, se bater com o endereço pedido.
 *
 * @return array<string,mixed>|null
 */
function awesomeapi_cep_to_geocode_hit(
    array $cepData,
    string $cidade,
    string $uf,
    string $bairro = '',
    string $logradouro = '',
    string $numero = ''
): ?array {
    require_once __DIR__ . '/chamado_geo.php';

    $cidade = trim($cidade);
    $uf     = strtoupper(preg_replace('/\./', '', trim($uf)));
    $bairro = trim($bairro);
    $logradouro = trim($logradouro);

    if ($uf !== '' && ($cepData['state'] ?? '') !== '' && strtoupper((string) $cepData['state']) !== $uf) {
        return null;
    }
    if ($cidade !== '' && ($cepData['city'] ?? '') !== ''
        && !chamado_geo_texto_contem((string) $cepData['city'], $cidade)
        && !chamado_geo_texto_contem($cidade, (string) $cepData['city'])) {
        return null;
    }
    if ($bairro !== '' && ($cepData['district'] ?? '') !== ''
        && !chamado_geo_texto_contem((string) $cepData['district'], $bairro)
        && !chamado_geo_texto_contem($bairro, (string) $cepData['district'])) {
        return null;
    }
    if ($logradouro !== '') {
        $addr = (string) ($cepData['address'] ?? '');
        if ($addr !== '' && !chamado_geocode_hit_matches_logradouro(
            ['display_name' => $addr, 'address' => ['road' => $addr]],
            $logradouro
        )) {
            return null;
        }
    }

    $cepFmt = substr((string) $cepData['cep'], 0, 5) . '-' . substr((string) $cepData['cep'], 5);
    $parts = [];
    if (($cepData['address'] ?? '') !== '') {
        $head = (string) $cepData['address'];
        if (chamado_geo_numero_valido($numero)) {
            $head .= ', ' . trim($numero);
        }
        $parts[] = $head;
    }
    if (($cepData['district'] ?? '') !== '') {
        $parts[] = (string) $cepData['district'];
    }
    if (($cepData['city'] ?? '') !== '') {
        $cityPart = (string) $cepData['city'];
        if (($cepData['state'] ?? '') !== '') {
            $cityPart .= ' - ' . $cepData['state'];
        }
        $parts[] = $cityPart;
    }
    $parts[] = $cepFmt;
    $parts[] = 'Brasil';

    return [
        'lat'          => (string) $cepData['lat'],
        'lon'          => (string) $cepData['lng'],
        'display_name' => implode(', ', $parts),
        'class'        => 'place',
        'type'         => 'postcode',
        'addresstype'  => 'postcode',
        'address'      => [
            'road'     => (string) ($cepData['address'] ?? ''),
            'suburb'   => (string) ($cepData['district'] ?? ''),
            'city'     => (string) ($cepData['city'] ?? ''),
            'state'    => chamado_geo_uf_nome((string) ($cepData['state'] ?? '')),
            'postcode' => $cepFmt,
            'country'  => 'Brasil',
            'house_number' => chamado_geo_numero_valido($numero) ? trim($numero) : '',
        ],
        '_source'      => 'awesomeapi_cep',
    ];
}

/**
 * Distância aproximada em metros (equiretangular).
 */
function chamado_geo_haversine_m(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $lat1r = deg2rad($lat1);
    $lat2r = deg2rad($lat2);
    $dLat  = deg2rad($lat2 - $lat1);
    $dLon  = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos($lat1r) * cos($lat2r) * sin($dLon / 2) ** 2;

    return 2 * 6371000 * asin(min(1.0, sqrt($a)));
}
