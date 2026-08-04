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
 * Remove prefixo de tipo de logradouro (Av., Rua, etc.) para variantes OSM.
 */
function chamado_geo_strip_tipo_logradouro(string $logradouro): string
{
    $logradouro = trim($logradouro);
    if ($logradouro === '') {
        return '';
    }
    $stripped = preg_replace(
        '/^(r(ua)?|av(enida)?|trav(essa)?|al(ameda)?|rod(ovia)?|est(rada)?|tv)\.?\s+/iu',
        '',
        $logradouro
    );

    return is_string($stripped) ? trim($stripped) : $logradouro;
}

/**
 * Tokens significativos do logradouro (sem tipo / preposições).
 *
 * @return list<string>
 */
function chamado_geo_logradouro_tokens_significativos(string $logradouro): array
{
    $logradouro = chamado_geo_strip_tipo_logradouro($logradouro);
    if ($logradouro === '') {
        return [];
    }
    $tokens = preg_split('/\s+/u', mb_strtolower($logradouro, 'UTF-8')) ?: [];
    static $stop = [
        'rua', 'r', 'av', 'avenida', 'travessa', 'alameda', 'rodovia', 'estrada',
        'da', 'de', 'do', 'dos', 'das', 'e',
    ];
    $significant = [];
    foreach ($tokens as $tok) {
        $tok = trim($tok);
        if ($tok === '' || mb_strlen($tok, 'UTF-8') < 3 || in_array($tok, $stop, true)) {
            continue;
        }
        $significant[] = $tok;
    }

    return $significant;
}

/**
 * Variantes de nome de rua para Nominatim (tipo OSM ≠ ViaCEP: Rua vs Avenida).
 *
 * @return list<string>
 */
function chamado_geo_logradouro_variants(string $logradouro): array
{
    $logradouro = trim($logradouro);
    if ($logradouro === '') {
        return [];
    }
    $core = chamado_geo_strip_tipo_logradouro($logradouro);
    $out = [];
    $push = static function (string $s) use (&$out): void {
        $s = trim($s);
        if ($s === '') {
            return;
        }
        $key = chamado_geo_texto_comparavel($s);
        if ($key === '' || isset($out[$key])) {
            return;
        }
        $out[$key] = $s;
    };
    $push($logradouro);
    if ($core !== '' && $core !== $logradouro) {
        $push($core);
        $push('Rua ' . $core);
        $push('Avenida ' . $core);
    }

    return array_values($out);
}

/** Extrai CEP 8 dígitos do hit Nominatim (address ou display_name). */
function chamado_geocode_hit_cep_digits(array $hit): string
{
    $addr = $hit['address'] ?? null;
    if (is_array($addr)) {
        $pc = preg_replace('/\D/', '', (string) ($addr['postcode'] ?? ''));
        if (strlen($pc) >= 8) {
            return substr($pc, 0, 8);
        }
        if (strlen($pc) === 5) {
            return $pc;
        }
    }
    $dn = (string) ($hit['display_name'] ?? '');
    if (preg_match('/\b(\d{5})-?(\d{3})\b/', $dn, $m)) {
        return $m[1] . $m[2];
    }

    return '';
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

/**
 * Bairro esperado no display_name / address (suburb, neighbourhood, …).
 */
function chamado_geocode_hit_matches_bairro(array $hit, string $bairro): bool
{
    $bairro = trim($bairro);
    if ($bairro === '') {
        return true;
    }

    $hay = (string) ($hit['display_name'] ?? '');
    $addr = $hit['address'] ?? null;
    if (is_array($addr)) {
        foreach (['suburb', 'neighbourhood', 'quarter', 'city_district', 'district', 'hamlet'] as $k) {
            $v = trim((string) ($addr[$k] ?? ''));
            if ($v !== '') {
                $hay .= ' ' . $v;
            }
        }
    }

    return chamado_geo_texto_contem($hay, $bairro);
}

/**
 * CEP do hit compatível com o informado (mesmo prefixo de 5 dígitos quando ambos existem).
 * Evita aceitar 54350-220 quando o usuário pediu 54430-350.
 */
function chamado_geocode_hit_matches_cep(array $hit, string $postalcode, bool $strict = false): bool
{
    $want = preg_replace('/\D/', '', trim($postalcode));
    if (strlen($want) < 5) {
        return true;
    }
    $got = chamado_geocode_hit_cep_digits($hit);
    if ($got === '') {
        return !$strict;
    }
    $wantPrefix = substr($want, 0, 5);
    $gotPrefix  = substr($got, 0, 5);
    if ($strict && strlen($want) === 8 && strlen($got) === 8) {
        return $want === $got;
    }

    return $wantPrefix === $gotPrefix;
}

function chamado_geocode_hit_score(
    array $hit,
    string $logradouro = '',
    string $bairro = '',
    string $postalcode = '',
    ?float $anchorLat = null,
    ?float $anchorLon = null
): int {
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
    if ($cls === 'place' || $adt === 'place' || $adt === 'postcode') {
        $score += 6;
    }
    if ($cls === 'highway' || $adt === 'road') {
        $score += 2;
    }
    if ($logradouro !== '' && chamado_geocode_hit_matches_logradouro($hit, $logradouro)) {
        $score += 20;
        $tokens = chamado_geo_logradouro_tokens_significativos($logradouro);
        $hay = chamado_geo_texto_comparavel((string) ($hit['display_name'] ?? ''));
        $matched = 0;
        foreach ($tokens as $tok) {
            if (str_contains($hay, chamado_geo_texto_comparavel($tok))) {
                $matched++;
            }
        }
        if ($tokens !== [] && $matched === count($tokens)) {
            $score += 10;
        }
    }
    if ($bairro !== '' && chamado_geocode_hit_matches_bairro($hit, $bairro)) {
        $score += 14;
    }
    $wantCep = preg_replace('/\D/', '', $postalcode);
    if (strlen($wantCep) >= 5) {
        if (chamado_geocode_hit_matches_cep($hit, $postalcode, true)) {
            $score += 18;
        } elseif (chamado_geocode_hit_matches_cep($hit, $postalcode, false)) {
            $score += 8;
        } elseif (chamado_geocode_hit_cep_digits($hit) !== '') {
            $score -= 25;
        }
    }

    // Preferir trecho OSM próximo ao ponto do CEP (evita outro segmento da mesma rua).
    if ($anchorLat !== null && $anchorLon !== null
        && isset($hit['lat'], $hit['lon'])
        && is_numeric($hit['lat']) && is_numeric($hit['lon'])) {
        require_once __DIR__ . '/awesomeapi_cep_client.php';
        $dist = chamado_geo_haversine_m(
            $anchorLat,
            $anchorLon,
            (float) $hit['lat'],
            (float) $hit['lon']
        );
        if ($dist <= 80) {
            $score += 40;
        } elseif ($dist <= 200) {
            $score += 28;
        } elseif ($dist <= 400) {
            $score += 12;
        } elseif ($dist <= 800) {
            $score -= 5;
        } else {
            $score -= 30;
        }
    }

    return $score;
}

/** Número de porta do hit compatível com o solicitado. */
function chamado_geocode_hit_matches_numero(array $hit, string $numero): bool
{
    if (!chamado_geo_numero_valido($numero)) {
        return true;
    }
    $want = preg_replace('/\D/', '', $numero);
    if ($want === '') {
        return true;
    }

    $addr = $hit['address'] ?? null;
    if (is_array($addr)) {
        $got = preg_replace('/\D/', '', (string) ($addr['house_number'] ?? ''));
        if ($got !== '' && ($got === $want || str_starts_with($got, $want) || str_starts_with($want, $got))) {
            return true;
        }
    }

    $dn = (string) ($hit['display_name'] ?? '');

    return $dn !== '' && (bool) preg_match('/\b' . preg_quote($want, '/') . '\b/', $dn);
}

/**
 * Nível de precisão do hit: housenumber | street | cep.
 */
function chamado_geocode_hit_precision(array $hit, string $numero = ''): string
{
    if (($hit['_source'] ?? '') === 'awesomeapi_cep') {
        return 'cep';
    }
    $addr = $hit['address'] ?? null;
    $house = is_array($addr) ? trim((string) ($addr['house_number'] ?? '')) : '';
    if ($house !== '') {
        if ($numero === '' || !chamado_geo_numero_valido($numero) || chamado_geocode_hit_matches_numero($hit, $numero)) {
            return 'housenumber';
        }
    }
    $cls = (string) ($hit['class'] ?? '');
    $adt = (string) ($hit['addresstype'] ?? '');
    if ($cls === 'place' || $adt === 'postcode' || $adt === 'place') {
        return 'cep';
    }

    return 'street';
}

/**
 * Ajusta score com bônus de número de porta (chamado à parte para não exigir $numero em todos os callers).
 */
function chamado_geocode_hit_score_with_numero(
    array $hit,
    string $logradouro = '',
    string $bairro = '',
    string $postalcode = '',
    ?float $anchorLat = null,
    ?float $anchorLon = null,
    string $numero = ''
): int {
    $score = chamado_geocode_hit_score($hit, $logradouro, $bairro, $postalcode, $anchorLat, $anchorLon);
    if (chamado_geo_numero_valido($numero) && chamado_geocode_hit_matches_numero($hit, $numero)) {
        $score += 50;
    }

    return $score;
}

/**
 * Exige token relevante do logradouro no resultado (evita «Avenida Brasil» para «Rua Beira Rio»).
 */
function chamado_geocode_hit_matches_logradouro(array $hit, string $logradouro): bool
{
    $significant = chamado_geo_logradouro_tokens_significativos($logradouro);
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
    bool $requireLogradouro = true,
    string $bairro = '',
    string $postalcode = '',
    bool $requireBairro = false,
    bool $requireCepPrefix = false,
    ?float $anchorLat = null,
    ?float $anchorLon = null
): ?array {
    $best = null;
    $bestScore = -1;
    $hasStreetTokens = chamado_geo_logradouro_tokens_significativos($logradouro) !== [];
    foreach ($hits as $hit) {
        if (!is_array($hit) || !chamado_geocode_hit_matches_context($hit, $cidade, $uf)) {
            continue;
        }
        // Com rua informada, nunca aceitar outra rua só porque a cidade bate.
        if ($hasStreetTokens && !chamado_geocode_hit_matches_logradouro($hit, $logradouro)) {
            continue;
        }
        if ($requireLogradouro && $logradouro !== '' && !chamado_geocode_hit_matches_logradouro($hit, $logradouro)) {
            continue;
        }
        if ($requireBairro && $bairro !== '' && !chamado_geocode_hit_matches_bairro($hit, $bairro)) {
            continue;
        }
        if ($requireCepPrefix && $postalcode !== '' && !chamado_geocode_hit_matches_cep($hit, $postalcode, false)) {
            if (!$hasStreetTokens || !chamado_geocode_hit_matches_logradouro($hit, $logradouro)) {
                continue;
            }
        }
        $sc = chamado_geocode_hit_score($hit, $logradouro, $bairro, $postalcode, $anchorLat, $anchorLon);
        if ($sc > $bestScore) {
            $bestScore = $sc;
            $best = $hit;
        }
    }

    return $best;
}

/**
 * Escolhe o melhor hit: logradouro obrigatório quando há tokens; bairro/CEP como desempate.
 *
 * @param array<int, array<string, mixed>> $hits
 */
function chamado_geocode_pick_best_hit_relaxed(
    array $hits,
    string $cidade,
    string $uf,
    string $logradouro = '',
    string $bairro = '',
    string $postalcode = '',
    ?float $anchorLat = null,
    ?float $anchorLon = null
): ?array {
    $hasStreet = chamado_geo_logradouro_tokens_significativos($logradouro) !== [];
    $anchor = [$anchorLat, $anchorLon];

    // 1) Rua + bairro (mais preciso)
    if ($hasStreet && $bairro !== '') {
        $hit = chamado_geocode_pick_best_hit($hits, $cidade, $uf, $logradouro, true, $bairro, $postalcode, true, false, ...$anchor);
        if ($hit !== null) {
            return $hit;
        }
    }

    // 2) Rua + preferência de CEP (se a rua não bater no OSM, cai no fallback de bairro/cidade)
    if ($hasStreet) {
        $hit = chamado_geocode_pick_best_hit($hits, $cidade, $uf, $logradouro, true, $bairro, $postalcode, false, true, ...$anchor);
        if ($hit !== null) {
            return $hit;
        }
        $hit = chamado_geocode_pick_best_hit($hits, $cidade, $uf, $logradouro, true, $bairro, $postalcode, false, false, ...$anchor);
        if ($hit !== null) {
            return $hit;
        }
    }

    // 3) Fallback: bairro + CEP / bairro + cidade (útil p/ ruas inexistentes no OSM)
    if ($bairro !== '') {
        $hit = chamado_geocode_pick_best_hit($hits, $cidade, $uf, '', false, $bairro, $postalcode, true, true, ...$anchor);
        if ($hit !== null) {
            return $hit;
        }
        $hit = chamado_geocode_pick_best_hit($hits, $cidade, $uf, '', false, $bairro, $postalcode, true, false, ...$anchor);
        if ($hit !== null) {
            return $hit;
        }
    }

    if ($postalcode !== '') {
        $hit = chamado_geocode_pick_best_hit($hits, $cidade, $uf, '', false, $bairro, $postalcode, false, true, ...$anchor);
        if ($hit !== null) {
            return $hit;
        }
    }

    // 4) Último recurso: centroide da cidade (melhor que deixar o pin sem mapa)
    if ($cidade !== '' && $uf !== '') {
        return chamado_geocode_pick_best_hit($hits, $cidade, $uf, '', false, '', '', false, false, ...$anchor);
    }

    return null;
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

    // q no formato CRM (com · / —) → preenche campos estruturados vazios
    if ($fallbackQ !== '' && ($logradouro === '' || $city === '' || $uf === '')) {
        $parsed = chamado_geo_parse_endereco_crm($fallbackQ);
        if ($logradouro === '' && $parsed['logradouro'] !== '') {
            $logradouro = $parsed['logradouro'];
        }
        if ($bairro === '' && $parsed['bairro'] !== '') {
            $bairro = $parsed['bairro'];
        }
        if ($city === '' && $parsed['cidade'] !== '') {
            $city = $parsed['cidade'];
        }
        if ($uf === '' && $parsed['uf'] !== '') {
            $uf = $parsed['uf'];
        }
    }

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
        'os_logradouro'     => $logradouro,
        'os_numero'         => $numero,
        'os_bairro'         => $bairro,
        'os_cidade'         => $city,
        'os_uf'             => $uf,
        'os_cep'            => $postalcode,
        'endereco_completo' => $fallbackQ !== '' ? chamado_geo_normalizar_separadores_endereco($fallbackQ) : '',
    ];

    $cep8 = strlen(preg_replace('/\D/', '', $postalcode)) === 8;
    $attempts = $cep8 ? chamado_geocode_attempts_com_cep($ch) : chamado_geocode_attempts($ch);

    if ($fallbackQ !== '') {
        $qNorm = chamado_geo_limpar_texto($fallbackQ);
        if ($qNorm === '') {
            $qNorm = chamado_geo_normalizar_separadores_endereco($fallbackQ);
        }
        if ($qNorm !== '') {
            $fqKey = mb_strtolower($qNorm, 'UTF-8');
            $found = false;
            foreach ($attempts as $attempt) {
                if (($attempt['type'] ?? '') === 'q'
                    && mb_strtolower(trim((string) ($attempt['q'] ?? '')), 'UTF-8') === $fqKey) {
                    $found = true;
                    break;
                }
                if (($attempt['type'] ?? '') === 'q'
                    && mb_strtolower(trim((string) ($attempt['q'] ?? '')), 'UTF-8') === $fqKey . ', brasil') {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $q = $qNorm;
                if (stripos($q, 'brasil') === false && stripos($q, 'brazil') === false) {
                    $q .= ', Brasil';
                }
                $attempts[] = ['type' => 'q', 'q' => $q];
            }
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
 * @return array{hit:?array<string,mixed>,rate_limited:bool,precision:?string}
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
    require_once __DIR__ . '/awesomeapi_cep_client.php';

    $city       = trim($city);
    $uf         = strtoupper(preg_replace('/\./', '', trim($uf)));
    $postalcode = trim($postalcode);
    $street     = trim($street);
    $fallbackQ  = trim($fallbackQ);
    $bairro     = trim($bairro);
    $logradouro = trim($logradouro);
    $numero     = trim($numero);

    if ($fallbackQ !== '' && ($logradouro === '' || $city === '' || $uf === '' || $bairro === '')) {
        $parsedQ = chamado_geo_parse_endereco_crm($fallbackQ);
        if ($logradouro === '' && $parsedQ['logradouro'] !== '') {
            $logradouro = $parsedQ['logradouro'];
        }
        if ($bairro === '' && $parsedQ['bairro'] !== '') {
            $bairro = $parsedQ['bairro'];
        }
        if ($city === '' && $parsedQ['cidade'] !== '') {
            $city = $parsedQ['cidade'];
        }
        if ($uf === '' && $parsedQ['uf'] !== '') {
            $uf = $parsedQ['uf'];
        }
    }

    $logForPick = $logradouro !== '' ? $logradouro : $street;

    // Âncora do CEP (Correios) — escolhe o trecho OSM certo e serve de fallback.
    $cepHit = null;
    $anchorLat = null;
    $anchorLon = null;
    $cepDigits = preg_replace('/\D/', '', $postalcode);
    if (strlen($cepDigits) === 8) {
        $cepData = awesomeapi_cep_lookup($cepDigits);
        if ($cepData !== null) {
            $anchorLat = $cepData['lat'];
            $anchorLon = $cepData['lng'];
            $cepHit = awesomeapi_cep_to_geocode_hit(
                $cepData,
                $city,
                $uf,
                $bairro,
                $logForPick,
                $numero
            );
        }
    }

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

    $bestOverall = null;
    $bestOverallScore = -1;
    $bestWithNumero = null;
    $bestWithNumeroScore = -1;
    $sawRateLimit = false;

    foreach ($attempts as $attempt) {
        $r = chamado_geocode_run_attempt($attempt);
        if (($r['err'] ?? '') === 'rate_limited') {
            $sawRateLimit = true;
            break;
        }
        if (!$r['ok'] || $r['hits'] === []) {
            continue;
        }
        $hit = chamado_geocode_pick_best_hit_relaxed(
            $r['hits'],
            $city,
            $uf,
            $logForPick,
            $bairro,
            $postalcode,
            $anchorLat,
            $anchorLon
        );
        if ($hit === null) {
            continue;
        }
        $sc = chamado_geocode_hit_score_with_numero(
            $hit,
            $logForPick,
            $bairro,
            $postalcode,
            $anchorLat,
            $anchorLon,
            $numero
        );
        if ($sc > $bestOverallScore) {
            $bestOverallScore = $sc;
            $bestOverall = $hit;
        }
        if (chamado_geo_numero_valido($numero) && chamado_geocode_hit_matches_numero($hit, $numero)) {
            if ($sc > $bestWithNumeroScore) {
                $bestWithNumeroScore = $sc;
                $bestWithNumero = $hit;
            }
        }
        $addr = $hit['address'] ?? null;
        $hasHouse = is_array($addr) && trim((string) ($addr['house_number'] ?? '')) !== '';
        $nearCep = false;
        if ($anchorLat !== null && $anchorLon !== null && isset($hit['lat'], $hit['lon'])) {
            $nearCep = chamado_geo_haversine_m(
                $anchorLat,
                $anchorLon,
                (float) $hit['lat'],
                (float) $hit['lon']
            ) <= 150;
        }
        if (($hasHouse && chamado_geocode_hit_matches_numero($hit, $numero)) || $nearCep) {
            break;
        }
    }

    if ($bestWithNumero !== null) {
        return [
            'hit' => $bestWithNumero,
            'rate_limited' => false,
            'precision' => 'housenumber',
        ];
    }

    if ($bestOverall !== null) {
        $addr = $bestOverall['address'] ?? null;
        $hasHouse = is_array($addr) && trim((string) ($addr['house_number'] ?? '')) !== '';
        // Sem número de porta no OSM: preferir ponto do CEP (Correios) ao centróide de trecho aleatório.
        if ($cepHit !== null && (!$hasHouse || (chamado_geo_numero_valido($numero) && !chamado_geocode_hit_matches_numero($bestOverall, $numero)))) {
            if ($hasHouse && $anchorLat !== null && $anchorLon !== null) {
                $dist = chamado_geo_haversine_m(
                    $anchorLat,
                    $anchorLon,
                    (float) $bestOverall['lat'],
                    (float) $bestOverall['lon']
                );
                if ($dist <= 600 && chamado_geocode_hit_matches_numero($bestOverall, $numero)) {
                    return [
                        'hit' => $bestOverall,
                        'rate_limited' => false,
                        'precision' => chamado_geocode_hit_precision($bestOverall, $numero),
                    ];
                }
            }

            return [
                'hit' => $cepHit,
                'rate_limited' => false,
                'precision' => 'cep',
            ];
        }

        return [
            'hit' => $bestOverall,
            'rate_limited' => false,
            'precision' => chamado_geocode_hit_precision($bestOverall, $numero),
        ];
    }

    if ($cepHit !== null) {
        return [
            'hit' => $cepHit,
            'rate_limited' => false,
            'precision' => 'cep',
        ];
    }

    return ['hit' => null, 'rate_limited' => $sawRateLimit, 'precision' => null];
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

/**
 * Normaliza separadores do CRM (`·`, `—`) para formato amigável ao Nominatim/Google.
 */
function chamado_geo_normalizar_separadores_endereco(string $s): string
{
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    $s = str_replace(['·', '•'], ',', $s);
    // "Ipojuca — PE" / "Ipojuca – PE" → "Ipojuca - PE"
    $s = preg_replace('/\s*[—–]\s*([A-Za-z]{2})\b/u', ' - $1', $s);
    $s = preg_replace('/\s*,\s*/u', ', ', $s);
    $s = preg_replace('/\s+/u', ' ', trim($s));

    return $s;
}

/**
 * Extrai logradouro/bairro/cidade/UF de endereços no formato CRM
 * (`RUA · BAIRRO · Cidade — PE` ou `RUA, BAIRRO, Cidade - PE`).
 *
 * @return array{logradouro:string,bairro:string,cidade:string,uf:string}
 */
function chamado_geo_parse_endereco_crm(string $s): array
{
    $empty = ['logradouro' => '', 'bairro' => '', 'cidade' => '', 'uf' => ''];
    $s = trim($s);
    if ($s === '') {
        return $empty;
    }

    if (preg_match('/^(.+?)\s*[·•]\s*(.+?)\s*[·•]\s*(.+?)\s*[—–-]\s*([A-Za-z]{2})\b/u', $s, $m)) {
        return [
            'logradouro' => trim($m[1]),
            'bairro'     => trim($m[2]),
            'cidade'     => trim($m[3]),
            'uf'         => strtoupper($m[4]),
        ];
    }

    $norm = chamado_geo_normalizar_separadores_endereco($s);
    $norm = preg_replace('/,\s*Brasil\s*$/iu', '', $norm);
    if (preg_match('/^(.+?),\s*(.+?),\s*(.+?)\s*-\s*([A-Za-z]{2})\b/u', $norm, $m)) {
        return [
            'logradouro' => trim($m[1]),
            'bairro'     => trim($m[2]),
            'cidade'     => trim($m[3]),
            'uf'         => strtoupper($m[4]),
        ];
    }

    return $empty;
}

function chamado_geo_limpar_texto(string $s): string
{
    $s = chamado_geo_normalizar_separadores_endereco($s);
    if ($s === '') {
        return '';
    }
    $s = preg_replace('/\s*\([^)]*\)\s*/u', ' ', $s);
    // Remove sufixo descritivo após " - ", mas preserva UF de 2 letras (ex.: " - PE").
    $s = preg_replace('/\s+-\s+(?![A-Za-z]{2}\b).+$/u', '', $s);
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
    $bairro = trim((string) ($ch['os_bairro'] ?? ''));
    $cidade = trim((string) ($ch['os_cidade'] ?? ''));
    $uf     = strtoupper(preg_replace('/\./', '', trim((string) ($ch['os_uf'] ?? ''))));

    if ($log !== '' && $cidade !== '' && $uf !== '') {
        foreach (chamado_geo_logradouro_variants($log) as $logVar) {
            $street = chamado_geo_numero_valido($num) ? trim($num . ' ' . $logVar) : $logVar;
            $key    = 's:' . mb_strtolower($street . '|' . $cidade . '|' . $uf, 'UTF-8');
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[]      = ['type' => 'structured', 'street' => $street, 'city' => $cidade, 'state' => chamado_geo_uf_nome($uf)];
            }
            if ($bairro !== '') {
                $parts = [$logVar];
                if (chamado_geo_numero_valido($num)) {
                    $parts[] = $num;
                }
                $parts[] = $bairro;
                $parts[] = $cidade . ' - ' . $uf;
                $pushQ(implode(', ', $parts));
            }
        }
    }

    $osFull = chamado_geo_endereco_os($ch);
    if ($osFull !== '') {
        $pushQ($osFull);
    }

    $endereco = trim((string) ($ch['endereco_completo'] ?? ''));
    if ($endereco !== '') {
        $norm = chamado_geo_normalizar_separadores_endereco($endereco);
        if ($norm !== '') {
            $pushQ($norm);
        }
        $limpo = chamado_geo_limpar_texto($endereco);
        if ($limpo !== '' && $limpo !== $norm) {
            $pushQ($limpo);
            if ($cidade !== '' && $uf !== '' && mb_stripos($limpo, $cidade, 0, 'UTF-8') === false) {
                $pushQ($limpo . ', ' . $cidade . ' - ' . $uf);
            }
        }
    }

    // Fallback: muitas ruas da planilha não existem no OSM — bairro/cidade ainda posicionam o pin.
    if ($bairro !== '' && $cidade !== '' && $uf !== '') {
        $pushQ($bairro . ', ' . $cidade . ' - ' . $uf);
    }
    if ($cidade !== '' && $uf !== '') {
        $pushQ($cidade . ' - ' . $uf);
        $ufNome = chamado_geo_uf_nome($uf);
        if ($ufNome !== '' && $ufNome !== $uf) {
            $pushQ($cidade . ', ' . $ufNome . ', Brasil');
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
    $pushStructured = static function (string $street, string $cidade, string $ufNom, string $cepFmt = '') use (&$seen, &$out): void {
        $street = trim($street);
        $cidade = trim($cidade);
        if ($street === '' || $cidade === '' || $ufNom === '') {
            return;
        }
        $key = 's:' . mb_strtolower($street . '|' . $cidade . '|' . $ufNom . '|' . $cepFmt, 'UTF-8');
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $row = [
            'type'   => 'structured',
            'street' => $street,
            'city'   => $cidade,
            'state'  => $ufNom,
        ];
        if ($cepFmt !== '') {
            $row['postalcode'] = $cepFmt;
        }
        $out[] = $row;
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
    $logVariants = chamado_geo_logradouro_variants($log);
    $tailCity = '';
    if ($cidade !== '' && $uf !== '') {
        $tailCity = $cidade . ' - ' . $uf;
    } elseif ($cidade !== '') {
        $tailCity = $cidade;
    }

    // Prioridade: logradouro (variantes Rua/Av) + bairro + cidade — OSM costuma
    // cadastrar "Rua X" mesmo quando o ViaCEP diz "Avenida X".
    foreach ($logVariants as $logVar) {
        if ($bairro !== '' && $cidade !== '') {
            $parts = [$logVar];
            if (chamado_geo_numero_valido($num)) {
                $parts[] = $num;
            }
            $parts[] = $bairro;
            if ($tailCity !== '') {
                $parts[] = $tailCity;
            }
            $pushQ(implode(', ', $parts));
        }
        if ($cidade !== '') {
            $parts = [$logVar];
            if ($bairro !== '') {
                $parts[] = $bairro;
            }
            if ($tailCity !== '') {
                $parts[] = $tailCity;
            }
            $pushQ(implode(', ', $parts));
        }
    }

    if ($log !== '' && chamado_geo_numero_valido($num)) {
        $partsCepNum = [$cepFmt, $num, $log];
        if ($bairro !== '') {
            $partsCepNum[] = $bairro;
        }
        if ($tailCity !== '') {
            $partsCepNum[] = $tailCity;
        }
        $pushQ(implode(', ', $partsCepNum));
    }

    if ($cidade !== '' && $ufNom !== '') {
        foreach ($logVariants as $logVar) {
            if (chamado_geo_numero_valido($num)) {
                $pushStructured(trim($num . ' ' . $logVar), $cidade, $ufNom, $cepFmt);
                $pushStructured(trim($num . ' ' . $logVar), $cidade, $ufNom, '');
            }
            $pushStructured($logVar, $cidade, $ufNom, $cepFmt);
            $pushStructured($logVar, $cidade, $ufNom, '');
        }
    }

    if ($log !== '' && !chamado_geo_numero_valido($num)) {
        $partsSemNum = [$log, $cepFmt];
        if ($bairro !== '') {
            $partsSemNum[] = $bairro;
        }
        if ($tailCity !== '') {
            $partsSemNum[] = $tailCity;
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
        if ($tailCity !== '') {
            $parts[] = $tailCity;
        }
        $pushQ(implode(', ', $parts));
    }

    // CEP + bairro (melhor que CEP + cidade sozinho — evita hits em outro bairro).
    if ($bairro !== '' && $cidade !== '' && $uf !== '') {
        $pushQ($cepFmt . ', ' . $bairro . ', ' . $cidade . ' - ' . $uf);
    }

    if ($cidade !== '' && $uf !== '') {
        $pushQ($cepFmt . ', ' . $cidade . ' - ' . $uf);
    }

    // CEP isolado por último: Nominatim interpreta mal (ex.: "54430-350" → "350").
    if ($log === '') {
        $pushQ($cepFmt . ', Brasil');
    }

    foreach (chamado_geocode_attempts($ch) as $attempt) {
        if ($attempt['type'] === 'structured') {
            $pushStructured(
                (string) ($attempt['street'] ?? ''),
                (string) ($attempt['city'] ?? ''),
                (string) ($attempt['state'] ?? ''),
                (string) ($attempt['postalcode'] ?? '')
            );
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
