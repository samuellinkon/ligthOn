<?php
declare(strict_types=1);

require_once __DIR__ . '/instituicao_visual_bm_tokens.php';

/**
 * URL base do painel admin (links absolutos no PDF para anexos).
 */
function chamados_pdf_admin_base_url(): string
{
    if (empty($_SERVER['HTTP_HOST'])) {
        return '';
    }
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $scheme = $https ? 'https' : 'http';
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/admin/chamados.php');
    $dir    = str_replace('\\', '/', dirname($script));

    return rtrim($scheme . '://' . $_SERVER['HTTP_HOST'] . $dir, '/');
}

/**
 * Slug seguro para classe CSS a partir de rótulo (status, prioridade).
 */
function chamados_pdf_badge_slug(string $label): string
{
    $t = trim($label);
    if ($t === '') {
        return 'outro';
    }
    $lower = function_exists('mb_convert_case')
        ? mb_convert_case($t, MB_CASE_LOWER, 'UTF-8')
        : strtolower($t);
    static $map = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'é' => 'e', 'ê' => 'e', 'è' => 'e',
        'í' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n',
    ];
    $lower = strtr($lower, $map);
    $x = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $lower) ?? '');
    $x = trim($x, '-');

    return $x !== '' ? $x : 'outro';
}

/**
 * Trunca legenda de ficheiro para PDF (uma linha legível).
 */
function chamados_pdf_legenda_curta(string $nome, int $max = 72): string
{
    $t = trim($nome);
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($t) <= $max) {
            return $t;
        }

        return mb_substr($t, 0, $max - 1) . '…';
    }
    if (strlen($t) <= $max) {
        return $t;
    }

    return substr($t, 0, $max - 3) . '…';
}

/**
 * Célula HTML de uma foto no relatório (Dompdf).
 *
 * @param callable(array<string,mixed>, int, bool, string): array{src: string, ok: bool} $resolveAnexoImagemSrc
 * @param callable(int): string $anexoUrl
 */
function chamados_pdf_photo_fig_html(
    array $a,
    int $cid,
    bool $embedImagesBase64,
    string $projectRootFs,
    callable $h,
    callable $resolveAnexoImagemSrc,
    callable $anexoUrl,
    string $imgMaxHeight = '36mm'
): string {
    $aid = (int) ($a['id'] ?? 0);
    $got = $resolveAnexoImagemSrc($a, $cid, $embedImagesBase64, $projectRootFs);
    $leg = 'Foto';

    ob_start();
    ?>
<figure class="photo-grid__fig">
  <div class="photo-grid__imgwrap">
    <?php if ($got['ok'] && $got['src'] !== ''): ?>
    <img src="<?= $h($got['src']) ?>" alt="<?= $h($leg) ?>" style="max-height:<?= $h($imgMaxHeight) ?>;" />
    <?php else: ?>
    <span class="muted" style="font-size:8px;">Imagem não incorporada.</span>
    <?php endif; ?>
  </div>
</figure>
    <?php

    return (string) ob_get_clean();
}

/**
 * Até 3 fotos em 2 linhas (2 quadrantes + 1 largo), ou 2x2 se houver 4 imagens.
 *
 * @param list<array<string,mixed>> $imgs
 * @param callable(array<string,mixed>, int, bool, string): array{src: string, ok: bool} $resolveAnexoImagemSrc
 * @param callable(int): string $anexoUrl
 */
function chamados_pdf_photo_quadrants_html(
    array $imgs,
    int $cid,
    bool $embedImagesBase64,
    string $projectRootFs,
    callable $h,
    callable $resolveAnexoImagemSrc,
    callable $anexoUrl
): string {
    if ($imgs === []) {
        return '';
    }
    if (count($imgs) > 3) {
        $imgs = array_slice($imgs, 0, 3);
    }
    $n = count($imgs);

    $fig = static function (array $a, string $maxH = '36mm') use (
        $cid,
        $embedImagesBase64,
        $projectRootFs,
        $h,
        $resolveAnexoImagemSrc,
        $anexoUrl
    ): string {
        return chamados_pdf_photo_fig_html(
            $a,
            $cid,
            $embedImagesBase64,
            $projectRootFs,
            $h,
            $resolveAnexoImagemSrc,
            $anexoUrl,
            $maxH
        );
    };

    ob_start();
    ?>
<table class="photo-quad" width="100%">
    <?php if ($n === 1): ?>
  <tr class="photo-quad__row">
    <td class="photo-quad__cell" colspan="2"><?= $fig($imgs[0], '58mm') ?></td>
  </tr>
    <?php elseif ($n === 2): ?>
  <tr class="photo-quad__row">
    <td class="photo-quad__cell"><?= $fig($imgs[0]) ?></td>
    <td class="photo-quad__cell"><?= $fig($imgs[1]) ?></td>
  </tr>
    <?php elseif ($n === 3): ?>
  <tr class="photo-quad__row">
    <td class="photo-quad__cell"><?= $fig($imgs[0]) ?></td>
    <td class="photo-quad__cell"><?= $fig($imgs[1]) ?></td>
  </tr>
  <tr class="photo-quad__row">
    <td class="photo-quad__cell photo-quad__cell--wide" colspan="2"><?= $fig($imgs[2], '42mm') ?></td>
  </tr>
    <?php endif; ?>
</table>
    <?php

    return (string) ob_get_clean();
}

/**
 * Documento HTML para impressão ou PDF (relatório institucional de chamados + anexos).
 *
 * @param list<array{chamado: array<string,mixed>, anexos: list<array<string,mixed>>}> $items
 * @param array{
 *   total?: int,
 *   por_status?: array<string,int>,
 *   por_prioridade?: array<string,int>,
 *   com_anexo?: int,
 *   urgentes_abertos?: int
 * } $resumoExecutivo agregados do período (mesmos filtros da listagem)
 */
function chamados_periodo_anexos_export_html(
    array $items,
    string $periodoLabel,
    int $totalNoPeriodo,
    int $mostrados,
    bool $listaTruncada,
    bool $autoprint,
    bool $embedImagesBase64 = false,
    array $resumoExecutivo = [],
    string $orgaoMunicipio = '',
    array $anexoPdfIdsIncorporados = []
): string {
    if (!defined('APP_BRAND_NAME')) {
        require_once __DIR__ . '/config.php';
    }
    if ($embedImagesBase64) {
        require_once __DIR__ . '/upload.php';
    }
    $projectRootFs = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
    $brand   = defined('APP_BRAND_NAME') ? (string) APP_BRAND_NAME : 'CRM';
    $tagline = defined('APP_BRAND_TAGLINE') ? (string) APP_BRAND_TAGLINE : '';

    $orgao = trim($orgaoMunicipio) !== '' ? trim($orgaoMunicipio) : $brand;

    $logoSvgPath = dirname(__DIR__) . '/assets/img/logo.svg';
    $logoInline  = '';
    if (is_readable($logoSvgPath)) {
        $raw = (string) file_get_contents($logoSvgPath);
        $raw = preg_replace('/\s(width|height)="[^"]*"/i', '', $raw) ?? $raw;
        $raw = str_replace('<svg ', '<svg class="doc-logo-svg doc-logo-svg--hero" ', $raw);
        $logoInline = $raw;
    }

    $adminBase = chamados_pdf_admin_base_url();

    $h = static function (?string $s): string {
        return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };

    $porSt  = $resumoExecutivo['por_status'] ?? [];
    $porPr  = $resumoExecutivo['por_prioridade'] ?? [];
    $totalR = (int) ($resumoExecutivo['total'] ?? $totalNoPeriodo);
    $comAx  = (int) ($resumoExecutivo['com_anexo'] ?? 0);
    $urgAb  = (int) ($resumoExecutivo['urgentes_abertos'] ?? 0);

    $nAberto      = (int) ($porSt['Aberto'] ?? 0);
    $nAndamento   = (int) ($porSt['Em andamento'] ?? 0);
    $nAguardando  = (int) ($porSt['Aguardando Aprovação'] ?? $porSt['Aguardando Finalização'] ?? $porSt['Aguardando'] ?? 0);
    $nResolvido   = (int) ($porSt['Resolvido'] ?? 0);
    $nFechado     = (int) ($porSt['Fechado'] ?? 0);
    $nCancelado   = (int) ($porSt['Cancelado'] ?? 0);

    /** Pendentes operacionais: ainda em circuito (não resolvido/fechado/ cancelado). */
    $nPendentes = $nAberto + $nAndamento + $nAguardando;

    /** KPI «Resolvidos»: encerramentos com conclusão (Resolvido + Fechado). */
    $nResolvidosKpi = $nResolvido + $nFechado;

    $emitidoEm = date('d/m/Y H:i');
    $docTitle  = 'Relatório Fotográfico';

    $mimeIsImage = static function (?string $mime): bool {
        $m = strtolower(trim((string) $mime));

        return $m !== '' && strncmp($m, 'image/', 6) === 0;
    };

    $anexoEhImagem = static function (array $anexoRow) use ($mimeIsImage): bool {
        if ($mimeIsImage((string) ($anexoRow['mime'] ?? ''))) {
            return true;
        }
        $nome = (string) ($anexoRow['nome_original'] ?? '');
        $fs   = (string) ($anexoRow['nome_arquivo'] ?? '');
        $try  = $nome !== '' ? $nome : $fs;
        $ext  = strtolower(pathinfo($try, PATHINFO_EXTENSION));

        return in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true);
    };

    $mimeImagemParaDataUri = static function (string $pathNoDisco, string $mimeBd, string $nomeOriginal, string $nomeFs): string {
        $m = strtolower(trim($mimeBd));
        if ($m !== '' && strncmp($m, 'image/', 6) === 0) {
            return $m;
        }
        if (function_exists('mime_content_type')) {
            $detect = @mime_content_type($pathNoDisco);
            if (is_string($detect) && strncmp(strtolower($detect), 'image/', 6) === 0) {
                return $detect;
            }
        }
        $try = $nomeOriginal !== '' ? $nomeOriginal : $nomeFs;
        $ext = strtolower(pathinfo($try, PATHINFO_EXTENSION));

        return match ($ext) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'image/jpeg',
        };
    };

    $fmtBytes = static function (int $n): string {
        if ($n < 1024) {
            return (string) $n . ' B';
        }
        if ($n < 1024 * 1024) {
            return number_format($n / 1024, 1, ',', '.') . ' KB';
        }

        return number_format($n / (1024 * 1024), 2, ',', '.') . ' MB';
    };

    $anexoUrl = static function (int $aid) use ($adminBase, $h): string {
        $path = 'chamado_download.php?id=' . $aid;
        if ($adminBase !== '') {
            return $h($adminBase . '/' . $path);
        }

        return $h($path);
    };

    $extAnexo = static function (array $a): string {
        $nome = (string) ($a['nome_original'] ?? '');
        $fs   = (string) ($a['nome_arquivo'] ?? '');
        $try  = $nome !== '' ? $nome : $fs;

        return strtolower(pathinfo($try, PATHINFO_EXTENSION));
    };

    /**
     * Resolve src para pré-visualização de imagem no PDF (caminho sob chroot ou data URI).
     *
     * @return array{src: string, ok: bool}
     */
    $resolveAnexoImagemSrc = static function (
        array $a,
        int $cid,
        bool $embedImagesBase64,
        string $projectRootFs
    ) use ($mimeImagemParaDataUri): array {
        $nome = (string) ($a['nome_original'] ?? '');
        $mime = (string) ($a['mime'] ?? '');
        $fn   = basename(trim((string) ($a['nome_arquivo'] ?? '')));
        if (!$embedImagesBase64 || $cid <= 0) {
            return ['src' => '', 'ok' => false];
        }
        $path = $fn !== '' ? upload_dir_chamado($cid) . DIRECTORY_SEPARATOR . $fn : '';
        $real = ($path !== '' && is_file($path)) ? realpath($path) : false;
        $readPath = $real !== false ? $real : $path;
        if ($readPath === '' || !is_file($readPath) || !is_readable($readPath)) {
            if ($fn !== '') {
                error_log('[crm_prefeitura] PDF chamado ' . $cid . ' anexo: ficheiro não encontrado — ' . $path);
            }

            return ['src' => '', 'ok' => false];
        }
        $rootNorm = str_replace('\\', '/', $projectRootFs);
        $readNorm = str_replace('\\', '/', $readPath);
        if ($rootNorm !== '' && str_starts_with($readNorm, $rootNorm)) {
            return ['src' => $readNorm, 'ok' => true];
        }
        $rawImg = @file_get_contents($readPath);
        if ($rawImg !== false && $rawImg !== '') {
            $dataMime = $mimeImagemParaDataUri($readPath, $mime, $nome, $fn);

            return ['src' => 'data:' . $dataMime . ';base64,' . base64_encode((string) $rawImg), 'ok' => true];
        }
        error_log('[crm_prefeitura] PDF chamado ' . $cid . ' anexo: leitura vazia — ' . $readPath);

        return ['src' => '', 'ok' => false];
    };

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex,nofollow" />
  <title><?= $h($docTitle) ?> — <?= $h($periodoLabel) ?> — <?= $h($brand) ?></title>
  <?php if (!$embedImagesBase64): ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <?php endif; ?>
  <style>
    <?= chamados_pdf_bm_css_vars() ?>

    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: <?= $embedImagesBase64
          ? "'DejaVu Sans', DejaVu Sans, sans-serif"
          : "Inter, 'DejaVu Sans', Helvetica, Arial, sans-serif" ?>;
      font-size: 10.5px;
      line-height: 1.42;
      color: var(--bm-text);
      background: var(--bm-white);
    }
    @page {
      size: A4 portrait;
      margin: 12mm 11mm 20mm 11mm;
    }
    table { border-collapse: collapse; }
    @media print {
      body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      .no-print { display: none !important; }
      a { color: var(--bm-brand); }
    }
    .toolbar {
      text-align: center;
      padding: 10px;
      border-bottom: 1px solid var(--bm-line);
      background: var(--bm-bg-alt);
    }
    .toolbar button {
      font-family: inherit;
      font-size: 12px;
      font-weight: 600;
      padding: 8px 16px;
      border: 1px solid var(--bm-brand);
      background: var(--bm-brand);
      color: var(--bm-white);
      cursor: pointer;
      border-radius: 6px;
    }
    .sheet { padding: 0 0 6mm; }

    /* Rodapé institucional (todas as páginas) */
    .pdf-run-footer {
      position: fixed;
      left: 11mm;
      right: 11mm;
      bottom: 5mm;
      height: 14mm;
      font-size: 8px;
      color: var(--bm-muted);
      text-align: center;
      line-height: 1.35;
      border-top: 1px solid var(--bm-line);
      padding-top: 3px;
      page-break-inside: avoid;
    }
    .pdf-run-footer__doc {
      font-weight: 600;
      color: var(--bm-text);
    }
    .pdf-run-footer__pag::before {
      content: "Pág. " counter(page) " de " counter(pages) " · ";
    }

    .pdf-cover {
      page-break-after: always;
    }
    .hero {
      background: var(--bm-brand);
      color: var(--bm-white);
      border-radius: 0 0 10px 10px;
      padding: 16px 18px 18px;
      margin: -12mm -11mm 14px -11mm;
      padding-left: calc(11mm + 18px);
      padding-right: calc(11mm + 18px);
      padding-top: 14px;
    }
    .hero__top {
      width: 100%;
      margin-bottom: 10px;
    }
    .hero__top td { vertical-align: middle; }
    .doc-logo-svg--hero { width: 44px; height: 44px; display: block; filter: brightness(0) invert(1); opacity: 0.95; }
    .hero__title {
      font-size: 19px;
      font-weight: 700;
      margin: 0 0 4px;
      letter-spacing: -0.02em;
      line-height: 1.15;
    }
    .hero__sub {
      margin: 0;
      font-size: 10.5px;
      opacity: 0.92;
      font-weight: 500;
    }
    .hero__meta {
      margin-top: 12px;
      font-size: 10px;
      line-height: 1.55;
      opacity: 0.95;
      border-top: 1px solid rgba(255,255,255,0.25);
      padding-top: 10px;
    }
    .hero__meta strong { font-weight: 700; }

    .kpi-wrap { margin: 12px 0 8px; }
    .kpi-title {
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.07em;
      color: var(--bm-brand);
      margin: 0 0 8px;
      padding-bottom: 4px;
      border-bottom: 2px solid var(--bm-line-strong);
    }
    .kpi-grid { width: 100%; }
    .kpi-grid td {
      width: 33.33%;
      vertical-align: top;
      padding: 5px;
    }
    .kpi-card {
      border: 1px solid var(--bm-line);
      border-radius: 8px;
      background: linear-gradient(180deg, var(--bm-bg-soft) 0%, var(--bm-bg-alt) 100%);
      padding: 10px 10px 8px;
      min-height: 56px;
      page-break-inside: avoid;
    }
    .kpi-card__label {
      font-size: 8.5px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: var(--bm-muted);
      margin-bottom: 4px;
    }
    .kpi-card__val {
      font-size: 22px;
      font-weight: 700;
      color: var(--bm-brand);
      line-height: 1;
    }
    .kpi-card__hint {
      font-size: 8px;
      color: var(--bm-muted);
      margin-top: 4px;
    }
    .kpi-card--ok .kpi-card__val { color: var(--bm-ok); }
    .kpi-card--warn .kpi-card__val { color: var(--bm-warn); }
    .kpi-card--danger .kpi-card__val { color: var(--bm-danger); }
    .kpi-card--info .kpi-card__val { color: var(--bm-info); }

    .status-mini {
      margin: 10px 0 6px;
      font-size: 9px;
      color: var(--bm-muted);
    }
    .status-mini table { width: 100%; font-size: 9px; }
    .status-mini th {
      text-align: center;
      padding: 4px 2px;
      background: var(--bm-bg-soft);
      border: 1px solid var(--bm-line);
      font-weight: 600;
    }
    .status-mini td {
      text-align: center;
      padding: 5px 2px;
      border: 1px solid var(--bm-line);
      font-weight: 700;
      color: var(--bm-text);
    }

    .warn-box {
      border: 1px solid var(--bm-warn);
      background: #FFFBEB;
      border-radius: 8px;
      padding: 10px 12px;
      font-size: 9.5px;
      margin: 12px 0 0;
      color: var(--bm-text);
    }

    .section-title {
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: var(--bm-brand);
      margin: 8px 0 5px;
      padding-bottom: 2px;
      border-bottom: 1px solid var(--bm-line);
    }

    /* Dois chamados por folha (após a capa). */
    .chamado-page {
      page-break-after: always;
    }
    .chamado-page:last-child {
      page-break-after: auto;
    }

    .chamado-doc {
      page-break-before: auto;
      page-break-inside: avoid;
    }
    .chamado-doc + .chamado-doc {
      margin-top: 8px;
      padding-top: 8px;
      border-top: 1px dashed var(--bm-line);
    }

    .ch-card {
      border: 1px solid var(--bm-line);
      border-left: 4px solid var(--bm-brand);
      border-radius: 6px;
      background: var(--bm-bg-alt);
      padding: 6px 8px;
      margin-bottom: 4px;
      page-break-inside: avoid;
    }
    .ch-card__head {
      display: table;
      width: 100%;
      margin-bottom: 0;
    }
    .ch-card__id {
      font-size: 11px;
      font-weight: 700;
      color: var(--bm-brand);
      margin: 0;
    }
    .ch-card__meta {
      font-size: 9px;
      line-height: 1.35;
      color: var(--bm-text);
      margin: 2px 0 0;
    }
    .ch-card__meta .muted { color: var(--bm-muted); }
    .ch-card__badges { margin-bottom: 6px; }

    .badge {
      display: inline-block;
      padding: 2px 8px;
      margin: 0 6px 4px 0;
      border-radius: 999px;
      font-size: 8.5px;
      font-weight: 700;
      vertical-align: middle;
      border: 1px solid transparent;
    }
    .badge--status-aberto { background: #FEF9C3; color: #854D0E; border-color: #FDE047; }
    .badge--status-em-andamento { background: #DBEAFE; color: #1E40AF; border-color: #93C5FD; }
    .badge--status-aguardando { background: #F3E8FF; color: #6B21A8; border-color: #D8B4FE; }
    .badge--status-resolvido { background: #D1FAE5; color: #065F46; border-color: #6EE7B7; }
    .badge--status-fechado { background: #E2E8F0; color: #334155; border-color: #CBD5E1; }
    .badge--status-cancelado { background: #FEE2E2; color: #991B1B; border-color: #FCA5A5; }
    .badge--status-outro { background: var(--bm-bg-soft); color: var(--bm-text); border-color: var(--bm-line); }

    .badge--prio-baixa { background: #ECFDF5; color: #047857; }
    .badge--prio-normal { background: #F1F5F9; color: #475569; }
    .badge--prio-media { background: #FEF3C7; color: #B45309; }
    .badge--prio-alta { background: #FFEDD5; color: #C2410C; }
    .badge--prio-urgente { background: #FEE2E2; color: #B91C1C; }
    .badge--prio-outro { background: var(--bm-bg-soft); color: var(--bm-muted); }

    .ch-card__grid { width: 100%; font-size: 10px; }
    .ch-card__grid th {
      text-align: left;
      vertical-align: top;
      width: 118px;
      padding: 2px 8px 4px 0;
      color: var(--bm-muted);
      font-weight: 600;
    }
    .ch-card__grid td {
      padding: 2px 0 4px;
      color: var(--bm-text);
      vertical-align: top;
    }
    .photo-quad { width: 100%; margin: 3px 0 0; table-layout: fixed; border-collapse: separate; border-spacing: 3px; }
    .photo-quad__row { page-break-inside: avoid; }
    .photo-quad__cell {
      width: 50%;
      vertical-align: top;
      padding: 0;
    }
    .photo-quad__cell--wide { width: 100%; }
    .photo-grid__fig {
      margin: 0;
      border: 1px solid var(--bm-line-strong);
      border-radius: 5px;
      padding: 3px;
      background: var(--bm-white);
      page-break-inside: avoid;
    }
    .photo-grid__imgwrap {
      text-align: center;
      min-height: 18mm;
      line-height: 0;
    }
    .photo-grid__imgwrap img {
      max-width: 100%;
      height: auto;
      width: auto;
      display: block;
      margin: 0 auto;
    }
    .chamado-doc__outros {
      font-size: 8px;
      color: var(--bm-muted);
      margin: 2px 0 0;
      line-height: 1.3;
    }

    .anexo-list {
      margin: 4px 0 0;
      padding-left: 18px;
      font-size: 9.5px;
    }
    .anexo-list li { margin-bottom: 5px; }
    .callout {
      border-radius: 8px;
      border: 1px solid var(--bm-line);
      background: var(--bm-bg-soft);
      padding: 8px 10px;
      font-size: 9px;
      margin: 6px 0;
      color: var(--bm-text);
    }

    .muted { color: var(--bm-muted); }

    .empty-state {
      color: var(--bm-muted);
      text-align: center;
      padding: 28px 12px;
      font-size: 10px;
    }
  </style>
</head>
<body>
  <?php if (!$embedImagesBase64): ?>
  <div class="toolbar no-print">
    <button type="button" onclick="window.print()">Imprimir / Guardar como PDF</button>
  </div>
  <?php endif; ?>

  <div class="pdf-run-footer">
    <div class="pdf-run-footer__doc"><?= $h($brand) ?> · <?= $h($docTitle) ?></div>
    <div>Período: <?= $h($periodoLabel) ?> · Gerado em <?= $h($emitidoEm) ?> · Uso institucional e auditoria.</div>
    <div><span class="pdf-run-footer__pag"></span> Documento não substitui registos oficiais sem validação interna.</div>
  </div>

  <div class="sheet">
    <!-- Capa + resumo (página 1) -->
    <div class="pdf-cover">
      <div class="hero">
        <table class="hero__top" width="100%">
          <tr>
            <td width="52">
              <?php if ($logoInline !== ''): ?>
                <?= $logoInline ?>
              <?php else: ?>
                <div class="doc-logo-svg doc-logo-svg--hero" style="width:44px;height:44px;background:rgba(255,255,255,0.2);border-radius:10px;"></div>
              <?php endif; ?>
            </td>
            <td>
              <h1 class="hero__title"><?= $h($docTitle) ?></h1>
              <p class="hero__sub"><?= $h($orgao) ?><?php if ($tagline !== ''): ?> · <?= $h($tagline) ?><?php endif; ?></p>
            </td>
          </tr>
        </table>
        <div class="hero__meta">
          <strong>Período (filtro):</strong> <?= $h($periodoLabel) ?><br />
          <strong>Emissão:</strong> <?= $h($emitidoEm) ?> · <strong>Total no período:</strong> <?= (int) $totalR ?> chamados
          · <strong>Neste PDF:</strong> <?= (int) $mostrados ?><?php if ($listaTruncada): ?> <span style="opacity:.95;">(lista limitada)</span><?php endif; ?>
        </div>
      </div>

      <p class="section-title" style="margin-top:16px;">Chamados neste relatório</p>
      <div class="status-mini">
        <table width="100%">
          <tr>
            <th style="width:12%;">#</th>
            <th style="width:22%;">Data</th>
            <th>Endereço / local</th>
          </tr>
          <?php foreach ($items as $packCapa):
              $chCapa = $packCapa['chamado'];
              $cidCapa = (int) ($chCapa['id'] ?? 0);
              $dataCapa = trim((string) ($chCapa['data'] ?? ''));
              $endCapa = trim((string) ($chCapa['endereco_completo'] ?? ''));
              ?>
          <tr>
            <td><strong><?= $h((string) $cidCapa) ?></strong></td>
            <td><?= $h($dataCapa !== '' ? $dataCapa : '—') ?></td>
            <td><?= $h($endCapa !== '' ? $endCapa : '—') ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>

      <?php if ($listaTruncada): ?>
      <div class="warn-box">
        Lista detalhada limitada aos primeiros <strong><?= (int) $mostrados ?></strong> registos.
        Refine filtros ou exporte CSV. Os totais acima referem-se a <strong>todos</strong> os chamados do período com os filtros atuais.
      </div>
      <?php endif; ?>
    </div>

    <?php if ($items === []): ?>
    <p class="empty-state">Nenhum chamado CRM encontrado com os filtros e período atuais.</p>
    <?php endif; ?>

    <?php
    $chamadosPorPagina = 2;
    $paginasChamados   = $items !== [] ? array_chunk($items, $chamadosPorPagina) : [];
    foreach ($paginasChamados as $packPagina):
        ?>
    <div class="chamado-page">
        <?php
        foreach ($packPagina as $pack):
        $ch     = $pack['chamado'];
        $anexos = $pack['anexos'];
        $cid    = (int) ($ch['id'] ?? 0);
        $dataCh   = trim((string) ($ch['data'] ?? ''));
        $endereco = trim((string) ($ch['endereco_completo'] ?? ''));
        $tecnico  = trim((string) ($ch['tecnico_nome'] ?? ''));
        ?>
    <section class="chamado-doc">
      <div class="ch-card">
        <div class="ch-card__head">
          <div class="ch-card__id">Chamado #<?= $h((string) $cid) ?></div>
        </div>
        <p class="ch-card__meta">
          <?php if ($dataCh !== ''): ?><span><?= $h($dataCh) ?></span><?php endif; ?>
          <?php if ($endereco !== ''): ?>
            <?php if ($dataCh !== ''): ?><span class="muted"> · </span><?php endif; ?>
            <span><?= $h($endereco) ?></span>
          <?php endif; ?>
          <?php if ($tecnico !== ''): ?>
            <span class="muted"> · </span><span class="muted"><?= $h($tecnico) ?></span>
          <?php endif; ?>
        </p>
      </div>

      <?php if ($anexos === []): ?>
      <p class="muted" style="font-size:8px;font-style:italic;margin:2px 0 0;">Sem fotos.</p>
      <?php else: ?>
        <?php
        $imgs = [];
        $outros = [];
        foreach ($anexos as $a) {
            if ($anexoEhImagem($a)) {
                $imgs[] = $a;
            } else {
                $outros[] = $a;
            }
        }
        echo chamados_pdf_photo_quadrants_html(
            $imgs,
            $cid,
            $embedImagesBase64,
            $projectRootFs,
            $h,
            $resolveAnexoImagemSrc,
            $anexoUrl
        );
        if ($outros !== []) {
            $nOut = count($outros);
            echo '<p class="chamado-doc__outros">' . $h(
                $nOut === 1
                    ? '1 outro ficheiro (ver CRM).'
                    : (string) $nOut . ' outros ficheiros (ver CRM).'
            ) . '</p>';
        }
        ?>
      <?php endif; ?>
    </section>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($autoprint && !$embedImagesBase64): ?>
  <script>
    window.addEventListener('load', function () {
      setTimeout(function () { window.print(); }, 400);
    });
  </script>
  <?php endif; ?>
</body>
</html>
    <?php

    return (string) ob_get_clean();
}
