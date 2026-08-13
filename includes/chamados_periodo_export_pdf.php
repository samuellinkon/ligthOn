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
 * Conta anexos de imagem de um pack do relatório fotográfico.
 *
 * @param array{chamado?: array<string,mixed>, anexos?: list<array<string,mixed>>} $pack
 * @param callable(array<string,mixed>): bool $anexoEhImagem
 */
function chamados_pdf_pack_image_count(array $pack, callable $anexoEhImagem): int
{
    $n = 0;
    foreach ($pack['anexos'] ?? [] as $a) {
        if ($anexoEhImagem($a)) {
            $n++;
        }
    }

    return $n;
}

/**
 * Empacota chamados em páginas: ≤2 fotos → até 2 por folha; >2 fotos → 1 por folha.
 *
 * @param list<array{chamado: array<string,mixed>, anexos: list<array<string,mixed>>}> $items
 * @param callable(array<string,mixed>): bool $anexoEhImagem
 * @return list<list<array{chamado: array<string,mixed>, anexos: list<array<string,mixed>>}>>
 */
function chamados_pdf_pack_pages(array $items, callable $anexoEhImagem): array
{
    $pages = [];
    $i = 0;
    $n = count($items);
    while ($i < $n) {
        $cur = $items[$i];
        $curImgs = chamados_pdf_pack_image_count($cur, $anexoEhImagem);
        if ($curImgs <= 2 && ($i + 1) < $n) {
            $nextImgs = chamados_pdf_pack_image_count($items[$i + 1], $anexoEhImagem);
            if ($nextImgs <= 2) {
                $pages[] = [$cur, $items[$i + 1]];
                $i += 2;
                continue;
            }
        }
        $pages[] = [$cur];
        $i++;
    }

    return $pages;
}

/**
 * Separa imagens e outros anexos de um pack.
 *
 * @param array{chamado?: array<string,mixed>, anexos?: list<array<string,mixed>>} $pack
 * @param callable(array<string,mixed>): bool $anexoEhImagem
 * @return array{imgs: list<array<string,mixed>>, outros: list<array<string,mixed>>}
 */
function chamados_pdf_pack_split_anexos(array $pack, callable $anexoEhImagem): array
{
    $imgs = [];
    $outros = [];
    foreach ($pack['anexos'] ?? [] as $a) {
        if ($anexoEhImagem($a)) {
            $imgs[] = $a;
        } else {
            $outros[] = $a;
        }
    }

    return ['imgs' => $imgs, 'outros' => $outros];
}

/**
 * Empacota folhas renderizáveis do relatório fotográfico.
 * ≤2 fotos/chamado → até 2 chamados na mesma folha;
 * >2 fotos → 1 chamado por folha, fotos em chunks de no máximo 4 (2×2).
 *
 * @param list<array{chamado: array<string,mixed>, anexos: list<array<string,mixed>>}> $items
 * @param callable(array<string,mixed>): bool $anexoEhImagem
 * @return list<list<array{
 *   chamado: array<string,mixed>,
 *   imgs: list<array<string,mixed>>,
 *   outros: list<array<string,mixed>>,
 *   continuation: bool
 * }>>
 */
function chamados_pdf_pack_photo_pages(array $items, callable $anexoEhImagem): array
{
    $pages = [];
    $i = 0;
    $n = count($items);
    $maxPerPage = 4;

    while ($i < $n) {
        $cur = $items[$i];
        $curSplit = chamados_pdf_pack_split_anexos($cur, $anexoEhImagem);
        $curImgs = $curSplit['imgs'];
        $curCount = count($curImgs);

        if ($curCount <= 2 && ($i + 1) < $n) {
            $next = $items[$i + 1];
            $nextSplit = chamados_pdf_pack_split_anexos($next, $anexoEhImagem);
            if (count($nextSplit['imgs']) <= 2) {
                $pages[] = [
                    [
                        'chamado'      => $cur['chamado'] ?? [],
                        'imgs'         => $curImgs,
                        'outros'       => $curSplit['outros'],
                        'continuation' => false,
                    ],
                    [
                        'chamado'      => $next['chamado'] ?? [],
                        'imgs'         => $nextSplit['imgs'],
                        'outros'       => $nextSplit['outros'],
                        'continuation' => false,
                    ],
                ];
                $i += 2;
                continue;
            }
        }

        if ($curCount <= $maxPerPage) {
            $pages[] = [[
                'chamado'      => $cur['chamado'] ?? [],
                'imgs'         => $curImgs,
                'outros'       => $curSplit['outros'],
                'continuation' => false,
            ]];
            $i++;
            continue;
        }

        $chunks = array_chunk($curImgs, $maxPerPage);
        foreach ($chunks as $idx => $chunk) {
            $pages[] = [[
                'chamado'      => $cur['chamado'] ?? [],
                'imgs'         => $chunk,
                'outros'       => $idx === 0 ? $curSplit['outros'] : [],
                'continuation' => $idx > 0,
            ]];
        }
        $i++;
    }

    return $pages;
}

/**
 * Caminho legível do ficheiro de anexo (para getimagesize / Dompdf).
 *
 * @param array<string,mixed> $a
 */
function chamados_pdf_anexo_fs_path(array $a, int $cid): string
{
    if ($cid <= 0) {
        return '';
    }
    $fn = basename(trim((string) ($a['nome_arquivo'] ?? '')));
    if ($fn === '') {
        return '';
    }
    $path = upload_dir_chamado($cid) . DIRECTORY_SEPARATOR . $fn;
    $real = is_file($path) ? realpath($path) : false;

    return $real !== false ? $real : (is_file($path) ? $path : '');
}

/**
 * Redimensiona imagem para o PDF (proporção intacta) e grava JPEG temporário.
 * Dompdf calcula mal a altura com fotos de vários MB e parte a grelha entre linhas.
 *
 * @return array{path: string, width: int, height: int}|null
 */
function chamados_pdf_image_fit_for_dompdf(string $srcPath, int $maxEdge = 960): ?array
{
    if ($srcPath === '' || !is_file($srcPath) || !is_readable($srcPath)) {
        return null;
    }
    $dim = @getimagesize($srcPath);
    if (!is_array($dim) || (int) ($dim[0] ?? 0) <= 0 || (int) ($dim[1] ?? 0) <= 0) {
        return null;
    }
    $ow = (int) $dim[0];
    $oh = (int) $dim[1];
    $mime = (string) ($dim['mime'] ?? '');

    $scale = 1.0;
    $long = max($ow, $oh);
    if ($long > $maxEdge) {
        $scale = $maxEdge / $long;
    }
    $nw = max(1, (int) round($ow * $scale));
    $nh = max(1, (int) round($oh * $scale));

    $projectRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
    $tmpDir = $projectRoot . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR . 'dompdf_tmp';
    if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0775, true)) {
        return ['path' => $srcPath, 'width' => $ow, 'height' => $oh];
    }

    // Sem GD ou já no tamanho: devolve original.
    if ($scale >= 0.999 || !extension_loaded('gd')) {
        return ['path' => $srcPath, 'width' => $ow, 'height' => $oh];
    }

    $hash = substr(sha1($srcPath . '|' . filemtime($srcPath) . '|' . $nw . 'x' . $nh), 0, 20);
    $outPath = $tmpDir . DIRECTORY_SEPARATOR . 'rf_' . $hash . '.jpg';
    if (is_file($outPath) && filesize($outPath) > 0) {
        return ['path' => $outPath, 'width' => $nw, 'height' => $nh];
    }

    $src = null;
    if ($mime === 'image/jpeg' || preg_match('/\.(jpe?g)$/i', $srcPath)) {
        $src = @imagecreatefromjpeg($srcPath);
    } elseif ($mime === 'image/png' || preg_match('/\.png$/i', $srcPath)) {
        $src = @imagecreatefrompng($srcPath);
    } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $src = @imagecreatefromwebp($srcPath);
    } elseif ($mime === 'image/gif' || preg_match('/\.gif$/i', $srcPath)) {
        $src = @imagecreatefromgif($srcPath);
    }
    if ($src === false || $src === null) {
        return ['path' => $srcPath, 'width' => $ow, 'height' => $oh];
    }

    $dst = imagecreatetruecolor($nw, $nh);
    if ($dst === false) {
        imagedestroy($src);

        return ['path' => $srcPath, 'width' => $ow, 'height' => $oh];
    }
    $white = imagecolorallocate($dst, 255, 255, 255);
    if ($white !== false) {
        imagefill($dst, 0, 0, $white);
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $ow, $oh);
    imagedestroy($src);
    $ok = @imagejpeg($dst, $outPath, 82);
    imagedestroy($dst);
    if (!$ok || !is_file($outPath)) {
        return ['path' => $srcPath, 'width' => $ow, 'height' => $oh];
    }

    return ['path' => $outPath, 'width' => $nw, 'height' => $nh];
}

/**
 * Escala (sem distorcer) para caber numa caixa maxW × maxH.
 *
 * @return array{0: int, 1: int} width, height em px
 */
function chamados_pdf_fit_box(int $iw, int $ih, int $maxW, int $maxH): array
{
    $iw = max(1, $iw);
    $ih = max(1, $ih);
    $maxW = max(1, $maxW);
    $maxH = max(1, $maxH);
    $scale = min($maxW / $iw, $maxH / $ih, 1.0);

    return [
        max(1, (int) round($iw * $scale)),
        max(1, (int) round($ih * $scale)),
    ];
}

/**
 * Caixa fixa de exibição (todas as fotos iguais, independente da quantidade).
 * A proporção da imagem é preservada dentro da caixa.
 *
 * @return array{w: int, h: int}
 */
function chamados_pdf_photo_slot_size(): array
{
    // px @96dpi — meia largura A4; 2×2 + ch-card cabem numa folha com rodapé.
    return ['w' => 340, 'h' => 355];
}

/**
 * Célula HTML de uma foto no relatório (Dompdf) — caixa fixa, proporção original.
 *
 * @param callable(array<string,mixed>, int, bool, string): array{src: string, ok: bool, width?: int, height?: int} $resolveAnexoImagemSrc
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
    int $cellWidthPx = 340,
    int $maxHeightPx = 400
): string {
    $got = $resolveAnexoImagemSrc($a, $cid, $embedImagesBase64, $projectRootFs);
    $leg = 'Foto';
    $slot = chamados_pdf_photo_slot_size();
    $cellWidthPx = (int) $slot['w'];
    $maxHeightPx = (int) $slot['h'];
    $wAttr = '';
    $hAttr = '';
    $styleExtra = 'max-width:100%;max-height:100%;height:auto;margin:0;display:block;';
    $iw = (int) ($got['width'] ?? 0);
    $ih = (int) ($got['height'] ?? 0);
    if ($iw <= 0 || $ih <= 0) {
        $fs = chamados_pdf_anexo_fs_path($a, $cid);
        if ($fs !== '' && function_exists('getimagesize')) {
            $dim = @getimagesize($fs);
            if (is_array($dim) && ($dim[0] ?? 0) > 0 && ($dim[1] ?? 0) > 0) {
                $iw = (int) $dim[0];
                $ih = (int) $dim[1];
            }
        }
    }
    if ($iw > 0 && $ih > 0) {
        [$tw, $th] = chamados_pdf_fit_box($iw, $ih, $cellWidthPx, $maxHeightPx);
        $wAttr = ' width="' . $tw . '"';
        $hAttr = ' height="' . $th . '"';
        $styleExtra = 'width:' . $tw . 'px;height:' . $th . 'px;margin:0;display:block;';
        $slotStyle = 'width:' . $tw . 'px;height:' . $th . 'px;';
    } else {
        $slotStyle = 'width:' . $cellWidthPx . 'px;height:auto;';
    }

    ob_start();
    ?>
<div class="photo-grid__fig" style="<?= $h($slotStyle) ?>">
  <div class="photo-grid__imgwrap">
    <?php if (!empty($got['ok']) && ($got['src'] ?? '') !== ''): ?>
    <img src="<?= $h((string) $got['src']) ?>" alt="<?= $h($leg) ?>"<?= $wAttr . $hAttr ?> style="<?= $h($styleExtra) ?>" />
    <?php else: ?>
    <span class="muted" style="font-size:8px;line-height:1.2;">Imagem não incorporada.</span>
    <?php endif; ?>
  </div>
</div>
    <?php

    return (string) ob_get_clean();
}

/**
 * Fotos em grelha 2 por linha — todas com a mesma caixa (proporção preservada dentro).
 *
 * @param list<array<string,mixed>> $imgs
 * @param callable(array<string,mixed>, int, bool, string): array{src: string, ok: bool, width?: int, height?: int} $resolveAnexoImagemSrc
 * @param callable(int): string $anexoUrl
 */
function chamados_pdf_photo_quadrants_html(
    array $imgs,
    int $cid,
    bool $embedImagesBase64,
    string $projectRootFs,
    callable $h,
    callable $resolveAnexoImagemSrc,
    callable $anexoUrl,
    bool $compact = false
): string {
    if ($imgs === []) {
        return '';
    }
    unset($compact); // tamanho da foto é sempre o mesmo (slot fixo)
    $slot = chamados_pdf_photo_slot_size();
    $cellW = (int) $slot['w'];
    $maxH = (int) $slot['h'];

    $fig = static function (array $a) use (
        $cid,
        $embedImagesBase64,
        $projectRootFs,
        $h,
        $resolveAnexoImagemSrc,
        $anexoUrl,
        $cellW,
        $maxH
    ): string {
        return chamados_pdf_photo_fig_html(
            $a,
            $cid,
            $embedImagesBase64,
            $projectRootFs,
            $h,
            $resolveAnexoImagemSrc,
            $anexoUrl,
            $cellW,
            $maxH
        );
    };

    $rows = array_chunk($imgs, 2);
    // Páginas já vêm com ≤4 fotos; manter bloco íntegro no Dompdf.

    ob_start();
    ?>
<table class="photo-quad-shell photo-quad-shell--keep" width="100%" cellspacing="0" cellpadding="0">
  <tr>
    <td class="photo-quad-shell__cell">
    <?php foreach ($rows as $row): ?>
      <div class="photo-quad-line">
        <?php if (count($row) === 1): ?>
        <div class="photo-quad-item"><?= $fig($row[0]) ?></div>
        <div class="photo-quad-item photo-quad-item--empty"></div>
        <?php else: ?>
        <div class="photo-quad-item"><?= $fig($row[0]) ?></div>
        <div class="photo-quad-item"><?= $fig($row[1]) ?></div>
        <?php endif; ?>
        <div class="photo-quad-clear"></div>
      </div>
    <?php endforeach; ?>
    </td>
  </tr>
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
     * Redimensiona para o Dompdf não partir a grelha (proporção mantida).
     *
     * @return array{src: string, ok: bool, width?: int, height?: int}
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

        $fitted = chamados_pdf_image_fit_for_dompdf($readPath, 960);
        $usePath = $fitted['path'] ?? $readPath;
        $useW = (int) ($fitted['width'] ?? 0);
        $useH = (int) ($fitted['height'] ?? 0);

        $rootNorm = str_replace('\\', '/', $projectRootFs);
        $readNorm = str_replace('\\', '/', $usePath);
        if ($rootNorm !== '' && str_starts_with($readNorm, $rootNorm)) {
            return ['src' => $readNorm, 'ok' => true, 'width' => $useW, 'height' => $useH];
        }
        $rawImg = @file_get_contents($usePath);
        if ($rawImg !== false && $rawImg !== '') {
            $dataMime = str_ends_with(strtolower($usePath), '.jpg') || str_ends_with(strtolower($usePath), '.jpeg')
                ? 'image/jpeg'
                : $mimeImagemParaDataUri($usePath, $mime, $nome, $fn);

            return [
                'src' => 'data:' . $dataMime . ';base64,' . base64_encode((string) $rawImg),
                'ok' => true,
                'width' => $useW,
                'height' => $useH,
            ];
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
      /* Folga inferior para o rodapé fixed não cobrir linhas da tabela/índice */
      margin: 12mm 11mm 28mm 11mm;
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

    /* Rodapé institucional (todas as páginas) — centrado, fora da área útil */
    .pdf-run-footer {
      position: fixed;
      left: 11mm;
      right: 11mm;
      bottom: 4mm;
      height: 16mm;
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
      text-align: center;
    }
    .pdf-run-footer__legal {
      margin-top: 1px;
    }

    .pdf-cover {
      page-break-after: always;
      page-break-inside: auto;
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
      text-align: center;
    }
    .hero__top {
      width: 100%;
      margin-bottom: 10px;
    }
    .hero__top td { vertical-align: middle; text-align: center; }
    .hero__logo-wrap {
      text-align: center;
      margin-bottom: 8px;
    }
    .doc-logo-svg--hero {
      width: 44px;
      height: 44px;
      display: inline-block;
      filter: brightness(0) invert(1);
      opacity: 0.95;
      margin: 0 auto;
    }
    .hero__title {
      font-size: 19px;
      font-weight: 700;
      margin: 0 0 4px;
      letter-spacing: -0.02em;
      line-height: 1.15;
      text-align: center;
    }
    .hero__sub {
      margin: 0;
      font-size: 10.5px;
      opacity: 0.92;
      font-weight: 500;
      text-align: center;
    }
    .hero__meta {
      margin-top: 12px;
      font-size: 10px;
      line-height: 1.55;
      opacity: 0.95;
      border-top: 1px solid rgba(255,255,255,0.25);
      padding-top: 10px;
      text-align: center;
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
    .status-mini table,
    .pdf-index-table {
      width: 100%;
      font-size: 9px;
      border-collapse: collapse;
    }
    /* Dompdf: thead em table-header-group repete o cabeçalho em cada página */
    .pdf-index-table thead {
      display: table-header-group;
    }
    .pdf-index-table tbody {
      display: table-row-group;
    }
    .pdf-index-table tr {
      page-break-inside: avoid;
      break-inside: avoid;
    }
    .status-mini th,
    .pdf-index-table th {
      text-align: center;
      padding: 4px 2px;
      background: var(--bm-bg-soft);
      border: 1px solid var(--bm-line);
      font-weight: 600;
      color: var(--bm-muted);
    }
    .status-mini td,
    .pdf-index-table td {
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

    /* Folhas de fotos: máx. 4 fotos/folha (2×2); paginação feita em PHP. */
    .chamado-page {
      page-break-after: always;
      page-break-inside: avoid;
    }
    .chamado-page:last-child {
      page-break-after: auto;
    }

    .chamado-doc {
      page-break-before: auto;
      page-break-inside: avoid;
    }
    .chamado-doc + .chamado-doc {
      margin-top: 10px;
      padding-top: 10px;
      border-top: 1px dashed var(--bm-line);
    }

    .ch-card {
      border: 1px solid var(--bm-line);
      border-left: 4px solid var(--bm-brand);
      border-radius: 6px;
      background: var(--bm-bg-alt);
      padding: 8px 10px;
      margin-bottom: 6px;
      page-break-inside: avoid;
      text-align: center;
    }
    .ch-card__head {
      display: table;
      width: 100%;
      margin-bottom: 0;
      text-align: center;
    }
    .ch-card__id {
      font-size: 11px;
      font-weight: 700;
      color: var(--bm-brand);
      margin: 0;
      text-align: center;
    }
    .ch-card__cont {
      display: block;
      margin-top: 2px;
      font-size: 8.5px;
      font-weight: 600;
      color: var(--bm-muted);
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }
    .ch-card__meta {
      font-size: 9px;
      line-height: 1.35;
      color: var(--bm-text);
      margin: 4px 0 0;
      text-align: center;
    }
    .ch-card__meta .muted { color: var(--bm-muted); }
    .ch-card__badges { margin-bottom: 6px; text-align: center; }

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
    .photo-quad-shell {
      width: 100%;
      margin: 4px 0 0;
      border-collapse: collapse;
    }
    .photo-quad-shell--keep {
      page-break-inside: avoid;
    }
    .photo-quad-shell__cell {
      padding: 0;
      vertical-align: top;
    }
    .photo-quad-line {
      width: 100%;
      margin: 0 0 4px;
    }
    .photo-quad-item {
      float: left;
      width: 49.5%;
      padding: 0 0.25%;
      box-sizing: border-box;
      text-align: left;
    }
    .photo-quad-item--full {
      float: none;
      width: 100%;
      padding: 0;
      text-align: left;
    }
    .photo-quad-item--empty {
      height: 1px;
    }
    .photo-quad-clear {
      clear: both;
      height: 0;
      line-height: 0;
      font-size: 0;
    }
    .photo-grid__fig {
      margin: 0;
      border: 0;
      border-radius: 0;
      padding: 0;
      background: transparent;
      overflow: hidden;
      display: block;
      box-sizing: border-box;
      text-align: left;
      vertical-align: top;
    }
    .photo-grid__imgwrap {
      width: 100%;
      height: 100%;
      text-align: left;
      line-height: 0;
      background: transparent;
    }
    .photo-grid__imgwrap img {
      display: block;
      margin: 0;
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
    <div class="pdf-run-footer__legal">Documento não substitui registos oficiais sem validação interna.</div>
  </div>

  <div class="sheet">
    <!-- Capa + resumo (página 1) -->
    <div class="pdf-cover">
      <div class="hero">
        <div class="hero__logo-wrap">
          <?php if ($logoInline !== ''): ?>
            <?= $logoInline ?>
          <?php else: ?>
            <div class="doc-logo-svg doc-logo-svg--hero" style="width:44px;height:44px;background:rgba(255,255,255,0.2);border-radius:10px;"></div>
          <?php endif; ?>
        </div>
        <h1 class="hero__title"><?= $h($docTitle) ?></h1>
        <p class="hero__sub"><?= $h($orgao) ?><?php if ($tagline !== ''): ?> · <?= $h($tagline) ?><?php endif; ?></p>
        <div class="hero__meta">
          <strong>Período (filtro):</strong> <?= $h($periodoLabel) ?><br />
          <strong>Emissão:</strong> <?= $h($emitidoEm) ?> · <strong>Total no período:</strong> <?= (int) $totalR ?> chamados
          · <strong>Neste PDF:</strong> <?= (int) $mostrados ?><?php if ($listaTruncada): ?> <span style="opacity:.95;">(lista limitada)</span><?php endif; ?>
        </div>
      </div>

      <p class="section-title" style="margin-top:16px;">Chamados neste relatório</p>
      <div class="status-mini">
        <table class="pdf-index-table" width="100%">
          <thead>
            <tr>
              <th style="width:12%;">#</th>
              <th style="width:22%;">Data</th>
              <th>Endereço / local</th>
            </tr>
          </thead>
          <tbody>
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
          </tbody>
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
    $paginasChamados = $items !== [] ? chamados_pdf_pack_photo_pages($items, $anexoEhImagem) : [];
    foreach ($paginasChamados as $packPagina):
        $compactPage = count($packPagina) > 1;
        ?>
    <div class="chamado-page">
        <?php
        foreach ($packPagina as $pack):
        $ch           = $pack['chamado'] ?? [];
        $imgs         = $pack['imgs'] ?? [];
        $outros       = $pack['outros'] ?? [];
        $continuation = !empty($pack['continuation']);
        $cid          = (int) ($ch['id'] ?? 0);
        $dataCh       = trim((string) ($ch['data'] ?? ''));
        $endereco     = trim((string) ($ch['endereco_completo'] ?? ''));
        $tecnico      = trim((string) ($ch['tecnico_nome'] ?? ''));
        ?>
    <section class="chamado-doc">
      <div class="ch-card">
        <div class="ch-card__head">
          <div class="ch-card__id">Chamado #<?= $h((string) $cid) ?></div>
          <?php if ($continuation): ?>
          <span class="ch-card__cont">Fotos (cont.)</span>
          <?php endif; ?>
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

      <?php if ($imgs === [] && !$continuation): ?>
      <p class="muted" style="font-size:8px;font-style:italic;margin:2px 0 0;text-align:center;">Sem fotos.</p>
      <?php elseif ($imgs !== []): ?>
        <?php
        echo chamados_pdf_photo_quadrants_html(
            $imgs,
            $cid,
            $embedImagesBase64,
            $projectRootFs,
            $h,
            $resolveAnexoImagemSrc,
            $anexoUrl,
            $compactPage
        );
        if ($outros !== [] && !$continuation) {
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
