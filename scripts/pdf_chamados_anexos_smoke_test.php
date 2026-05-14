<?php
/**
 * Smoke test: gera HTML do relatório PDF (embed base64 desligado) e renderiza com Dompdf.
 * Uso: php scripts/pdf_chamados_anexos_smoke_test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/chamados_periodo_export_pdf.php';
require_once $root . '/includes/chamados_periodo_pdf_dompdf.php';

$html = chamados_periodo_anexos_export_html(
    [],
    'Smoke test',
    0,
    0,
    false,
    false,
    false,
    [],
    '',
    []
);
if ($html === '') {
    fwrite(STDERR, "HTML vazio.\n");
    exit(1);
}

$options = new \Dompdf\Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
chamados_dompdf_apply_writable_options($options);

$dompdf = new \Dompdf\Dompdf($options);
$dompdf->loadHtml(chamados_dompdf_strip_empty_resource_uris($html), 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$bin = $dompdf->output();
$n = strlen($bin);
if ($n < 500) {
    fwrite(STDERR, "PDF demasiado pequeno ({$n} bytes).\n");
    exit(1);
}

echo "OK: PDF gerado com {$n} bytes (Dompdf).\n";
exit(0);
