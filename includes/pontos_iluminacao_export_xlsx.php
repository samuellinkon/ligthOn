<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/medicao_export_bm_boletim_xlsx.php';

/** @return list<string> */
function pontos_iluminacao_export_headers(): array
{
    return [
        'ID / código do poste',
        'Identificador externo',
        'Bairro',
        'Status',
        'Endereço completo',
        'Referência',
        'Latitude',
        'Longitude',
        'Observações',
        'Qtd. fotos',
    ];
}

/**
 * @return array<int, int>
 */
function pontos_iluminacao_export_qtd_fotos(array $pontoIds): array
{
    $pdo = db();
    $ids = array_values(array_unique(array_filter(array_map('intval', $pontoIds), static fn (int $id): bool => $id > 0)));
    if (!$pdo || $ids === []) {
        return [];
    }
    try {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("
            SELECT ponto_iluminacao_id, COUNT(*) AS n
            FROM ponto_iluminacao_imagens
            WHERE ponto_iluminacao_id IN ($ph)
            GROUP BY ponto_iluminacao_id
        ");
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) ($r['ponto_iluminacao_id'] ?? 0)] = (int) ($r['n'] ?? 0);
        }

        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @param list<array<string,mixed>> $pontos
 */
function pontos_iluminacao_export_xlsx_send(array $pontos, string $arquivoBase = 'parque_iluminacao'): void
{
    if (!defined('APP_BRAND_NAME')) {
        require_once __DIR__ . '/config.php';
    }
    $brand = defined('APP_BRAND_NAME') ? (string) APP_BRAND_NAME : 'OnLight';
    $hdrs  = pontos_iluminacao_export_headers();
    $last  = Coordinate::stringFromColumnIndex(count($hdrs));
    $st    = medicao_bm_boletim_style_arrays_tipografia();

    $ids = [];
    foreach ($pontos as $p) {
        $ids[] = (int) ($p['id'] ?? 0);
    }
    $fotos = pontos_iluminacao_export_qtd_fotos($ids);

    $ss = new Spreadsheet();
    $ss->getDefaultStyle()->getFont()->setName(MEDICAO_BM_XLSX_FONT)->setSize(MEDICAO_BM_XLSX_SIZE_BASE);
    $ss->getProperties()->setCreator($brand)->setTitle('Parque de iluminação');
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle('Parque');
    $sheet->setShowGridlines(false);

    $sheet->mergeCells('A1:' . $last . '1');
    $sheet->setCellValue('A1', 'PARQUE DE ILUMINAÇÃO  ·  ' . $brand);
    $sheet->getStyle('A1:' . $last . '1')->applyFromArray($st['title_main']);
    $sheet->getRowDimension(1)->setRowHeight(28);

    $sheet->mergeCells('A2:' . $last . '2');
    $sheet->setCellValue('A2', count($pontos) . ' poste(s)  ·  Gerado em ' . date('d/m/Y H:i'));
    $sheet->getStyle('A2:' . $last . '2')->applyFromArray($st['title_sub_flat']);

    $hr = 4;
    $ci = 1;
    foreach ($hdrs as $h) {
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($ci) . $hr, $h);
        ++$ci;
    }
    $sheet->getStyle('A' . $hr . ':' . $last . $hr)->applyFromArray($st['header_table']);
    $sheet->getRowDimension($hr)->setRowHeight(22);

    $r = $hr + 1;
    foreach ($pontos as $p) {
        $pid = (int) ($p['id'] ?? 0);
        $sheet->setCellValue('A' . $r, (string) ($p['codigo_poste'] ?? ''));
        $sheet->setCellValue('B' . $r, (string) ($p['identificador_externo'] ?? ''));
        $sheet->setCellValue('C' . $r, (string) ($p['bairro'] ?? ''));
        $sheet->setCellValue('D' . $r, (string) ($p['status'] ?? ''));
        $sheet->setCellValue('E' . $r, (string) ($p['endereco_completo'] ?? ''));
        $sheet->setCellValue('F' . $r, (string) ($p['referencia'] ?? ''));
        $lat = $p['latitude'] ?? null;
        $lng = $p['longitude'] ?? null;
        if ($lat !== null && $lat !== '') {
            $sheet->setCellValue('G' . $r, (float) $lat);
        }
        if ($lng !== null && $lng !== '') {
            $sheet->setCellValue('H' . $r, (float) $lng);
        }
        $sheet->setCellValue('I' . $r, (string) ($p['observacoes'] ?? ''));
        $sheet->setCellValue('J' . $r, (int) ($fotos[$pid] ?? 0));
        ++$r;
    }

    $lastData = $r - 1;
    if ($lastData >= $hr + 1) {
        $sheet->getStyle('A' . ($hr + 1) . ':' . $last . $lastData)->applyFromArray([
            'borders' => medicao_bm_boletim_style_borders_all(MEDICAO_BM_BORDER_LIGHT, Border::BORDER_THIN),
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);
        $sheet->getStyle('G' . ($hr + 1) . ':H' . $lastData)->getNumberFormat()->setFormatCode('0.0000000');
        $sheet->setAutoFilter('A' . $hr . ':' . $last . $lastData);
    }

    $sheet->getColumnDimension('A')->setWidth(22);
    $sheet->getColumnDimension('B')->setWidth(20);
    $sheet->getColumnDimension('C')->setWidth(22);
    $sheet->getColumnDimension('D')->setWidth(12);
    $sheet->getColumnDimension('E')->setWidth(42);
    $sheet->getColumnDimension('F')->setWidth(28);
    $sheet->getColumnDimension('G')->setWidth(14);
    $sheet->getColumnDimension('H')->setWidth(14);
    $sheet->getColumnDimension('I')->setWidth(56);
    $sheet->getColumnDimension('J')->setWidth(12);
    $sheet->freezePane('A5');
    $sheet->getPageSetup()->setFitToPage(true)->setFitToWidth(1)->setFitToHeight(0);

    $fn = preg_replace('/[^A-Za-z0-9_-]+/', '_', $arquivoBase) ?: 'parque_iluminacao';
    $fn .= '_' . date('Y-m-d_His') . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    header('Cache-Control: max-age=0');
    (new Xlsx($ss))->save('php://output');
    exit;
}
