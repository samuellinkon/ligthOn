<?php
declare(strict_types=1);

/**
 * PDFs anexados encontrados em disco (para fundir ao relatório) e respetivos IDs de anexo.
 *
 * @param list<array{chamado: array<string,mixed>, anexos: list<array<string,mixed>>}> $items
 * @return array{paths: list<string>, anexo_ids: list<int>}
 */
function chamados_periodo_pdf_anexos_para_merge(array $items): array
{
    require_once __DIR__ . '/upload.php';
    $paths   = [];
    $seen    = [];
    $idOrder = [];
    foreach ($items as $pack) {
        $cid = (int) ($pack['chamado']['id'] ?? 0);
        if ($cid <= 0) {
            continue;
        }
        foreach ($pack['anexos'] ?? [] as $a) {
            $mime = strtolower((string) ($a['mime'] ?? ''));
            $nome = (string) ($a['nome_original'] ?? '');
            $fs   = basename(trim((string) ($a['nome_arquivo'] ?? '')));
            $ext  = strtolower(pathinfo($nome !== '' ? $nome : $fs, PATHINFO_EXTENSION));
            $isPdf = ($ext === 'pdf') || str_contains($mime, 'pdf');
            if (!$isPdf || $fs === '') {
                continue;
            }
            $path = upload_dir_chamado($cid) . DIRECTORY_SEPARATOR . $fs;
            $real = is_file($path) ? realpath($path) : false;
            if ($real === false || !is_readable($real) || !str_ends_with(strtolower($real), '.pdf')) {
                continue;
            }
            $aid = (int) ($a['id'] ?? 0);
            if (!isset($seen[$real])) {
                $seen[$real] = true;
                $paths[] = $real;
            }
            if ($aid > 0 && !in_array($aid, $idOrder, true)) {
                $idOrder[] = $aid;
            }
        }
    }

    return ['paths' => $paths, 'anexo_ids' => $idOrder];
}

/**
 * FPDI estende FpdfTpl, que estende a classe global \FPDF. O pacote Composer fpdf/fpdf
 * expõe apenas Fpdf\Fpdf — regista o alias antes de carregar setasign\Fpdi\Fpdi.
 */
function chamados_pdf_alias_fpdf_global_se_necessario(): void
{
    if (class_exists('FPDF', false)) {
        return;
    }
    if (!class_exists(\Fpdf\Fpdf::class, true)) {
        return;
    }
    class_alias(\Fpdf\Fpdf::class, 'FPDF');
}

function chamados_pdf_fpdi_disponivel(): bool
{
    chamados_pdf_alias_fpdf_global_se_necessario();

    return class_exists(\setasign\Fpdi\Fpdi::class)
        && class_exists(\setasign\Fpdi\PdfParser\StreamReader::class);
}

/**
 * Junta o PDF principal (Dompdf) com PDFs anexados. Limite de páginas só nos anexos (não no relatório).
 *
 * @param list<string> $pdfPaths caminhos absolutos de PDFs a acrescentar após o principal
 *
 * @throws Throwable PDFs encriptados ou incompatíveis podem falhar
 */
function chamados_pdf_merge_main_plus_anexos(string $mainPdfBinary, array $pdfPaths, int $maxPaginasAnexos = 120): string
{
    if ($pdfPaths === []) {
        return $mainPdfBinary;
    }
    if (!chamados_pdf_fpdi_disponivel()) {
        return $mainPdfBinary;
    }

    $pdf = new \setasign\Fpdi\Fpdi();

    $importarTodas = static function (\setasign\Fpdi\Fpdi $pdf, string $source, bool $fromString): void {
        $pageCount = $fromString
            ? $pdf->setSourceFile(\setasign\Fpdi\PdfParser\StreamReader::createByString($source))
            : $pdf->setSourceFile($source);
        for ($p = 1; $p <= $pageCount; $p++) {
            $tplId = $pdf->importPage($p);
            $size  = $pdf->getTemplateSize($tplId);
            $w     = (float) ($size['width'] ?? 210);
            $h     = (float) ($size['height'] ?? 297);
            $o     = $size['orientation'] ?? ($w > $h ? 'L' : 'P');
            $pdf->AddPage($o, [$w, $h]);
            $pdf->useTemplate($tplId, 0, 0, $w, $h, true);
        }
    };

    $importarTodas($pdf, $mainPdfBinary, true);

    $restantes = $maxPaginasAnexos;
    foreach ($pdfPaths as $path) {
        if ($restantes <= 0) {
            break;
        }
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }
        $pageCount = $pdf->setSourceFile($path);
        for ($p = 1; $p <= $pageCount && $restantes > 0; $p++) {
            $tplId = $pdf->importPage($p);
            $size  = $pdf->getTemplateSize($tplId);
            $w     = (float) ($size['width'] ?? 210);
            $h     = (float) ($size['height'] ?? 297);
            $o     = $size['orientation'] ?? ($w > $h ? 'L' : 'P');
            $pdf->AddPage($o, [$w, $h]);
            $pdf->useTemplate($tplId, 0, 0, $w, $h, true);
            $restantes--;
        }
    }

    $out = $pdf->Output('S');
    if (!is_string($out) || $out === '') {
        throw new RuntimeException('FPDI devolveu PDF vazio após merge.');
    }

    return $out;
}
