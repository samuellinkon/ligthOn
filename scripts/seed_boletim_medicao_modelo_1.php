<?php
/**
 * Gera/atualiza assets/templates/boletim_medicao_modelo_1.xlsx com capa + tabela institucional GIP (A–S).
 * Executar após alterar o layout em includes/chamados_medicao_export_xlsx.php.
 *
 * Uso: php scripts/seed_boletim_medicao_modelo_1.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/chamados_medicao_export_xlsx.php';

use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$out = $root . '/assets/templates/boletim_medicao_modelo_1.xlsx';

$ctx = [
    'periodo_label'        => 'Modelo de referência',
    'usuario_nome'        => 'Sistema',
    'matriz_label'        => '—',
    'detalhe_itens_linhas' => [],
    'ref_ym'              => '',
    'arquivo_suffix'      => '',
];

$built = bm_med_workbook_build($ctx);
$w     = new Xlsx($built['ss']);
$w->save($out);

echo "Gravado: {$out}\n";
