<?php
declare(strict_types=1);

/**
 * Exportação XLSX da listagem de itens devolvidos / sucatas.
 */

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/lighton_export_xlsx_brand.php';

/**
 * @return list<array{0:string,1:string}>
 */
function catalogo_itens_devolvidos_xlsx_colunas(): array
{
    return [
        ['Data lançamento', 'lancamento_em'],
        ['Data abertura chamado', 'chamado_aberto_em'],
        ['Chamado #', 'chamado_id'],
        ['Título do chamado', 'chamado_titulo'],
        ['Status chamado', 'chamado_status'],
        ['Item', 'item_nome'],
        ['Código', 'item_codigo'],
        ['Tipo', 'item_tipo'],
        ['Unidade', 'catalogo_unidade'],
        ['Qtd', 'quantidade'],
        ['Valor unit. (ref.)', 'valor_unitario'],
        ['Subtotal (ref.)', 'subtotal'],
        ['Origem', 'origem'],
        ['Técnico', 'tecnico_nomes'],
        ['Observação', 'observacao'],
        ['Endereço', 'chamado_endereco'],
    ];
}

/**
 * @param array<string,mixed> $row
 * @return list<string|int|float>
 */
function catalogo_itens_devolvidos_xlsx_row_values(array $row): array
{
    $out = [];
    foreach (catalogo_itens_devolvidos_xlsx_colunas() as [, $key]) {
        $v = $row[$key] ?? '';
        if ($v === null) {
            $out[] = '';
            continue;
        }
        if (is_string($v)) {
            $out[] = str_replace(["\r\n", "\r", "\n"], ' ', $v);
            continue;
        }
        $out[] = $v;
    }

    return $out;
}

/**
 * @param list<array<string,mixed>> $rows
 * @param array{empresa?:string,periodo_label?:string,busca?:string} $meta
 */
function catalogo_itens_devolvidos_export_xlsx_send(array $rows, array $meta = []): void
{
    if (!defined('APP_BRAND_NAME')) {
        require_once __DIR__ . '/config.php';
    }
    $brand = defined('APP_BRAND_NAME') ? (string) APP_BRAND_NAME : 'OnLight';
    $cols  = catalogo_itens_devolvidos_xlsx_colunas();
    $nCol  = count($cols);
    $last  = Coordinate::stringFromColumnIndex($nCol);

    $spreadsheet = new Spreadsheet();
    $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);
    $spreadsheet->getProperties()
        ->setCreator($brand)
        ->setTitle('Itens devolvidos / sucatas — ' . $brand)
        ->setSubject('Exportação de recolhimentos e sucatas')
        ->setDescription('CRM');

    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Devolvidos');
    $sheet->setShowGridlines(false);

    $empresa = trim((string) ($meta['empresa'] ?? ''));
    $periodo = trim((string) ($meta['periodo_label'] ?? ''));
    $busca   = trim((string) ($meta['busca'] ?? ''));
    $resumoParts = [count($rows) . ' lançamento(s)'];
    if ($empresa !== '') {
        $resumoParts[] = $empresa;
    }
    if ($periodo !== '') {
        $resumoParts[] = 'Período: ' . $periodo;
    }
    if ($busca !== '') {
        $resumoParts[] = 'Busca: ' . $busca;
    }
    $resumoParts[] = 'Gerado em ' . date('d/m/Y H:i');

    $r = 1;
    $sheet->mergeCells("A{$r}:{$last}{$r}");
    $sheet->setCellValue("A{$r}", 'ITENS DEVOLVIDOS / SUCATAS  ·  ' . $brand);
    $sheet->getStyle("A{$r}:{$last}{$r}")->applyFromArray([
        'font' => ['bold' => true, 'size' => 14, 'color' => ['argb' => CH_XLSX_HEAD_TXT]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => CH_XLSX_HEAD]],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical'   => Alignment::VERTICAL_CENTER,
        ],
    ]);
    $sheet->getRowDimension($r)->setRowHeight(28);

    ++$r;
    $sheet->mergeCells("A{$r}:{$last}{$r}");
    $sheet->setCellValue("A{$r}", implode('  ·  ', $resumoParts));
    $sheet->getStyle("A{$r}:{$last}{$r}")->applyFromArray([
        'font' => ['size' => 9, 'color' => ['argb' => LO_MUTED]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => LO_BG_PAGE]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
    ]);
    $sheet->getRowDimension($r)->setRowHeight(20);

    ++$r;
    $headerRow = $r;
    foreach ($cols as $i => [$label]) {
        $col = Coordinate::stringFromColumnIndex($i + 1);
        $sheet->setCellValue("{$col}{$r}", $label);
    }
    $sheet->getStyle("A{$r}:{$last}{$r}")->applyFromArray([
        'font' => ['bold' => true, 'color' => ['argb' => CH_XLSX_HEAD_TXT], 'size' => 10],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => CH_XLSX_HEAD]],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_LEFT,
            'vertical'   => Alignment::VERTICAL_CENTER,
            'wrapText'   => false,
        ],
        'borders' => [
            'bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => CH_XLSX_BORDER]],
        ],
    ]);
    $sheet->getRowDimension($r)->setRowHeight(22);

    $firstData = $r + 1;
    $colQtd = Coordinate::stringFromColumnIndex(10);
    $colVu  = Coordinate::stringFromColumnIndex(11);
    $colSub = Coordinate::stringFromColumnIndex(12);

    foreach ($rows as $row) {
        ++$r;
        $vals = catalogo_itens_devolvidos_xlsx_row_values($row);
        foreach ($vals as $i => $val) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue("{$col}{$r}", $val);
        }
        $sheet->getStyle("{$colQtd}{$r}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
        $sheet->getStyle("{$colVu}{$r}")->getNumberFormat()->setFormatCode('#,##0.0000');
        $sheet->getStyle("{$colSub}{$r}")->getNumberFormat()->setFormatCode('"R$"#,##0.00');
        if (($r - $firstData) % 2 === 1) {
            $sheet->getStyle("A{$r}:{$last}{$r}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB(CH_XLSX_ROW_ALT);
        }
    }
    $lastData = $r;

    if ($lastData >= $firstData) {
        $sheet->getStyle("A{$firstData}:{$last}{$lastData}")->applyFromArray([
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => false],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => CH_XLSX_BORDER]],
            ],
        ]);
    }

    for ($i = 1; $i <= $nCol; $i++) {
        $col = Coordinate::stringFromColumnIndex($i);
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    foreach (['D', 'P'] as $wideCol) {
        if ($sheet->getColumnDimension($wideCol)->getWidth() > 48) {
            $sheet->getColumnDimension($wideCol)->setAutoSize(false)->setWidth(48);
        }
    }

    $sheet->freezePane('A' . $firstData);
    $sheet->setAutoFilter("A{$headerRow}:{$last}{$lastData}");

    $filename = 'itens_devolvidos_sucatas_' . date('Y-m-d_His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    (new Xlsx($spreadsheet))->save('php://output');
}
