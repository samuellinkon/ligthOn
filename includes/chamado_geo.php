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

/** Texto normalizado para comparação (lowercase, sem acentos). */
function chamado_geo_texto_comparavel(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    if ($s === '') {
        return '';
    }
    if (class_exists('Transliterator')) {
        $tr = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
        if ($tr !== null) {
            $converted = $tr->transliterate($s);
            if (is_string($converted)) {
                $s = $converted;
            }
        }
    } elseif (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($converted !== false) {
            $s = strtolower($converted);
        }
    }

    $s = preg_replace('/[^a-z0-9\s]/', '', $s);
    $s = preg_replace('/\s+/u', ' ', trim($s));

    return $s;
}

function chamado_geo_texto_contem(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return true;
    }

    return str_contains(
        chamado_geo_texto_comparavel($haystack),
        chamado_geo_texto_comparavel($needle)
    );
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

    $dn = (string) ($hit['display_name'] ?? '');
    if ($dn === '') {
        return false;
    }

    $addr = $hit['address'] ?? null;
    if (is_array($addr)) {
        $st = trim((string) ($addr['state'] ?? ''));
        $ufNome = chamado_geo_uf_nome($uf);
        if ($uf !== '' && $st !== '') {
            $okUf = chamado_geo_texto_contem($st, $uf)
                || ($ufNome !== '' && (chamado_geo_texto_contem($st, $ufNome) || chamado_geo_texto_contem($ufNome, $st)));
            if (!$okUf) {
                return false;
            }
        }
        $ct = trim((string) ($addr['city'] ?? $addr['town'] ?? $addr['municipality'] ?? ''));
        if ($cidade !== '' && $ct !== '' && !chamado_geo_texto_contem($ct, $cidade)) {
            return false;
        }
    }

    if ($uf !== '') {
        $ufNome = chamado_geo_uf_nome($uf);
        if (!chamado_geo_texto_contem($dn, $uf) && ($ufNome === '' || !chamado_geo_texto_contem($dn, $ufNome))) {
            return false;
        }
    }

    if ($cidade !== '' && !chamado_geo_texto_contem($dn, $cidade)) {
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

    $hay = (string) ($hit['display_name'] ?? '');
    $addr = $hit['address'] ?? null;
    if (is_array($addr)) {
        $hay .= ' ' . trim(
            (string) ($addr['road'] ?? '')
            . ' ' . (string) ($addr['street'] ?? '')
            . ' ' . (string) ($addr['pedestrian'] ?? '')
        );
    }

    foreach ($significant as $tok) {
        if (chamado_geo_texto_contem($hay, $tok)) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<int, array<string, mixed>> $hits
 */
function chamado_geocode_pick_best_hit(
    array $hits,
    string $cidade,
    string $uf,
    string $logradouro = '',
    bool $requireLogradouro = true
): ?array {
    $best = null;
    $bestScore = -1;
    foreach ($hits as $hit) {
        if (!is_array($hit) || !chamado_geocode_hit_matches_context($hit, $cidade, $uf)) {
            continue;
        }
        if ($requireLogradouro && !chamado_geocode_hit_matches_logradouro($hit, $logradouro)) {
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
 * @param array<int, array<string, mixed>> $hits
 */
function chamado_geocode_pick_best_hit_relaxed(array $hits, string $cidade, string $uf, string $logradouro = ''): ?array
{
    $strict = chamado_geocode_pick_best_hit($hits, $cidade, $uf, $logradouro, true);
    if ($strict !== null) {
        return $strict;
    }

    return chamado_geocode_pick_best_hit($hits, $cidade, $uf, $logradouro, false);
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
        $hit = chamado_geocode_pick_best_hit_relaxed($r['hits'], $city, $uf, $logForPick);
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
    $pushQ($cepFmt . ', Brasil');

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

        if (chamado_geo_numero_valido($num)) {
            $streetSemNum = $log;
            $keySemNum    = 's:' . mb_strtolower($streetSemNum . '|' . $cidade . '|' . $uf . '|' . $cepFmt . '|semnum', 'UTF-8');
            if (!isset($seen[$keySemNum])) {
                $seen[$keySemNum] = true;
                $rowSemNum        = [
                    'type'   => 'structured',
                    'street' => $streetSemNum,
                    'city'   => $cidade,
                    'state'  => $ufNom,
                ];
                if ($cepFmt !== '') {
                    $rowSemNum['postalcode'] = $cepFmt;
                }
                $out[] = $rowSemNum;
            }
        }
    }

    if ($log !== '' && !chamado_geo_numero_valido($num)) {
        $partsSemNum = [$log, $cepFmt];
        if ($bairro !== '') {
            $partsSemNum[] = $bairro;
        }
        if ($cidade !== '' && $uf !== '') {
            $partsSemNum[] = $cidade . ' - ' . $uf;
        } elseif ($cidade !== '') {
            $partsSemNum[] = $cidade;
        }
        $pushQ(implode(', ', $partsSemNum));
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

    if ($bairro !== '' && $cidade !== '' && $uf !== '') {
        $pushQ($cepFmt . ', ' . $bairro . ', ' . $cidade . ' - ' . $uf);
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

/**
 * Opções de geocode para CrmChamadoVizMapa quando ainda não há lat/lng.
 *
 * @return array{modo: string, attempts: list<array>, mapaQuery: string}|null
 */
function chamado_viz_mapa_geocode_js_opts(array $locPreview): ?array
{
    if ($locPreview['lat'] !== null && $locPreview['lng'] !== null) {
        return null;
    }
    $modo = (string) ($locPreview['modo'] ?? 'none');
    if ($modo === 'geocode' && !empty($locPreview['geocode_attempts'])) {
        return [
            'modo'      => 'geocode',
            'attempts'  => $locPreview['geocode_attempts'],
            'mapaQuery' => '',
        ];
    }
    $mapaQuery = trim((string) ($locPreview['mapa_query'] ?? ''));
    if ($modo === 'mapa_endereco' && $mapaQuery !== '') {
        return [
            'modo'      => 'mapa_endereco',
            'attempts'  => [],
            'mapaQuery' => $mapaQuery,
        ];
    }

    return null;
}

/**
 * Garante que GOOGLE_MAPS_API_KEY foi carregada de config.
 */
function crm_google_maps_bootstrap_config(): void
{
    if (!defined('GOOGLE_MAPS_API_KEY')) {
        $configPath = __DIR__ . '/config.php';
        if (is_file($configPath)) {
            require_once $configPath;
        }
    }
}

/** Chave Google Maps (Maps Embed API + Street View metadata). */
function crm_google_maps_api_key(): string
{
    crm_google_maps_bootstrap_config();

    return defined('GOOGLE_MAPS_API_KEY') ? trim((string) GOOGLE_MAPS_API_KEY) : '';
}

function crm_google_maps_has_api_key(): bool
{
    return crm_google_maps_api_key() !== '';
}

/** Map ID (Cloud Console) para Advanced Markers no dashboard. Opcional. */
function crm_google_maps_map_id(): string
{
    crm_google_maps_bootstrap_config();

    return defined('GOOGLE_MAPS_MAP_ID') ? trim((string) GOOGLE_MAPS_MAP_ID) : '';
}

function crm_google_maps_has_map_id(): bool
{
    return crm_google_maps_map_id() !== '';
}

/**
 * Coordenadas formatadas para URLs embed Google.
 *
 * @return array{0: string, 1: float, 2: float}
 */
function crm_google_maps_embed_location(float $lat, float $lng): array
{
    return [
        rawurlencode(number_format($lat, 7, '.', '') . ',' . number_format($lng, 7, '.', '')),
        $lat,
        $lng,
    ];
}

/** URL legada svembed (sem chave). */
function crm_google_maps_legacy_streetview_embed_url(float $lat, float $lng): string
{
    [$loc] = crm_google_maps_embed_location($lat, $lng);

    return 'https://www.google.com/maps?cbll=' . $loc . '&cbp=11,0,0,0,0&layer=c&output=svembed&hl=pt-BR';
}

/** URL iframe Street View (Embed API se houver chave). */
function crm_google_maps_embed_streetview_url(float $lat, float $lng, string $apiKey = ''): string
{
    $apiKey = $apiKey !== '' ? $apiKey : crm_google_maps_api_key();
    [$loc] = crm_google_maps_embed_location($lat, $lng);
    if ($apiKey === '') {
        return crm_google_maps_legacy_streetview_embed_url($lat, $lng);
    }

    return 'https://www.google.com/maps/embed/v1/streetview?key=' . rawurlencode($apiKey)
        . '&location=' . $loc . '&heading=0&pitch=0&fov=80';
}

/** Link externo para abrir coordenadas no Google Maps. */
function crm_google_maps_external_map_url(float $lat, float $lng): string
{
    [$loc] = crm_google_maps_embed_location($lat, $lng);

    return 'https://www.google.com/maps/search/?api=1&query=' . $loc;
}

/** URL do script Maps JavaScript API (dashboard interativo). */
function crm_google_maps_js_api_url(string $callback = 'crmGoogleMapsApiReady'): string
{
    $apiKey = crm_google_maps_api_key();
    if ($apiKey === '') {
        return '';
    }
    $callback = preg_replace('/[^a-zA-Z0-9_.]/', '', $callback) ?: 'crmGoogleMapsApiReady';

    $url = 'https://maps.googleapis.com/maps/api/js?key=' . rawurlencode($apiKey)
        . '&loading=async&callback=' . rawurlencode($callback);
    if (crm_google_maps_has_map_id()) {
        $url .= '&libraries=marker';
    }

    return $url;
}

/** URL iframe mapa (Embed API view — sem marcador). */
function crm_google_maps_embed_view_url(float $lat, float $lng, int $zoom = 16, string $apiKey = ''): string
{
    $apiKey = $apiKey !== '' ? $apiKey : crm_google_maps_api_key();
    [$loc] = crm_google_maps_embed_location($lat, $lng);
    if ($apiKey === '') {
        return '';
    }
    $zoom = max(1, min(21, $zoom));

    return 'https://www.google.com/maps/embed/v1/view?key=' . rawurlencode($apiKey)
        . '&center=' . $loc . '&zoom=' . $zoom;
}

/** URL iframe mapa com pin no ponto (Embed API place). */
function crm_google_maps_embed_place_url(float $lat, float $lng, int $zoom = 16, string $apiKey = ''): string
{
    $apiKey = $apiKey !== '' ? $apiKey : crm_google_maps_api_key();
    [$loc] = crm_google_maps_embed_location($lat, $lng);
    if ($apiKey === '') {
        return '';
    }
    $zoom = max(1, min(21, $zoom));

    return 'https://www.google.com/maps/embed/v1/place?key=' . rawurlencode($apiKey)
        . '&q=' . $loc . '&zoom=' . $zoom;
}

/**
 * Chave Google Maps para embed oficial de Street View (Maps Embed API).
 * @deprecated Use crm_google_maps_api_key()
 */
function chamado_google_maps_embed_api_key(): string
{
    return crm_google_maps_api_key();
}

/** URL do iframe Street View (Embed API se houver chave; senão embed legado svembed). */
function chamado_street_view_embed_url(float $lat, float $lng, string $apiKey = ''): string
{
    return crm_google_maps_embed_streetview_url($lat, $lng, $apiKey);
}

/** Centro e zoom padrão do mapa de pontos (Prefeitura do Ipojuca). */
function crm_pontos_iluminacao_mapa_centro_default(): array
{
    return [
        'lat' => -8.398075,
        'lng' => -35.063889,
        'zoom' => 10,
    ];
}

/** URL relativa da API de detalhe do poste no mapa. */
function crm_ponto_mapa_detalhe_api_url(string $basePath = '../'): string
{
    $base = rtrim(str_replace('\\', '/', $basePath), '/');
    if ($base === '' || $base === '.') {
        return 'api/ponto_mapa_detalhe.php';
    }

    return $base . '/api/ponto_mapa_detalhe.php';
}

/** URL relativa da API de pontos por viewport do mapa. */
function crm_pontos_mapa_api_url(string $basePath = '../'): string
{
    $base = rtrim(str_replace('\\', '/', $basePath), '/');
    if ($base === '' || $base === '.') {
        return 'api/pontos_mapa.php';
    }

    return $base . '/api/pontos_mapa.php';
}

/** Configuração padrão enviada ao JavaScript do mapa por viewport. */
function crm_pontos_mapa_js_config(int $escopoId, array $filtros = [], string $basePath = '../'): array
{
    if (!function_exists('pontos_mapa_cache_generation')) {
        require_once __DIR__ . '/pontos_mapa_cache.php';
    }

    return [
        'escopo_id' => $escopoId,
        'api_url' => crm_pontos_mapa_api_url($basePath),
        'detalhe_api_url' => crm_ponto_mapa_detalhe_api_url($basePath),
        'center' => crm_pontos_iluminacao_mapa_centro_default(),
        'cache_gen' => pontos_mapa_cache_generation($escopoId),
        'filtros' => [
            'status' => (string) ($filtros['status'] ?? ''),
            'somente_chamados_abertos' => !empty($filtros['somente_chamados_abertos']),
        ],
        'debug' => defined('CRM_DEBUG') && CRM_DEBUG,
    ];
}
