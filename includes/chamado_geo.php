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
