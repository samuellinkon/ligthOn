<?php
declare(strict_types=1);

/**
 * Importador para planilhas de cadastro georreferenciado de iluminação pública.
 *
 * @return array{ok:bool,erro:string,linhas:list<array<string,mixed>>,avisos:list<string>}
 */
function pontos_iluminacao_import_parse_upload(string $path, string $nomeOriginal): array
{
    $ext = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
    if ($ext === 'xlsx') {
        return pontos_iluminacao_import_parse_xlsx($path);
    }
    if ($ext === 'csv' || $ext === 'txt') {
        return pontos_iluminacao_import_parse_csv($path);
    }

    return ['ok' => false, 'erro' => 'Formato não suportado. Envie .xlsx, .csv ou .txt.', 'linhas' => [], 'avisos' => []];
}

/**
 * @return array{ok:bool,erro:string,linhas:list<array<string,mixed>>,avisos:list<string>}
 */
function pontos_iluminacao_import_parse_xlsx(string $path): array
{
    if (!is_readable($path)) {
        return ['ok' => false, 'erro' => 'Arquivo ilegível.', 'linhas' => [], 'avisos' => []];
    }
    if (!class_exists(ZipArchive::class)) {
        return [
            'ok' => false,
            'erro' => 'Importação .xlsx requer a extensão PHP zip (ZipArchive). Ative extension=zip no php.ini ou envie CSV UTF-8.',
            'linhas' => [],
            'avisos' => [],
        ];
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return ['ok' => false, 'erro' => 'Não foi possível abrir o .xlsx. Verifique se o arquivo não está corrompido.', 'linhas' => [], 'avisos' => []];
    }

    @ini_set('memory_limit', '512M');
    $stringsXml = $zip->getFromName('xl/sharedStrings.xml');
    $strings = ($stringsXml !== false && $stringsXml !== '') ? pontos_import_xlsx_shared_strings($stringsXml) : [];
    $sheetPaths = pontos_import_xlsx_worksheet_paths($zip);
    if ($sheetPaths === []) {
        $zip->close();
        return ['ok' => false, 'erro' => 'Nenhuma aba encontrada no .xlsx.', 'linhas' => [], 'avisos' => []];
    }

    $ultimoErro = 'Cabeçalho não encontrado. Use o modelo com colunas Código, Latitude e Longitude (Barramento e características do poste são opcionais).';
    $ultimoAvisos = [];
    foreach ($sheetPaths as $sheetPath) {
        $sheetXml = $zip->getFromName($sheetPath);
        if ($sheetXml === false || $sheetXml === '') {
            continue;
        }
        $parsed = pontos_iluminacao_import_rows_to_linhas(pontos_import_xlsx_sheet_rows($sheetXml, $strings));
        if ($parsed['ok']) {
            $zip->close();

            return $parsed;
        }
        if (($parsed['erro'] ?? '') !== '') {
            $ultimoErro = (string) $parsed['erro'];
        }
        if (!empty($parsed['avisos']) && is_array($parsed['avisos'])) {
            $ultimoAvisos = $parsed['avisos'];
        }
    }
    $zip->close();

    return ['ok' => false, 'erro' => $ultimoErro, 'linhas' => [], 'avisos' => $ultimoAvisos];
}

/**
 * @return array{ok:bool,erro:string,linhas:list<array<string,mixed>>,avisos:list<string>}
 */
function pontos_iluminacao_import_parse_csv(string $path): array
{
    $raw = file_get_contents($path);
    if ($raw === false) {
        return ['ok' => false, 'erro' => 'Não foi possível ler o arquivo.', 'linhas' => [], 'avisos' => []];
    }
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $lines = preg_split('/\r\n|\n|\r/', (string) $raw) ?: [];
    $delim = substr_count($lines[0] ?? '', ';') >= substr_count($lines[0] ?? '', ',') ? ';' : ',';
    $rows = [];
    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $rows[] = str_getcsv($line, $delim);
    }

    return pontos_iluminacao_import_rows_to_linhas($rows);
}

/**
 * @param list<list<string>> $rows
 * @return array{ok:bool,erro:string,linhas:list<array<string,mixed>>,avisos:list<string>}
 */
function pontos_iluminacao_import_rows_to_linhas(array $rows): array
{
    $headerIdx = null;
    $map = [];
    foreach ($rows as $i => $row) {
        $tmp = pontos_import_header_map($row);
        if (isset($tmp['codigo'], $tmp['latitude'], $tmp['longitude'])) {
            $headerIdx = $i;
            $map = $tmp;
            break;
        }
    }
    if ($headerIdx === null) {
        return [
            'ok' => false,
            'erro' => 'Cabeçalho não encontrado. Use o modelo com colunas Código, Latitude e Longitude (Barramento e características do poste são opcionais).',
            'linhas' => [],
            'avisos' => [],
        ];
    }

    $porCodigo = [];
    $avisos = [];
    $nExtras = 0;
    for ($i = $headerIdx + 1, $n = count($rows); $i < $n; $i++) {
        $row = $rows[$i];
        $codigo = pontos_import_cell($row, $map, 'codigo');
        if ($codigo === '') {
            continue;
        }

        $referencia = pontos_import_cell($row, $map, 'referencia');
        $lat = pontos_import_decimal(pontos_import_cell($row, $map, 'latitude'));
        $lng = pontos_import_decimal(pontos_import_cell($row, $map, 'longitude'));
        $refComoLat = pontos_import_decimal($referencia);
        if ($lat === null && $refComoLat !== null && $refComoLat >= -90 && $refComoLat <= 90) {
            $lat = $refComoLat;
            $referencia = '';
        }
        if ($lat === null || $lng === null) {
            $avisos[] = 'Linha ' . ($i + 1) . ': poste ' . $codigo . ' sem latitude/longitude válida.';
        }

        $tipoLogradouro = pontos_import_cell($row, $map, 'tipo_logradouro');
        $nomeLogradouro = pontos_import_cell($row, $map, 'nome_logradouro');
        $bairro = pontos_import_cell($row, $map, 'bairro');
        $localidade = pontos_import_cell($row, $map, 'localidade');
        $endereco = pontos_import_montar_endereco($tipoLogradouro, $nomeLogradouro, $bairro, $localidade);
        $barramento = pontos_import_cell($row, $map, 'barramento');
        $obsEquip = pontos_import_obs_equipamento($row, $map);

        if (isset($porCodigo[$codigo])) {
            if ($obsEquip !== '') {
                $porCodigo[$codigo]['observacoes'] = pontos_import_observacoes([
                    (string) ($porCodigo[$codigo]['observacoes'] ?? ''),
                    'Luminária extra: ' . $obsEquip,
                ]);
            }
            if (($porCodigo[$codigo]['latitude'] ?? null) === null && $lat !== null) {
                $porCodigo[$codigo]['latitude'] = $lat;
            }
            if (($porCodigo[$codigo]['longitude'] ?? null) === null && $lng !== null) {
                $porCodigo[$codigo]['longitude'] = $lng;
            }
            if (trim((string) ($porCodigo[$codigo]['identificador_externo'] ?? '')) === '' && $barramento !== '') {
                $porCodigo[$codigo]['identificador_externo'] = $barramento;
            }
            if (trim((string) ($porCodigo[$codigo]['endereco_completo'] ?? '')) === '' && $endereco !== '') {
                $porCodigo[$codigo]['endereco_completo'] = $endereco;
            }
            if (trim((string) ($porCodigo[$codigo]['bairro'] ?? '')) === '' && $bairro !== '') {
                $porCodigo[$codigo]['bairro'] = $bairro;
            }
            $nExtras++;
            continue;
        }

        $porCodigo[$codigo] = [
            'codigo_poste' => $codigo,
            'identificador_externo' => $barramento,
            'endereco_completo' => $endereco,
            'bairro' => $bairro,
            'referencia' => $referencia,
            'latitude' => $lat,
            'longitude' => $lng,
            'status' => 'Ativo',
            'observacoes' => pontos_import_observacoes([
                'Importado da planilha de iluminação',
                pontos_import_obs_item('Data', pontos_import_formatar_data_excel(pontos_import_cell($row, $map, 'data'))),
                pontos_import_obs_item('Registro 1', pontos_import_cell($row, $map, 'registro1')),
                pontos_import_obs_item('Registro 2', pontos_import_cell($row, $map, 'registro2')),
                pontos_import_obs_item('Foto 1', pontos_import_cell($row, $map, 'foto1')),
                pontos_import_obs_item('Foto 2', pontos_import_cell($row, $map, 'foto2')),
                pontos_import_obs_item('Tipo de consumo', pontos_import_cell($row, $map, 'tipo_consumo')),
                pontos_import_obs_item('Propriedade', pontos_import_cell($row, $map, 'propriedade')),
                pontos_import_obs_item('ID/Pontos luminosos', pontos_import_cell($row, $map, 'id_pontos_luminosos')),
                $obsEquip,
            ]),
        ];
    }

    if ($nExtras > 0) {
        $avisos[] = $nExtras . ' linha(s) com o mesmo Código foram agrupadas no mesmo poste (luminária extra nas observações).';
    }

    $linhas = array_values($porCodigo);
    if (!$linhas) {
        return ['ok' => false, 'erro' => 'Nenhuma linha válida encontrada para importação.', 'linhas' => [], 'avisos' => $avisos];
    }

    return ['ok' => true, 'erro' => '', 'linhas' => $linhas, 'avisos' => $avisos];
}

function pontos_import_header_map(array $row): array
{
    $map = [];
    foreach ($row as $idx => $cell) {
        $h = pontos_import_norm_header((string) $cell);
        if ($h === '') {
            continue;
        }
        if ($h === 'codigo') $map['codigo'] = $idx;
        elseif ($h === 'data') $map['data'] = $idx;
        elseif ($h === 'registro1') $map['registro1'] = $idx;
        elseif ($h === 'registro2') $map['registro2'] = $idx;
        elseif ($h === 'foto1') $map['foto1'] = $idx;
        elseif ($h === 'foto2') $map['foto2'] = $idx;
        elseif ($h === 'barramento') $map['barramento'] = $idx;
        elseif ($h === 'referencia') $map['referencia'] = $idx;
        elseif ($h === 'latitude' || $h === 'lat') $map['latitude'] = $idx;
        elseif ($h === 'longitude' || $h === 'long' || $h === 'lng' || $h === 'lon') $map['longitude'] = $idx;
        elseif ($h === 'localidade') $map['localidade'] = $idx;
        elseif ($h === 'tipodelogradourodaluminaria') $map['tipo_logradouro'] = $idx;
        elseif ($h === 'nomedologradourodaluminaria') $map['nome_logradouro'] = $idx;
        elseif ($h === 'bairro') $map['bairro'] = $idx;
        elseif ($h === 'tipodeconsumo') $map['tipo_consumo'] = $idx;
        elseif ($h === 'propriedade') $map['propriedade'] = $idx;
        elseif ($h === 'idpontosluminosos') $map['id_pontos_luminosos'] = $idx;
        elseif ($h === 'quantidadedeluminarias') $map['qtd_luminarias'] = $idx;
        elseif ($h === 'quantidadedelampadasdaluminaria') $map['qtd_lampadas'] = $idx;
        elseif ($h === 'tecnologiadaluminaria' || $h === 'tecnologia') $map['tecnologia'] = $idx;
        elseif ($h === 'potenciadaluminaria' || $h === 'potencia') $map['potencia'] = $idx;
        elseif (str_starts_with($h, 'comprimentodobraco') || $h === 'comprimentodobracextensor') $map['braco'] = $idx;
        elseif ($h === 'tipodeposte') $map['tipo_poste'] = $idx;
        elseif ($h === 'materialdoposte') $map['material_poste'] = $idx;
        elseif (str_starts_with($h, 'alturautil')) $map['altura_util'] = $idx;
    }

    return $map;
}

function pontos_import_norm_header(string $s): string
{
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (function_exists('mb_strtolower')) {
        $s = mb_strtolower($s, 'UTF-8');
    } else {
        $s = strtolower($s);
    }
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($ascii !== false) {
        $s = $ascii;
    }
    return preg_replace('/[^a-z0-9]+/', '', strtolower($s)) ?: '';
}

function pontos_import_cell(array $row, array $map, string $key): string
{
    if (!isset($map[$key])) {
        return '';
    }
    $v = $row[(int) $map[$key]] ?? '';
    if ($v instanceof DateTimeInterface) {
        return $v->format('Y-m-d H:i:s');
    }
    return trim((string) $v);
}

function pontos_import_decimal(string $s): ?float
{
    $s = trim(str_replace(',', '.', $s));
    if ($s === '' || !is_numeric($s)) {
        return null;
    }
    return (float) $s;
}

function pontos_import_formatar_data_excel(string $s): string
{
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    if (is_numeric($s)) {
        $serial = (float) $s;
        if ($serial > 20000 && $serial < 80000) {
            $timestamp = ((int) floor($serial) - 25569) * 86400;
            return gmdate('Y-m-d', $timestamp);
        }
    }
    return $s;
}

function pontos_import_montar_endereco(string $tipo, string $nome, string $bairro, string $localidade): string
{
    $tipo = trim($tipo);
    $nome = trim($nome);
    $partes = [];
    if ($nome !== '') {
        $prefix = $tipo !== '' && stripos($nome, $tipo . ' ') !== 0 ? ($tipo . ' ') : '';
        $partes[] = trim($prefix . $nome);
    }
    if ($bairro !== '') {
        $partes[] = $bairro;
    }
    if ($localidade !== '') {
        $partes[] = $localidade;
    }
    return implode(' - ', array_filter($partes, static fn ($v) => trim((string) $v) !== ''));
}

function pontos_import_obs_item(string $label, string $value): string
{
    $value = trim($value);
    return $value !== '' ? ($label . ': ' . $value) : '';
}

/**
 * Características do equipamento (luminária / poste) para o campo observações.
 *
 * @param array<int, mixed> $row
 * @param array<string, int> $map
 */
function pontos_import_obs_equipamento(array $row, array $map): string
{
    return pontos_import_observacoes([
        pontos_import_obs_item('Qtd luminárias', pontos_import_cell($row, $map, 'qtd_luminarias')),
        pontos_import_obs_item('Qtd lâmpadas', pontos_import_cell($row, $map, 'qtd_lampadas')),
        pontos_import_obs_item('Tecnologia', pontos_import_cell($row, $map, 'tecnologia')),
        pontos_import_obs_item('Potência', pontos_import_cell($row, $map, 'potencia')),
        pontos_import_obs_item('Comprimento do braço', pontos_import_cell($row, $map, 'braco')),
        pontos_import_obs_item('Tipo de poste', pontos_import_cell($row, $map, 'tipo_poste')),
        pontos_import_obs_item('Material do poste', pontos_import_cell($row, $map, 'material_poste')),
        pontos_import_obs_item('Altura útil', pontos_import_cell($row, $map, 'altura_util')),
    ]);
}

function pontos_import_observacoes(array $parts): string
{
    $parts = array_values(array_filter(array_map('trim', $parts), static fn ($v) => $v !== ''));
    return implode(' | ', $parts);
}

function pontos_import_xlsx_shared_strings(string $xml): array
{
    $out = [];
    if (!preg_match_all('/<si\b[^>]*>(.*?)<\/si>/su', $xml, $items)) {
        return $out;
    }
    foreach ($items[1] as $siXml) {
        $buf = '';
        if (preg_match_all('/<t\b[^>]*>(.*?)<\/t>/su', $siXml, $texts)) {
            foreach ($texts[1] as $t) {
                $buf .= html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
        $out[] = $buf;
    }
    return $out;
}

function pontos_import_xlsx_rel_target_to_path(string $target): string
{
    $target = str_replace('\\', '/', trim($target));
    if (strpos($target, '/xl/') === 0) {
        return ltrim($target, '/');
    }
    if (strpos($target, 'xl/') === 0) {
        return $target;
    }

    return 'xl/' . ltrim($target, '/');
}

/**
 * Abas na ordem do workbook (a primeira costuma ser o cadastro).
 *
 * @return list<string>
 */
function pontos_import_xlsx_worksheet_paths(ZipArchive $zip): array
{
    $ridToPath = [];
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($rels !== false && preg_match_all('/<Relationship\s([^>]+)\/>/u', $rels, $blocks)) {
        foreach ($blocks[1] as $attr) {
            $type = preg_match('/Type="([^"]*)"/u', $attr, $m) ? $m[1] : '';
            $id = preg_match('/Id="([^"]*)"/u', $attr, $m) ? $m[1] : '';
            $target = preg_match('/Target="([^"]*)"/u', $attr, $m) ? $m[1] : '';
            if ($id === '' || $target === '' || strpos($type, 'worksheet') === false) {
                continue;
            }
            $path = pontos_import_xlsx_rel_target_to_path($target);
            if ($zip->locateName($path) !== false) {
                $ridToPath[$id] = $path;
            }
        }
    }

    $paths = [];
    $seen = [];
    $wb = $zip->getFromName('xl/workbook.xml');
    if (is_string($wb) && $wb !== '' && preg_match_all('/<sheet\b([^>]+)\/>/u', $wb, $sheets)) {
        foreach ($sheets[1] as $attr) {
            $rid = '';
            if (preg_match('/(?:r:id|r:Id)="([^"]+)"/u', $attr, $m)) {
                $rid = $m[1];
            }
            if ($rid !== '' && isset($ridToPath[$rid]) && !isset($seen[$ridToPath[$rid]])) {
                $paths[] = $ridToPath[$rid];
                $seen[$ridToPath[$rid]] = true;
            }
        }
    }
    foreach ($ridToPath as $path) {
        if (!isset($seen[$path])) {
            $paths[] = $path;
            $seen[$path] = true;
        }
    }
    if ($paths === [] && $zip->locateName('xl/worksheets/sheet1.xml') !== false) {
        $paths[] = 'xl/worksheets/sheet1.xml';
    }

    return $paths;
}

/** @deprecated Use pontos_import_xlsx_worksheet_paths */
function pontos_import_xlsx_first_sheet_path(ZipArchive $zip): ?string
{
    $paths = pontos_import_xlsx_worksheet_paths($zip);

    return $paths[0] ?? null;
}

/**
 * @return list<list<string>>
 */
function pontos_import_xlsx_sheet_rows(string $sheetXml, array $strings): array
{
    if ($sheetXml === '') {
        return [];
    }
    if (class_exists(XMLReader::class)) {
        $fromReader = pontos_import_xlsx_sheet_rows_xmlreader($sheetXml, $strings);
        if ($fromReader !== []) {
            return $fromReader;
        }
    }

    return pontos_import_xlsx_sheet_rows_regex($sheetXml, $strings);
}

/**
 * @return list<list<string>>
 */
function pontos_import_xlsx_sheet_rows_xmlreader(string $sheetXml, array $strings): array
{
    $reader = new XMLReader();
    if (!$reader->XML($sheetXml, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT)) {
        return [];
    }
    $grid = [];
    $lastColByRow = [];
    $seq = 0;
    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
            continue;
        }
        if ($reader->isEmptyElement) {
            continue;
        }
        $rowNum = (int) $reader->getAttribute('r');
        if ($rowNum <= 0) {
            $rowNum = ++$seq;
        }
        $rowXml = $reader->readOuterXML();
        pontos_import_xlsx_collect_row_cells($rowXml, $strings, $rowNum, $grid, $lastColByRow);
    }
    $reader->close();

    return pontos_import_xlsx_grid_to_rows($grid, $lastColByRow);
}

/**
 * @return list<list<string>>
 */
function pontos_import_xlsx_sheet_rows_regex(string $sheetXml, array $strings): array
{
    $grid = [];
    $lastColByRow = [];
    $offset = 0;
    $len = strlen($sheetXml);
    $seq = 0;
    while ($offset < $len && preg_match('/<row\b([^>]*)>(.*?)<\/row>/su', $sheetXml, $rowMatch, PREG_OFFSET_CAPTURE, $offset)) {
        $rowAttr = $rowMatch[1][0] ?? '';
        $rowInner = $rowMatch[2][0] ?? '';
        $offset = (int) $rowMatch[0][1] + strlen($rowMatch[0][0]);
        $rowNum = preg_match('/\br="(\d+)"/u', $rowAttr, $mRow) ? (int) $mRow[1] : 0;
        if ($rowNum <= 0) {
            $rowNum = ++$seq;
        }
        pontos_import_xlsx_collect_row_cells('<row' . $rowAttr . '>' . $rowInner . '</row>', $strings, $rowNum, $grid, $lastColByRow);
    }

    return pontos_import_xlsx_grid_to_rows($grid, $lastColByRow);
}

/**
 * @param array<int, array<int, string>> $grid
 * @param array<int, int> $lastColByRow
 */
function pontos_import_xlsx_collect_row_cells(
    string $rowXml,
    array $strings,
    int $rowNum,
    array &$grid,
    array &$lastColByRow
): void {
    if (!preg_match_all('/<c\b([^>]*)>(.*?)<\/c>/su', $rowXml, $cells, PREG_SET_ORDER)) {
        return;
    }
    foreach ($cells as $cellMatch) {
        $cellAttr = $cellMatch[1] ?? '';
        $cellXml = $cellMatch[2] ?? '';
        $ref = preg_match('/\br="([^"]+)"/u', $cellAttr, $mRef) ? $mRef[1] : '';
        if (!preg_match('/^([A-Z]+)(\d+)$/i', $ref, $m)) {
            continue;
        }
        $col = pontos_import_xlsx_col_index($m[1]);
        if ($col < 0 || $col > 80) {
            continue;
        }
        $t = preg_match('/\bt="([^"]+)"/u', $cellAttr, $mType) ? $mType[1] : '';
        $val = pontos_import_xlsx_cell_value_fast($cellXml, $t, $strings);
        if ($val === '') {
            continue;
        }
        $grid[$rowNum][$col] = $val;
        $lastColByRow[$rowNum] = max($lastColByRow[$rowNum] ?? 0, $col);
    }
}

/**
 * @param array<int, array<int, string>> $grid
 * @param array<int, int> $lastColByRow
 * @return list<list<string>>
 */
function pontos_import_xlsx_grid_to_rows(array $grid, array $lastColByRow): array
{
    if ($grid === []) {
        return [];
    }
    ksort($grid);
    $out = [];
    foreach ($grid as $rnum => $cols) {
        $last = min(80, $lastColByRow[$rnum] ?? (count($cols) - 1));
        $row = [];
        $has = false;
        for ($i = 0; $i <= $last; $i++) {
            $cell = (string) ($cols[$i] ?? '');
            if ($cell !== '') {
                $has = true;
            }
            $row[] = $cell;
        }
        if ($has) {
            $out[] = $row;
        }
    }

    return $out;
}

function pontos_import_xlsx_col_index(string $letters): int
{
    $letters = strtoupper($letters);
    $n = 0;
    for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return $n - 1;
}

function pontos_import_xlsx_cell_value_fast(string $cellXml, string $type, array $strings): string
{
    if ($type === 'inlineStr') {
        $buf = '';
        if (preg_match_all('/<t\b[^>]*>(.*?)<\/t>/su', $cellXml, $texts)) {
            foreach ($texts[1] as $t) {
                $buf .= html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
        return $buf;
    }
    $raw = preg_match('/<v\b[^>]*>(.*?)<\/v>/su', $cellXml, $m) ? html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_XML1, 'UTF-8') : '';
    if ($type === 's') {
        return (string) ($strings[(int) $raw] ?? '');
    }
    return $raw;
}
