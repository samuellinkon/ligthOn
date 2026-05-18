<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Caminho do modelo visual do boletim de medição (Chamados).
 * Deve existir em `assets/templates/` (gerado/atualizado por `scripts/seed_boletim_medicao_modelo_1.php`).
 */
function bm_med_tpl1_model_path(): string
{
    return dirname(__DIR__) . '/assets/templates/boletim_medicao_modelo_1.xlsx';
}

function bm_med_tpl1_celula_texto_plano(mixed $v): string
{
    if ($v instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
        return trim($v->getPlainText());
    }

    return trim((string) $v);
}

/** Localiza a linha do cabeçalho GIP: DATA + ID (novo) ou DATA + PROTOCOLO GIP (legado); senão qualquer célula «PROTOCOLO GIP». */
function bm_med_tpl1_find_gip_header_row(Worksheet $sheet): ?int
{
    $maxR = min(120, max(5, (int) $sheet->getHighestRow()));
    $hi   = Coordinate::columnIndexFromString($sheet->getHighestColumn());
    $maxC = min(Coordinate::columnIndexFromString(BM_MED_GIP_LAST_COL), max(2, $hi));
    for ($r = 1; $r <= $maxR; ++$r) {
        $a = strtoupper(bm_med_tpl1_celula_texto_plano($sheet->getCell('A' . $r)->getValue()));
        $b = strtoupper(bm_med_tpl1_celula_texto_plano($sheet->getCell('B' . $r)->getValue()));
        if ($a === 'DATA' && ($b === 'ID' || stripos($b, 'PROTOCOLO GIP') !== false)) {
            return $r;
        }
    }
    for ($r = 1; $r <= $maxR; ++$r) {
        for ($ci = 1; $ci <= $maxC; ++$ci) {
            $c = Coordinate::stringFromColumnIndex($ci);
            $v = bm_med_tpl1_celula_texto_plano($sheet->getCell($c . $r)->getValue());
            if ($v !== '' && stripos($v, 'PROTOCOLO GIP') !== false) {
                return $r;
            }
        }
    }

    return null;
}

/**
 * Aceita ficheiros com folha «MEDIÇÃO» e modelo institucional GIP (cabeçalho DATA + ID, ou legado DATA + PROTOCOLO GIP / célula PROTOCOLO GIP)
 * ou capa com título «BOLETIM» em A1/B1.
 */
function bm_med_tpl1_validate(string $tplPath): bool
{
    if (!is_readable($tplPath)) {
        return false;
    }
    try {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $wb    = $reader->load($tplPath);
        $sheet = $wb->getActiveSheet();
        if ($sheet->getTitle() !== 'MEDIÇÃO') {
            return false;
        }
        if (bm_med_tpl1_find_gip_header_row($sheet) !== null) {
            return true;
        }
        $a1 = bm_med_tpl1_celula_texto_plano($sheet->getCell('A1')->getValue());
        if (stripos($a1, 'BOLETIM') !== false) {
            return true;
        }
        $b1 = bm_med_tpl1_celula_texto_plano($sheet->getCell('B1')->getValue());

        return stripos($b1, 'BOLETIM') !== false;
    } catch (\Throwable) {
        return false;
    }
}

/**
 * Copia do modelo margens e configuração de página (sem larguras/alturas — definidas em PHP).
 *
 * Usado depois de `bm_med_workbook_build`; o layout final GIP reaplica alturas e colunas.
 */
function bm_med_tpl1_overlay_surface(Worksheet $dst, string $tplPath): void
{
    if (!is_readable($tplPath) || !bm_med_tpl1_validate($tplPath)) {
        return;
    }
    $reader = IOFactory::createReader('Xlsx');
    $reader->setReadDataOnly(false);
    $srcWb = $reader->load($tplPath);
    $src    = $srcWb->getActiveSheet();

    $dst->getPageMargins()->setLeft($src->getPageMargins()->getLeft());
    $dst->getPageMargins()->setRight($src->getPageMargins()->getRight());
    $dst->getPageMargins()->setTop($src->getPageMargins()->getTop());
    $dst->getPageMargins()->setBottom($src->getPageMargins()->getBottom());
    $dst->getPageSetup()->setOrientation($src->getPageSetup()->getOrientation());
    $dst->getPageSetup()->setPaperSize($src->getPageSetup()->getPaperSize());
    $dst->getPageSetup()->setFitToWidth($src->getPageSetup()->getFitToWidth());
    $dst->getPageSetup()->setFitToHeight($src->getPageSetup()->getFitToHeight());
    $dst->getPageSetup()->setHorizontalCentered($src->getPageSetup()->getHorizontalCentered());
    $rpt = $src->getPageSetup()->getRowsToRepeatAtTop();
    if ($rpt !== null) {
        $dst->getPageSetup()->setRowsToRepeatAtTop($rpt);
    }
    $dst->getHeaderFooter()->setOddHeader($src->getHeaderFooter()->getOddHeader());
    $dst->getHeaderFooter()->setOddFooter($src->getHeaderFooter()->getOddFooter());
}

/**
 * Replica o estilo da primeira linha de dados do modelo (abaixo do cabeçalho GIP) nas linhas de corpo geradas.
 */
function bm_med_tpl1_apply_sample_body_row_style_from_template(Worksheet $dst, string $tplPath, array $bodyRowNums): void
{
    if ($bodyRowNums === [] || !is_readable($tplPath) || !bm_med_tpl1_validate($tplPath)) {
        return;
    }
    try {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(false);
        $src   = $reader->load($tplPath)->getActiveSheet();
        $hdr   = bm_med_tpl1_find_gip_header_row($src);
        if ($hdr === null) {
            return;
        }
        $sample = $hdr + 1;
        if ($sample > (int) $src->getHighestRow()) {
            return;
        }
        $bodyStyle = $src->getStyle('A' . $sample . ':' . BM_MED_GIP_LAST_COL . $sample);
        foreach ($bodyRowNums as $br) {
            $r = (int) $br;
            if ($r <= 0) {
                continue;
            }
            $dst->duplicateStyle($bodyStyle, 'A' . $r . ':' . BM_MED_GIP_LAST_COL . $r, true);
        }
    } catch (\Throwable) {
        /* mantém estilos só PHP */
    }
}
