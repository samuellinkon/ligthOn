<?php
/**
 * Lista merges, larguras de coluna e alturas de linha (trecho inicial) de um .xlsx.
 *
 * Uso: php scripts/dump_xlsx_template_layout.php [caminho.xlsx]
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

$path = $argv[1] ?? ($root . '/assets/templates/boletim_medicao_modelo_1.xlsx');
if (!is_readable($path)) {
    fwrite(STDERR, "Ficheiro não legível: {$path}\n");
    exit(1);
}

$reader = IOFactory::createReader('Xlsx');
$reader->setReadDataOnly(false);
$wb    = $reader->load($path);
$sheet = $wb->getActiveSheet();

echo "Ficheiro: {$path}\n";
echo "Folha: " . $sheet->getTitle() . "\n";
echo "Dimensão usada: " . $sheet->getHighestColumn() . $sheet->getHighestRow() . "\n\n";

echo "--- Merges (" . count($sheet->getMergeCells()) . ") ---\n";
foreach ($sheet->getMergeCells() as $range) {
    echo $range . "\n";
}

echo "\n--- Larguras colunas A–S ---\n";
for ($i = 1; $i <= 19; ++$i) {
    $c = Coordinate::stringFromColumnIndex($i);
    $w = $sheet->getColumnDimension($c)->getWidth();
    echo "{$c}: " . ($w >= 0 ? (string) $w : '(padrão)') . "\n";
}

echo "\n--- Alturas linhas 1–40 ---\n";
for ($r = 1; $r <= 40; ++$r) {
    $h = $sheet->getRowDimension($r)->getRowHeight();
    if ($h >= 0) {
        echo "R{$r}: {$h}\n";
    }
}
