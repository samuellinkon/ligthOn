<?php
declare(strict_types=1);

/**
 * Tokens de cor alinhados ao Boletim de Medição (BM).
 * Manter sincronizados com medicao_export_bm_boletim_xlsx.php (RGB OOXML sem #).
 */

const INSTITUICAO_BM_BRAND_PRIMARY     = '534AB7';
const INSTITUICAO_BM_BRAND_PRIMARY_HOV = '7F77DD';
const INSTITUICAO_BM_TEXT_MAIN       = '1F2937';
const INSTITUICAO_BM_TEXT_MUTED      = '6B7280';
const INSTITUICAO_BM_BG_SOFT         = 'F4F3FF';
const INSTITUICAO_BM_BG_ALT          = 'FAFAFF';
const INSTITUICAO_BM_BORDER_LIGHT    = 'DADDF0';
const INSTITUICAO_BM_BORDER_STRONG   = 'BFC3D9';
const INSTITUICAO_BM_WHITE           = 'FFFFFF';

/** Semântica operacional (impressão; não faz parte do XLSX BM). */
const INSTITUICAO_BM_SEM_OK    = '059669';
const INSTITUICAO_BM_SEM_WARN  = 'CA8A04';
const INSTITUICAO_BM_SEM_DANG  = 'B91C1C';
const INSTITUICAO_BM_SEM_INFO  = '2563EB';
const INSTITUICAO_BM_SEM_NEUT  = '64748B';

/**
 * Bloco CSS :root para relatórios HTML/PDF (hex com #).
 */
function chamados_pdf_bm_css_vars(): string
{
    $h = static function (string $rgb): string {
        return '#' . $rgb;
    };

    return <<<CSS
    :root {
      --bm-brand: {$h(INSTITUICAO_BM_BRAND_PRIMARY)};
      --bm-brand-hover: {$h(INSTITUICAO_BM_BRAND_PRIMARY_HOV)};
      --bm-text: {$h(INSTITUICAO_BM_TEXT_MAIN)};
      --bm-muted: {$h(INSTITUICAO_BM_TEXT_MUTED)};
      --bm-bg-soft: {$h(INSTITUICAO_BM_BG_SOFT)};
      --bm-bg-alt: {$h(INSTITUICAO_BM_BG_ALT)};
      --bm-line: {$h(INSTITUICAO_BM_BORDER_LIGHT)};
      --bm-line-strong: {$h(INSTITUICAO_BM_BORDER_STRONG)};
      --bm-white: {$h(INSTITUICAO_BM_WHITE)};
      --bm-ok: {$h(INSTITUICAO_BM_SEM_OK)};
      --bm-warn: {$h(INSTITUICAO_BM_SEM_WARN)};
      --bm-danger: {$h(INSTITUICAO_BM_SEM_DANG)};
      --bm-info: {$h(INSTITUICAO_BM_SEM_INFO)};
      --bm-neut: {$h(INSTITUICAO_BM_SEM_NEUT)};
      --ink: {$h(INSTITUICAO_BM_TEXT_MAIN)};
      --muted: {$h(INSTITUICAO_BM_TEXT_MUTED)};
      --line: {$h(INSTITUICAO_BM_BORDER_LIGHT)};
      --accent: {$h(INSTITUICAO_BM_BRAND_PRIMARY)};
      --panel: {$h(INSTITUICAO_BM_BG_SOFT)};
    }
CSS;
}
