<?php
/**
 * Geocódigo de endereço de chamado (Nominatim / mapa operador).
 */

/** Sigla UF → nome para Nominatim (evita ambiguidade, ex.: PE). */
function chamado_geo_uf_nome(string $uf): string
{
    $uf = strtoupper(preg_replace('/\./', '', trim($uf)));
    static $map = [
        'AC' => 'Acre', 'AL' => 'Alagoas', 'AP' => 'Amapá', 'AM' => 'Amazonas',
        'BA' => 'Bahia', 'CE' => 'Ceará', 'DF' => 'Distrito Federal', 'ES' => 'Espírito Santo',
        'GO' => 'Goiás', 'MA' => 'Maranhão', 'MT' => 'Mato Grosso', 'MS' => 'Mato Grosso do Sul',
        'MG' => 'Minas Gerais', 'PA' => 'Pará', 'PB' => 'Paraíba', 'PR' => 'Paraná',
        'PE' => 'Pernambuco', 'PI' => 'Piauí', 'RJ' => 'Rio de Janeiro', 'RN' => 'Rio Grande do Norte',
        'RS' => 'Rio Grande do Sul', 'RO' => 'Rondônia', 'RR' => 'Roraima', 'SC' => 'Santa Catarina',
        'SP' => 'São Paulo', 'SE' => 'Sergipe', 'TO' => 'Tocantins',
    ];

    return $map[$uf] ?? $uf;
}

/**
 * Rejeita resultado de geocode fora da cidade/UF esperada (ex.: CEP "020" → Lavras do Sul/RS).
 */
function chamado_geocode_hit_matches_context(array $hit, string $cidade, string $uf): bool
{
    $cidade = trim($cidade);
    $uf     = strtoupper(preg_replace('/\./', '', trim($uf)));
    if ($cidade === '' && $uf === '') {
        return true;
    }

    $dn = mb_strtolower((string) ($hit['display_name'] ?? ''), 'UTF-8');
    if ($dn === '') {
        return false;
    }

    $addr = $hit['address'] ?? null;
    if (is_array($addr)) {
        $st = mb_strtolower(trim((string) ($addr['state'] ?? '')), 'UTF-8');
        $ufNome = mb_strtolower(chamado_geo_uf_nome($uf), 'UTF-8');
        if ($uf !== '' && $st !== '') {
            $okUf = ($st === mb_strtolower($uf, 'UTF-8'))
                || ($ufNome !== '' && (str_contains($st, $ufNome) || str_contains($ufNome, $st)));
            if (!$okUf) {
                return false;
            }
        }
        $ct = mb_strtolower(trim((string) ($addr['city'] ?? $addr['town'] ?? $addr['municipality'] ?? '')), 'UTF-8');
        if ($cidade !== '' && $ct !== '' && !str_contains($ct, mb_strtolower($cidade, 'UTF-8'))) {
            return false;
        }
    }

    if ($uf !== '') {
        $ufNome = mb_strtolower(chamado_geo_uf_nome($uf), 'UTF-8');
        if (!str_contains($dn, mb_strtolower($uf, 'UTF-8')) && ($ufNome === '' || !str_contains($dn, $ufNome))) {
            return false;
        }
    }

    if ($cidade !== '' && !str_contains($dn, mb_strtolower($cidade, 'UTF-8'))) {
        return false;
    }

    return true;
}

function chamado_geocode_hit_score(array $hit): int
{
    $score = 0;
    $cls = (string) ($hit['class'] ?? '');
    $typ = (string) ($hit['type'] ?? '');
    $adt = (string) ($hit['addresstype'] ?? '');
    if (in_array($cls, ['building'], true) || $typ === 'house' || in_array($adt, ['building', 'house'], true)) {
        $score += 12;
    }
    $addr = $hit['address'] ?? null;
    if (is_array($addr) && !empty($addr['house_number'])) {
        $score += 10;
    }
    if ($cls === 'place' || $adt === 'place') {
        $score += 6;
    }
    if ($cls === 'highway' || $adt === 'road') {
        $score += 2;
    }

    return $score;
}

/**
 * Exige token relevante do logradouro no resultado (evita «Avenida Brasil» para «Rua Beira Rio»).
 */
function chamado_geocode_hit_matches_logradouro(array $hit, string $logradouro): bool
{
    $logradouro = trim($logradouro);
    if ($logradouro === '') {
        return true;
    }

    $tokens = preg_split('/\s+/u', mb_strtolower($logradouro, 'UTF-8')) ?: [];
    static $stop = [
        'rua', 'r', 'av', 'avenida', 'travessa', 'alameda', 'rodovia', 'estrada',
        'da', 'de', 'do', 'dos', 'das', 'e',
    ];
    $significant = [];
    foreach ($tokens as $tok) {
        $tok = trim($tok);
        if ($tok === '' || strlen($tok) < 3 || in_array($tok, $stop, true)) {
            continue;
        }
        $significant[] = $tok;
    }
    if ($significant === []) {
        return true;
    }

    $hay = mb_strtolower((string) ($hit['display_name'] ?? ''), 'UTF-8');
    $addr = $hit['address'] ?? null;
    if (is_array($addr)) {
        $hay .= ' ' . mb_strtolower(
            trim(
                (string) ($addr['road'] ?? '')
                . ' ' . (string) ($addr['street'] ?? '')
                . ' ' . (string) ($addr['pedestrian'] ?? '')
            ),
            'UTF-8'
        );
    }

    foreach ($significant as $tok) {
        if (str_contains($hay, $tok)) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<int, array<string, mixed>> $hits
 */
function chamado_geocode_pick_best_hit(array $hits, string $cidade, string $uf, string $logradouro = ''): ?array
{
    $best = null;
    $bestScore = -1;
    foreach ($hits as $hit) {
        if (!is_array($hit) || !chamado_geocode_hit_matches_context($hit, $cidade, $uf)) {
            continue;
        }
        if (!chamado_geocode_hit_matches_logradouro($hit, $logradouro)) {
            continue;
        }
        $sc = chamado_geocode_hit_score($hit);
        if ($sc > $bestScore) {
            $bestScore = $sc;
            $best = $hit;
        }
    }

    return $best;
}

/**
 * Monta tentativas de geocode a partir dos parâmetros da API JSON.
 *
 * @return list<array{type:string,street?:string,city?:string,state?:string,postalcode?:string,q?:string}>
 */
function chamado_geocode_attempts_from_api_params(
    string $street,
    string $city,
    string $uf,
    string $postalcode = '',
    string $fallbackQ = '',
    string $bairro = '',
    string $logradouro = '',
    string $numero = ''
): array {
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
    ];

    $cep8 = strlen(preg_replace('/\D/', '', $postalcode)) === 8;
    $attempts = $cep8 ? chamado_geocode_attempts_com_cep($ch) : chamado_geocode_attempts($ch);

    if ($fallbackQ !== '') {
        $fqKey = mb_strtolower($fallbackQ, 'UTF-8');
        $found = false;
        foreach ($attempts as $attempt) {
            if (($attempt['type'] ?? '') === 'q'
                && mb_strtolower(trim((string) ($attempt['q'] ?? '')), 'UTF-8') === $fqKey) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $q = $fallbackQ;
            if (stripos($q, 'brasil') === false && stripos($q, 'brazil') === false) {
                $q .= ', Brasil';
            }
            array_unshift($attempts, ['type' => 'q', 'q' => $q]);
        }
    }

    return $attempts;
}

/**
 * @param array{type:string,street?:string,city?:string,state?:string,postalcode?:string,q?:string} $attempt
 * @return array{ok:bool,status:int,hits:array<int,array<string,mixed>>,cached:bool,err?:string}
 */
function chamado_geocode_run_attempt(array $attempt): array
{
    require_once __DIR__ . '/nominatim_client.php';

    if (($attempt['type'] ?? '') === 'structured') {
        $stateRaw = trim((string) ($attempt['state'] ?? ''));
        $ufGuess  = strtoupper(preg_replace('/\./', '', $stateRaw));
        $stateParam = strlen($ufGuess) === 2 ? $ufGuess : $stateRaw;

        return nominatim_search_structured(
            trim((string) ($attempt['street'] ?? '')),
            trim((string) ($attempt['city'] ?? '')),
            $stateParam,
            trim((string) ($attempt['postalcode'] ?? ''))
        );
    }

    return nominatim_search_free_text(trim((string) ($attempt['q'] ?? '')));
}

/**
 * Resolve endereço OS via Nominatim (várias tentativas estruturadas + texto livre).
 *
 * @return array{hit:?array<string,mixed>,rate_limited:bool}
 */
function chamado_geocode_resolve_os(
    string $street,
    string $city,
    string $uf,
    string $postalcode = '',
    string $fallbackQ = '',
    string $bairro = '',
    string $logradouro = '',
    string $numero = ''
): array {
    require_once __DIR__ . '/nominatim_client.php';

    $city       = trim($city);
    $uf         = strtoupper(preg_replace('/\./', '', trim($uf)));
    $postalcode = trim($postalcode);
    $street     = trim($street);
    $fallbackQ  = trim($fallbackQ);

    $attempts = chamado_geocode_attempts_from_api_params(
        $street,
        $city,
        $uf,
        $postalcode,
        $fallbackQ,
        $bairro,
        $logradouro,
        $numero
    );

    $logForPick = $logradouro !== '' ? $logradouro : $street;

    foreach ($attempts as $attempt) {
        $r = chamado_geocode_run_attempt($attempt);
        if (($r['err'] ?? '') === 'rate_limited') {
            return ['hit' => null, 'rate_limited' => true];
        }
        if (!$r['ok']) {
            continue;
        }
        $hit = chamado_geocode_pick_best_hit($r['hits'], $city, $uf, $logForPick);
        if ($hit !== null) {
            return ['hit' => $hit, 'rate_limited' => false];
        }
    }

    return ['hit' => null, 'rate_limited' => false];
}

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
            $out[]      = ['type' => 'structured', 'street' => $street, 'city' => $cidade, 'state' => chamado_geo_uf_nome($uf)];
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
    $ufNom  = $uf !== '' ? chamado_geo_uf_nome($uf) : '';

    if ($log !== '' && chamado_geo_numero_valido($num)) {
        $partsCepNum = [$cepFmt, $num, $log];
        if ($bairro !== '') {
            $partsCepNum[] = $bairro;
        }
        if ($cidade !== '' && $uf !== '') {
            $partsCepNum[] = $cidade . ' - ' . $uf;
        } elseif ($cidade !== '') {
            $partsCepNum[] = $cidade;
        }
        $pushQ(implode(', ', $partsCepNum));
    }

    if ($log !== '' && $cidade !== '' && $uf !== '') {
        $street = chamado_geo_numero_valido($num) ? trim($num . ' ' . $log) : $log;
        $key    = 's:' . mb_strtolower($street . '|' . $cidade . '|' . $uf . '|' . $cepFmt, 'UTF-8');
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $row        = [
                'type'   => 'structured',
                'street' => $street,
                'city'   => $cidade,
                'state'  => $ufNom,
            ];
            if ($cepFmt !== '') {
                $row['postalcode'] = $cepFmt;
            }
            $out[] = $row;
        }
    }

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

    if ($cidade !== '' && $uf !== '') {
        $pushQ($cepFmt . ', ' . $cidade . ' - ' . $uf);
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
 * Indica se há logradouro preenchido nos campos OS do chamado.
 */
function chamado_geo_tem_logradouro_os(array $ch): bool
{
    return trim((string) ($ch['os_logradouro'] ?? '')) !== '';
}

/**
 * Prioridade da fonte do mapa no formulário / preview.
 *
 * 0 = ponto de iluminação vinculado (lat/lng do poste)
 * 1 = lat/lng do chamado (sem ponto vinculado)
 * 2 = CEP (8 dígitos) + endereço OS
 * 3 = endereço OS sem CEP válido para tier 2
 * -1 = sem fonte utilizável
 */
function chamado_map_preview_tier(array $chamado, ?array $ponto = null): int
{
    $pontoId = (int) ($chamado['ponto_iluminacao_id'] ?? 0);
    if ($ponto !== null && (int) ($ponto['id'] ?? 0) > 0) {
        $pontoId = (int) $ponto['id'];
    }
    $pontoVinculado = $pontoId > 0;

    if ($pontoVinculado && $ponto !== null) {
        [$pla, $plo] = chamado_geo_row_latlng($ponto);
        if ($pla !== null && $plo !== null) {
            return 0;
        }

        return -1;
    }

    if (!$pontoVinculado) {
        [$cla, $clo] = chamado_geo_row_latlng($chamado);
        if ($cla !== null && $clo !== null) {
            return 1;
        }
    }

    $enderecoOs    = chamado_geo_endereco_os($chamado);
    $enderecoFull  = trim((string) ($chamado['endereco_completo'] ?? ''));
    $enderecoLimpo = $enderecoFull !== '' ? (chamado_geo_limpar_texto($enderecoFull) ?: $enderecoFull) : '';
    $cep8          = strlen(preg_replace('/\D/', '', (string) ($chamado['os_cep'] ?? ''))) === 8;
    $temEndereco   = ($enderecoOs !== '' || $enderecoLimpo !== '') && chamado_geo_tem_logradouro_os($chamado);

    if ($cep8 && $temEndereco) {
        return 2;
    }

    if ($temEndereco) {
        return 3;
    }

    return -1;
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
 *   label_fonte: string,
 *   tier: int
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
        'tier'             => -1,
    ];

    $tier = chamado_map_preview_tier($chamado, $ponto);

    if ($tier === 0 && $ponto !== null) {
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
                'tier'             => 0,
            ];
        }
    }

    if ($tier === 1) {
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
                'tier'             => 1,
            ];
        }
    }

    $enderecoOs    = chamado_geo_endereco_os($chamado);
    $enderecoFull  = trim((string) ($chamado['endereco_completo'] ?? ''));
    $enderecoLimpo = $enderecoFull !== '' ? (chamado_geo_limpar_texto($enderecoFull) ?: $enderecoFull) : '';

    if ($tier === 2) {
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
            'tier'             => 2,
        ];
    }

    if ($tier === 3) {
        $mapaQuery = $enderecoOs !== '' ? $enderecoOs : $enderecoLimpo;
        $attempts  = chamado_geocode_attempts($chamado);
        if ($attempts !== []) {
            return [
                'lat'              => null,
                'lng'              => null,
                'fonte'            => null,
                'modo'             => 'geocode',
                'geocode_attempts' => $attempts,
                'mapa_query'       => $mapaQuery,
                'nav_query'        => $mapaQuery,
                'show_preview'     => true,
                'label_fonte'      => '',
                'tier'             => 3,
            ];
        }

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
            'tier'             => 3,
        ];
    }

    return $empty;
}
