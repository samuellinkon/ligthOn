<?php
declare(strict_types=1);

/**
 * Evita ValueError «Path cannot be empty» (PHP 8+): Dompdf chama file_get_contents com URIs vazias
 * quando encontra atributos src/href vazios ou url("") no CSS.
 */
function chamados_dompdf_strip_empty_resource_uris(string $html): string
{
    $out = preg_replace('/\s(?:src|href|xlink:href)\s*=\s*(["\'])\s*\1\s*/iu', ' ', $html) ?? $html;
    $out = preg_replace('/\surl\s*\(\s*(["\'])\s*\1\s*\)/iu', ' ', $out) ?? $out;

    return $out;
}

/**
 * Dompdf usa tempnam() para subsetting de fontes e para imagens. Com open_basedir (só htdocs),
 * sys_get_temp_dir() pode falhar → tempnam=false → fopen('') → «Path cannot be empty».
 * Usamos pastas dentro do projeto e desativamos subsetting (embed da fonte completa, mais estável).
 */
function chamados_dompdf_apply_writable_options(\Dompdf\Options $options): void
{
    $projectRoot = realpath(dirname(__DIR__));
    if ($projectRoot === false) {
        return;
    }
    $tmpDir     = $projectRoot . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR . 'dompdf_tmp';
    $fontCache  = $projectRoot . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR . 'dompdf_fontcache';
    foreach ([$tmpDir, $fontCache] as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new RuntimeException('Dompdf: não foi possível criar o diretório gravável: ' . $dir);
        }
    }
    $options->set('tempDir', $tmpDir);
    $options->set('fontCache', $fontCache);
    $options->set('isFontSubsettingEnabled', false);

    $chroot = $options->getChroot();
    if (!in_array($projectRoot, $chroot, true)) {
        $chroot[] = $projectRoot;
        $options->setChroot($chroot);
    }
}

/**
 * Gera PDF binário (Dompdf) a partir do HTML do relatório de chamados + anexos e envia-o (com merge FPDI opcional).
 *
 * @param string|null $downloadUtf8 Nome sugerido (UTF-8), ex.: "Chamados e anexos — 01_05_2026 — 31_05_2026 — LightOn.pdf"
 * @param list<string> $pdfAnexoPaths caminhos absolutos de PDFs a juntar após o relatório Dompdf (FPDI)
 *
 * @throws RuntimeException se Dompdf não estiver instalado ou falhar a renderização
 */
function chamados_periodo_anexos_stream_pdf(string $html, ?string $downloadUtf8 = null, array $pdfAnexoPaths = []): void
{
    @ini_set('zlib.output_compression', '0');
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }

    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_readable($autoload)) {
        throw new RuntimeException('Dompdf não encontrado. Execute composer install na raiz do projeto.');
    }
    require_once $autoload;
    require_once __DIR__ . '/chamados_periodo_pdf_merge.php';

    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    chamados_dompdf_apply_writable_options($options);

    $dompdf = new \Dompdf\Dompdf($options);
    $html = chamados_dompdf_strip_empty_resource_uris($html);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $binary = $dompdf->output();
    if ($pdfAnexoPaths !== [] && chamados_pdf_fpdi_disponivel()) {
        try {
            $binary = chamados_pdf_merge_main_plus_anexos($binary, $pdfAnexoPaths);
        } catch (Throwable $e) {
            error_log('[crm_prefeitura] merge PDF anexos: ' . $e->getMessage());
        }
    } elseif ($pdfAnexoPaths !== [] && !chamados_pdf_fpdi_disponivel()) {
        error_log('[crm_prefeitura] PDF anexos não fundidos: execute «composer update» (setasign/fpdi + fpdf/fpdf).');
    }

    $stamp = date('Y-m-d_His');
    $utf8 = $downloadUtf8 !== null && $downloadUtf8 !== ''
        ? $downloadUtf8
        : ('chamados_anexos_' . $stamp . '.pdf');
    if (!str_ends_with(strtolower($utf8), '.pdf')) {
        $utf8 .= '.pdf';
    }
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', str_replace('—', '-', $utf8));
    if ($ascii === false || $ascii === '') {
        $ascii = 'chamados_anexos_' . $stamp . '.pdf';
    }
    $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $ascii) ?? $ascii;
    if ($ascii === '' || $ascii === '_' || !str_ends_with(strtolower($ascii), '.pdf')) {
        $ascii = 'chamados_anexos_' . $stamp . '.pdf';
    }

    header('Content-Type: application/pdf');
    header(
        'Content-Disposition: attachment; filename="' . str_replace(['\\', '"'], ['\\\\', '\\"'], $ascii)
        . '"; filename*=UTF-8\'\'' . rawurlencode($utf8)
    );
    echo $binary;
}
