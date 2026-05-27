<?php
declare(strict_types=1);

require_once __DIR__ . '/medicao_helpers.php';
require_once __DIR__ . '/config.php';

/**
 * Exportação .xlsx no layout «relatório detalhado BM» com estilos nativos do Excel
 * (cores, bordas, larguras, cabeçalho fixo, zebrado — OOXML styles.xml, não CSS/HTML).
 */

/** @return list<string> */
function medicao_relatorio_detalhado_header_row(): array
{
    return [
        'DATA',
        'PROTOCOLO',
        'COORDENADA S',
        'COORDENADA W',
        'PLAQUETA / BARRAMENTO',
        'BAIRRO',
        'RUA - AVENIDA',
        'EQUIPE',
        'SERVIÇO (catálogo)',
        'VALOR SERVIÇOS (R$)',
        'VALOR MATERIAIS (R$)',
        'VALOR DEVOLVIDO (R$)',
        'PRIORIDADE',
        'STATUS',
        'CÓDIGO',
        'DESCRIÇÃO DOS ITENS',
        'QTD.',
        'VALOR UNITÁRIO (R$)',
        'VALOR LÍQUIDO (R$)',
    ];
}

/**
 * @param list<array<string,mixed>> $linhasChamados saída de repo_medicao_chamados_relatorio()['rows']
 * @param list<array<string,mixed>> $impLinhas      medicao_import_linhas
 * @return list<list<string>> linhas incluindo cabeçalho na linha 0
 */
function medicao_relatorio_detalhado_sheet_rows(array $linhasChamados, array $impLinhas, string $mesRef, int $matrizId = 0): array
{
    $out   = [];
    $out[] = medicao_relatorio_detalhado_header_row();

    $dataPadraoBm = '';
    if (preg_match('/^\d{4}-\d{2}$/', $mesRef)) {
        $dataPadraoBm = date('d/m/Y', strtotime(date('Y-m-t', strtotime($mesRef . '-01'))));
    }

    /** Mesma composição da tabela «Listagem dos chamados» em medicao_ver.php */
    $view = medicao_linhas_exibicao_mes($linhasChamados, $impLinhas);

    foreach ($view as $ch) {
        $id   = (int) ($ch['id'] ?? 0);
        $vTot = (float) ($ch['valor_total_linha'] ?? 0);

        if ($id > 0) {
            $qtd = 1.0;
            $vm  = (float) ($ch['valor_materiais'] ?? 0);
            $vs  = (float) ($ch['valor_servicos_itens'] ?? 0);
            $vd  = (float) ($ch['valor_devolvidos'] ?? 0);
            $vLiq = $vm + $vs;
            $vUni = $qtd > 0 ? $vLiq / $qtd : $vLiq;
            $lat = array_key_exists('latitude', $ch) && $ch['latitude'] !== null && $ch['latitude'] !== '' ? (float) $ch['latitude'] : null;
            $lng = array_key_exists('longitude', $ch) && $ch['longitude'] !== null && $ch['longitude'] !== '' ? (float) $ch['longitude'] : null;
            $plaqueta = trim((string) ($ch['ponto_codigo_poste'] ?? ''));
            $equipe   = trim((string) ($ch['tecnico_nome'] ?? ''));
            if ($equipe === '') {
                $equipe = trim((string) ($ch['unidade_nome'] ?? ''));
            }
            $out[] = [
                (string) ($ch['aberto_em_br'] ?? ''),
                (string) $id,
                medicao_relatorio_fmt_coord($lat),
                medicao_relatorio_fmt_coord($lng),
                $plaqueta,
                medicao_relatorio_bairro_linha($ch),
                medicao_relatorio_rua_linha($ch),
                $equipe,
                trim((string) ($ch['servico_principal_nome'] ?? '')),
                medicao_relatorio_fmt_numero_br($vs, 2),
                medicao_relatorio_fmt_numero_br($vm, 2),
                medicao_relatorio_fmt_numero_br($vd, 2),
                (string) ($ch['prioridade'] ?? ''),
                (string) ($ch['status'] ?? ''),
                medicao_relatorio_codigo_chamado($ch),
                (string) ($ch['titulo'] ?? ''),
                medicao_relatorio_fmt_numero_br($qtd, 4),
                medicao_relatorio_fmt_numero_br($vUni, 2),
                medicao_relatorio_fmt_numero_br($vLiq, 2),
            ];

            continue;
        }

        $cod  = trim((string) ($ch['medicao_bm_item_codigo'] ?? ''));
        $desc = trim((string) ($ch['titulo'] ?? ''));
        $qtd  = 1.0;
        $vUni = $vTot;
        $proto = $cod !== '' ? $cod : 'BM';
        $nomeSrv = trim((string) ($ch['servico_principal_nome'] ?? 'Medição (BM)'));
        $out[] = [
            $dataPadraoBm !== '' ? $dataPadraoBm : '',
            $proto,
            '',
            '',
            '',
            '',
            '',
            '—',
            $nomeSrv,
            medicao_relatorio_fmt_numero_br($vTot, 2),
            medicao_relatorio_fmt_numero_br(0.0, 2),
            medicao_relatorio_fmt_numero_br(0.0, 2),
            '—',
            (string) ($ch['status'] ?? 'Medição (BM)'),
            $cod,
            $desc !== '' ? $desc : ($cod !== '' ? 'Item ' . $cod : 'Linha importada'),
            medicao_relatorio_fmt_numero_br($qtd, 4),
            medicao_relatorio_fmt_numero_br($vUni, 2),
            medicao_relatorio_fmt_numero_br($vTot, 2),
        ];
    }

    if ($matrizId > 0 && function_exists('repo_medicao_custos_aprovados_bm')) {
        $dataPadraoCustos = $dataPadraoBm;
        foreach (repo_medicao_custos_aprovados_bm($matrizId, $mesRef) as $c) {
            $cod  = function_exists('medicao_custo_bm_codigo_exibir')
                ? medicao_custo_bm_codigo_exibir($c)
                : (string) ($c['item_codigo'] ?? '');
            $desc = trim((string) ($c['descricao'] ?? ''));
            $qtd  = (float) ($c['quantidade'] ?? 0);
            $vu   = (float) ($c['valor_unitario'] ?? 0);
            $vt   = (float) ($c['valor_total'] ?? 0);
            $out[] = [
                $dataPadraoCustos,
                'CUSTO-' . (int) ($c['id'] ?? 0),
                '',
                '',
                '',
                '',
                '',
                '—',
                'Custo adicional (aprovado)',
                medicao_relatorio_fmt_numero_br($vt, 2),
                medicao_relatorio_fmt_numero_br(0.0, 2),
                medicao_relatorio_fmt_numero_br(0.0, 2),
                '—',
                'Aprovado',
                $cod,
                $desc !== '' ? $desc : 'Custo adicional',
                medicao_relatorio_fmt_numero_br($qtd, 4),
                medicao_relatorio_fmt_numero_br($vu, 2),
                medicao_relatorio_fmt_numero_br($vt, 2),
            ];
        }
    }

    return $out;
}

function medicao_relatorio_primeira_linha(string $texto): string
{
    $t = trim(str_replace(["\r\n", "\r"], "\n", $texto));
    if ($t === '') {
        return '';
    }
    $parts = explode("\n", $t);

    return trim((string) ($parts[0] ?? ''));
}

/** @param array<string,mixed> $ch */
function medicao_relatorio_codigo_chamado(array $ch): string
{
    $sv = trim((string) ($ch['servico_principal_nome'] ?? ''));
    if (preg_match('/^([\d.]+)\b/u', $sv, $m)) {
        return $m[1];
    }
    $id = (int) ($ch['id'] ?? 0);

    return $id > 0 ? 'CH-' . $id : '';
}

function medicao_relatorio_fmt_numero_br(float $v, int $dec): string
{
    return number_format($v, $dec, ',', '.');
}

/** Latitude/longitude no formato BM (vírgula decimal). */
function medicao_relatorio_fmt_coord(?float $v): string
{
    if ($v === null) {
        return '';
    }
    $s = rtrim(rtrim(sprintf('%.7f', $v), '0'), '.');

    return str_replace('.', ',', $s);
}

function medicao_relatorio_rua_linha(array $ch): string
{
    $log = trim((string) ($ch['os_logradouro'] ?? ''));
    $num = trim((string) ($ch['os_numero'] ?? ''));
    if ($log !== '') {
        return $num !== '' ? ($log . ', ' . $num) : $log;
    }
    $end = trim(str_replace(["\r\n", "\r"], "\n", (string) ($ch['endereco_completo'] ?? '')));
    if ($end === '') {
        return '';
    }
    $linhas = explode("\n", $end);
    $primeira = trim((string) ($linhas[0] ?? ''));
    if ($primeira === '') {
        return '';
    }
    $partes = array_map('trim', explode(',', $primeira, 2));

    return trim((string) ($partes[0] ?? ''));
}

function medicao_relatorio_bairro_linha(array $ch): string
{
    $b = trim((string) ($ch['os_bairro'] ?? ''));
    if ($b !== '') {
        return $b;
    }
    $end = trim(str_replace(["\r\n", "\r"], "\n", (string) ($ch['endereco_completo'] ?? '')));
    if ($end === '') {
        return '';
    }
    $linhas = explode("\n", $end);
    $primeira = trim((string) ($linhas[0] ?? ''));
    if ($primeira === '') {
        return '';
    }
    $partes = array_map('trim', explode(',', $primeira, 2));
    if (count($partes) < 2) {
        return '';
    }
    $resto = trim((string) $partes[1]);
    if ($resto === '') {
        return '';
    }
    $segs = array_values(array_filter(array_map('trim', explode('-', $resto)), static function ($x) {
        return $x !== '';
    }));
    if ($segs === []) {
        return '';
    }
    $first = preg_replace('/\s+/', '', $segs[0]);
    if (isset($segs[1]) && ctype_digit($first)) {
        return $segs[1];
    }
    if (count($segs) >= 2 && preg_match('/^\d+$/', $first)) {
        return $segs[1];
    }

    return $segs[0];
}

/**
 * Logo operadora (APP_BRAND_LOGO) e logo prefeitura (config ou uploads/branding).
 *
 * @return array{empresa: ?string, prefeitura: ?string} caminhos absolutos legíveis
 */
function medicao_export_resolve_logo_paths(int $clienteMatrizId): array
{
    $root = dirname(__DIR__);
    $out  = ['empresa' => null, 'prefeitura' => null];

    $empresaCandidates = [];
    if (defined('MEDICAO_EXPORT_LOGO_EMPRESA') && is_string(MEDICAO_EXPORT_LOGO_EMPRESA) && MEDICAO_EXPORT_LOGO_EMPRESA !== '') {
        $empresaCandidates[] = $root . '/' . ltrim(MEDICAO_EXPORT_LOGO_EMPRESA, '/');
    }
    if (defined('APP_BRAND_LOGO')) {
        $empresaCandidates[] = $root . '/' . ltrim((string) APP_BRAND_LOGO, '/');
    }
    foreach ($empresaCandidates as $p) {
        if ($p !== '' && is_file($p) && is_readable($p)) {
            $out['empresa'] = $p;
            break;
        }
    }

    $prefCandidates = [];
    if (defined('MEDICAO_EXPORT_LOGO_PREFEITURA') && is_string(MEDICAO_EXPORT_LOGO_PREFEITURA) && trim(MEDICAO_EXPORT_LOGO_PREFEITURA) !== '') {
        $prefCandidates[] = $root . '/' . ltrim(trim(MEDICAO_EXPORT_LOGO_PREFEITURA), '/');
    }
    if ($clienteMatrizId > 0) {
        $prefCandidates[] = $root . '/uploads/branding/prefeitura_' . $clienteMatrizId . '.png';
        $prefCandidates[] = $root . '/uploads/branding/prefeitura_' . $clienteMatrizId . '.jpg';
        $prefCandidates[] = $root . '/uploads/branding/prefeitura_' . $clienteMatrizId . '.jpeg';
    }
    $prefCandidates[] = $root . '/uploads/branding/prefeitura.png';
    $prefCandidates[] = $root . '/uploads/branding/prefeitura.jpg';

    foreach ($prefCandidates as $p) {
        if ($p !== '' && is_file($p) && is_readable($p)) {
            $out['prefeitura'] = $p;
            break;
        }
    }

    return $out;
}

/**
 * @return array{0: string, 1: string, 2: string}|null binário, extensão sem ponto, mime
 */
function medicao_export_read_image_for_xlsx(string $path): ?array
{
    if (!is_readable($path)) {
        return null;
    }
    $bin = @file_get_contents($path);
    if ($bin === false || strlen($bin) < 24) {
        return null;
    }
    if (strncmp($bin, "\x89PNG\r\n\x1a\n", 8) === 0) {
        return [$bin, 'png', 'image/png'];
    }
    if (strncmp($bin, "\xff\xd8\xff", 3) === 0) {
        return [$bin, 'jpeg', 'image/jpeg'];
    }

    return null;
}

function medicao_xlsx_col_letters_from_zero(int $colIndex): string
{
    $letters = '';
    $n       = $colIndex + 1;
    while ($n > 0) {
        $n0      = $n - 1;
        $letters = chr(65 + ($n0 % 26)) . $letters;
        $n       = intdiv($n0, 26);
    }

    return $letters;
}

function medicao_xlsx_xml_text(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

/** Estilos de célula (cellXfs) — índices usados na folha */
const MEDICAO_XLSX_STYLE_NORMAL = 0;
const MEDICAO_XLSX_STYLE_HEADER = 1;
const MEDICAO_XLSX_STYLE_DATA_A = 2;
const MEDICAO_XLSX_STYLE_DATA_B = 3;
const MEDICAO_XLSX_STYLE_TITLE = 4;
const MEDICAO_XLSX_STYLE_SUBTITLE = 5;
const MEDICAO_XLSX_STYLE_HEADER_NUM = 6;
const MEDICAO_XLSX_STYLE_DATA_A_NUM = 7;
const MEDICAO_XLSX_STYLE_DATA_B_NUM = 8;

function medicao_xlsx_cell_inline(int $col0, int $row1, string $text, int $styleIdx): string
{
    $ref = medicao_xlsx_col_letters_from_zero($col0) . $row1;
    $esc = medicao_xlsx_xml_text($text);

    return '<c r="' . $ref . '" s="' . $styleIdx . '" t="inlineStr"><is><t>' . $esc . '</t></is></c>';
}

/**
 * Folha única: faixa para logos (linhas 1–3), título, período, cabeçalho BM e dados.
 *
 * @param list<list<string>> $sheetRows linha 0 = cabeçalho das colunas BM (fica na linha 7 do Excel)
 */
function medicao_xlsx_build_worksheet_xml(
    array $sheetRows,
    string $empresaNome,
    string $mesLabelPt,
    string $mesRef,
    bool $hasDrawing = false,
    ?string $periodoDe = null,
    ?string $periodoAte = null
): string {
    $ns          = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $empresaNome = trim($empresaNome);
    $mesLabelPt  = trim($mesLabelPt);
    $deEff       = $periodoDe !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($periodoDe)) ? trim($periodoDe) : '';
    $ateEff      = $periodoAte !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($periodoAte)) ? trim($periodoAte) : '';
    if ($deEff === '' && $ateEff === '' && preg_match('/^\d{4}-\d{2}$/', $mesRef)) {
        $resolved = medicao_resolve_periodo_filtro($mesRef, '', '');
        if ($resolved['ok']) {
            $deEff  = $resolved['de'];
            $ateEff = $resolved['ate'];
        }
    }
    $periodoTxt = ($deEff !== '' && $ateEff !== '')
        ? medicao_periodo_export_label($deEff, $ateEff, $mesRef)
        : ($mesLabelPt !== '' ? $mesLabelPt : '—');

    if ($sheetRows === []) {
        $sheetRows = [medicao_relatorio_detalhado_header_row()];
    }

    $nCols       = count($sheetRows[0]);
    $rowHeaderCols = 7;
    $lastRow       = 6 + count($sheetRows);
    $lastColLt     = medicao_xlsx_col_letters_from_zero($nCols - 1);
    $spanMax       = (string) max(1, $nCols);

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="' . $ns . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<dimension ref="A1:' . $lastColLt . $lastRow . '"/>'
        . '<sheetViews>'
        . '<sheetView tabSelected="1" workbookViewId="0">'
        . '<pane ySplit="' . $rowHeaderCols . '" topLeftCell="A' . ($rowHeaderCols + 1) . '" activePane="bottomLeft" state="frozen"/>'
        . '<selection pane="bottomLeft" activeCell="A' . ($rowHeaderCols + 1) . '" sqref="A' . ($rowHeaderCols + 1) . '"/>'
        . '</sheetView>'
        . '</sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>'
        . '<cols>';

    $widths = [11, 13, 11, 11, 16, 14, 26, 14, 28, 14, 14, 14, 11, 12, 12, 34, 10, 14, 14];
    for ($i = 1; $i <= $nCols; $i++) {
        $w = $widths[$i - 1] ?? 11;
        $xml .= '<col min="' . $i . '" max="' . $i . '" width="' . $w . '" customWidth="1"/>';
    }

    $xml .= '</cols><sheetData>';

    $xml .= '<row r="1" spans="1:' . $spanMax . '" ht="216" customHeight="1">'
        . medicao_xlsx_cell_inline(0, 1, ' ', MEDICAO_XLSX_STYLE_NORMAL)
        . '</row>';

    $xml .= '<row r="4" spans="1:' . $spanMax . '">'
        . medicao_xlsx_cell_inline(0, 4, 'RELATÓRIO DETALHADO BM — Medição', MEDICAO_XLSX_STYLE_TITLE)
        . '</row>';
    $xml .= '<row r="5" spans="1:' . $spanMax . '">'
        . medicao_xlsx_cell_inline(0, 5, $empresaNome !== '' ? ('Prefeitura / cliente: ' . $empresaNome) : 'Prefeitura / cliente: —', MEDICAO_XLSX_STYLE_SUBTITLE)
        . '</row>';
    $xml .= '<row r="6" spans="1:' . $spanMax . '">'
        . medicao_xlsx_cell_inline(0, 6, $periodoTxt !== '—' ? $periodoTxt : 'Período: —', MEDICAO_XLSX_STYLE_SUBTITLE)
        . '</row>';

    foreach ($sheetRows as $i => $row) {
        $rnum = $rowHeaderCols + $i;
        if (!is_array($row)) {
            $row = [];
        }
        $row = array_pad(array_values($row), $nCols, '');
        if ($i === 0) {
            $styleBase = MEDICAO_XLSX_STYLE_HEADER;
        } else {
            $styleBase = (($i - 1) % 2 === 0) ? MEDICAO_XLSX_STYLE_DATA_A : MEDICAO_XLSX_STYLE_DATA_B;
        }
        $xml .= '<row r="' . $rnum . '" spans="1:' . $spanMax . '">';
        for ($c = 0; $c < $nCols; $c++) {
            $text = (string) ($row[$c] ?? '');
            $xml .= medicao_xlsx_cell_inline($c, $rnum, $text, $styleBase);
        }
        $xml .= '</row>';
    }

    $xml .= '</sheetData>'
        . '<mergeCells count="4">'
        . '<mergeCell ref="A1:' . $lastColLt . '3"/>'
        . '<mergeCell ref="A4:' . $lastColLt . '4"/>'
        . '<mergeCell ref="A5:' . $lastColLt . '5"/>'
        . '<mergeCell ref="A6:' . $lastColLt . '6"/>'
        . '</mergeCells>';

    if ($hasDrawing) {
        $xml .= '<drawing r:id="rId1"/>';
    }

    $xml .= '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
        . '</worksheet>';

    return $xml;
}

/**
 * Imagem ancorada num intervalo de células (melhor compatibilidade no Excel que oneCellAnchor).
 */
function medicao_xlsx_drawing_picture_two_cell_xml(
    int $fromCol,
    int $fromRow,
    int $toCol,
    int $toRow,
    int $cx,
    int $cy,
    int $embedRidNum,
    int $picNvId
): string {
    return '<xdr:twoCellAnchor editAs="oneCell">'
        . '<xdr:from><xdr:col>' . $fromCol . '</xdr:col><xdr:colOff>38100</xdr:colOff><xdr:row>' . $fromRow . '</xdr:row><xdr:rowOff>38100</xdr:rowOff></xdr:from>'
        . '<xdr:to><xdr:col>' . $toCol . '</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>' . $toRow . '</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to>'
        . '<xdr:pic>'
        . '<xdr:nvPicPr><xdr:cNvPr id="' . $picNvId . '" name="Logo"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>'
        . '<xdr:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="rId' . $embedRidNum . '"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
        . '<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr>'
        . '</xdr:pic>'
        . '<xdr:clientData/>'
        . '</xdr:twoCellAnchor>';
}

/**
 * @param list<list<string>> $sheetRows
 */
function medicao_xlsx_build_workbook_zip(
    string $tmpPath,
    array $sheetRows,
    string $empresaNome,
    string $mesLabelPt,
    string $mesRef,
    int $clienteMatrizId = 0,
    ?string $periodoDe = null,
    ?string $periodoAte = null
): bool {
{
    $zip = new ZipArchive();
    if ($zip->open($tmpPath, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
        return false;
    }

    $resolved    = medicao_export_resolve_logo_paths($clienteMatrizId);
    $imagePack   = [];
    foreach (['prefeitura', 'empresa'] as $slot) {
        $path = $resolved[$slot] ?? null;
        if ($path === null || $path === '') {
            continue;
        }
        $parsed = medicao_export_read_image_for_xlsx($path);
        if ($parsed !== null) {
            $imagePack[] = ['slot' => $slot, 'bin' => $parsed[0], 'ext' => $parsed[1]];
        }
    }

    $relsDrawing   = '';
    $anchorsXml    = '';
    $mediaInserted = 0;
    $anchorsPreset = [
        ['fc' => 0, 'fr' => 0, 'tc' => 5, 'tr' => 2],
        ['fc' => 11, 'fr' => 0, 'tc' => 17, 'tr' => 2],
    ];
    $emuPerPx = 9525;
    $maxWpx   = 300;

    foreach ($imagePack as $idx => $img) {
        $tmpImg = @tempnam(sys_get_temp_dir(), 'mxlsx');
        if ($tmpImg === false) {
            continue;
        }
        file_put_contents($tmpImg, $img['bin']);
        $dim = @getimagesize($tmpImg);
        @unlink($tmpImg);
        $w = is_array($dim) ? (int) $dim[0] : 220;
        $h = is_array($dim) ? (int) $dim[1] : 80;
        $scale = $w > $maxWpx ? ($maxWpx / $w) : 1.0;
        $cx    = max(1, (int) round($w * $scale * $emuPerPx));
        $cy    = max(1, (int) round($h * $scale * $emuPerPx));
        $ac = $anchorsPreset[$idx] ?? $anchorsPreset[0];

        $mediaInserted++;
        $ridNum = $mediaInserted;
        $relsDrawing .= '<Relationship Id="rId' . $ridNum . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image' . $mediaInserted . '.' . $img['ext'] . '"/>';
        $anchorsXml  .= medicao_xlsx_drawing_picture_two_cell_xml(
            $ac['fc'],
            $ac['fr'],
            $ac['tc'],
            $ac['tr'],
            $cx,
            $cy,
            $ridNum,
            100 + $mediaInserted
        );
        $zip->addFromString('xl/media/image' . $mediaInserted . '.' . $img['ext'], $img['bin']);
    }

    $hasDrawing = $relsDrawing !== '';
    $xmlDrawing = $hasDrawing
        ? ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . $anchorsXml
            . '</xdr:wsDr>')
        : '';

    if ($hasDrawing) {
        $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relsDrawing . '</Relationships>');
        $zip->addFromString('xl/drawings/drawing1.xml', $xmlDrawing);
        $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/>'
            . '</Relationships>');
    }

    $zip->addFromString('[Content_Types].xml', medicao_xlsx_part_content_types($hasDrawing));
    $zip->addFromString('_rels/.rels', medicao_xlsx_part_rels_root());
    $zip->addFromString('xl/workbook.xml', medicao_xlsx_part_workbook());
    $zip->addFromString('xl/_rels/workbook.xml.rels', medicao_xlsx_part_workbook_rels());
    $zip->addFromString('xl/styles.xml', medicao_xlsx_part_styles_professional());
    $zip->addFromString(
        'xl/worksheets/sheet1.xml',
        medicao_xlsx_build_worksheet_xml($sheetRows, $empresaNome, $mesLabelPt, $mesRef, $hasDrawing, $periodoDe, $periodoAte)
    );
    $zip->close();

    return true;
}

/** @return array<string,float> código item => quantidade medida */
function medicao_relatorio_qtd_por_codigo(array $sheetRows): array
{
    $out = [];
    foreach ($sheetRows as $ri => $row) {
        if ($ri === 0 || !is_array($row)) {
            continue;
        }
        $codRaw = trim((string) ($row[14] ?? ''));
        if ($codRaw === '') {
            continue;
        }
        if (!preg_match('/^\d+(?:\.\d+)?$/', $codRaw)) {
            continue;
        }
        $qtdRaw = (string) ($row[16] ?? '');
        $qtdNum = (float) str_replace(['.', ','], ['', '.'], $qtdRaw);
        $key = ltrim($codRaw, '0');
        if ($key === '' || $key[0] === '.') {
            $key = '0' . $key;
        }
        $out[$key] = ($out[$key] ?? 0.0) + $qtdNum;
    }
    return $out;
}

/** @return list<string> */
function medicao_relatorio_template_shared_strings(?string $xml): array
{
    if (!is_string($xml) || trim($xml) === '') {
        return [];
    }
    $doc = new DOMDocument('1.0', 'UTF-8');
    if (!@$doc->loadXML($xml)) {
        return [];
    }
    $xp = new DOMXPath($doc);
    $xp->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $out = [];
    $si = $xp->query('//x:sst/x:si');
    if (!$si) {
        return $out;
    }
    foreach ($si as $node) {
        $parts = $xp->query('.//x:t', $node);
        $txt = '';
        if ($parts) {
            foreach ($parts as $t) {
                $txt .= (string) $t->textContent;
            }
        }
        $out[] = $txt;
    }
    return $out;
}

/** @return array<int,string> row => item código */
function medicao_relatorio_template_mapa_itens(DOMXPath $xp, array $sharedStrings): array
{
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $xp->registerNamespace('x', $ns);
    $map = [];
    $cells = $xp->query('//x:sheetData/x:row/x:c[starts-with(@r,"A")]');
    if (!$cells) {
        return $map;
    }
    foreach ($cells as $cell) {
        if (!$cell instanceof DOMElement) {
            continue;
        }
        $ref = (string) $cell->getAttribute('r');
        if (!preg_match('/^A(\d+)$/', $ref, $m)) {
            continue;
        }
        $row = (int) $m[1];
        if ($row < 16) {
            continue;
        }
        $value = '';
        $tAttr = (string) $cell->getAttribute('t');
        if ($tAttr === 'inlineStr') {
            $tNode = $xp->query('./x:is/x:t', $cell)->item(0);
            $value = $tNode ? trim((string) $tNode->textContent) : '';
        } else {
            $vNode = $xp->query('./x:v', $cell)->item(0);
            if (!$vNode) {
                continue;
            }
            $rawV = trim((string) $vNode->textContent);
            if ($tAttr === 's') {
                $idx = (int) $rawV;
                $value = trim((string) ($sharedStrings[$idx] ?? ''));
            } else {
                $value = $rawV;
            }
        }
        if ($value === '' || !preg_match('/^\d+(?:\.\d+)?$/', $value)) {
            continue;
        }
        $k = ltrim($value, '0');
        if ($k === '' || $k[0] === '.') {
            $k = '0' . $k;
        }
        $map[$row] = $k;
    }
    return $map;
}

function medicao_relatorio_template_set_num(DOMDocument $doc, DOMXPath $xp, string $ref, float $value): void
{
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $xp->registerNamespace('x', $ns);
    $cell = $xp->query('//x:sheetData/x:row/x:c[@r="' . $ref . '"]')->item(0);
    if (!$cell instanceof DOMElement) {
        return;
    }
    $cell->removeAttribute('t');
    foreach (['f', 'is'] as $tag) {
        $nodes = $xp->query('./x:' . $tag, $cell);
        if ($nodes) {
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $n = $nodes->item($i);
                if ($n) {
                    $cell->removeChild($n);
                }
            }
        }
    }
    $vNode = $xp->query('./x:v', $cell)->item(0);
    if (!$vNode) {
        $vNode = $doc->createElementNS($ns, 'v');
        $cell->appendChild($vNode);
    }
    $num = round($value, 6);
    $vNode->nodeValue = rtrim(rtrim(number_format($num, 6, '.', ''), '0'), '.');
    if ($vNode->nodeValue === '') {
        $vNode->nodeValue = '0';
    }
}

function medicao_relatorio_template_set_text(DOMDocument $doc, DOMXPath $xp, string $ref, string $value): void
{
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $xp->registerNamespace('x', $ns);
    $cell = $xp->query('//x:sheetData/x:row/x:c[@r="' . $ref . '"]')->item(0);
    if (!$cell instanceof DOMElement) {
        return;
    }
    foreach (['f', 'v', 'is'] as $tag) {
        $nodes = $xp->query('./x:' . $tag, $cell);
        if ($nodes) {
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $n = $nodes->item($i);
                if ($n) {
                    $cell->removeChild($n);
                }
            }
        }
    }
    $cell->setAttribute('t', 'inlineStr');
    $is = $doc->createElementNS($ns, 'is');
    $t  = $doc->createElementNS($ns, 't');
    $t->appendChild($doc->createTextNode($value));
    $is->appendChild($t);
    $cell->appendChild($is);
}

/**
 * @param list<list<string>> $sheetRows linha 0 = cabeçalho; demais linhas incluem código (coluna 10) e qtd (coluna 12)
 */
function medicao_export_relatorio_detalhado_xlsx_send(
    string $mesRef,
    array $sheetRows,
    string $empresaNome = '',
    string $mesLabelPt = '',
    int $clienteMatrizId = 0,
    ?string $periodoDe = null,
    ?string $periodoAte = null
): void {
{
    if (!class_exists(ZipArchive::class)) {
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Exportação XLSX requer a extensão PHP zip (ZipArchive).';
        return;
    }

    $tmpDir = __DIR__ . '/../uploads/tmp';
    if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Falha ao preparar diretório temporário de exportação.';
        return;
    }
    $tmp = tempnam($tmpDir, 'medicao_xlsx_');
    if ($tmp === false) {
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Falha ao criar arquivo temporário para exportação.';
        return;
    }

    if (!medicao_xlsx_build_workbook_zip($tmp, $sheetRows, $empresaNome, $mesLabelPt, $mesRef, $clienteMatrizId, $periodoDe, $periodoAte)) {
        @unlink($tmp);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Falha ao gerar o arquivo XLSX.';
        return;
    }

    $fn = 'relatorio_detalhado_' . $mesRef . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    header('Cache-Control: no-store');
    readfile($tmp);
    @unlink($tmp);
}

function medicao_xlsx_part_content_types(bool $withDrawing = false): string
{
    $extra = '';
    if ($withDrawing) {
        $extra .= '<Default Extension="png" ContentType="image/png"/>'
            . '<Default Extension="jpeg" ContentType="image/jpeg"/>'
            . '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
    }

    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . $extra
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';
}

function medicao_xlsx_part_rels_root(): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
}

function medicao_xlsx_part_workbook(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="RELATÓRIO DETALHADO BM" sheetId="1" r:id="rId1"/></sheets></workbook>';
}

function medicao_xlsx_part_workbook_rels(): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';
}

/**
 * Fontes, preenchimentos (2 primeiros obrigatórios), bordas e cellXfs para aspeto «relatório».
 */
function medicao_xlsx_part_styles_professional(): string
{
    $thin = '<left style="thin"><color rgb="FFD1D5DB"/></left>'
        . '<right style="thin"><color rgb="FFD1D5DB"/></right>'
        . '<top style="thin"><color rgb="FFD1D5DB"/></top>'
        . '<bottom style="thin"><color rgb="FFD1D5DB"/></bottom>';

    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="4">'
        . '<font><sz val="11"/><color rgb="FF111827"/><name val="Calibri"/></font>'
        . '<font><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/><b/></font>'
        . '<font><sz val="15"/><color rgb="FFFFFFFF"/><name val="Calibri"/><b/></font>'
        . '<font><sz val="11"/><color rgb="FF4B5563"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="5">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF0F172A"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFFFFF"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        . '<border/>'
        . '<border>' . $thin . '</border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="9">'
        . '<xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
        . '<alignment vertical="top" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
        . '<alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
        . '<alignment vertical="top" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
        . '<alignment vertical="top" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
        . '<alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="4" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1">'
        . '<alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
        . '<alignment horizontal="right" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
        . '<alignment horizontal="right" vertical="top" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
        . '<alignment horizontal="right" vertical="top" wrapText="1"/></xf>'
        . '</cellXfs>'
        . '</styleSheet>';
}
