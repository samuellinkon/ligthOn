<?php
declare(strict_types=1);

/**
 * Exportação e modelo XLSX do catálogo — layout alinhado ao boletim de medição (marca OnLight).
 */

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require_once __DIR__ . '/medicao_export_bm_boletim_xlsx.php';

/**
 * @return list<string>
 */
function catalogo_xlsx_table_headers(bool $temEstoque, bool $temCapacidade): array
{
    $headers = ['Tipo', 'Nome', 'Código', 'Unidade', 'Valor unit. (R$)'];
    if ($temEstoque && $temCapacidade) {
        $headers[] = 'Estoque';
        $headers[] = 'Saldo';
    } elseif ($temEstoque) {
        $headers[] = 'Estoque saldo';
    }
    $headers[] = 'Descrição';

    return $headers;
}

/**
 * @return array{spreadsheet: Spreadsheet, header_row: int, first_data_row: int, last_col_data: string, col_valor: string, col_estoque: ?string, col_saldo: ?string, col_desc: string, tem_estoque: bool}
 */
function catalogo_xlsx_build_workbook(
    int $clienteId,
    string $tituloCapa,
    string $subtituloCapa,
    string $faixaResumo,
    string $faixaRodape,
    array $linhasDados
): array {
    if (!defined('APP_BRAND_NAME')) {
        require_once __DIR__ . '/config.php';
    }

    $brand   = defined('APP_BRAND_NAME') ? (string) APP_BRAND_NAME : 'OnLight';
    $cliente = repo_cliente($clienteId);
    $nomeCli = trim((string) ($cliente['nome'] ?? $cliente['razao_social'] ?? $cliente['empresa'] ?? ''));
    if ($nomeCli === '') {
        $nomeCli = 'Cliente #' . $clienteId;
    }

    $st            = medicao_bm_boletim_style_arrays_tipografia();
    $temEstoque    = repo_cliente_itens_estoque_saldo_column_exists();
    $temCapacidade = repo_cliente_itens_estoque_capacidade_column_exists();
    $headers       = catalogo_xlsx_table_headers($temEstoque, $temCapacidade);
    $lastCol       = Coordinate::stringFromColumnIndex(count($headers));

    $spreadsheet = new Spreadsheet();
    $spreadsheet->getDefaultStyle()->getFont()->setName(MEDICAO_BM_XLSX_FONT)->setSize(MEDICAO_BM_XLSX_SIZE_BASE);
    $spreadsheet->getDefaultStyle()->getFont()->getColor()->setRGB(MEDICAO_BM_TEXT_MAIN);
    $spreadsheet->getProperties()
        ->setCreator($brand)
        ->setTitle($tituloCapa . ' — ' . $nomeCli)
        ->setSubject('Catálogo de produtos e serviços')
        ->setDescription('CRM');

    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Catálogo');
    $sheet->setShowGridlines(false);

    $r = 1;
    $sheet->mergeCells("A{$r}:{$lastCol}{$r}");
    $sheet->setCellValue("A{$r}", $tituloCapa . '  ·  ' . $brand);
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
    $sheet->setCellValue("A{$r}", $subtituloCapa !== '' ? $subtituloCapa : ('Cliente: ' . $nomeCli));
    $sheet->getStyle("A{$r}:{$lastCol}{$r}")->applyFromArray($st['title_sub_flat']);
    $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getAlignment()->setIndent(1);
    $sheet->getRowDimension($r)->setRowHeight(MEDICAO_BM_XLSX_ROW_TITLE_META);

    ++$r;
    $sheet->mergeCells("A{$r}:D{$r}");
    $sheet->setCellValue("A{$r}", $faixaResumo);
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
    $sheet->setCellValue("A{$r}", $faixaRodape);
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
    $colEstoque   = null;
    $colSaldo     = null;
    $colDesc      = 'F';
    if ($temEstoque && $temCapacidade) {
        $colEstoque = 'F';
        $colSaldo   = 'G';
        $colDesc    = 'H';
    } elseif ($temEstoque) {
        $colEstoque = 'F';
        $colDesc    = 'G';
    }

    foreach ($linhasDados as $it) {
        $tipoRaw = (string) ($it['tipo'] ?? 'produto');
        $tipoLbl = catalogo_xlsx_tipo_label($tipoRaw);
        $valor   = round((float) ($it['valor_unitario'] ?? 0), 2, PHP_ROUND_HALF_UP);
        $ativo   = array_key_exists('ativo', $it) ? !empty($it['ativo']) : true;
        $estCap  = isset($it['estoque_capacidade'])
            ? (float) $it['estoque_capacidade']
            : (float) ($it['estoque_saldo'] ?? 0);
        $estSaldo = (float) ($it['estoque_saldo'] ?? $estCap);

        $sheet->setCellValue('A' . $row, $tipoLbl);
        $sheet->setCellValue('B' . $row, (string) ($it['nome'] ?? ''));
        $sheet->setCellValue('C' . $row, (string) ($it['codigo'] ?? ''));
        $sheet->setCellValue('D' . $row, (string) ($it['unidade'] ?? 'UN'));
        $sheet->setCellValue($colValor . $row, $valor);
        if ($colEstoque !== null && $colSaldo !== null) {
            $sheet->setCellValue($colEstoque . $row, $estCap);
            $sheet->setCellValue($colSaldo . $row, $estSaldo);
        } elseif ($colEstoque !== null) {
            $sheet->setCellValue($colEstoque . $row, $estSaldo);
        }
        $sheet->setCellValue($colDesc . $row, (string) ($it['descricao'] ?? ''));

        $bg    = (($row - $firstDataRow) % 2 === 0) ? MEDICAO_BM_WHITE : MEDICAO_BM_BG_ALT;
        $rowSt = $st['body_row'];
        $rowSt['fill'] = medicao_bm_boletim_style_fill_solid($bg);
        $sheet->getStyle('A' . $row . ':' . $lastColData . $row)->applyFromArray($rowSt);
        $sheet->getStyle('B' . $row)->applyFromArray($st['body_desc']);
        $sheet->getStyle($colDesc . $row)->applyFromArray($st['body_desc']);
        $sheet->getStyle($colValor . $row)->applyFromArray($st['money']);
        $sheet->getStyle($colValor . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        if ($colEstoque !== null) {
            $sheet->getStyle($colEstoque . $row)->applyFromArray($st['numeric']);
        }
        if ($colSaldo !== null) {
            $sheet->getStyle($colSaldo . $row)->applyFromArray($st['numeric']);
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
    catalogo_xlsx_finalize_sheet($sheet, $headerRow, $firstDataRow, $lastDataRow, $lastColData, $colValor, $colEstoque, $colSaldo, $colDesc, $ultimaLinha);

    return [
        'spreadsheet'    => $spreadsheet,
        'header_row'     => $headerRow,
        'first_data_row' => $firstDataRow,
        'last_col_data'  => $lastColData,
        'col_valor'      => $colValor,
        'col_estoque'    => $colEstoque,
        'col_saldo'      => $colSaldo,
        'col_desc'       => $colDesc,
        'tem_estoque'    => $temEstoque,
    ];
}

function catalogo_xlsx_tipo_label(string $tipoRaw): string
{
    $t = strtolower(trim($tipoRaw));

    return $t === 'servico' ? 'Serviço' : 'Produto';
}

function catalogo_xlsx_finalize_sheet(
    Worksheet $sheet,
    int $headerRow,
    int $firstDataRow,
    int $lastDataRow,
    string $lastColData,
    string $colValor,
    ?string $colEstoque,
    ?string $colSaldo,
    string $colDesc,
    int $ultimaLinha
): void {
    $qtyCols = array_values(array_filter([$colEstoque, $colSaldo], static fn ($c) => $c !== null));
    medicao_bm_boletim_autosize_colunas($sheet, 'A', $lastColData, 1, $ultimaLinha, $headerRow);
    medicao_bm_boletim_aplicar_layout_sem_quebra($sheet, 'A', $lastColData, 1, $ultimaLinha, [
        'header_row'     => $headerRow,
        'first_data_row' => $firstDataRow,
        'last_data_row'  => $lastDataRow,
        'money_cols'     => [$colValor],
        'qty_cols'       => $qtyCols,
        'left_cols'      => ['A', 'B', 'C', $colDesc],
    ]);
    medicao_bm_boletim_aplicar_alturas_linhas_compactas($sheet, $ultimaLinha, $headerRow, $firstDataRow, $lastDataRow);
    $sheet->freezePane('A' . $firstDataRow);
}

function catalogo_xlsx_send_spreadsheet(Spreadsheet $spreadsheet, string $filename): void
{
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    (new Xlsx($spreadsheet))->save('php://output');
}

/**
 * @param list<array<string,mixed>> $itens
 */
function catalogo_export_xlsx_send(int $clienteId, array $itens): void
{
    $totalItens  = count($itens);
    $totalAtivos = count(array_filter($itens, static fn ($it) => !empty($it['ativo'])));
    $totalProd   = count(array_filter($itens, static fn ($it) => ($it['tipo'] ?? '') === 'produto'));
    $totalServ   = count(array_filter($itens, static fn ($it) => ($it['tipo'] ?? '') === 'servico'));
    $emitido     = date('d/m/Y H:i');

    $built = catalogo_xlsx_build_workbook(
        $clienteId,
        'CATÁLOGO DE PRODUTOS E SERVIÇOS',
        '',
        "Itens: {$totalItens}  ·  Ativos: {$totalAtivos}  ·  Produtos: {$totalProd}  ·  Serviços: {$totalServ}",
        'Exportação CRM · Gerado em ' . $emitido,
        $itens
    );

    catalogo_xlsx_send_spreadsheet(
        $built['spreadsheet'],
        'catalogo_' . $clienteId . '_' . date('Y-m-d') . '.xlsx'
    );
}

function catalogo_modelo_xlsx_send(int $clienteId): void
{
    $emitido = date('d/m/Y H:i');
    $exemplos = [
        [
            'tipo'               => 'produto',
            'nome'               => 'Exemplo produto',
            'codigo'             => 'SKU-001',
            'unidade'            => 'UN',
            'valor_unitario'     => 59.90,
            'estoque_capacidade' => 100.0,
            'estoque_saldo'      => 25.0,
            'descricao'          => 'Substitua pelos seus dados',
        ],
        [
            'tipo'               => 'servico',
            'nome'               => 'Exemplo serviço',
            'codigo'             => 'SERV-001',
            'unidade'            => 'UN',
            'valor_unitario'     => 120.00,
            'estoque_capacidade' => 0.0,
            'estoque_saldo'      => 0.0,
            'descricao'          => '',
        ],
    ];

    $built = catalogo_xlsx_build_workbook(
        $clienteId,
        'MODELO DE IMPORTAÇÃO — CATÁLOGO',
        'Cliente: preencha as linhas abaixo do cabeçalho e envie pelo CRM',
        'Preencha tipo (produto/servico), nome e demais colunas · Código único por empresa',
        'Modelo CRM · Gerado em ' . $emitido . ' · Não altere os títulos das colunas',
        $exemplos
    );

    catalogo_xlsx_send_spreadsheet(
        $built['spreadsheet'],
        'modelo_importacao_catalogo_' . $clienteId . '_' . date('Y-m-d') . '.xlsx'
    );
}
