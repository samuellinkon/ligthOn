<?php
declare(strict_types=1);

require_once __DIR__ . '/medicao_xlsx_import.php';

/**
 * @return array{ok: bool, erro: string, linhas: list<array<string, mixed>>, avisos: list<string>}
 */
function catalogo_import_parse_upload(string $path, string $nomeOriginal): array
{
    $ext = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
    if ($ext === 'xlsx') {
        return catalogo_import_parse_xlsx($path);
    }
    if ($ext === 'csv' || $ext === 'txt') {
        return catalogo_import_parse_csv_file($path);
    }

    return [
        'ok'     => false,
        'erro'   => 'Formato não suportado. Envie .xlsx, .csv ou .txt.',
        'linhas' => [],
        'avisos' => [],
    ];
}

/**
 * @return array{ok: bool, erro: string, linhas: list<array<string, mixed>>, avisos: list<string>}
 */
function catalogo_import_parse_csv_file(string $path): array
{
    $raw = file_get_contents($path);
    if ($raw === false) {
        return ['ok' => false, 'erro' => 'Não foi possível ler o arquivo.', 'linhas' => [], 'avisos' => []];
    }

    return catalogo_import_parse_csv_content($raw);
}

/**
 * @return array{ok: bool, erro: string, linhas: list<array<string, mixed>>, avisos: list<string>}
 */
function catalogo_import_parse_csv_content(string $conteudo): array
{
    $conteudo = preg_replace('/^\xEF\xBB\xBF/', '', (string) $conteudo);
    $linhasTxt = preg_split('/\r\n|\r|\n/', trim($conteudo));
    if ($linhasTxt === false || $linhasTxt === []) {
        return ['ok' => false, 'erro' => 'Arquivo vazio.', 'linhas' => [], 'avisos' => []];
    }

    $delim = ';';
    if (substr_count($linhasTxt[0], ',') > substr_count($linhasTxt[0], ';')) {
        $delim = ',';
    }

    $rows = [];
    foreach ($linhasTxt as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }
        $rows[] = str_getcsv($line, $delim);
    }

    return catalogo_import_rows_to_linhas($rows);
}

/**
 * @return array{ok: bool, erro: string, linhas: list<array<string, mixed>>, avisos: list<string>}
 */
function catalogo_import_parse_xlsx(string $path): array
{
    if (!is_readable($path)) {
        return ['ok' => false, 'erro' => 'Arquivo ilegível.', 'linhas' => [], 'avisos' => []];
    }
    if (!class_exists(ZipArchive::class)) {
        return [
            'ok'   => false,
            'erro' => 'Importação .xlsx requer a extensão PHP zip (ZipArchive). Ative extension=zip no php.ini ou envie CSV UTF-8.',
            'linhas' => [],
            'avisos' => [],
        ];
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return ['ok' => false, 'erro' => 'Não foi possível abrir o .xlsx. Verifique se o arquivo não está corrompido.', 'linhas' => [], 'avisos' => []];
    }

    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    $strings = ($ssXml !== false && $ssXml !== '')
        ? medicao_xlsx_parse_shared_strings_xml($ssXml)
        : [];

    $sheetPath = medicao_xlsx_pick_worksheet_path($zip, 'catalogo');
    if ($sheetPath === null) {
        $zip->close();

        return ['ok' => false, 'erro' => 'Nenhuma folha encontrada no .xlsx.', 'linhas' => [], 'avisos' => []];
    }

    $sheetXml = $zip->getFromName($sheetPath);
    $zip->close();

    if ($sheetXml === false || $sheetXml === '') {
        return ['ok' => false, 'erro' => 'A folha da planilha está vazia ou ilegível.', 'linhas' => [], 'avisos' => []];
    }

    $grid = medicao_xlsx_sheet_to_sparse_grid($sheetXml, $strings);
    $rows = medicao_xlsx_grid_to_rows($grid);

    return catalogo_import_rows_to_linhas($rows);
}

/**
 * @param list<list<string>> $rows
 * @return array{ok: bool, erro: string, linhas: list<array<string, mixed>>, avisos: list<string>}
 */
function catalogo_import_rows_to_linhas(array $rows): array
{
    $avisos = [];
    if ($rows === []) {
        return ['ok' => false, 'erro' => 'Planilha sem linhas de dados.', 'linhas' => [], 'avisos' => []];
    }

    $headerIdx = catalogo_import_find_header_row_index($rows);
    if ($headerIdx === null) {
        return catalogo_import_rows_positional($rows);
    }

    $map = catalogo_import_header_map($rows[$headerIdx]);
    if (!isset($map['tipo'], $map['nome'])) {
        return [
            'ok'   => false,
            'erro' => 'Colunas obrigatórias «Tipo» e «Nome» não identificadas no cabeçalho.',
            'linhas' => [],
            'avisos' => [],
        ];
    }

    $out = [];
    $n   = 0;
    for ($i = $headerIdx + 1, $c = count($rows); $i < $c; $i++) {
        $n++;
        $cells = $rows[$i];
        if (catalogo_import_row_is_empty($cells)) {
            continue;
        }

        $tipo = catalogo_import_normalize_tipo(catalogo_import_cell($cells, $map, 'tipo'));
        $nome = trim(catalogo_import_cell($cells, $map, 'nome'));
        if ($tipo === '' && $nome === '') {
            continue;
        }
        if ($tipo === '') {
            $avisos[] = "Linha " . ($i + 1) . ": tipo inválido (use produto ou servico).";

            continue;
        }
        if ($nome === '') {
            continue;
        }

        $codigo = trim(catalogo_import_cell($cells, $map, 'codigo'));
        $unid   = trim(catalogo_import_cell($cells, $map, 'unidade'));
        $valRaw = str_replace(',', '.', trim(catalogo_import_cell($cells, $map, 'valor_unitario')));
        $desc   = trim(catalogo_import_cell($cells, $map, 'descricao'));
        $descSimp = trim(catalogo_import_cell($cells, $map, 'descricao_simplificada'));
        $stock  = catalogo_import_row_stock_fields($cells, $map);

        $out[] = array_merge([
            'tipo'           => $tipo,
            'nome'           => $nome,
            'codigo'         => $codigo,
            'unidade'        => $unid !== '' ? $unid : 'UN',
            'valor_unitario' => (float) ($valRaw !== '' ? $valRaw : '0'),
            'descricao'      => $desc,
            'descricao_simplificada' => $descSimp,
            '_linha_plan'    => $i + 1,
        ], $stock);
    }

    if ($out === []) {
        return ['ok' => false, 'erro' => 'Nenhuma linha de dados válida após o cabeçalho.', 'linhas' => [], 'avisos' => $avisos];
    }

    return ['ok' => true, 'erro' => '', 'linhas' => $out, 'avisos' => $avisos];
}

/**
 * @param list<list<string>> $rows
 */
function catalogo_import_find_header_row_index(array $rows): ?int
{
    $max = min(count($rows), 35);
    for ($i = 0; $i < $max; $i++) {
        $map = catalogo_import_header_map($rows[$i]);
        if (isset($map['tipo'], $map['nome'])) {
            return $i;
        }
    }

    return null;
}

/**
 * @param list<string> $headerRow
 * @return array<string, int>
 */
function catalogo_import_header_map(array $headerRow): array
{
    $map = [];
    foreach ($headerRow as $idx => $label) {
        $key = catalogo_import_header_key((string) $label);
        if ($key !== '' && !isset($map[$key])) {
            $map[$key] = (int) $idx;
        }
    }

    return $map;
}

function catalogo_import_header_key(string $label): string
{
    $s = catalogo_import_normalize_header($label);
    if ($s === '') {
        return '';
    }

    static $aliases = [
        'tipo'            => 'tipo',
        'nome'            => 'nome',
        'codigo'          => 'codigo',
        'id'              => 'codigo',
        'unidade'         => 'unidade',
        'un'              => 'unidade',
        'valor unitario'  => 'valor_unitario',
        'valor unit'      => 'valor_unitario',
        'valor unitario r' => 'valor_unitario',
        'valor_unitario'  => 'valor_unitario',
        'preco unitario'  => 'valor_unitario',
        'estoque saldo'      => 'estoque_saldo',
        'estoque_saldo'      => 'estoque_saldo',
        'saldo'              => 'estoque_saldo',
        'estoque capacidade' => 'estoque_capacidade',
        'estoque_capacidade' => 'estoque_capacidade',
        'estoque'            => 'estoque_capacidade',
        'descricao'          => 'descricao',
        'descricao simplificada' => 'descricao_simplificada',
        'descricao_simplificada' => 'descricao_simplificada',
    ];

    if (isset($aliases[$s])) {
        return $aliases[$s];
    }
    if (str_starts_with($s, 'valor unit')) {
        return 'valor_unitario';
    }

    return '';
}

/**
 * @param list<string> $cells
 * @param array<string, int> $map
 * @return array{estoque_capacidade: float, estoque_saldo: float, saldo_informado: bool}
 */
function catalogo_import_row_stock_fields(array $cells, array $map): array
{
    $hasCapCol   = isset($map['estoque_capacidade']);
    $hasSaldoCol = isset($map['estoque_saldo']);
    $capRaw      = catalogo_import_cell($cells, $map, 'estoque_capacidade');
    $saldoRaw    = catalogo_import_cell($cells, $map, 'estoque_saldo');
    $capVal      = catalogo_import_parse_decimal_cell($capRaw);
    $saldoVal    = catalogo_import_parse_decimal_cell($saldoRaw);

    if ($hasCapCol && $capRaw !== '') {
        $estCap = $capVal ?? 0.0;
    } elseif (!$hasCapCol && $hasSaldoCol && $saldoRaw !== '') {
        $estCap = $saldoVal ?? 0.0;
    } else {
        $estCap = $capVal ?? 0.0;
    }

    $saldoInformado = $hasSaldoCol && $saldoRaw !== '';
    $estSaldo       = $saldoInformado ? ($saldoVal ?? 0.0) : $estCap;

    return [
        'estoque_capacidade' => $estCap,
        'estoque_saldo'      => $estSaldo,
        'saldo_informado'    => $saldoInformado,
    ];
}

function catalogo_import_parse_decimal_cell(string $raw): ?float
{
    $raw = trim(str_replace(',', '.', $raw));
    if ($raw === '') {
        return null;
    }

    return (float) $raw;
}

function catalogo_import_normalize_header(string $label): string
{
    $s = mb_strtolower(trim($label), 'UTF-8');
    $s = str_replace(['á', 'à', 'ã', 'â', 'ä'], 'a', $s);
    $s = str_replace(['é', 'è', 'ê', 'ë'], 'e', $s);
    $s = str_replace(['í', 'ì', 'î', 'ï'], 'i', $s);
    $s = str_replace(['ó', 'ò', 'õ', 'ô', 'ö'], 'o', $s);
    $s = str_replace(['ú', 'ù', 'û', 'ü'], 'u', $s);
    $s = str_replace('ç', 'c', $s);
    $s = preg_replace('/[^a-z0-9]+/u', ' ', $s) ?? $s;
    $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);

    return $s;
}

/**
 * @param list<string> $cells
 * @param array<string, int> $map
 */
function catalogo_import_cell(array $cells, array $map, string $field): string
{
    if (!isset($map[$field])) {
        return '';
    }
    $idx = $map[$field];

    return trim((string) ($cells[$idx] ?? ''));
}

/**
 * @param list<string> $cells
 */
function catalogo_import_row_is_empty(array $cells): bool
{
    foreach ($cells as $c) {
        if (trim((string) $c) !== '') {
            return false;
        }
    }

    return true;
}

function catalogo_import_normalize_tipo(string $raw): string
{
    $s = catalogo_import_normalize_header($raw);
    if ($s === 'produto' || $s === 'product' || $s === 'material') {
        return 'produto';
    }
    if ($s === 'servico' || $s === 'serviço' || $s === 'service' || $s === 'serv') {
        return 'servico';
    }

    return '';
}

/**
 * CSV legado sem cabeçalho: tipo, nome, codigo, unidade, valor, estoque, saldo, descricao.
 *
 * @param list<list<string>> $rows
 * @return array{ok: bool, erro: string, linhas: list<array<string, mixed>>, avisos: list<string>}
 */
function catalogo_import_rows_positional(array $rows): array
{
    $out    = [];
    $avisos = [];
    foreach ($rows as $i => $cells) {
        if (catalogo_import_row_is_empty($cells)) {
            continue;
        }
        $tipo = catalogo_import_normalize_tipo(trim((string) ($cells[0] ?? '')));
        $nome = trim((string) ($cells[1] ?? ''));
        if ($tipo === '' || $nome === '') {
            continue;
        }
        $capRaw   = trim((string) ($cells[5] ?? ''));
        $saldoRaw = trim((string) ($cells[6] ?? ''));
        $estCap   = catalogo_import_parse_decimal_cell($capRaw) ?? 0.0;
        $saldoInformado = $saldoRaw !== '';
        $estSaldo       = $saldoInformado ? (catalogo_import_parse_decimal_cell($saldoRaw) ?? 0.0) : $estCap;
        $out[] = [
            'tipo'               => $tipo,
            'nome'               => $nome,
            'codigo'             => trim((string) ($cells[2] ?? '')),
            'unidade'            => trim((string) ($cells[3] ?? 'UN')) !== '' ? trim((string) ($cells[3] ?? 'UN')) : 'UN',
            'valor_unitario'     => (float) str_replace(',', '.', trim((string) ($cells[4] ?? '0'))),
            'estoque_capacidade' => $estCap,
            'estoque_saldo'      => $estSaldo,
            'saldo_informado'    => $saldoInformado,
            'descricao'          => trim((string) ($cells[7] ?? '')),
            '_linha_plan'        => $i + 1,
        ];
    }
    if ($out === []) {
        return [
            'ok'   => false,
            'erro' => 'Cabeçalho não encontrado. Use as colunas Tipo, Nome, Código, Unidade, Valor unit. (R$), Estoque, Saldo e Descrição (como no modelo XLSX).',
            'linhas' => [],
            'avisos' => [],
        ];
    }

    return ['ok' => true, 'erro' => '', 'linhas' => $out, 'avisos' => $avisos];
}
