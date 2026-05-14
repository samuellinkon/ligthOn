<?php
declare(strict_types=1);

/**
 * Boletim BM: mantém o cabeçalho do modelo (título, contrato, «VALOR TOTAL», grelha ITEM)
 * e substitui a grelha de itens por linhas derivadas dos chamados (quantidades «utilizado» no mês).
 */

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
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

/** Fonte: Aptos (Office moderno); usar «Calibri» se necessário compatibilidade antiga. */
const MEDICAO_BM_XLSX_FONT = 'Aptos';
const MEDICAO_BM_XLSX_SIZE_BASE = 10;
const MEDICAO_BM_XLSX_SIZE_HEADER = 10;
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
/** Cabeçalho da grelha ITEM (duas linhas de rótulos). */
const MEDICAO_BM_XLSX_ROW_TABLE_HDR = 40.0;
/** Largura da coluna descrição (evita autoSize excessivo). */
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
    return [
        'vertical'   => Alignment::VERTICAL_CENTER,
        'horizontal' => $horizontal,
        'wrapText'   => $wrap,
    ];
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
            'alignment' => medicao_bm_boletim_style_align_vc(true, Alignment::HORIZONTAL_LEFT),
            'borders'   => $bSoft,
        ],
        'title_sub_flat' => [
            'font'      => medicao_bm_boletim_style_font(true, MEDICAO_BM_XLSX_SIZE_TITLE_SUB, MEDICAO_BM_BRAND_PRIMARY),
            'fill'      => medicao_bm_boletim_style_fill_solid(MEDICAO_BM_BG_SOFT),
            'alignment' => medicao_bm_boletim_style_align_vc(true, Alignment::HORIZONTAL_LEFT),
        ],
        'header_table' => [
            'font'      => medicao_bm_boletim_style_font(true, MEDICAO_BM_XLSX_SIZE_HEADER, MEDICAO_BM_WHITE),
            'fill'      => medicao_bm_boletim_style_fill_solid(MEDICAO_BM_BRAND_PRIMARY),
            'alignment' => medicao_bm_boletim_style_align_vc(true, Alignment::HORIZONTAL_CENTER),
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
            'alignment' => medicao_bm_boletim_style_align_vc(true, Alignment::HORIZONTAL_LEFT),
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
            'alignment' => medicao_bm_boletim_style_align_vc(true, Alignment::HORIZONTAL_LEFT),
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
            'alignment' => medicao_bm_boletim_style_align_vc(true, Alignment::HORIZONTAL_LEFT),
        ],
        /** Faixa larga descrição institucional / serviço. */
        'capa_service_banner' => [
            'font'      => medicao_bm_boletim_style_font(true, MEDICAO_BM_XLSX_SIZE_TITLE_SUB, MEDICAO_BM_BRAND_PRIMARY),
            'fill'      => medicao_bm_boletim_style_fill_solid(MEDICAO_BM_BG_SOFT),
            'alignment' => medicao_bm_boletim_style_align_vc(true, Alignment::HORIZONTAL_CENTER),
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

    for ($r = 1; $r <= $maxRow; $r++) {
        $sheet->getRowDimension($r)->setRowHeight(MEDICAO_BM_XLSX_ROW_HEIGHT);
    }
    $sheet->getRowDimension(1)->setRowHeight(MEDICAO_BM_XLSX_ROW_TITLE);
    $sheet->getRowDimension(2)->setRowHeight(24.0);
    $sheet->getRowDimension(3)->setRowHeight(MEDICAO_BM_XLSX_ROW_SUB_META);

    $sheet->getStyle('A1:' . $lastCol . '1')->applyFromArray($st['title_main']);
    $sheet->getStyle('A2:' . $lastCol . '2')->applyFromArray($st['title_sub']);
    $sheet->getStyle('A3:' . $lastCol . '3')->applyFromArray($st['title_sub']);

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
    $sheet->getRowDimension($itemHeaderRow)->setRowHeight(MEDICAO_BM_XLSX_ROW_TABLE_HDR);
    $subH = $itemHeaderRow + 1;
    if ($subH <= $maxRow) {
        $sheet->getStyle('A' . $subH . ':' . $lastCol . $subH)->applyFromArray($st['header_table']);
        $sheet->getRowDimension($subH)->setRowHeight(MEDICAO_BM_XLSX_ROW_TABLE_HDR);
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

    $sheet->getColumnDimension('A')->setAutoSize(true);
    $sheet->getColumnDimension('B')->setWidth(MEDICAO_BM_XLSX_COL_DESC_WIDTH);
    $sheet->getColumnDimension('C')->setAutoSize(true);
}

function medicao_bm_boletim_celula_texto_plano(mixed $v): string
{
    if ($v === null) {
        return '';
    }
    if ($v instanceof RichText) {
        return trim(str_replace(["\n", "\r"], ' ', $v->getPlainText()));
    }

    return trim(str_replace(["\n", "\r"], ' ', (string) $v));
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
