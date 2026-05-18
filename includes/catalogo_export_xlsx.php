<?php
declare(strict_types=1);

/**
 * Exportação XLSX do catálogo — layout e estilos alinhados ao boletim de medição (marca OnLight).
 */

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require_once __DIR__ . '/medicao_export_bm_boletim_xlsx.php';

const CATALOGO_XLSX_LAST_COL = 'G';

/**
 * @param list<array<string,mixed>> $itens
 */
function catalogo_export_xlsx_send(int $clienteId, array $itens): void
{
    if (!defined('APP_BRAND_NAME')) {
        require_once __DIR__ . '/config.php';
    }

    $brand   = defined('APP_BRAND_NAME') ? (string) APP_BRAND_NAME : 'OnLight';
    $cliente = repo_cliente($clienteId);
    $nomeCli = trim((string) ($cliente['nome'] ?? $cliente['razao_social'] ?? ''));
    if ($nomeCli === '') {
        $nomeCli = 'Cliente #' . $clienteId;
    }

    $emitido = date('d/m/Y H:i');
    $st      = medicao_bm_boletim_style_arrays_tipografia();
    $lastCol = CATALOGO_XLSX_LAST_COL;

    $totalItens   = count($itens);
    $totalAtivos  = count(array_filter($itens, static fn ($it) => !empty($it['ativo'])));
    $totalProd    = count(array_filter($itens, static fn ($it) => ($it['tipo'] ?? '') === 'produto'));
    $totalServ    = count(array_filter($itens, static fn ($it) => ($it['tipo'] ?? '') === 'servico'));
    $temEstoque   = repo_cliente_itens_estoque_saldo_column_exists();

    $spreadsheet = new Spreadsheet();
    $spreadsheet->getDefaultStyle()->getFont()->setName(MEDICAO_BM_XLSX_FONT)->setSize(MEDICAO_BM_XLSX_SIZE_BASE);
    $spreadsheet->getDefaultStyle()->getFont()->getColor()->setRGB(MEDICAO_BM_TEXT_MAIN);
    $spreadsheet->getProperties()
        ->setCreator($brand)
        ->setTitle('Catálogo — ' . $nomeCli)
        ->setSubject('Catálogo de produtos e serviços')
        ->setDescription('Exportação CRM');

    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Catálogo');
    $sheet->setShowGridlines(false);

    $r = 1;
    $sheet->mergeCells("A{$r}:{$lastCol}{$r}");
    $sheet->setCellValue("A{$r}", 'CATÁLOGO DE PRODUTOS E SERVIÇOS  ·  ' . $brand);
    $titleHero = $st['title_main'];
    $titleHero['fill'] = medicao_bm_boletim_style_fill_solid(MEDICAO_BM_BRAND_PRIMARY);
    $sheet->getStyle("A{$r}:{$lastCol}{$r}")->applyFromArray($titleHero);
    $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)
        ->setVertical(Alignment::VERTICAL_CENTER)
        ->setWrapText(false);
    $sheet->getRowDimension($r)->setRowHeight(MEDICAO_BM_XLSX_ROW_TITLE);

    ++$r;
    $sheet->mergeCells("A{$r}:{$lastCol}{$r}");
    $sheet->setCellValue("A{$r}", 'Cliente: ' . $nomeCli);
    $sheet->getStyle("A{$r}:{$lastCol}{$r}")->applyFromArray($st['title_sub_flat']);
    $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getAlignment()->setIndent(1);
    $sheet->getRowDimension($r)->setRowHeight(MEDICAO_BM_XLSX_ROW_TITLE_META);

    ++$r;
    $sheet->mergeCells("A{$r}:D{$r}");
    $sheet->setCellValue(
        "A{$r}",
        "Itens: {$totalItens}  ·  Ativos: {$totalAtivos}  ·  Produtos: {$totalProd}  ·  Serviços: {$totalServ}"
    );
    $sheet->mergeCells("E{$r}:{$lastCol}{$r}");
    $sheet->setCellValue("E{$r}", "Referência\nCatálogo CRM");
    $sheet->getStyle("A{$r}:D{$r}")->applyFromArray($st['highlight_financial_box']);
    $sheet->getStyle("E{$r}:{$lastCol}{$r}")->applyFromArray($st['capa_grid_cell_plain']);
    $sheet->getStyle("A{$r}:{$lastCol}{$r}")->applyFromArray($st['section_outline_soft']);
    $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getAlignment()
        ->setVertical(Alignment::VERTICAL_CENTER)
        ->setWrapText(true)
        ->setIndent(1);
    $sheet->getRowDimension($r)->setRowHeight(MEDICAO_BM_XLSX_ROW_CAPA_GRID);

    ++$r;
    $sheet->mergeCells("A{$r}:{$lastCol}{$r}");
    $sheet->setCellValue("A{$r}", 'Exportação CRM · Gerado em ' . $emitido);
    $sheet->getStyle("A{$r}:{$lastCol}{$r}")->applyFromArray($st['capa_service_banner']);
    $sheet->getRowDimension($r)->setRowHeight(MEDICAO_BM_XLSX_ROW_CAPA_SERVICE);

    ++$r;
    $sheet->mergeCells("A{$r}:{$lastCol}{$r}");
    $sheet->setCellValue("A{$r}", '');
    $sheet->getStyle("A{$r}:{$lastCol}{$r}")->applyFromArray([
        'fill' => medicao_bm_boletim_style_fill_solid(MEDICAO_BM_WHITE),
    ]);
    $sheet->getRowDimension($r)->setRowHeight(MEDICAO_BM_XLSX_ROW_SPACER);

    $headerRow = ++$r;
    $headers   = ['Tipo', 'Nome', 'Código', 'Unidade', 'Valor unit. (R$)'];
    if ($temEstoque) {
        $headers[] = 'Estoque saldo';
    }
    $headers[] = 'Descrição';

    foreach ($headers as $i => $label) {
        $col = Coordinate::stringFromColumnIndex($i + 1);
        $sheet->setCellValue($col . $headerRow, $label);
    }
    $lastColData = Coordinate::stringFromColumnIndex(count($headers));
    $sheet->getStyle('A' . $headerRow . ':' . $lastColData . $headerRow)->applyFromArray($st['header_table']);
    $sheet->getRowDimension($headerRow)->setRowHeight(MEDICAO_BM_XLSX_ROW_TABLE_HDR);

    $firstDataRow = $headerRow + 1;
    $row          = $firstDataRow;
    $colValor     = 'E';
    $colEstoque   = $temEstoque ? 'F' : null;
    $colDesc      = $temEstoque ? 'G' : 'F';

    foreach ($itens as $it) {
        $tipoRaw = (string) ($it['tipo'] ?? 'produto');
        $tipoLbl = $tipoRaw === 'servico' ? 'Serviço' : 'Produto';
        $valor   = round((float) ($it['valor_unitario'] ?? 0), 2, PHP_ROUND_HALF_UP);
        $ativo   = !empty($it['ativo']);

        $sheet->setCellValue('A' . $row, $tipoLbl);
        $sheet->setCellValue('B' . $row, (string) ($it['nome'] ?? ''));
        $sheet->setCellValue('C' . $row, (string) ($it['codigo'] ?? ''));
        $sheet->setCellValue('D' . $row, (string) ($it['unidade'] ?? ''));
        $sheet->setCellValue($colValor . $row, $valor);
        if ($colEstoque !== null) {
            $sheet->setCellValue($colEstoque . $row, (float) ($it['estoque_saldo'] ?? 0));
        }
        $sheet->setCellValue($colDesc . $row, (string) ($it['descricao'] ?? ''));

        $bg     = (($row - $firstDataRow) % 2 === 0) ? MEDICAO_BM_WHITE : MEDICAO_BM_BG_ALT;
        $rowSt  = $st['body_row'];
        $rowSt['fill'] = medicao_bm_boletim_style_fill_solid($bg);
        $sheet->getStyle('A' . $row . ':' . $lastColData . $row)->applyFromArray($rowSt);
        $sheet->getStyle('B' . $row)->applyFromArray($st['body_desc']);
        $sheet->getStyle($colDesc . $row)->applyFromArray($st['body_desc']);
        $sheet->getStyle($colValor . $row)->applyFromArray($st['money']);
        $sheet->getStyle($colValor . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        if ($colEstoque !== null) {
            $sheet->getStyle($colEstoque . $row)->applyFromArray($st['numeric']);
        }
        if (!$ativo) {
            $sheet->getStyle('A' . $row)->getFont()->getColor()->setRGB(MEDICAO_BM_TEXT_MUTED);
        }
        $sheet->getRowDimension($row)->setRowHeight(MEDICAO_BM_XLSX_ROW_HEIGHT);
        $row++;
    }

    $lastDataRow = max($firstDataRow, $row - 1);
    if ($lastDataRow >= $firstDataRow) {
        $sheet->getStyle('A' . $firstDataRow . ':' . $lastColData . $lastDataRow)->applyFromArray($st['body_block_border']);
        $sheet->setAutoFilter('A' . $headerRow . ':' . $lastColData . $headerRow);
    }

    $ultimaLinha = max($headerRow, (int) $sheet->getHighestRow());
    medicao_bm_boletim_autosize_colunas($sheet, 'A', $lastColData, 1, $ultimaLinha, $headerRow);
    medicao_bm_boletim_aplicar_layout_sem_quebra($sheet, 'A', $lastColData, 1, $ultimaLinha, [
        'header_row'     => $headerRow,
        'first_data_row' => $firstDataRow,
        'last_data_row'  => $lastDataRow,
        'money_cols'     => [$colValor],
        'qty_cols'       => $colEstoque !== null ? [$colEstoque] : [],
        'left_cols'      => ['A', 'B', 'C', $colDesc],
    ]);
    medicao_bm_boletim_aplicar_alturas_linhas_compactas($sheet, $ultimaLinha, $headerRow, $firstDataRow, $lastDataRow);
    $sheet->freezePane('A' . $firstDataRow);

    $fn = 'catalogo_' . $clienteId . '_' . date('Y-m-d') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    header('Cache-Control: max-age=0');
    (new Xlsx($spreadsheet))->save('php://output');
}
