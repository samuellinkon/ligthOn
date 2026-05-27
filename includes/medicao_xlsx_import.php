<?php
declare(strict_types=1);

require_once __DIR__ . '/medicao_csv_import.php';

/**
 * Lê .xlsx (Office Open XML), escolhe a aba de relatório/matriz e delega ao parser BM.
 *
 * @return array{
 *   ok: bool,
 *   erro: string,
 *   idx_qtd_medido: int|null,
 *   idx_valor_medido: int|null,
 *   linhas: list<array<string, mixed>>
 * }
 */
function medicao_xlsx_parse_bm_upload(string $path, ?string $refYm = null, ?string $preferSheet = null): array
{
    $empty = [
        'ok'                 => false,
        'erro'               => '',
        'idx_qtd_medido'     => null,
        'idx_valor_medido'   => null,
        'linhas'             => [],
    ];

    if (!is_readable($path)) {
        return array_merge($empty, ['erro' => 'Arquivo ilegível.']);
    }

    if (!class_exists(ZipArchive::class)) {
        return array_merge($empty, [
            'erro' => 'O ficheiro .xlsx do Excel é, por norma técnica, um arquivo ZIP com documentos XML lá dentro. '
                . 'O importador usa a extensão PHP «zip» (classe ZipArchive) só para ler esse conteúdo — não estás a «comprimir» nada manualmente. '
                . 'No XAMPP: em C:\\xampp\\php\\php.ini descomente ou confirme a linha «extension=zip», guarde o ficheiro e reinicie o Apache. '
                . 'Alternativa: no Excel use «Guardar como» → CSV UTF-8 e envie o .csv.',
        ]);
    }

    $zip = new ZipArchive();
    $open = $zip->open($path);
    if ($open !== true) {
        $det = is_int($open) ? medicao_xlsx_zip_open_error_pt($open) : 'Abertura do arquivo falhou. ';
        return array_merge($empty, [
            'erro' => 'Não foi possível ler o .xlsx como pacote ZIP (formato interno do Excel). ' . $det
                . ' Se gravou como «Excel 97-2004 (.xls)» ou o ficheiro está corrompido, guarde de novo como «Excel (.xlsx)» ou exporte CSV.',
        ]);
    }

    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    $strings = ($ssXml !== false && $ssXml !== '')
        ? medicao_xlsx_parse_shared_strings_xml($ssXml)
        : [];

    $sheetPath = medicao_xlsx_pick_worksheet_path($zip, $preferSheet);
    if ($sheetPath === null) {
        $zip->close();
        return array_merge($empty, ['erro' => 'Nenhuma folha de cálculo encontrada no .xlsx.']);
    }

    $sheetXml = $zip->getFromName($sheetPath);
    $zip->close();

    if ($sheetXml === false || $sheetXml === '') {
        return array_merge($empty, ['erro' => 'A folha escolhida está vazia ou ilegível no .xlsx.']);
    }

    $grid = medicao_xlsx_sheet_to_sparse_grid($sheetXml, $strings);
    $rows    = medicao_xlsx_grid_to_rows($grid);

    return medicao_parse_bm_from_rows($rows, $refYm);
}

/**
 * Lê arquivo enviado (.xlsx ou .csv) como matriz de linhas.
 *
 * @return array{ok:bool,erro:string,rows:list<list<string>>}
 */
function medicao_upload_file_to_rows(string $path, string $ext, ?string $preferSheet = null): array
{
    $empty = ['ok' => false, 'erro' => '', 'rows' => []];
    $ext   = strtolower($ext);
    if ($ext === 'xlsx') {
        if (!class_exists(ZipArchive::class)) {
            return array_merge($empty, ['erro' => 'Extensão PHP zip necessária para .xlsx.']);
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return array_merge($empty, ['erro' => 'Não foi possível abrir o .xlsx.']);
        }
        $strings = [];
        $ssXml   = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml !== false && $ssXml !== '') {
            $strings = medicao_xlsx_parse_shared_strings_xml($ssXml);
        }
        $sheetPath = medicao_xlsx_pick_worksheet_path($zip, $preferSheet);
        if ($sheetPath === null) {
            $zip->close();

            return array_merge($empty, ['erro' => 'Nenhuma folha encontrada.']);
        }
        $sheetXml = $zip->getFromName($sheetPath);
        $zip->close();
        if ($sheetXml === false || $sheetXml === '') {
            return array_merge($empty, ['erro' => 'Folha vazia.']);
        }
        $grid = medicao_xlsx_sheet_to_sparse_grid($sheetXml, $strings);

        return ['ok' => true, 'erro' => '', 'rows' => medicao_xlsx_grid_to_rows($grid)];
    }

    if ($ext === 'csv') {
        $parse = medicao_csv_parse_bm_planilha($path, null);
        if (!$parse['ok']) {
            return array_merge($empty, ['erro' => $parse['erro']]);
        }
        $full = file_get_contents($path);
        if ($full === false) {
            return array_merge($empty, ['erro' => 'CSV ilegível.']);
        }
        if (str_starts_with($full, "\xEF\xBB\xBF")) {
            $full = substr($full, 3);
        }
        $delimiter = medicao_csv_sniff_delimiter_best(substr($full, 0, min(524288, strlen($full))));
        $rows      = [];
        $fp        = fopen('php://memory', 'r+b');
        if ($fp === false) {
            return array_merge($empty, ['erro' => 'Memória indisponível.']);
        }
        fwrite($fp, $full);
        rewind($fp);
        while (($row = fgetcsv($fp, 0, $delimiter, '"', '\\')) !== false) {
            $rows[] = array_map(static fn ($c): string => is_string($c) ? trim(str_replace("\xc2\xa0", ' ', $c)) : '', $row);
        }
        fclose($fp);

        return ['ok' => true, 'erro' => '', 'rows' => $rows];
    }

    return array_merge($empty, ['erro' => 'Formato não suportado.']);
}

function medicao_xlsx_parse_shared_strings_xml(string $xml): array
{
    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml, LIBXML_NONET)) {
        return [];
    }
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $out = [];
    foreach ($dom->getElementsByTagNameNS($ns, 'si') as $si) {
        $buf = '';
        foreach ($si->getElementsByTagNameNS($ns, 't') as $t) {
            $buf .= $t->textContent;
        }
        $out[] = $buf;
    }
    return $out;
}

function medicao_xlsx_col_letters_to_index(string $letters): int
{
    $letters = strtoupper($letters);
    $n       = 0;
    $len     = strlen($letters);
    for ($i = 0; $i < $len; $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return $n - 1;
}

/**
 * @return array{0:int,1:int}|null [row 1-based, col 0-based]
 */
function medicao_xlsx_parse_cell_ref(string $ref): ?array
{
    if (!preg_match('/^([A-Z]+)(\d+)$/i', trim($ref), $m)) {
        return null;
    }
    return [(int) $m[2], medicao_xlsx_col_letters_to_index($m[1])];
}

function medicao_xlsx_normalize_part_target(string $target): string
{
    $target = str_replace('\\', '/', $target);
    if (str_starts_with($target, '/xl/')) {
        return substr($target, 1);
    }
    if (str_starts_with($target, 'xl/')) {
        return $target;
    }
    return 'xl/' . ltrim($target, '/');
}

/**
 * @return list<array{name:string,rid:string,hidden:bool}>
 */
function medicao_xlsx_parse_workbook_sheet_entries(string $wbXml): array
{
    $out = [];
    if (!preg_match_all('/<sheet\s([^>]+)\/>/u', $wbXml, $blocks)) {
        return $out;
    }
    foreach ($blocks[1] as $attr) {
        $name = preg_match('/name="([^"]*)"/u', $attr, $m)
            ? html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8')
            : '';
        $rid = preg_match('/r:id="([^"]*)"/u', $attr, $m) ? $m[1] : '';
        if ($rid === '') {
            continue;
        }
        $hidden = (bool) preg_match('/state="hidden"/u', $attr);
        $out[]  = ['name' => $name, 'rid' => $rid, 'hidden' => $hidden];
    }
    return $out;
}

/**
 * @return array<string,string> rId -> path inside zip (xl/worksheets/sheet1.xml)
 */
function medicao_xlsx_parse_workbook_rels(string $relsXml): array
{
    $map = [];
    if (!preg_match_all('/<Relationship\s([^>]+)\/>/u', $relsXml, $blocks)) {
        return $map;
    }
    foreach ($blocks[1] as $attr) {
        $id = preg_match('/Id="([^"]*)"/u', $attr, $m) ? $m[1] : '';
        if ($id === '') {
            continue;
        }
        $type   = preg_match('/Type="([^"]*)"/u', $attr, $m) ? $m[1] : '';
        $target = preg_match('/Target="([^"]*)"/u', $attr, $m) ? $m[1] : '';
        if ($target === '' || !str_contains($type, 'worksheet')) {
            continue;
        }
        $map[$id] = medicao_xlsx_normalize_part_target($target);
    }
    return $map;
}

function medicao_xlsx_sheet_name_score(string $name, bool $hidden): int
{
    if ($hidden) {
        return -500;
    }
    $u = mb_strtoupper($name, 'UTF-8');
    $uAscii = str_replace(['Ç', 'Ã', 'Õ', 'Á', 'É', 'Í', 'Ó', 'Ú', 'Â', 'Ê', 'Ô'], ['C', 'A', 'O', 'A', 'E', 'I', 'O', 'U', 'A', 'E', 'O'], $u);
    $isMedicaoBm = (str_contains($uAscii, 'MEDIC') || str_contains($u, 'MEDI'))
        && !str_contains($u, 'DETALHADO')
        && !str_contains($u, 'ESPELHO')
        && (str_contains($u, 'BM') || preg_match('/\d+/', $u));
    if ($isMedicaoBm) {
        return 250;
    }
    if (str_contains($u, 'MATRIZ') && !str_contains($u, 'DETALHADO')) {
        return 5;
    }
    if (str_contains($u, 'DETALHADO') && str_contains($u, 'BM')) {
        return 200;
    }
    if (str_contains($u, 'DETALHADO')) {
        return 150;
    }
    if (str_contains($u, 'RELAT') && str_contains($u, 'BM')) {
        return 120;
    }
    if (str_contains($u, 'RELAT')) {
        return 80;
    }
    return 10;
}

function medicao_xlsx_pick_worksheet_path(ZipArchive $zip, ?string $preferSheet = null): ?string
{
    $wb = $zip->getFromName('xl/workbook.xml');
    if ($wb === false || $wb === '') {
        return null;
    }
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($rels === false || $rels === '') {
        return null;
    }
    $ridToPath = medicao_xlsx_parse_workbook_rels($rels);
    $sheets    = medicao_xlsx_parse_workbook_sheet_entries($wb);
    if ($sheets === []) {
        return null;
    }

    $prefer = $preferSheet !== null && $preferSheet !== ''
        ? mb_strtoupper(trim($preferSheet), 'UTF-8')
        : null;

    $bestPath  = null;
    $bestScore = PHP_INT_MIN;
    foreach ($sheets as $sh) {
        $rid  = $sh['rid'];
        $path = $ridToPath[$rid] ?? null;
        if ($path === null || $zip->locateName($path) === false) {
            continue;
        }
        $sc = medicao_xlsx_sheet_name_score($sh['name'], $sh['hidden']);
        if ($prefer !== null) {
            $nameU = mb_strtoupper($sh['name'], 'UTF-8');
            if ($prefer === 'MEDICAO' || $prefer === 'MEDIÇÃO') {
                if (str_contains($nameU, 'MEDI') && !str_contains($nameU, 'DETALHADO')) {
                    $sc += 1000;
                }
            } elseif (str_contains($nameU, $prefer)) {
                $sc += 1000;
            }
        }
        if ($sc > $bestScore) {
            $bestScore = $sc;
            $bestPath  = $path;
        }
    }

    if ($bestPath !== null) {
        return $bestPath;
    }

    foreach ($sheets as $sh) {
        $path = $ridToPath[$sh['rid']] ?? null;
        if ($path !== null && $zip->locateName($path) !== false) {
            return $path;
        }
    }

    return null;
}

/**
 * @param array<int, array<int, string>> $grid row (1-based) => col (0-based) => value
 * @return list<list<string>>
 */
function medicao_xlsx_grid_to_rows(array $grid): array
{
    if ($grid === []) {
        return [];
    }
    $rows = array_keys($grid);
    sort($rows, SORT_NUMERIC);
    $minR = (int) $rows[0];
    $maxR = (int) end($rows);
    $maxC = 0;
    foreach ($grid as $cols) {
        if ($cols !== []) {
            $ks = array_keys($cols);
            sort($ks, SORT_NUMERIC);
            $maxC = max($maxC, (int) end($ks));
        }
    }

    $out = [];
    for ($r = $minR; $r <= $maxR; $r++) {
        $line = [];
        for ($c = 0; $c <= $maxC; $c++) {
            $line[] = (string) ($grid[$r][$c] ?? '');
        }
        $out[] = $line;
    }
    return $out;
}

/**
 * @param list<string> $strings
 * @return array<int, array<int, string>>
 */
function medicao_xlsx_sheet_to_sparse_grid(string $sheetXml, array $strings): array
{
    $dom = new DOMDocument();
    if (!@$dom->loadXML($sheetXml, LIBXML_NONET)) {
        return [];
    }
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    $grid = [];
    $sd   = $dom->getElementsByTagNameNS($ns, 'sheetData')->item(0);
    if (!$sd) {
        return [];
    }

    $lastColByRow = [];

    foreach ($sd->getElementsByTagNameNS($ns, 'row') as $rowEl) {
        $rAttr = $rowEl->getAttribute('r');
        $rowNum = $rAttr !== '' ? (int) $rAttr : 0;

        foreach ($rowEl->getElementsByTagNameNS($ns, 'c') as $cell) {
            $ref = $cell->getAttribute('r');
            if ($ref !== '') {
                $p = medicao_xlsx_parse_cell_ref($ref);
                if ($p === null) {
                    continue;
                }
                $rowNum   = $p[0];
                $colIndex = $p[1];
            } else {
                if ($rowNum <= 0) {
                    continue;
                }
                $colIndex = ($lastColByRow[$rowNum] ?? -1) + 1;
            }

            $lastColByRow[$rowNum] = $colIndex;
            $val                    = medicao_xlsx_cell_dom_value($cell, $strings, $ns);
            if (!isset($grid[$rowNum])) {
                $grid[$rowNum] = [];
            }
            $grid[$rowNum][$colIndex] = $val;
        }
    }

    return $grid;
}

function medicao_xlsx_cell_dom_value(DOMElement $c, array $strings, string $ns): string
{
    $t = $c->getAttribute('t');

    $vNodes = $c->getElementsByTagNameNS($ns, 'v');
    if ($vNodes->length > 0) {
        $v = $vNodes->item(0)->textContent;
        if ($t === 's') {
            return $strings[(int) $v] ?? '';
        }
        return $v;
    }

    if ($t === 'inlineStr') {
        $is = $c->getElementsByTagNameNS($ns, 'is')->item(0);
        if (!$is) {
            return '';
        }
        $buf = '';
        foreach ($is->getElementsByTagNameNS($ns, 't') as $tnode) {
            $buf .= $tnode->textContent;
        }
        if ($buf === '') {
            foreach ($is->getElementsByTagNameNS($ns, 'r') as $rEl) {
                foreach ($rEl->getElementsByTagNameNS($ns, 't') as $tnode) {
                    $buf .= $tnode->textContent;
                }
            }
        }
        return $buf;
    }

    return '';
}

/** @param int $code Código devolvido por ZipArchive::open em caso de falha */
function medicao_xlsx_zip_open_error_pt(int $code): string
{
    switch ($code) {
        case ZipArchive::ER_NOZIP:
            return 'O ficheiro não é um ZIP válido (por vezes: ficheiro .xls com extensão .xlsx, download incompleto ou ficheiro corrompido). ';
        case ZipArchive::ER_OPEN:
        case ZipArchive::ER_READ:
            return 'Não foi possível ler o ficheiro (permissões ou pasta temporária). ';
        case ZipArchive::ER_INVAL:
            return 'Formato inválido. ';
        case ZipArchive::ER_MEMORY:
            return 'Falta de memória ao abrir o arquivo. ';
        default:
            return '';
    }
}
