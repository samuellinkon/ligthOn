<?php
/**
 * Geocódigo de endereço de chamado (Nominatim / mapa operador).
 */

function chamado_geo_numero_valido(string $num): bool
{
    $n = trim($num);
    if ($n === '' || $n === '#' || $n === '0') {
        return false;
    }
    if (preg_match('/^(n[º°\']?o?|num(ero)?|s\/n|[—\-–_]+)$/iu', $n)) {
        return false;
    }

    return true;
}

function chamado_geo_limpar_texto(string $s): string
{
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    $s = preg_replace('/\s*\([^)]*\)\s*/u', ' ', $s);
    $s = preg_replace('/\s*[—–-]\s*.+$/u', '', $s);
    $s = preg_replace('/\s+/u', ' ', trim($s));

    return $s;
}

/**
 * Monta endereço completo a partir dos campos OS (igual à lógica do admin).
 */
function chamado_geo_endereco_os(array $ch): string
{
    $log = trim((string) ($ch['os_logradouro'] ?? ''));
    if ($log === '') {
        return '';
    }
    $num    = trim((string) ($ch['os_numero'] ?? ''));
    $comp   = trim((string) ($ch['os_complemento'] ?? ''));
    $bairro = trim((string) ($ch['os_bairro'] ?? ''));
    $cidade = trim((string) ($ch['os_cidade'] ?? ''));
    $uf     = strtoupper(preg_replace('/\./', '', trim((string) ($ch['os_uf'] ?? ''))));
    $cep    = preg_replace('/\D/', '', (string) ($ch['os_cep'] ?? ''));

    $omitComp = $comp !== '' && preg_match('/\bde\s*\d+\s*a\s*\d+/i', $comp);

    $head = [$log];
    if (chamado_geo_numero_valido($num)) {
        $head[] = $num;
    }
    if ($comp !== '' && !$omitComp) {
        $head[] = $comp;
    }
    $headStr = implode(', ', $head);

    $tail = [];
    if ($bairro !== '') {
        $tail[] = $bairro;
    }
    if ($cidade !== '' && $uf !== '') {
        $tail[] = $cidade . ' - ' . $uf;
    } elseif ($cidade !== '') {
        $tail[] = $cidade;
    } elseif ($uf !== '') {
        $tail[] = $uf;
    }
    if (strlen($cep) === 8) {
        $tail[] = substr($cep, 0, 5) . '-' . substr($cep, 5);
    }

    $full = $headStr;
    if ($tail !== []) {
        $full .= ', ' . implode(', ', $tail);
    }
    if (stripos($full, 'brasil') === false && stripos($full, 'brazil') === false) {
        $full .= ', Brasil';
    }

    return $full;
}

/**
 * Lista de tentativas para geocódigo (ordem de prioridade).
 *
 * @return list<array{type:string,street?:string,city?:string,state?:string,q?:string}>
 */
function chamado_geocode_attempts(array $ch): array
{
    $seen  = [];
    $out   = [];
    $pushQ = static function (string $q) use (&$seen, &$out): void {
        $q = trim($q);
        if ($q === '') {
            return;
        }
        $key = mb_strtolower($q, 'UTF-8');
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        if (stripos($q, 'brasil') === false && stripos($q, 'brazil') === false) {
            $q .= ', Brasil';
        }
        $out[] = ['type' => 'q', 'q' => $q];
    };

    $log    = trim((string) ($ch['os_logradouro'] ?? ''));
    $num    = trim((string) ($ch['os_numero'] ?? ''));
    $cidade = trim((string) ($ch['os_cidade'] ?? ''));
    $uf     = strtoupper(preg_replace('/\./', '', trim((string) ($ch['os_uf'] ?? ''))));

    if ($log !== '' && $cidade !== '' && $uf !== '') {
        $street = chamado_geo_numero_valido($num) ? trim($num . ' ' . $log) : $log;
        $key    = 's:' . mb_strtolower($street . '|' . $cidade . '|' . $uf, 'UTF-8');
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $out[]      = ['type' => 'structured', 'street' => $street, 'city' => $cidade, 'state' => $uf];
        }
    }

    $osFull = chamado_geo_endereco_os($ch);
    if ($osFull !== '') {
        $pushQ($osFull);
    }

    $endereco = trim((string) ($ch['endereco_completo'] ?? ''));
    if ($endereco !== '') {
        $pushQ($endereco);
        $limpo = chamado_geo_limpar_texto($endereco);
        if ($limpo !== '') {
            $pushQ($limpo);
            if ($cidade !== '' && $uf !== '' && mb_stripos($limpo, $cidade, 0, 'UTF-8') === false) {
                $pushQ($limpo . ', ' . $cidade . ' - ' . $uf);
            }
        }
    }

    return $out;
}

/**
 * Valida par lat/lng (aceita float, int ou string).
 */
function chamado_geo_coords_validas(mixed $lat, mixed $lng): bool
{
    if ($lat === null || $lng === null || $lat === '' || $lng === '') {
        return false;
    }
    $latStr = is_string($lat) ? trim(str_replace(',', '.', $lat)) : (string) $lat;
    $lngStr = is_string($lng) ? trim(str_replace(',', '.', $lng)) : (string) $lng;
    if (!is_numeric($latStr) || !is_numeric($lngStr)) {
        return false;
    }
    $la = (float) $latStr;
    $lo = (float) $lngStr;

    return $la >= -90.0 && $la <= 90.0 && $lo >= -180.0 && $lo <= 180.0;
}

/**
 * @return array{0: ?float, 1: ?float}
 */
function chamado_geo_row_latlng(?array $row): array
{
    if (!$row) {
        return [null, null];
    }
    $la = $row['latitude'] ?? null;
    $lo = $row['longitude'] ?? null;
    if (!chamado_geo_coords_validas($la, $lo)) {
        return [null, null];
    }
    $latStr = is_string($la) ? trim(str_replace(',', '.', $la)) : (string) $la;
    $lngStr = is_string($lo) ? trim(str_replace(',', '.', $lo)) : (string) $lo;

    return [(float) $latStr, (float) $lngStr];
}

/**
 * Tentativas de geocode com CEP em destaque (prioridade 3).
 *
 * @return list<array{type:string,street?:string,city?:string,state?:string,q?:string}>
 */
function chamado_geocode_attempts_com_cep(array $ch): array
{
    $seen  = [];
    $out   = [];
    $pushQ = static function (string $q) use (&$seen, &$out): void {
        $q = trim($q);
        if ($q === '') {
            return;
        }
        $key = mb_strtolower($q, 'UTF-8');
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        if (stripos($q, 'brasil') === false && stripos($q, 'brazil') === false) {
            $q .= ', Brasil';
        }
        $out[] = ['type' => 'q', 'q' => $q];
    };

    $cepRaw = preg_replace('/\D/', '', (string) ($ch['os_cep'] ?? ''));
    if (strlen($cepRaw) !== 8) {
        return chamado_geocode_attempts($ch);
    }
    $cepFmt = substr($cepRaw, 0, 5) . '-' . substr($cepRaw, 5);

    $log    = trim((string) ($ch['os_logradouro'] ?? ''));
    $num    = trim((string) ($ch['os_numero'] ?? ''));
    $bairro = trim((string) ($ch['os_bairro'] ?? ''));
    $cidade = trim((string) ($ch['os_cidade'] ?? ''));
    $uf     = strtoupper(preg_replace('/\./', '', trim((string) ($ch['os_uf'] ?? ''))));

    if ($log !== '') {
        $head = [$log];
        if (chamado_geo_numero_valido($num)) {
            $head[] = $num;
        }
        $parts = [implode(', ', $head), $cepFmt];
        if ($bairro !== '') {
            $parts[] = $bairro;
        }
        if ($cidade !== '' && $uf !== '') {
            $parts[] = $cidade . ' - ' . $uf;
        } elseif ($cidade !== '') {
            $parts[] = $cidade;
        }
        $pushQ(implode(', ', $parts));
    }

    $pushQ($cepFmt . ', Brasil');

    if ($log !== '' && $cidade !== '' && $uf !== '') {
        $street = chamado_geo_numero_valido($num) ? trim($num . ' ' . $log) : $log;
        $key    = 's:' . mb_strtolower($street . '|' . $cidade . '|' . $uf, 'UTF-8');
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $out[]      = ['type' => 'structured', 'street' => $street, 'city' => $cidade, 'state' => $uf];
        }
    }

    foreach (chamado_geocode_attempts($ch) as $attempt) {
        if ($attempt['type'] === 'structured') {
            $key = 's:' . mb_strtolower(
                ($attempt['street'] ?? '') . '|' . ($attempt['city'] ?? '') . '|' . ($attempt['state'] ?? ''),
                'UTF-8'
            );
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $attempt;
            }
        } else {
            $q = trim((string) ($attempt['q'] ?? ''));
            if ($q !== '') {
                $pushQ($q);
            }
        }
    }

    return $out;
}

/**
 * Resolve localização para preview (ponto → chamado → CEP+endereço → mapa por endereço).
 *
 * @return array{
 *   lat: ?float,
 *   lng: ?float,
 *   fonte: ?string,
 *   modo: string,
 *   geocode_attempts: list<array>,
 *   mapa_query: string,
 *   nav_query: string,
 *   show_preview: bool,
 *   label_fonte: string
 * }
 */
function chamado_resolver_localizacao_preview(array $chamado, ?array $ponto = null): array
{
    $empty = [
        'lat'              => null,
        'lng'              => null,
        'fonte'            => null,
        'modo'             => 'none',
        'geocode_attempts' => [],
        'mapa_query'       => '',
        'nav_query'        => '',
        'show_preview'     => false,
        'label_fonte'      => '',
    ];

    [$pla, $plo] = chamado_geo_row_latlng($ponto);
    if ($pla !== null && $plo !== null) {
        $nav = number_format($pla, 7, '.', '') . ',' . number_format($plo, 7, '.', '');
        $cod = trim((string) ($ponto['codigo_poste'] ?? ''));
        if ($cod === '') {
            $cod = trim((string) ($chamado['ponto_codigo_poste'] ?? ''));
        }

        return [
            'lat'              => $pla,
            'lng'              => $plo,
            'fonte'            => 'ponto',
            'modo'             => 'streetview',
            'geocode_attempts' => [],
            'mapa_query'       => '',
            'nav_query'        => $nav,
            'show_preview'     => true,
            'label_fonte'      => $cod !== '' ? 'Poste ' . $cod : 'Ponto de iluminação cadastrado',
        ];
    }

    [$cla, $clo] = chamado_geo_row_latlng($chamado);
    if ($cla !== null && $clo !== null) {
        $nav = number_format($cla, 7, '.', '') . ',' . number_format($clo, 7, '.', '');

        return [
            'lat'              => $cla,
            'lng'              => $clo,
            'fonte'            => 'chamado',
            'modo'             => 'streetview',
            'geocode_attempts' => [],
            'mapa_query'       => '',
            'nav_query'        => $nav,
            'show_preview'     => true,
            'label_fonte'      => 'Coordenadas do chamado',
        ];
    }

    $enderecoOs   = chamado_geo_endereco_os($chamado);
    $enderecoFull = trim((string) ($chamado['endereco_completo'] ?? ''));
    $enderecoLimpo = $enderecoFull !== '' ? (chamado_geo_limpar_texto($enderecoFull) ?: $enderecoFull) : '';
    $cep8         = strlen(preg_replace('/\D/', '', (string) ($chamado['os_cep'] ?? ''))) === 8;
    $temEndereco  = $enderecoOs !== '' || $enderecoLimpo !== '';

    if ($cep8 && $temEndereco) {
        $nav = $enderecoOs !== '' ? $enderecoOs : $enderecoLimpo;

        return [
            'lat'              => null,
            'lng'              => null,
            'fonte'            => null,
            'modo'             => 'geocode',
            'geocode_attempts' => chamado_geocode_attempts_com_cep($chamado),
            'mapa_query'       => '',
            'nav_query'        => $nav,
            'show_preview'     => true,
            'label_fonte'      => '',
        ];
    }

    if ($temEndereco) {
        $mapaQuery = $enderecoOs !== '' ? $enderecoOs : $enderecoLimpo;

        return [
            'lat'              => null,
            'lng'              => null,
            'fonte'            => null,
            'modo'             => 'mapa_endereco',
            'geocode_attempts' => [],
            'mapa_query'       => $mapaQuery,
            'nav_query'        => $mapaQuery,
            'show_preview'     => true,
            'label_fonte'      => '',
        ];
    }

    return $empty;
}
