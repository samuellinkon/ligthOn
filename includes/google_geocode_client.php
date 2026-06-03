<?php
declare(strict_types=1);

/**
 * Geocoding via Google Maps Geocoding API (fallback quando Nominatim falha).
 */

function google_geocode_api_key(): string
{
    if (!defined('GOOGLE_MAPS_API_KEY')) {
        require_once __DIR__ . '/config.php';
    }

    return defined('GOOGLE_MAPS_API_KEY') ? trim((string) GOOGLE_MAPS_API_KEY) : '';
}

/**
 * @param array<string, mixed> $result
 */
function google_geocode_result_matches_context(array $result, string $cidade, string $uf): bool
{
    $cidade = trim($cidade);
    $uf     = strtoupper(preg_replace('/\./', '', trim($uf)));
    if ($cidade === '' && $uf === '') {
        return true;
    }

    $components = $result['address_components'] ?? null;
    if (!is_array($components)) {
        $formatted = (string) ($result['formatted_address'] ?? '');

        return chamado_geo_texto_contem($formatted, $cidade)
            && ($uf === '' || chamado_geo_texto_contem($formatted, $uf) || chamado_geo_texto_contem($formatted, chamado_geo_uf_nome($uf)));
    }

    $stateName = '';
    $cityName  = '';
    foreach ($components as $comp) {
        if (!is_array($comp)) {
            continue;
        }
        $types = $comp['types'] ?? [];
        if (!is_array($types)) {
            continue;
        }
        $long = trim((string) ($comp['long_name'] ?? ''));
        if ($long === '') {
            continue;
        }
        if (in_array('administrative_area_level_1', $types, true)) {
            $stateName = $long;
        }
        if (in_array('locality', $types, true)
            || in_array('administrative_area_level_2', $types, true)
            || in_array('postal_town', $types, true)) {
            $cityName = $long;
        }
    }

    if ($uf !== '' && $stateName !== '') {
        $ufNome = chamado_geo_uf_nome($uf);
        $okUf   = chamado_geo_texto_contem($stateName, $uf)
            || ($ufNome !== '' && (chamado_geo_texto_contem($stateName, $ufNome) || chamado_geo_texto_contem($ufNome, $stateName)));
        if (!$okUf) {
            return false;
        }
    }

    if ($cidade !== '' && $cityName !== '' && !chamado_geo_texto_contem($cityName, $cidade)) {
        return false;
    }

    if ($cidade !== '' && $cityName === '') {
        $formatted = (string) ($result['formatted_address'] ?? '');
        if ($formatted !== '' && !chamado_geo_texto_contem($formatted, $cidade)) {
            return false;
        }
    }

    return true;
}

/**
 * @return array{lat:float,lon:float,display_name:string}|null
 */
function google_geocode_search(string $address, string $city, string $uf): ?array
{
    $apiKey = google_geocode_api_key();
    $address = trim($address);
    if ($apiKey === '' || $address === '') {
        return null;
    }

    $params = [
        'address'    => $address,
        'key'        => $apiKey,
        'language'   => 'pt-BR',
        'region'     => 'br',
        'components' => 'country:BR',
    ];
    $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query($params);

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 12,
            'header'  => "Accept: application/json\r\n",
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') {
        return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }

    $status = strtoupper(trim((string) ($data['status'] ?? '')));
    if ($status !== 'OK' || !is_array($data['results'] ?? null) || $data['results'] === []) {
        return null;
    }

    foreach ($data['results'] as $result) {
        if (!is_array($result)) {
            continue;
        }
        if (!google_geocode_result_matches_context($result, $city, $uf)) {
            continue;
        }
        $loc = $result['geometry']['location'] ?? null;
        if (!is_array($loc)) {
            continue;
        }
        $lat = isset($loc['lat']) ? (float) $loc['lat'] : NAN;
        $lon = isset($loc['lng']) ? (float) $loc['lng'] : NAN;
        if (!is_finite($lat) || !is_finite($lon)) {
            continue;
        }

        return [
            'lat'          => $lat,
            'lon'          => $lon,
            'display_name' => (string) ($result['formatted_address'] ?? $address),
        ];
    }

    return null;
}

/**
 * Resolve endereço OS via Google (fallback).
 *
 * @return array{lat:float,lon:float,display_name:string}|null
 */
function google_geocode_resolve_os(
    string $street,
    string $city,
    string $uf,
    string $postalcode = '',
    string $fallbackQ = '',
    string $bairro = '',
    string $logradouro = '',
    string $numero = ''
): ?array {
    require_once __DIR__ . '/chamado_geo.php';

    $street     = trim($street);
    $city       = trim($city);
    $uf         = strtoupper(preg_replace('/\./', '', trim($uf)));
    $postalcode = trim($postalcode);
    $fallbackQ  = trim($fallbackQ);
    $bairro     = trim($bairro);
    $logradouro = trim($logradouro);
    $numero     = trim($numero);

    if ($logradouro === '' && $street !== '') {
        if (preg_match('/^(\d+)\s+(.+)$/u', $street, $m)) {
            if ($numero === '') {
                $numero = $m[1];
            }
            $logradouro = trim($m[2]);
        } else {
            $logradouro = $street;
        }
    }

    $ch = [
        'os_logradouro' => $logradouro,
        'os_numero'     => $numero,
        'os_bairro'     => $bairro,
        'os_cidade'     => $city,
        'os_uf'         => $uf,
        'os_cep'        => $postalcode,
        'os_complemento'=> '',
    ];

    $queries = [];
    $full = chamado_geo_endereco_os($ch);
    if ($full !== '') {
        $queries[] = $full;
    }
    if ($fallbackQ !== '') {
        $queries[] = $fallbackQ;
    }
    if ($street !== '' && $street !== $full) {
        $queries[] = $street . ($city !== '' && $uf !== '' ? ', ' . $city . ' - ' . $uf : '') . ', Brasil';
    }

    $seen = [];
    foreach ($queries as $q) {
        $q = trim($q);
        if ($q === '') {
            continue;
        }
        $key = mb_strtolower($q, 'UTF-8');
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $hit = google_geocode_search($q, $city, $uf);
        if ($hit !== null) {
            return $hit;
        }
    }

    return null;
}
