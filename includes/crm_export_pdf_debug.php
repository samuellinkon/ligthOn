<?php
declare(strict_types=1);

/**
 * Ferramentas de diagnóstico para falhas na geração de PDF (Dompdf).
 */

/** Remove todos os buffers (avisos/notices antes de header() corrompem a resposta → ERR_INVALID_RESPONSE). */
function crm_export_pdf_flush_output_buffers(): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

function crm_export_pdf_debug_enabled(): bool
{
    return defined('CRM_EXPORT_PDF_DEBUG') && CRM_EXPORT_PDF_DEBUG === true;
}

/**
 * Escreve no error_log do PHP e, com CRM_EXPORT_PDF_DEBUG, anexa stack em writable/pdf_export_debug.log
 */
function crm_export_pdf_log_failure(Throwable $e, array $context = []): void
{
    $line = sprintf(
        '[crm_prefeitura] PDF export: %s in %s:%d',
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    );
    error_log($line);

    if (!crm_export_pdf_debug_enabled()) {
        return;
    }

    $root = dirname(__DIR__);
    $dir  = $root . '/writable';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $path = $dir . '/pdf_export_debug.log';
    $buf  = "\n" . str_repeat('=', 72) . "\n";
    $buf .= date('c') . "\n";
    $buf .= $line . "\n";
    if ($context !== []) {
        $buf .= json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    }
    $buf .= "\n--- Stack trace ---\n" . $e->getTraceAsString() . "\n";
    @file_put_contents($path, $buf, FILE_APPEND | LOCK_EX);
}

function crm_export_pdf_wants_browser_debug(): bool
{
    return crm_export_pdf_debug_enabled()
        && isset($_GET['pdf_debug'])
        && (string) $_GET['pdf_debug'] === '1';
}
