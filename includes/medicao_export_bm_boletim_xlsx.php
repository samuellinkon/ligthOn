<?php
declare(strict_types=1);

/**
 * Boletim BM: mantém o cabeçalho do modelo (título, contrato, «VALOR TOTAL», grelha ITEM)
 * e substitui a grelha de itens por linhas derivadas dos chamados (quantidades «utilizado» no mês).
 */

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Style;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/medicao_helpers.php';
require_once __DIR__ . '/repository.php';

/** Fonte: Aptos (Office moderno); usar «Calibri» se necessário compatibilidade antiga. */
const MEDICAO_BM_XLSX_FONT = 'Aptos';
const MEDICAO_BM_XLSX_SIZE_BASE = 10;
const MEDICAO_BM_XLSX_SIZE_HEADER = 9;
const MEDICAO_BM_XLSX_SIZE_TITLE_MAIN = 15;
const MEDICAO_BM_XLSX_SIZE_TITLE_SUB = 11;
const MEDICAO_BM_XLSX_SIZE_TOTAL = 11;
/** Altura de linha compacta (pt). */
const MEDICAO_BM_XLSX_ROW_HEIGHT = 16.0;
/** Título principal (faixa hero; próximo do modelo institucional ~55 pt). */
const MEDICAO_BM_XLSX_ROW_TITLE = 54.0;
/** Linhas de meta / contrato sob o título. */
const MEDICAO_BM_XLSX_ROW_SUB_META = 21.0;
/** Grelha 2x2 da capa do boletim consolidado. */
const MEDICAO_BM_XLSX_ROW_CAPA_GRID = 24.0;
/** Faixa de descrição do serviço na capa. */
const MEDICAO_BM_XLSX_ROW_CAPA_SERVICE = 36.0;
/** Espaçador entre capa e bloco seguinte. */
const MEDICAO_BM_XLSX_ROW_SPACER = 8.0;
/** Cabeçalho da grelha ITEM (uma linha, sem wrap). */
const MEDICAO_BM_XLSX_ROW_TABLE_HDR = 22.0;
/** Meta / subtítulo em linha única. */
const MEDICAO_BM_XLSX_ROW_TITLE_META = 24.0;
/** Largura mínima/máxima de coluna (autosize). */
const MEDICAO_BM_XLSX_COL_WIDTH_MIN = 9.5;
const MEDICAO_BM_XLSX_COL_WIDTH_MAX = 48.0;
const MEDICAO_BM_XLSX_COL_DESC_MAX = 60.0;
/** Largura da coluna descrição (fallback legado v1). */
const MEDICAO_BM_XLSX_COL_DESC_WIDTH = 52.0;

/** Identidade visual (RGB sem # — formato OOXML). */
const MEDICAO_BM_BRAND_PRIMARY = '534AB7';
const MEDICAO_BM_BRAND_PRIMARY_HOVER = '7F77DD';
const MEDICAO_BM_BRAND_SIDEBAR = '1A1A2E';
const MEDICAO_BM_BRAND_SIDEBAR_2 = '14142B';
const MEDICAO_BM_TEXT_MAIN = '1F2937';
const MEDICAO_BM_TEXT_MUTED = '6B7280';
const MEDICAO_BM_BG_SOFT = 'F4F3FF';
const MEDICAO_BM_BG_ALT = 'FAFAFF';
const MEDICAO_BM_BORDER_LIGHT = 'DADDF0';
const MEDICAO_BM_BORDER_STRONG = 'BFC3D9';
const MEDICAO_BM_WHITE = 'FFFFFF';
const MEDICAO_BM_TOTAL_BORDER = 'C9C6F4';

/**
 * @return array<string, mixed>
 */
function medicao_bm_boletim_style_fill_solid(string $rgb): array
{
    return [
        'fillType'   => Fill::FILL_SOLID,
        'startColor' => ['rgb' => $rgb],
        'endColor'   => ['rgb' => $rgb],
    ];
}

/**
 * @return array<string, mixed>
 */
function medicao_bm_boletim_style_borders_all(string $rgb, string $style = Border::BORDER_THIN): array
{
    return [
        'allBorders' => [
            'borderStyle' => $style,
            'color'       => ['rgb' => $rgb],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function medicao_bm_boletim_style_font(bool $bold, int $size, string $colorRgb): array
{
    return [
        'name'  => MEDICAO_BM_XLSX_FONT,
        'size'  => $size,
        'bold'  => $bold,
        'color' => ['rgb' => $colorRgb],
    ];
}

/**
 * @return array<string, mixed>
 */
function medicao_bm_boletim_style_align_vc(bool $wrap, string $horizontal = Alignment::HORIZONTAL_GENERAL): array
{
    $align = [
        'vertical'   => Alignment::VERTICAL_CENTER,
        'horizontal' => $horizontal,
        'wrapText'   => $wrap,
    ];
    if (!$wrap) {
        $align['shrinkToFit'] = true;
    }

    return $align;
}

/**
 * @return array<string, mixed>
 */
function medicao_bm_boletim_alignment_celula(string $horizontal = Alignment::HORIZONTAL_GENERAL): array
{
    return medicao_bm_boletim_style_align_vc(false, $horizontal);
}

/**
 * Contorno suave + horizontais discretas (menos “grelha Excel” no corpo da tabela).
 *
 * @return array<string, mixed>
 */
function medicao_bm_boletim_style_borders_table_body_soft(): array
{
    return [
        'borders' => [
            'outline' => [
                'borderStyle' => Border::BORDER_THIN,
                'color'       => ['rgb' => MEDICAO_BM_BORDER_STRONG],
            ],
            'horizontal' => [
                'borderStyle' => Border::BORDER_THIN,
                'color'       => ['rgb' => MEDICAO_BM_BORDER_LIGHT],
            ],
        ],
    ];
}

/**
 * Estilos visuais do relatório (cores de marca, preenchimentos, bordas).
 * OOXML não tem semi-bold: ênfase monetária no corpo = cor primária, peso normal.
 *
 * @return array<string, array<string, mixed>>
 */
function medicao_bm_boletim_style_arrays_tipografia(): array
{
    $bSoft = medicao_bm_boletim_style_borders_all(MEDICAO_BM_BORDER_LIGHT, Border::BORDER_THIN);
    $bTot  = medicao_bm_boletim_style_borders_all(MEDICAO_BM_TOTAL_BORDER, Border::BORDER_THIN);

    return [
        'title_main' => [
            'font'      => medicao_bm_boletim_style_font(true, MEDICAO_BM_XLSX_SIZE_TITLE_MAIN, MEDICAO_BM_WHITE),
            'fill'      => medicao_bm_boletim_style_fill_solid(MEDICAO_BM_BRAND_SIDEBAR),
            'alignment' => medicao_bm_boletim_style_align_vc(false, Alignment::HORIZONTAL_LEFT),
            'borders'   => [
                'bottom' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['rgb' => MEDICAO_BM_BRAND_PRIMARY_HOVER],
                ],
            ],
        ],
        'title_sub' => [
            'font'      => medicao_bm_boletim_style_font(true, MEDICAO_BM_XLSX_SIZE_TITLE_SUB, MEDICAO_BM_BRAND_PRIMARY),
            'fill'      => medicao_bm_boletim_style_fill_solid(MEDICAO_BM_BG_SOFT),
            'alignment' => medicao_bm_boletim_style_align_vc(false, Alignment::HORIZONTAL_LEFT),
            'borders'   => $bSoft,
        ],
        'title_sub_flat' => [
            'font'      => medicao_bm_boletim_style_font(true, MEDICAO_BM_XLSX_SIZE_TITLE_SUB, MEDICAO_BM_BRAND_PRIMARY),
            'fill'      => medicao_bm_boletim_style_fill_solid(MEDICAO_BM_BG_SOFT),
            'alignment' => medicao_bm_boletim_style_align_vc(false, Alignment::HORIZONTAL_LEFT),
        ],
        'header_table' => [
            'font'      => medicao_bm_boletim_style_font(true, MEDICAO_BM_XLSX_SIZE_HEADER, MEDICAO_BM_WHITE),
            'fill'      => medicao_bm_boletim_style_fill_solid(MEDICAO_BM_BRAND_PRIMARY),
            'alignment' => medicao_bm_boletim_style_align_vc(false, Alignment::HORIZONTAL_CENTER),
            'borders'   => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['rgb' => MEDICAO_BM_BRAND_PRIMARY_HOVER],
                ],
            ],
        ],
        'body_row' => [
            'font'      => medicao_bm_boletim_style_font(false, MEDICAO_BM_XLSX_SIZE_BASE, MEDICAO_BM_TEXT_MAIN),
            'alignment' => medicao_bm_boletim_style_align_vc(false, Alignment::HORIZONTAL_LEFT),
        ],
        'body_desc' => [
            'font'      => medicao_bm_boletim_style_font(false, MEDICAO_BM_XLSX_SIZE_BASE, MEDICAO_BM_TEXT_MAIN),
            'alignment' => medicao_bm_boletim_style_align_vc(false, Alignment::HORIZONTAL_LEFT),
        ],
        'numeric' => [
            'font'      => medicao_bm_boletim_style_font(false, MEDICAO_BM_XLSX_SIZE_BASE, MEDICAO_BM_TEXT_MAIN),
            'alignment' => medicao_bm_boletim_style_align_vc(false, Alignment::HORIZONTAL_RIGHT),
        ],
        'money' => [
            'font'      => medicao_bm_boletim_style_font(false, MEDICAO_BM_XLSX_SIZE_BASE, MEDICAO_BM_BRAND_PRIMARY),
            'alignment' => medicao_bm_boletim_style_align_vc(false, Alignment::HORIZONTAL_RIGHT),
        ],
        'total_band' => [
            'font'      => medicao_bm_boletim_style_font(true, MEDICAO_BM_XLSX_SIZE_TOTAL, MEDICAO_BM_BRAND_PRIMARY),
            'fill'      => medicao_bm_boletim_style_fill_solid(MEDICAO_BM_BG_SOFT),
            'alignment' => medicao_bm_boletim_style_align_vc(false, Alignment::HORIZONTAL_LEFT),
            'borders'   => $bTot,
        ],
        'total_value' => [
            'font'         => medicao_bm_boletim_style_font(true, MEDICAO_BM_XLSX_SIZE_TOTAL, MEDICAO_BM_BRAND_PRIMARY),
            'fill'         => medicao_bm_boletim_style_fill_solid(MEDICAO_BM_BG_SOFT),
            'alignment'    => medicao_bm_boletim_style_align_vc(false, Alignment::HORIZONTAL_RIGHT),
            'numberFormat' => ['formatCode' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1],
            'borders'      => $bTot,
        ],
        'total_obs' => [
            'font'      => medicao_bm_boletim_style_font(true, MEDICAO_BM_XLSX_SIZE_TOTAL, MEDICAO_BM_BRAND_PRIMARY),
            'fill'      => medicao_bm_boletim_style_fill_solid(MEDICAO_BM_BG_SOFT),
            'alignment' => medicao_bm_boletim_style_align_vc(false, Alignment::HORIZONTAL_LEFT),
            'borders'   => $bTot,
        ],
        'body_block_border' => [
            'borders' => array_merge(
                medicao_bm_boletim_style_borders_all(MEDICAO_BM_BORDER_LIGHT, Border::BORDER_THIN),
                [
                    'outline' => [
                        'borderStyle' => Border::BORDER_MEDIUM,
                        'color'       => ['rgb' => MEDICAO_BM_BORDER_STRONG],
                    ],
                ]
            ),
        ],
        /** Corpo de tabela: contorno fino + linhas horizontais leves (export consolidado). */
        'body_table_border_soft' => medicao_bm_boletim_style_borders_table_body_soft(),
        /** Contorno de secção (KPI, capa) sem grelha interior pesada. */
        'section_outline_soft' => [
            'borders' => [
                'outline' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['rgb' => MEDICAO_BM_BORDER_STRONG],
                ],
            ],
        ],
        /** Células da grelha 2x2 da capa (rótulo + valor, fundo suave). */
        'capa_grid_cell' => [
            'font'      => medicao_bm_boletim_style_font(false, MEDICAO_BM_XLSX_SIZE_BASE, MEDICAO_BM_TEXT_MAIN),
            'fill'      => medicao_bm_boletim_style_fill_solid(MEDICAO_BM_BG_SOFT),
            'alignment' => medicao_bm_boletim_style_align_vc(false, Alignment::HORIZONTAL_LEFT),
            'borders'   => [
                'right' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['rgb' => MEDICAO_BM_BORDER_LIGHT],
                ],
            ],
        ],
        /** Última célula da grelha 2x2 (sem borda direita duplicada com o contorno). */
        'capa_grid_cell_plain' => [
            'font'      => medicao_bm_boletim_style_font(false, MEDICAO_BM_XLSX_SIZE_BASE, MEDICAO_BM_TEXT_MAIN),
            'fill'      => medicao_bm_boletim_style_fill_solid(MEDICAO_BM_BG_SOFT),
            'alignment' => medicao_bm_boletim_style_align_vc(false, Alignment::HORIZONTAL_LEFT),
        ],
        /** Faixa larga descrição institucional / serviço. */
        'capa_service_banner' => [
            'font'      => medicao_bm_boletim_style_font(true, MEDICAO_BM_XLSX_SIZE_TITLE_SUB, MEDICAO_BM_BRAND_PRIMARY),
            'fill'      => medicao_bm_boletim_style_fill_solid(MEDICAO_BM_BG_SOFT),
            'alignment' => medicao_bm_boletim_style_align_vc(false, Alignment::HORIZONTAL_CENTER),
            'borders'   => [
                'top' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['rgb' => MEDICAO_BM_BORDER_LIGHT],
                ],
                'bottom' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['rgb' => MEDICAO_BM_BORDER_LIGHT],
                ],
            ],
        ],
        /** Caixa de destaque financeiro (valor acumulado / saldo na capa contratual). */
        'highlight_financial_box' => [
            'font'      => medicao_bm_boletim_style_font(true, 13, MEDICAO_BM_BRAND_PRIMARY),
            'fill'      => medicao_bm_boletim_style_fill_solid(MEDICAO_BM_BG_SOFT),
            'alignment' => medicao_bm_boletim_style_align_vc(false, Alignment::HORIZONTAL_RIGHT),
            'borders'   => $bTot,
        ],
    ];
}

/**
 * Uniformiza tipografia e compactação após montar dados (não altera fórmulas nem valores).
 *
 * @param array{
 *   item_header_row: int,
 *   first_data_row: int,
 *   last_data_row: int,
 *   col_mes: int,
 *   col_exec_fis: int|null,
 *   col_exec_fin: int|null,
 *   l_mes: string,
 *   l_fis: string,
 *   l_fin: string
 * } $ctx
 */
function medicao_bm_boletim_aplicar_tipografia_relatorio(Spreadsheet $spreadsheet, Worksheet $sheet, array $ctx): void
{
    $itemHeaderRow = (int) $ctx['item_header_row'];
    $firstDataRow  = (int) $ctx['first_data_row'];
    $lastDataRow   = (int) $ctx['last_data_row'];
    $lMes          = (string) $ctx['l_mes'];
    $lFin          = (string) $ctx['l_fin'];

    $st = medicao_bm_boletim_style_arrays_tipografia();

    $defFont = $spreadsheet->getDefaultStyle()->getFont();
    $defFont->setName(MEDICAO_BM_XLSX_FONT)->setSize(MEDICAO_BM_XLSX_SIZE_BASE);
    $defFont->getColor()->setRGB(MEDICAO_BM_TEXT_MAIN);

    $lastCol = $sheet->getHighestDataColumn();
    if ($lastCol === '' || $lastCol === 'A') {
        $lastCol = 'Y';
    }
    $maxRow = max((int) $sheet->getHighestRow(), $itemHeaderRow + 2, $lastDataRow);

    $sheet->getStyle('A1:' . $lastCol . '1')->applyFromArray($st['title_main']);
    $sheet->getStyle('A2:' . $lastCol . '2')->applyFromArray($st['title_sub_flat']);
    $sheet->getStyle('A3:' . $lastCol . '3')->applyFromArray($st['title_sub_flat']);

    $linhaVt = medicao_bm_boletim_find_linha_valor_total_valor($sheet);
    if ($linhaVt !== null) {
        $lbl = $linhaVt - 1;
        if ($lbl >= 1) {
            $sheet->getStyle('A' . $lbl . ':' . $lastCol . $lbl)->applyFromArray($st['total_band']);
        }
        $sheet->getStyle('A' . $linhaVt . ':' . $lastCol . $linhaVt)->applyFromArray($st['total_band']);
        $sheet->getStyle('A' . $linhaVt)->applyFromArray($st['total_value']);
        $sheet->getStyle('C' . $linhaVt)->applyFromArray($st['total_obs']);
    }

    $sheet->getStyle('A' . $itemHeaderRow . ':' . $lastCol . $itemHeaderRow)->applyFromArray($st['header_table']);
    $subH = $itemHeaderRow + 1;
    if ($subH <= $maxRow) {
        $sheet->getStyle('A' . $subH . ':' . $lastCol . $subH)->applyFromArray($st['header_table']);
    }

    if ($lastDataRow >= $firstDataRow) {
        for ($r = $firstDataRow; $r <= $lastDataRow; $r++) {
            $bg = (($r - $firstDataRow) % 2 === 0) ? MEDICAO_BM_WHITE : MEDICAO_BM_BG_ALT;
            $rowSt = $st['body_row'];
            $rowSt['fill'] = medicao_bm_boletim_style_fill_solid($bg);
            $sheet->getStyle('A' . $r . ':' . $lastCol . $r)->applyFromArray($rowSt);
        }
        $bodyRange = 'A' . $firstDataRow . ':' . $lastCol . $lastDataRow;
        $sheet->getStyle($bodyRange)->applyFromArray($st['body_block_border']);

        $sheet->getStyle('B' . $firstDataRow . ':B' . $lastDataRow)->applyFromArray($st['body_desc']);
        $sheet->getStyle('G' . $firstDataRow . ':' . $lastCol . $lastDataRow)->applyFromArray($st['numeric']);

        $sheet->getStyle('H' . $firstDataRow . ':H' . $lastDataRow)->applyFromArray($st['money']);
        $sheet->getStyle($lFin . $firstDataRow . ':' . $lFin . $lastDataRow)->applyFromArray($st['money']);
        $sheet->getStyle($lMes . $firstDataRow . ':' . $lMes . $lastDataRow)->applyFromArray($st['numeric']);
    }

    $ultimaLinha = max(1, (int) $sheet->getHighestRow());
    medicao_bm_boletim_autosize_colunas($sheet, 'A', $lastCol, 1, $ultimaLinha, $itemHeaderRow);
    medicao_bm_boletim_aplicar_layout_sem_quebra($sheet, 'A', $lastCol, 1, $ultimaLinha, [
        'header_row'     => $itemHeaderRow,
        'first_data_row' => $firstDataRow,
        'last_data_row'  => $lastDataRow,
        'money_cols'     => ['H', $lFin],
        'qty_cols'       => ['G', $lMes],
        'left_cols'      => ['A', 'B', 'C'],
    ]);
    medicao_bm_boletim_aplicar_alturas_linhas_compactas($sheet, $ultimaLinha, $itemHeaderRow, $firstDataRow, $lastDataRow);
    if ($subH <= $ultimaLinha) {
        $sheet->getRowDimension($subH)->setRowHeight(MEDICAO_BM_XLSX_ROW_TABLE_HDR);
    }
}

function medicao_bm_boletim_celula_texto_plano(mixed $v): string
{
    if ($v === null) {
        return '';
    }
    if ($v instanceof RichText) {
        return trim(preg_replace('/\s+/u', ' ', str_replace(["\n", "\r"], ' ', $v->getPlainText())));
    }

    return trim(preg_replace('/\s+/u', ' ', str_replace(["\n", "\r"], ' ', (string) $v)));
}

function medicao_bm_boletim_largura_estimada_texto(string $text, bool $bold = false, int $fontSize = MEDICAO_BM_XLSX_SIZE_BASE): float
{
    $text = preg_replace('/\s+/u', ' ', trim($text));
    if ($text === '') {
        return MEDICAO_BM_XLSX_COL_WIDTH_MIN;
    }
    $len    = mb_strlen($text, 'UTF-8');
    $factor = 1.05 + ($bold ? 0.1 : 0.0);
    if ($fontSize < MEDICAO_BM_XLSX_SIZE_BASE) {
        $factor *= 0.92;
    } elseif ($fontSize > MEDICAO_BM_XLSX_SIZE_BASE) {
        $factor *= 1.06;
    }

    return max(MEDICAO_BM_XLSX_COL_WIDTH_MIN, ($len * $factor) + 2.5);
}

function medicao_bm_boletim_valor_celula_para_largura(Worksheet $sheet, string $coord): string
{
    if (!$sheet->cellExists($coord)) {
        return '';
    }
    $cell = $sheet->getCell($coord);
    try {
        $fmt = $cell->getFormattedValue();
        if ($fmt !== null && $fmt !== '') {
            return medicao_bm_boletim_celula_texto_plano((string) $fmt);
        }
    } catch (\Throwable $e) {
        // usa valor bruto
    }

    return medicao_bm_boletim_celula_texto_plano($cell->getValue());
}

function medicao_bm_boletim_autosize_colunas(
    Worksheet $sheet,
    string $firstColLetter,
    string $lastColLetter,
    int $firstRow,
    int $lastRow,
    ?int $headerRow = null
): void {
    $colIni = Coordinate::columnIndexFromString($firstColLetter);
    $colEnd = Coordinate::columnIndexFromString($lastColLetter);

    for ($c = $colIni; $c <= $colEnd; $c++) {
        $letter = Coordinate::stringFromColumnIndex($c);
        $maxW   = MEDICAO_BM_XLSX_COL_WIDTH_MIN;
        $cap    = ($letter === 'B') ? MEDICAO_BM_XLSX_COL_DESC_MAX : MEDICAO_BM_XLSX_COL_WIDTH_MAX;

        for ($r = $firstRow; $r <= $lastRow; $r++) {
            $coord    = $letter . $r;
            $bold     = ($headerRow !== null && $r === $headerRow);
            $fontSize = $bold ? MEDICAO_BM_XLSX_SIZE_HEADER : MEDICAO_BM_XLSX_SIZE_BASE;
            $text     = medicao_bm_boletim_valor_celula_para_largura($sheet, $coord);
            $maxW     = max($maxW, medicao_bm_boletim_largura_estimada_texto($text, $bold, $fontSize));
        }

        $maxW = min($cap, max(MEDICAO_BM_XLSX_COL_WIDTH_MIN, $maxW));
        $sheet->getColumnDimension($letter)->setAutoSize(false);
        $sheet->getColumnDimension($letter)->setWidth($maxW);
    }
}

/**
 * @param array{
 *   header_row?: int|null,
 *   first_data_row?: int|null,
 *   last_data_row?: int|null,
 *   money_cols?: list<string>,
 *   qty_cols?: list<string>,
 *   left_cols?: list<string>
 * } $ctx
 */
function medicao_bm_boletim_aplicar_layout_sem_quebra(
    Worksheet $sheet,
    string $firstColLetter,
    string $lastColLetter,
    int $firstRow,
    int $lastRow,
    array $ctx = []
): void {
    $headerRow    = isset($ctx['header_row']) ? (int) $ctx['header_row'] : null;
    $firstDataRow = isset($ctx['first_data_row']) ? (int) $ctx['first_data_row'] : null;
    $lastDataRow  = isset($ctx['last_data_row']) ? (int) $ctx['last_data_row'] : null;
    $moneyCols    = $ctx['money_cols'] ?? ['I', 'J', 'K', 'L'];
    $qtyCols      = $ctx['qty_cols'] ?? ['D', 'E', 'F', 'G', 'H', 'M'];
    $leftCols     = $ctx['left_cols'] ?? ['A', 'B', 'C'];

    $range = $firstColLetter . $firstRow . ':' . $lastColLetter . $lastRow;
    $sheet->getStyle($range)->getAlignment()
        ->setVertical(Alignment::VERTICAL_CENTER)
        ->setWrapText(false)
        ->setShrinkToFit(true);

    if ($headerRow !== null && $headerRow >= $firstRow && $headerRow <= $lastRow) {
        $hdr = 'A' . $headerRow . ':' . $lastColLetter . $headerRow;
        $sheet->getStyle($hdr)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(false)
            ->setShrinkToFit(true);
    }

    if ($firstDataRow !== null && $lastDataRow !== null && $firstDataRow <= $lastDataRow) {
        foreach ($leftCols as $col) {
            $sheet->getStyle($col . $firstDataRow . ':' . $col . $lastDataRow)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_LEFT)
                ->setWrapText(false)
                ->setShrinkToFit(true);
        }
        foreach ($qtyCols as $col) {
            $sheet->getStyle($col . $firstDataRow . ':' . $col . $lastDataRow)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_RIGHT)
                ->setWrapText(false)
                ->setShrinkToFit(true);
        }
        foreach ($moneyCols as $col) {
            $sheet->getStyle($col . $firstDataRow . ':' . $col . $lastDataRow)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_RIGHT)
                ->setWrapText(false)
                ->setShrinkToFit(true);
        }
    }

    $titleEnd = min(4, $lastRow);
    for ($r = 1; $r <= $titleEnd; $r++) {
        $sheet->getStyle('A' . $r . ':' . $lastColLetter . $r)->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(false)
            ->setShrinkToFit(true);
    }
}

function medicao_bm_boletim_aplicar_alturas_linhas_compactas(
    Worksheet $sheet,
    int $lastRow,
    int $headerRow,
    int $firstDataRow,
    int $lastDataRow
): void {
    for ($r = $firstDataRow; $r <= $lastDataRow; $r++) {
        $sheet->getRowDimension($r)->setRowHeight(MEDICAO_BM_XLSX_ROW_HEIGHT);
    }
    $sheet->getRowDimension(1)->setRowHeight(MEDICAO_BM_XLSX_ROW_TITLE);
    if ($lastRow >= 2) {
        $sheet->getRowDimension(2)->setRowHeight(MEDICAO_BM_XLSX_ROW_TITLE_META);
    }
    if ($lastRow >= 3) {
        $sheet->getRowDimension(3)->setRowHeight(MEDICAO_BM_XLSX_ROW_SUB_META);
    }
    if ($lastRow >= 4) {
        $sheet->getRowDimension(4)->setRowHeight(MEDICAO_BM_XLSX_ROW_TITLE_META);
    }
    if ($headerRow >= 1 && $headerRow <= $lastRow) {
        $sheet->getRowDimension($headerRow)->setRowHeight(MEDICAO_BM_XLSX_ROW_TABLE_HDR);
    }
}

/**
 * @param array<string,mixed> $clienteMatriz linha de repo_cliente
 * @param array{n_chamados:int,valor_materiais:float,valor_servicos:float,valor_total:float} $totaisMes
 */
function medicao_export_bm_boletim_xlsx_send(
    int $clienteMatrizId,
    string $refYm,
    array $clienteMatriz,
    array $totaisMes
): void {
    if ($clienteMatrizId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $refYm)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Parâmetros inválidos.';
        exit;
    }

    $tpl = dirname(__DIR__) . '/assets/templates/bm_boletim_padrao.xlsx';
    if (!is_readable($tpl)) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Modelo BM indisponível (assets/templates/bm_boletim_padrao.xlsx).';
        exit;
    }

    $dataDe  = $refYm . '-01';
    $dataAte = date('Y-m-t', strtotime($dataDe));

    $itensCh = repo_medicao_bm_utilizado_quantidades_por_item($clienteMatrizId, $dataDe, $dataAte);
    usort(
        $itensCh,
        static function (array $a, array $b): int {
            $ca = (string) ($a['item_codigo'] ?? '') . "\0" . (string) ($a['item_nome'] ?? '');
            $cb = (string) ($b['item_codigo'] ?? '') . "\0" . (string) ($b['item_nome'] ?? '');

            return strcmp($ca, $cb);
        }
    );

    $reader = IOFactory::createReader('Xlsx');
    $reader->setReadDataOnly(false);
    $spreadsheet = $reader->load($tpl);
    $sheet = $spreadsheet->getActiveSheet();

    medicao_bm_boletim_remover_bloco_resumo_contrato($sheet);

    $itemHeaderRow = medicao_bm_boletim_find_linha_cabecalho_item($sheet) ?? 13;
    $styleSourceRow = $itemHeaderRow + 3;
    $bodyStyle = $sheet->getStyle('A' . $styleSourceRow . ':AE' . $styleSourceRow);

    $colMes = medicao_bm_boletim_resolver_coluna_mes($sheet, $refYm, $itemHeaderRow);
    $colMes = medicao_bm_boletim_remover_colunas_historicas_bm_sheet($sheet, $itemHeaderRow, $refYm, $colMes);
    $colExecFis = medicao_bm_boletim_find_coluna_exec_fisico($sheet, $itemHeaderRow);
    $colExecFin = medicao_bm_boletim_find_coluna_exec_financeiro($sheet, $itemHeaderRow);

    $lMes = Coordinate::stringFromColumnIndex($colMes);
    $lFis = $colExecFis !== null ? Coordinate::stringFromColumnIndex($colExecFis) : 'W';
    $lFin = $colExecFin !== null ? Coordinate::stringFromColumnIndex($colExecFin) : 'AA';

    $firstDataRow = $itemHeaderRow + 2;
    $highest = (int) $sheet->getHighestRow();
    if ($highest >= $firstDataRow) {
        $sheet->removeRow($firstDataRow, $highest - $firstDataRow + 1);
    }

    medicao_bm_boletim_atualizar_cabecalho_contrato($sheet, $refYm, $clienteMatriz, $totaisMes);

    $lastDataRow = $firstDataRow - 1;
    $r = $firstDataRow;
    if ($itensCh === []) {
        medicao_bm_boletim_aplicar_estilo_linha($sheet, $bodyStyle, $r);
        $sheet->setCellValue('B' . $r, 'Nenhum item utilizado em chamados neste mês (apenas movimento «utilizado» nos chamados não cancelados).');
        $lastDataRow = $firstDataRow;
        $r++;
    } else {
        foreach ($itensCh as $it) {
            $q = (float) ($it['quantidade'] ?? 0);
            if ($q == 0.0) {
                continue;
            }
            medicao_bm_boletim_aplicar_estilo_linha($sheet, $bodyStyle, $r);

            $cod = trim((string) ($it['item_codigo'] ?? ''));
            $sheet->setCellValue('A' . $r, $cod !== '' ? $cod : ('#' . (int) ($it['item_id'] ?? 0)));
            $sheet->setCellValue('B' . $r, (string) ($it['item_nome'] ?? ''));
            $sheet->setCellValue('C' . $r, trim((string) ($it['unidade'] ?? '')) !== '' ? (string) $it['unidade'] : 'UN');

            $vu = (float) ($it['valor_unitario'] ?? 0);
            $sheet->setCellValue('D' . $r, null);
            $sheet->setCellValue('E' . $r, null);
            $sheet->setCellValue('F' . $r, null);
            $sheet->setCellValue('G' . $r, $q);
            $sheet->setCellValue('H' . $r, $vu);

            $sheet->setCellValue($lMes . $r, $q);

            $sheet->setCellValue($lFis . $r, '=SUM(' . $lMes . $r . ':' . $lMes . $r . ')');
            $sheet->setCellValue($lFin . $r, '=ROUND(' . $lFis . $r . '*H' . $r . ',2)');

            $lastDataRow = $r;
            $r++;
        }
    }

    $seq = medicao_bm_boletim_numero_sequencia($clienteMatrizId, $refYm);
    $sheet->setCellValue('B1', 'BOLETIM DE MEDIÇÃO Nº ' . $seq);

    medicao_bm_boletim_aplicar_tipografia_relatorio($spreadsheet, $sheet, [
        'item_header_row' => $itemHeaderRow,
        'first_data_row'  => $firstDataRow,
        'last_data_row'   => $lastDataRow,
        'col_mes'         => $colMes,
        'col_exec_fis'    => $colExecFis,
        'col_exec_fin'    => $colExecFin,
        'l_mes'           => $lMes,
        'l_fis'           => $lFis,
        'l_fin'           => $lFin,
    ]);

    $stamp = date('d.m.Y');
    $tag   = medicao_bm_boletim_mes_arquivo($refYm);
    $fn    = 'BM_' . $seq . '__' . $tag . '_v' . $stamp . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    header('Cache-Control: max-age=0');

    (new Xlsx($spreadsheet))->save('php://output');
    exit;
}

function medicao_bm_boletim_aplicar_estilo_linha(Worksheet $sheet, Style $bodyStyle, int $r): void
{
    $end = $sheet->getHighestDataColumn();
    if ($end === '' || $end === 'A') {
        $end = 'AE';
    }
    $sheet->duplicateStyle($bodyStyle, 'A' . $r . ':' . $end . $r, true);
}

/** Linha da grelha onde coluna A é «ITEM» e B descreve a coluna de descrição (modelo BM). */
function medicao_bm_boletim_find_linha_cabecalho_item(Worksheet $sheet): ?int
{
    $max = min(120, max(30, (int) $sheet->getHighestRow()));
    for ($r = 1; $r <= $max; $r++) {
        $aNorm = strtoupper(medicao_bm_boletim_celula_texto_plano($sheet->getCell('A' . $r)->getValue()));
        if ($aNorm !== 'ITEM') {
            continue;
        }
        $bNorm = strtoupper(medicao_bm_boletim_celula_texto_plano($sheet->getCell('B' . $r)->getValue()));
        if ($bNorm !== '' && str_contains($bNorm, 'DESC')) {
            return $r;
        }
    }

    return null;
}

/** Linha do valor numérico (célula A) imediatamente abaixo do rótulo «VALOR TOTAL». */
function medicao_bm_boletim_find_linha_valor_total_valor(Worksheet $sheet): ?int
{
    $max = min(40, max(8, (int) $sheet->getHighestRow()));
    for ($r = 1; $r <= $max; $r++) {
        $norm = medicao_bm_boletim_celula_texto_plano($sheet->getCell('A' . $r)->getValue());
        if (stripos($norm, 'VALOR TOTAL') === false) {
            continue;
        }
        $next = $r + 1;
        if ($next <= (int) $sheet->getHighestRow()) {
            return $next;
        }

        return null;
    }

    return null;
}

function medicao_bm_boletim_merge_intersecta_faixa_linhas(string $range, int $rowMin, int $rowMax): bool
{
    if (!preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/i', $range, $m)) {
        return false;
    }
    $rLo = min((int) $m[2], (int) $m[4]);
    $rHi = max((int) $m[2], (int) $m[4]);

    return !($rHi < $rowMin || $rLo > $rowMax);
}

function medicao_bm_boletim_desfazer_merges_intersectando_linhas(Worksheet $sheet, int $rowMin, int $rowMax): void
{
    foreach (array_keys($sheet->getMergeCells()) as $range) {
        if (!medicao_bm_boletim_merge_intersecta_faixa_linhas((string) $range, $rowMin, $rowMax)) {
            continue;
        }
        $sheet->unmergeCells($range);
    }
}

/**
 * Remove o bloco informativo do contrato (início, valor previsto, termos/aditivos) acima de «VALOR TOTAL»,
 * mantendo o par rótulo/valor «VALOR TOTAL» e a tabela ITEM.
 */
function medicao_bm_boletim_remover_bloco_resumo_contrato(Worksheet $sheet): void
{
    $itemRow = medicao_bm_boletim_find_linha_cabecalho_item($sheet);
    if ($itemRow === null || $itemRow < 11) {
        return;
    }
    $labelRow = $itemRow - 2;
    $lblNorm = medicao_bm_boletim_celula_texto_plano($sheet->getCell('A' . $labelRow)->getValue());
    if (stripos($lblNorm, 'VALOR TOTAL') === false) {
        return;
    }
    $cutEnd = $itemRow - 3;
    if ($cutEnd < 4) {
        return;
    }
    medicao_bm_boletim_desfazer_merges_intersectando_linhas($sheet, 4, $cutEnd);
    for ($r = $cutEnd; $r >= 4; $r--) {
        $sheet->removeRow($r, 1);
    }
}

/** Texto de cabeçalho agregado (linhas 1–20 + linha ITEM) para detetar colunas BM na folha. */
function medicao_bm_boletim_texto_cabecalho_coluna_sheet(Worksheet $sheet, int $itemHeaderRow, int $col): string
{
    $maxR = min(20, max(1, (int) $sheet->getHighestRow()));
    $want = array_unique(array_merge(
        range(1, $maxR),
        [$itemHeaderRow, $itemHeaderRow + 1]
    ));
    $parts = [];
    foreach ($want as $ri) {
        if ($ri < 1) {
            continue;
        }
        $t = strtoupper(medicao_bm_boletim_celula_texto_plano(
            $sheet->getCell(Coordinate::stringFromColumnIndex($col) . $ri)->getValue()
        ));
        if ($t !== '') {
            $parts[] = $t;
        }
    }

    return trim(implode(' ', $parts));
}

function medicao_bm_boletim_coluna_lixo_data_amostra(Worksheet $sheet, int $firstSampleRow, int $col, int $lastRow): bool
{
    static $ghostNeedles = [
        '31/12/1899', '30/12/1899', '29/12/1899', '00/01/1900', '01/01/1900', '02/01/1900', '03/01/1900',
        '04/01/1900', '05/01/1900', '06/01/1900', '07/01/1900', '08/01/1900', '09/01/1900', '10/01/1900',
        '11/01/1900', '12/01/1900', '13/01/1900', '14/01/1900', '15/01/1900', '16/01/1900',
        '14/02/1900', '28/02/1900', '29/02/1900',
    ];
    $n = 0;
    $g = 0;
    $lastRow = max($firstSampleRow, $lastRow);
    $letra = Coordinate::stringFromColumnIndex($col);
    for ($r = $firstSampleRow; $r <= $lastRow; $r++) {
        $t = strtoupper(trim($sheet->getCell($letra . $r)->getFormattedValue()));
        if ($t === '' || $t === '-' || $t === '—') {
            continue;
        }
        $n++;
        foreach ($ghostNeedles as $gh) {
            if (str_contains($t, $gh)) {
                $g++;
                break;
            }
        }
    }

    return $n >= 4 && $g >= (int) ceil($n * 0.55);
}

function medicao_bm_boletim_desfazer_merges_que_cruzam_colunas(Worksheet $sheet, array $colIndices): void
{
    if ($colIndices === []) {
        return;
    }
    $set = array_fill_keys($colIndices, true);
    foreach (array_keys($sheet->getMergeCells()) as $range) {
        if (!preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/i', (string) $range, $m)) {
            continue;
        }
        $c1 = Coordinate::columnIndexFromString($m[1]);
        $c2 = Coordinate::columnIndexFromString($m[3]);
        for ($cc = min($c1, $c2); $cc <= max($c1, $c2); $cc++) {
            if (isset($set[$cc])) {
                $sheet->unmergeCells($range);
                break;
            }
        }
    }
}

/**
 * Remove colunas de períodos BM antigos (cabeçalho BMnn) e colunas de datas «fantasma» 1899/1900.
 * Mantém a coluna do mês da medição (needle) ou, sem mês válido, a coluna BM mais à direita.
 *
 * @return int Índice 1-based da coluna do mês após remoções
 */
function medicao_bm_boletim_remover_colunas_historicas_bm_sheet(Worksheet $sheet, int $itemHeaderRow, string $refYm, int $keepColMes): int
{
    $needle = medicao_bm_needle_periodo_planilha($refYm);
    $needleU = $needle !== null ? mb_strtoupper($needle, 'UTF-8') : '';

    $hi = max(
        22,
        Coordinate::columnIndexFromString($sheet->getHighestDataColumn()),
        $keepColMes
    );

    $toRemove = [];

    if ($needleU !== '') {
        for ($c = 9; $c <= $hi; $c++) {
            $h = mb_strtoupper(medicao_bm_boletim_texto_cabecalho_coluna_sheet($sheet, $itemHeaderRow, $c), 'UTF-8');
            if (!preg_match('/\bBM\s*\d+/u', $h)) {
                continue;
            }
            if (str_contains($h, $needleU) || $c === $keepColMes) {
                continue;
            }
            $toRemove[] = $c;
        }
    } else {
        $bmCols = [];
        for ($c = 9; $c <= $hi; $c++) {
            $h = mb_strtoupper(medicao_bm_boletim_texto_cabecalho_coluna_sheet($sheet, $itemHeaderRow, $c), 'UTF-8');
            if (preg_match('/\bBM\s*\d+/u', $h)) {
                $bmCols[] = $c;
            }
        }
        if ($bmCols !== []) {
            $keepColMes = max($bmCols);
            foreach ($bmCols as $c) {
                if ($c !== $keepColMes) {
                    $toRemove[] = $c;
                }
            }
        }
    }

    $firstSample = $itemHeaderRow + 2;
    $lastRow = min((int) $sheet->getHighestRow(), $itemHeaderRow + 280);
    for ($c = 9; $c <= $hi; $c++) {
        if (in_array($c, $toRemove, true)) {
            continue;
        }
        $h = mb_strtoupper(medicao_bm_boletim_texto_cabecalho_coluna_sheet($sheet, $itemHeaderRow, $c), 'UTF-8');
        if (preg_match('/\bBM\s*\d+/u', $h)) {
            continue;
        }
        if ($h !== '' && (
            str_contains($h, 'ITEM') || str_contains($h, 'DESCRI') || str_contains($h, 'UNID')
            || str_contains($h, 'PRECO') || str_contains($h, 'PREC') || str_contains($h, 'QUANT')
            || str_contains($h, 'TERMO') || str_contains($h, 'EXECUTADO') || str_contains($h, 'MEDIDO')
            || str_contains($h, 'VALOR') || str_contains($h, 'ADIT')
        )) {
            continue;
        }
        if (medicao_bm_boletim_coluna_lixo_data_amostra($sheet, $firstSample, $c, $lastRow)) {
            $toRemove[] = $c;
        }
    }

    $toRemove = array_values(array_unique($toRemove));
    rsort($toRemove);
    if ($toRemove !== []) {
        medicao_bm_boletim_desfazer_merges_que_cruzam_colunas($sheet, $toRemove);
    }
    foreach ($toRemove as $c) {
        $sheet->removeColumn(Coordinate::stringFromColumnIndex($c), 1);
        if ($c < $keepColMes) {
            $keepColMes--;
        }
    }

    return $keepColMes;
}

function medicao_bm_boletim_find_coluna_exec_fisico(Worksheet $sheet, int $itemHeaderRow): ?int
{
    $hi = Coordinate::columnIndexFromString($sheet->getHighestColumn());
    for ($c = 1; $c <= $hi; $c++) {
        $t = mb_strtoupper(medicao_bm_boletim_texto_cabecalho_coluna_sheet($sheet, $itemHeaderRow, $c), 'UTF-8');
        if (str_contains($t, 'EXECUTADO') && str_contains($t, 'FISIC')) {
            return $c;
        }
    }

    return null;
}

function medicao_bm_boletim_find_coluna_exec_financeiro(Worksheet $sheet, int $itemHeaderRow): ?int
{
    $hi = Coordinate::columnIndexFromString($sheet->getHighestColumn());
    for ($c = 1; $c <= $hi; $c++) {
        $t = mb_strtoupper(medicao_bm_boletim_texto_cabecalho_coluna_sheet($sheet, $itemHeaderRow, $c), 'UTF-8');
        if (str_contains($t, 'EXECUTADO') && str_contains($t, 'FINANC')) {
            return $c;
        }
    }

    return null;
}

/** Mês para nome de ficheiro (ex.: MAIO_26), alinhado ao padrão BM. */
function medicao_bm_boletim_mes_arquivo(string $refYm): string
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', $refYm, $m)) {
        return $refYm;
    }
    $nomes = [
        1 => 'JAN', 2 => 'FEV', 3 => 'MAR', 4 => 'ABR',
        5 => 'MAIO', 6 => 'JUN', 7 => 'JUL', 8 => 'AGO',
        9 => 'SET', 10 => 'OUT', 11 => 'NOV', 12 => 'DEZ',
    ];
    $mi = (int) $m[2];

    return ($nomes[$mi] ?? $m[2]) . '_' . substr($m[1], 2, 2);
}

/**
 * Coluna 9..22 (I–V) cujo cabeçalho na linha 13 contém sigla/ano (ex.: MAI/2026).
 * Se não encontrar, usa a última coluna (V) e atualiza o rótulo do período.
 *
 * @param int|null $monthHeaderRow Linha do cabeçalho ITEM / colunas de mês (I–V); null = tentar detetar.
 */
function medicao_bm_boletim_resolver_coluna_mes(Worksheet $sheet, string $refYm, ?int $monthHeaderRow = null): int
{
    if (!preg_match('/^\d{4}-\d{2}$/', $refYm)) {
        return 22;
    }
    $needle = medicao_bm_needle_periodo_planilha($refYm);
    if ($needle === null || $needle === '') {
        return 22;
    }
    $needleU = strtoupper($needle);

    $headerRow = $monthHeaderRow ?? medicao_bm_boletim_find_linha_cabecalho_item($sheet) ?? 13;

    $hiCol = max(
        22,
        Coordinate::columnIndexFromString($sheet->getHighestDataColumn())
    );
    for ($col = 9; $col <= $hiCol; $col++) {
        $addr = Coordinate::stringFromColumnIndex($col) . $headerRow;
        $raw  = $sheet->getCell($addr)->getValue();
        if ($raw === null || $raw === '') {
            continue;
        }
        $s = strtoupper(str_replace(["\n", "\r"], ' ', medicao_bm_boletim_celula_texto_plano($raw)));
        if (str_contains($s, $needleU)) {
            return $col;
        }
    }

    $letra = Coordinate::stringFromColumnIndex($hiCol);
    $sheet->setCellValue($letra . $headerRow, 'BM' . "\n" . $needle);
    $sheet->setCellValue($letra . ($headerRow + 1), '');

    return $hiCol;
}

/**
 * @param array<string,mixed> $clienteMatriz
 * @param array{n_chamados:int,valor_materiais:float,valor_servicos:float,valor_total:float} $totaisMes
 */
function medicao_bm_boletim_atualizar_cabecalho_contrato(
    Worksheet $sheet,
    string $refYm,
    array $clienteMatriz,
    array $totaisMes
): void {
    $empresa = trim((string) ($clienteMatriz['empresa'] ?? ''));

    $rotuloMes = medicao_mes_label_pt($refYm);
    $sheet->setCellValue(
        'B2',
        'Medição ' . $rotuloMes . ' — ' . ($empresa !== '' ? $empresa : 'Contratante')
    );

    $vt = (float) ($totaisMes['valor_total'] ?? 0);
    $linhaVt = medicao_bm_boletim_find_linha_valor_total_valor($sheet);
    if ($linhaVt !== null) {
        $sheet->setCellValue('A' . $linhaVt, $vt);
    }

    $obs = trim((string) ($clienteMatriz['obs'] ?? ''));
    if ($obs !== '' && $linhaVt !== null) {
        $sheet->setCellValue('C' . $linhaVt, mb_substr($obs, 0, 200, 'UTF-8'));
    }
}

function medicao_bm_boletim_numero_sequencia(int $clienteMatrizId, string $refYm): int
{
    $pdo = db();
    if (!$pdo) {
        return 1;
    }
    try {
        $st = $pdo->prepare(
            'SELECT COUNT(DISTINCT ref_ym) FROM medicao_imports
             WHERE cliente_matriz_id = ? AND ref_ym <= ?'
        );
        $st->execute([$clienteMatrizId, $refYm]);
        $n = (int) $st->fetchColumn();

        return max(1, $n);
    } catch (Throwable $e) {
        return 1;
    }
}

/** Normaliza código de item para união BM / CRM (maiúsculas, trim). */
function medicao_bm_boletim_v2_norm_cod(?string $cod): string
{
    return strtoupper(trim((string) $cod));
}

/**
 * Ordem das linhas: ordem das importações do mês; em seguida as chaves só em BMs ou CRM ordenadas ASCII.
 *
 * @param list<array<string,mixed>> $impLinhas
 * @param list<array<string,mixed>> $crmRows já com campo `_bm_key`
 * @param array<string,mixed> $priorBmNorm chave = código uppercase
 *
 * @return list<string>
 */
function medicao_bm_boletim_v2_listar_keys_ordenadas(
    array $impLinhas,
    array $crmRows,
    array $priorBmNorm
): array {
    $ordered = [];
    $seen    = [];

    foreach ($impLinhas as $il) {
        $raw = isset($il['item_codigo']) ? (string) $il['item_codigo'] : '';
        $n   = medicao_bm_boletim_v2_norm_cod($raw);
        $k   = ($n !== '') ? $n : ('MIL:' . (int) ($il['linha_id'] ?? 0));
        if ($k === 'MIL:0') {
            continue;
        }
        if (isset($seen[$k])) {
            continue;
        }
        $seen[$k]   = true;
        $ordered[] = $k;
    }

    $restSet = [];
    foreach (array_keys($priorBmNorm) as $k) {
        if ($k !== '' && !isset($seen[$k])) {
            $restSet[$k] = true;
        }
    }
    foreach ($crmRows as $r) {
        $nk = (string) ($r['_bm_key'] ?? '');
        if ($nk !== '' && !isset($seen[$nk])) {
            $restSet[$nk] = true;
        }
    }
    $rest = array_keys($restSet);
    sort($rest, SORT_NATURAL);
    foreach ($rest as $k) {
        $seen[$k]   = true;
        $ordered[] = $k;
    }

    return $ordered;
}

/**
 * `@return array{rows:list<array<string,mixed>>, valor_medido_total:float}`
 */
function medicao_bm_boletim_v2_compor_linhas(
    int $matrizId,
    string $refYm,
    string $periodoDe,
    string $periodoAte
): array {
    $crmRows = repo_medicao_bm_utilizado_por_item_periodo_lancamento($matrizId, $periodoDe, $periodoAte);
    foreach ($crmRows as &$cr) {
        $nc            = medicao_bm_boletim_v2_norm_cod((string) ($cr['item_codigo'] ?? ''));
        $idItem        = (int) ($cr['item_id'] ?? 0);
        $cr['_bm_key'] = ($nc !== '') ? $nc : ('ID:' . $idItem);
    }
    unset($cr);

    $crmPorKey = [];
    foreach ($crmRows as $cr) {
        $crmPorKey[(string) $cr['_bm_key']] = $cr;
    }

    $priorBmRaw = repo_medicao_bm_imports_totals_before_ym($matrizId, $refYm);
    $priorBm    = [];
    foreach ($priorBmRaw as $c => $vals) {
        $n       = medicao_bm_boletim_v2_norm_cod((string) $c);
        if ($n !== '') {
            $priorBm[$n] = $vals;
        }
    }

    $impPkg        = repo_medicao_import_fetch($matrizId, $refYm);
    $impLinhas     = is_array($impPkg['linhas'] ?? null) ? $impPkg['linhas'] : [];
    $catPorCodRaw  = repo_medicao_bm_catalogo_por_codigo_matriz($matrizId);
    $catPorCodNorm = [];
    foreach ($catPorCodRaw as $cod => $row) {
        $u = medicao_bm_boletim_v2_norm_cod((string) $cod);
        if ($u !== '') {
            $catPorCodNorm[$u] = $row;
        }
    }

    $orderedKeys = medicao_bm_boletim_v2_listar_keys_ordenadas($impLinhas, $crmRows, $priorBm);
    $seenOrd = array_fill_keys($orderedKeys, true);
    foreach (array_keys($catPorCodNorm) as $ck) {
        if ($ck !== '' && !isset($seenOrd[$ck])) {
            $orderedKeys[] = $ck;
            $seenOrd[$ck]  = true;
        }
    }

    if ($orderedKeys === []) {
        return ['rows' => [], 'valor_medido_total' => 0.0];
    }

    $periodoPhp = strtotime($periodoDe . ' 12:00:00');
    $dataExcel  = $periodoPhp !== false ? ExcelDate::PHPToExcel($periodoPhp) : null;

    $valorMedidoSomaTotal = 0.0;

    /** @var array<int, mixed> */
    $importPorCodNorm = [];
    foreach ($impLinhas as $il) {
        $n = medicao_bm_boletim_v2_norm_cod((string) ($il['item_codigo'] ?? ''));
        if ($n !== '' && !isset($importPorCodNorm[$n])) {
            $importPorCodNorm[$n] = $il;
        }
    }

    $rows = [];

    foreach ($orderedKeys as $k) {
        $codNorm = '';
        if (strpos($k, 'MIL:') === 0 || strpos($k, 'ID:') === 0) {
            $codNorm = '';
        } else {
            $codNorm = $k;
        }

        $il      = (($codNorm !== '') && isset($importPorCodNorm[$codNorm])) ? $importPorCodNorm[$codNorm] : null;
        $crmLine = isset($crmPorKey[$k]) ? $crmPorKey[$k] : null;

        $codigoExibir = '';
        if ($crmLine !== null && trim((string) ($crmLine['item_codigo'] ?? '')) !== '') {
            $codigoExibir = (string) $crmLine['item_codigo'];
        } elseif ($il !== null && trim((string) ($il['item_codigo'] ?? '')) !== '') {
            $codigoExibir = (string) $il['item_codigo'];
        } elseif ($codNorm !== '') {
            $codigoExibir = $codNorm;
        } elseif ($k !== '') {
            $codigoExibir = $k;
        }

        $nome = '';
        if ($il !== null && trim((string) ($il['descricao'] ?? '')) !== '') {
            $nome = (string) $il['descricao'];
        } elseif ($crmLine !== null) {
            $nome = (string) ($crmLine['item_nome'] ?? '');
        }
        $unidade = 'UN';
        if ($crmLine !== null && trim((string) ($crmLine['unidade'] ?? '')) !== '') {
            $unidade = (string) $crmLine['unidade'];
        } elseif ($il !== null && trim((string) ($il['unidade'] ?? '')) !== '') {
            $unidade = (string) $il['unidade'];
        }

        $catLinha = null;
        if ($codNorm !== '' && isset($catPorCodNorm[$codNorm])) {
            $catLinha = $catPorCodNorm[$codNorm];
        }

        $itemCatalogoRow = null;
        if (strpos($k, 'ID:') === 0) {
            $idGuess = (int) substr($k, strlen('ID:'));
            if ($idGuess > 0) {
                $itemCatalogoRow = repo_cliente_item_por_id($idGuess, $matrizId);
            }
        }
        if ($itemCatalogoRow === null && ($catLinha === null || (int) ($catLinha['item_id'] ?? 0) <= 0) && $crmLine !== null) {
            $itemCatalogoRow = repo_cliente_item_por_id((int) ($crmLine['item_id'] ?? 0), $matrizId);
        }

        $estoqueSaldo = 0.0;
        $vu           = 0.0;
        if ($catLinha !== null) {
            $estoqueSaldo = (float) ($catLinha['estoque_saldo'] ?? 0);
            $vu           = (float) ($catLinha['valor_unitario'] ?? 0);
            if (trim($nome) === '' && trim((string) ($catLinha['item_nome'] ?? '')) !== '') {
                $nome = (string) $catLinha['item_nome'];
            }
            if ($unidade === 'UN' && trim((string) ($catLinha['unidade'] ?? '')) !== '') {
                $unidade = (string) $catLinha['unidade'];
            }
        }
        if ($itemCatalogoRow !== null) {
            $estoqueSaldo = (float) ($itemCatalogoRow['estoque_saldo'] ?? 0);
            $vu           = (float) ($itemCatalogoRow['valor_unitario'] ?? 0);
            if (trim($nome) === '' && trim((string) ($itemCatalogoRow['nome'] ?? '')) !== '') {
                $nome = (string) $itemCatalogoRow['nome'];
            }
            if ($unidade === 'UN' && trim((string) ($itemCatalogoRow['unidade'] ?? '')) !== '') {
                $unidade = (string) $itemCatalogoRow['unidade'];
            }
        }
        if ($crmLine !== null && $vu == 0.0) {
            $vu = (float) ($crmLine['valor_unitario'] ?? 0);
        }
        if (($estoqueSaldo == 0.0 && $crmLine !== null && repo_cliente_itens_estoque_saldo_column_exists())) {
            $estoqueSaldo = (float) ($crmLine['estoque_saldo'] ?? 0);
        }

        $bmPQ = ($codNorm !== '') && isset($priorBm[$codNorm]) ? (float) ($priorBm[$codNorm]['qtd'] ?? 0) : 0.0;
        $bmPV = ($codNorm !== '') && isset($priorBm[$codNorm]) ? (float) ($priorBm[$codNorm]['valor'] ?? 0) : 0.0;

        $mq = ($crmLine !== null) ? (float) ($crmLine['quantidade'] ?? 0) : 0.0;
        $mv = ($crmLine !== null) ? (float) ($crmLine['valor_subtotal'] ?? 0) : 0.0;

        $valorMedidoSomaTotal += $mv;

        $acumq = $mq + $bmPQ;
        $vqBase = ($estoqueSaldo * $vu);
        /* Acumulado financeiro: medido período + acumulados em BMs passados — evita usar qtd_medida*BMs que pode divergir dos valores gravados nos imports */
        $acumV = $mv + $bmPV;
        /* Saldo físico a executar: saldo atual no catálogo menos consumo físico já acumulado */
        $saldoQx = $estoqueSaldo - $acumq;
        /* Saldo financeiro: valor nominal em catálogo (saldo*qtd) menos acumulado em R$ */
        $saldoVx = ($vqBase) - ($acumV);

        $devFinRatio = null;
        if (abs((float) $vqBase) > 1e-12) {
            $devFinRatio = $acumV / $vqBase;
        }

        $rows[] = [
            '_key'              => $k,
            '_codigo_display'   => $codigoExibir,
            '_nome'             => $nome,
            '_unidade'          => $unidade,
            '_estoque_saldo'    => $estoqueSaldo,
            '_data_ini_excel'   => $dataExcel,
            '_soma_bm_q'        => $bmPQ,
            '_medido_q'         => $mq,
            '_acum_q'           => $acumq,
            '_saldo_exec_q'     => $saldoQx,
            '_val_acum_ant'     => $bmPV,
            '_val_med_periodo'  => $mv,
            '_val_acum'         => $acumV,
            '_saldo_exec_v'     => $saldoVx,
            '_dev_fin_ratio'    => $devFinRatio,
        ];
    }

    return [
        'rows'                 => $rows,
        'valor_medido_total'   => $valorMedidoSomaTotal,
    ];
}

function medicao_export_bm_boletim_v2_xlsx_send(
    int $clienteMatrizId,
    string $refYm,
    array $clienteMatriz,
    string $periodoDe,
    string $periodoAte
): void {
    if ($clienteMatrizId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $refYm)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Parâmetros inválidos.';
        exit;
    }
    $mesIni = strtotime($refYm . '-01 12:00:00');
    $ultDiaRef = $mesIni !== false ? date('Y-m-t', $mesIni) : ($refYm . '-28');
    if ($mesIni === false
        || $periodoDe > $periodoAte
        || $periodoAte < ($refYm . '-01')
        || $periodoAte > $ultDiaRef
    ) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Datas do período inválidas para o mês de referência.';
        exit;
    }

    $comp = medicao_bm_boletim_v2_compor_linhas($clienteMatrizId, $refYm, $periodoDe, $periodoAte);
    $linhasRows = $comp['rows'];

    $spreadsheet = new Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Boletim BM');

    $lastColLetter = 'M';
    $st            = medicao_bm_boletim_style_arrays_tipografia();
    $seq           = medicao_bm_boletim_numero_sequencia($clienteMatrizId, $refYm);

    $sheet->mergeCells('A1:' . $lastColLetter . '1');
    $sheet->setCellValue('A1', 'BOLETIM DE MEDIÇÃO Nº ' . $seq);
    $sheet->mergeCells('A2:' . $lastColLetter . '2');
    $empresa = trim((string) ($clienteMatriz['empresa'] ?? ''));
    $rotuloM = medicao_mes_label_pt($refYm);
    $periodoLbl = function_exists('medicao_periodo_export_label')
        ? medicao_periodo_export_label($periodoDe, $periodoAte, $refYm)
        : (medicao_data_br($periodoDe) . ' · ' . medicao_data_br($periodoAte));
    $sheet->setCellValue(
        'A2',
        'Medição ' . $rotuloM
        . ' — ' . ($empresa !== '' ? $empresa : 'Contratante')
        . ' — ' . $periodoLbl
    );
    $sheet->mergeCells('A3:' . $lastColLetter . '3');
    $sheet->setCellValue(
        'A3',
        'VALOR TOTAL DA MEDIÇÃO NO PERÍODO (CRM): R$ '
        . number_format((float) ($comp['valor_medido_total'] ?? 0), 2, ',', '.')
    );
    $sheet->getStyle('A3:' . $lastColLetter . '3')->applyFromArray([
        'font'      => medicao_bm_boletim_style_font(true, MEDICAO_BM_XLSX_SIZE_TOTAL + 1, MEDICAO_BM_BRAND_PRIMARY),
        'alignment' => [
            'vertical'   => Alignment::VERTICAL_CENTER,
            'horizontal' => Alignment::HORIZONTAL_CENTER,
        ],
        'fill' => medicao_bm_boletim_style_fill_solid(MEDICAO_BM_BG_SOFT),
    ]);

    $sheet->mergeCells('A4:' . $lastColLetter . '4');
    $iniPt = medicao_data_br($periodoDe);
    $fimPt = medicao_data_br($periodoAte);
    $sheet->setCellValue(
        'A4',
        'Exportação CRM · Data início: ' . $iniPt . ' · Data fim: ' . $fimPt . ' · Referência: ' . $rotuloM . '.'
    );
    $headerRow = 5;
    $headers   = [
        'Item',
        'Descrição',
        'Un',
        'QTD Saldo (total)',
        'QTD Soma BMS (anteriores)',
        'QTD Medido no período',
        'QTD Acumulado',
        'QTD Saldo a executar',
        'Acumulado período anterior (R$)',
        'Medido no período (R$)',
        'Acumulado (R$)',
        'Saldo a executar (R$)',
        'Desvio %',
    ];

    $colIni = Coordinate::columnIndexFromString('A');
    foreach ($headers as $i => $label) {
        $L = Coordinate::stringFromColumnIndex($colIni + $i);
        $sheet->setCellValue($L . $headerRow, $label);
    }
    $sheet->getStyle('A' . $headerRow . ':' . $lastColLetter . $headerRow)->applyFromArray($st['header_table']);

    $r0 = $headerRow + 1;
    if ($linhasRows === []) {
        $sheet->mergeCells('A' . $r0 . ':' . $lastColLetter . $r0);
        $sheet->setCellValue('A' . $r0, 'Nenhum item para esta combinação (importação BM vazia e sem materiais utilizados no período).');
        $lastDataRow = $r0;
    } else {
        foreach ($linhasRows as $i => $row) {
            $r = $r0 + $i;
            $sheet->setCellValue('A' . $r, (string) $row['_codigo_display']);
            $sheet->setCellValue('B' . $r, (string) $row['_nome']);
            $sheet->setCellValue('C' . $r, (string) $row['_unidade']);
            $sheet->setCellValue('D' . $r, (float) $row['_estoque_saldo']);
            $sheet->setCellValue('E' . $r, (float) $row['_soma_bm_q']);
            $sheet->setCellValue('F' . $r, (float) $row['_medido_q']);
            $sheet->setCellValue('G' . $r, (float) $row['_acum_q']);
            $sheet->setCellValue('H' . $r, (float) $row['_saldo_exec_q']);

            $sheet->setCellValue('I' . $r, (float) $row['_val_acum_ant']);
            $sheet->setCellValue('J' . $r, (float) $row['_val_med_periodo']);
            $sheet->setCellValue('K' . $r, (float) $row['_val_acum']);
            $sheet->setCellValue('L' . $r, (float) $row['_saldo_exec_v']);
            $ratio = isset($row['_dev_fin_ratio']) ? $row['_dev_fin_ratio'] : null;
            if ($ratio !== null) {
                $sheet->setCellValue('M' . $r, $ratio);
                $sheet->getStyle('M' . $r)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_PERCENTAGE_00);
            } else {
                $sheet->setCellValue('M' . $r, '');
            }

        }

        $lastDataRow = $r0 + count($linhasRows) - 1;

        $fmtQty = '#,##0.000###';
        $sheet->getStyle('D' . $r0 . ':H' . $lastDataRow)->getNumberFormat()->setFormatCode($fmtQty);
        $moneyFmt = '"R$" #,##0.00';
        $sheet->getStyle('I' . $r0 . ':L' . $lastDataRow)->getNumberFormat()->setFormatCode($moneyFmt);

        for ($r = $r0; $r <= $lastDataRow; $r++) {
            $bg                   = (($r - $r0) % 2 === 0) ? MEDICAO_BM_WHITE : MEDICAO_BM_BG_ALT;
            $rowStd               = $st['body_row'];
            $rowStd['fill']       = medicao_bm_boletim_style_fill_solid($bg);
            $sheet->getStyle('A' . $r . ':' . $lastColLetter . $r)->applyFromArray($rowStd);
            $sheet->getRowDimension($r)->setRowHeight(MEDICAO_BM_XLSX_ROW_HEIGHT);
            $sheet->getStyle('B' . $r)->applyFromArray($st['body_desc']);
            $sheet->getStyle('D' . $r . ':' . 'H' . $r)->applyFromArray($st['numeric']);
            $sheet->getStyle('I' . $r . ':' . 'L' . $r)->applyFromArray($st['money']);
            $sheet->getStyle('M' . $r)->applyFromArray($st['numeric']);
        }
        $sheet->getStyle('A' . $r0 . ':' . $lastColLetter . $lastDataRow)->applyFromArray($st['body_block_border']);
    }

    $defFont = $spreadsheet->getDefaultStyle()->getFont();
    $defFont->setName(MEDICAO_BM_XLSX_FONT)->setSize(MEDICAO_BM_XLSX_SIZE_BASE);

    $titleV2Purple = $st['title_main'];
    $titleV2Purple['fill'] = medicao_bm_boletim_style_fill_solid(MEDICAO_BM_BRAND_PRIMARY);
    $sheet->getStyle('A1:' . $lastColLetter . '1')->applyFromArray($titleV2Purple);
    $sheet->getStyle('A2:' . $lastColLetter . '2')->applyFromArray($st['title_sub_flat']);
    $sheet->getStyle('A4:' . $lastColLetter . '4')->applyFromArray($st['title_sub_flat']);

    $ultimaLinha = max(1, (int) $sheet->getHighestRow());

    medicao_bm_boletim_autosize_colunas($sheet, 'A', $lastColLetter, 1, $ultimaLinha, $headerRow);
    medicao_bm_boletim_aplicar_layout_sem_quebra($sheet, 'A', $lastColLetter, 1, $ultimaLinha, [
        'header_row'     => $headerRow,
        'first_data_row' => $r0,
        'last_data_row'  => $lastDataRow,
        'money_cols'     => ['I', 'J', 'K', 'L'],
        'qty_cols'       => ['D', 'E', 'F', 'G', 'H', 'M'],
        'left_cols'      => ['A', 'B', 'C'],
    ]);
    medicao_bm_boletim_aplicar_alturas_linhas_compactas($sheet, $ultimaLinha, $headerRow, $r0, $lastDataRow);

    $stampAte = date('d.m.Y', strtotime($periodoAte));
    $tag      = medicao_bm_boletim_mes_arquivo($refYm);
    $suffixDe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $periodoDe);
    $fn       = 'BM_' . $seq . '__' . $tag . '_' . trim($suffixDe, '_') . '_v' . $stampAte . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    header('Cache-Control: max-age=0');

    (new Xlsx($spreadsheet))->save('php://output');
    exit;
}
