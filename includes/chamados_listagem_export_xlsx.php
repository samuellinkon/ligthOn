<?php
declare(strict_types=1);

/**
 * Exportação tabular XLSX da listagem de chamados (todos os campos do registo).
 */

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/lighton_export_xlsx_brand.php';

/**
 * @return list<array{0:string,1:string}> [rótulo, chave]
 */
function chamados_listagem_xlsx_colunas(): array
{
    return [
        ['ID', 'id'],
        ['Prefeitura', 'cliente'],
        ['Título', 'titulo'],
        ['Descrição', 'descricao'],
        ['Prioridade', 'prioridade'],
        ['Status', 'status'],
        ['Responsável', 'responsavel'],
        ['Técnicos', 'tecnico_nome'],
        ['Data abertura (CRM)', 'data'],
        ['Data abertura OS', 'data_abertura_os'],
        ['Origem OS', 'origem_os'],
        ['Problema OS', 'problema_os'],
        ['Tipo OS', 'tipo_os'],
        ['Ponto referência', 'ponto_referencia'],
        ['Contribuinte nome', 'contribuinte_nome'],
        ['Contribuinte CPF', 'contribuinte_cpf'],
        ['Contribuinte telefone', 'contribuinte_telefone'],
        ['Contribuinte e-mail', 'contribuinte_email'],
        ['CEP', 'os_cep'],
        ['Logradouro', 'os_logradouro'],
        ['Número', 'os_numero'],
        ['Complemento', 'os_complemento'],
        ['Bairro', 'os_bairro'],
        ['Cidade', 'os_cidade'],
        ['UF', 'os_uf'],
        ['Endereço completo', 'endereco_completo'],
        ['Latitude', 'latitude'],
        ['Longitude', 'longitude'],
        ['Poste (código)', 'ponto_codigo_poste'],
        ['Poste ID', 'ponto_iluminacao_id'],
        ['Serviço', 'servico_nome'],
        ['Serviço tipo', 'servico_tipo'],
        ['Serviço valor unit.', 'servico_valor_unitario'],
        ['Finalizado operador em', 'finalizado_operador_em'],
        ['Aprovado gestor em', 'aprovado_gestor_em'],
        ['Aprovado por', 'aprovado_gestor_nome'],
        ['Aguardando aprovação gestor', 'aguardando_aprovacao'],
        ['Checklist realizado', 'checklist_realizado'],
        ['Ativo', 'ativo'],
        ['Excluído em', 'excluido_em'],
        ['Excluído por', 'excluido_por_nome'],
    ];
}

/**
 * @param array<string,mixed> $row
 * @return list<string|int|float|null>
 */
function chamados_listagem_xlsx_row_values(array $row): array
{
    $aguarda = (!empty($row['finalizado_operador_em']) && empty($row['aprovado_gestor_em'])) ? 'Sim' : 'Não';
    $row['aguardando_aprovacao'] = $aguarda;
    if (array_key_exists('ativo', $row)) {
        $row['ativo'] = ((int) ($row['ativo'] ?? 1) === 1) ? 'Sim' : 'Não';
    }

    $out = [];
    foreach (chamados_listagem_xlsx_colunas() as [, $key]) {
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
 * @param array{periodo_label?:string,filtro?:string,busca?:string} $meta
 */
function chamados_listagem_export_xlsx_send(array $rows, array $meta = []): void
{
    if (!defined('APP_BRAND_NAME')) {
        require_once __DIR__ . '/config.php';
    }
    $brand = defined('APP_BRAND_NAME') ? (string) APP_BRAND_NAME : 'OnLight';
    $cols  = chamados_listagem_xlsx_colunas();
    $nCol  = count($cols);
    $last  = Coordinate::stringFromColumnIndex($nCol);

    $spreadsheet = new Spreadsheet();
    $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);
    $spreadsheet->getProperties()
        ->setCreator($brand)
        ->setTitle('Chamados — ' . $brand)
        ->setSubject('Exportação da listagem de chamados')
        ->setDescription('CRM');

    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Chamados');
    $sheet->setShowGridlines(false);

    $periodo = trim((string) ($meta['periodo_label'] ?? ''));
    $filtro  = trim((string) ($meta['filtro'] ?? ''));
    $busca   = trim((string) ($meta['busca'] ?? ''));
    $resumoParts = [count($rows) . ' chamado(s)'];
    if ($periodo !== '') {
        $resumoParts[] = 'Período: ' . $periodo;
    }
    if ($filtro !== '') {
        $resumoParts[] = 'Filtro: ' . $filtro;
    }
    if ($busca !== '') {
        $resumoParts[] = 'Busca: ' . $busca;
    }
    $resumoParts[] = 'Gerado em ' . date('d/m/Y H:i');

    $r = 1;
    $sheet->mergeCells("A{$r}:{$last}{$r}");
    $sheet->setCellValue("A{$r}", 'CHAMADOS  ·  ' . $brand);
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
    foreach ($rows as $row) {
        ++$r;
        $vals = chamados_listagem_xlsx_row_values($row);
        foreach ($vals as $i => $val) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue("{$col}{$r}", $val);
        }
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
    // Limita largura de colunas longas
    foreach (['D', 'Z'] as $wideCol) {
        if ($sheet->getColumnDimension($wideCol)->getWidth() > 48) {
            $sheet->getColumnDimension($wideCol)->setAutoSize(false)->setWidth(48);
        }
    }

    $sheet->freezePane('A' . $firstData);
    $sheet->setAutoFilter("A{$headerRow}:{$last}{$lastData}");

    $filename = 'chamados_' . date('Y-m-d_His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    (new Xlsx($spreadsheet))->save('php://output');
}
