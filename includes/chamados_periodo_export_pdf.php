<?php
declare(strict_types=1);

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
        $raw = str_replace('<svg ', '<svg class="doc-logo-svg" ', $raw);
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
    $nAguardando  = (int) ($porSt['Aguardando'] ?? 0);
    $nResolvido   = (int) ($porSt['Resolvido'] ?? 0);
    $nFechado     = (int) ($porSt['Fechado'] ?? 0);
    $nCancelado   = (int) ($porSt['Cancelado'] ?? 0);

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

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex,nofollow" />
  <title>Relatório de chamados — <?= $h($periodoLabel) ?> — <?= $h($brand) ?></title>
  <?php if (!$embedImagesBase64): ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <?php endif; ?>
  <style>
    :root {
      --ink: #1e293b;
      --muted: #64748b;
      --line: #cbd5e1;
      --panel: #f1f5f9;
      --accent: #4338ca;
      --accent-soft: #e0e7ff;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: <?= $embedImagesBase64
          ? "DejaVu Sans, DejaVu Sans, sans-serif"
          : "Inter, DejaVu Sans, Helvetica, Arial, sans-serif" ?>;
      font-size: 11px;
      line-height: 1.45;
      color: var(--ink);
      background: #fff;
    }
    @page { size: A4; margin: 16mm 14mm; }
    table { border-collapse: collapse; }
    .no-print { }
    @media print {
      body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      .no-print { display: none !important; }
      a { color: var(--accent); }
    }
    .toolbar {
      text-align: center;
      padding: 10px;
      border-bottom: 1px solid var(--line);
      background: #fafafa;
    }
    .toolbar button {
      font-family: inherit;
      font-size: 12px;
      font-weight: 600;
      padding: 8px 16px;
      border: 1px solid var(--accent);
      background: var(--accent);
      color: #fff;
      cursor: pointer;
    }
    .sheet { padding: 0 0 24px; }
    .pdf-cover {
      page-break-after: always;
    }
    .pdf-exec {
      page-break-after: always;
    }
    .chamado-doc {
      page-break-before: always;
    }
    .chamado-doc-first {
      page-break-before: auto;
    }
    .cover-top {
      border-bottom: 2px solid var(--accent);
      padding-bottom: 16px;
      margin-bottom: 20px;
    }
    .cover-brand-row td { vertical-align: middle; padding: 0 8px 0 0; }
    .doc-logo-svg { width: 48px; height: 48px; display: block; }
    .cover-title {
      font-size: 18px;
      font-weight: 700;
      color: var(--ink);
      margin: 0 0 4px;
      letter-spacing: -0.02em;
    }
    .cover-sub { font-size: 11px; color: var(--muted); margin: 0; }
    .cover-meta-table {
      width: 100%;
      margin: 16px 0;
      font-size: 10px;
      border: 1px solid var(--line);
    }
    .cover-meta-table th {
      text-align: left;
      background: var(--panel);
      border: 1px solid var(--line);
      padding: 6px 10px;
      width: 28%;
      color: var(--muted);
      font-weight: 600;
    }
    .cover-meta-table td {
      border: 1px solid var(--line);
      padding: 6px 10px;
    }
    .stat-strip {
      width: 100%;
      margin-top: 14px;
      font-size: 9px;
    }
    .stat-strip th {
      background: var(--panel);
      border: 1px solid var(--line);
      padding: 6px 4px;
      text-align: center;
      font-weight: 600;
      color: var(--muted);
    }
    .stat-strip td {
      border: 1px solid var(--line);
      padding: 8px 4px;
      text-align: center;
      font-weight: 700;
      font-size: 14px;
      color: var(--ink);
    }
    .section-title {
      font-size: 12px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: var(--accent);
      margin: 0 0 10px;
      border-bottom: 1px solid var(--line);
      padding-bottom: 4px;
    }
    .exec-table {
      width: 100%;
      font-size: 10px;
      border: 1px solid var(--line);
      margin-bottom: 12px;
    }
    .exec-table th {
      background: var(--panel);
      border: 1px solid var(--line);
      padding: 8px 10px;
      text-align: left;
      width: 42%;
    }
    .exec-table td {
      border: 1px solid var(--line);
      padding: 8px 10px;
    }
    .warn-box {
      border: 1px solid #f59e0b;
      background: #fffbeb;
      padding: 10px 12px;
      font-size: 10px;
      margin: 12px 0;
    }
    .ch-head {
      border: 1px solid var(--line);
      background: var(--panel);
      padding: 12px 14px;
      margin-bottom: 10px;
    }
    .ch-id { font-size: 20px; font-weight: 700; color: var(--accent); margin-bottom: 6px; }
    .ch-date { font-size: 11px; color: var(--muted); }
    .anexo-table {
      width: 100%;
      font-size: 10px;
      border: 1px solid var(--line);
    }
    tr.anexo-img-row-break {
      page-break-before: always;
    }
    .anexo-table th {
      background: var(--panel);
      border: 1px solid var(--line);
      padding: 6px 8px;
      text-align: left;
    }
    .anexo-table td {
      border: 1px solid var(--line);
      padding: 8px;
      vertical-align: top;
    }
    .anexo-img {
      max-width: 100%;
      max-height: 280px;
      height: auto;
      border: 1px solid var(--line);
      display: block;
      margin: 0 auto;
    }
    .anexo-meta { font-size: 9px; color: var(--muted); margin-top: 6px; }
    .doc-footer {
      margin-top: 16px;
      padding-top: 10px;
      border-top: 1px solid var(--line);
      font-size: 9px;
      color: var(--muted);
      text-align: center;
      page-break-inside: avoid;
    }
    .prio-grid td { font-size: 9px; }
  </style>
</head>
<body>
  <?php if (!$embedImagesBase64): ?>
  <div class="toolbar no-print">
    <button type="button" onclick="window.print()">Imprimir / Guardar como PDF</button>
  </div>
  <?php endif; ?>

  <div class="sheet">
    <!-- Capa -->
    <div class="pdf-cover">
      <div class="cover-top">
        <table class="cover-brand-row" width="100%">
          <tr>
            <td width="56">
              <?php if ($logoInline !== ''): ?>
                <?= $logoInline ?>
              <?php else: ?>
                <div class="doc-logo-svg" style="background:linear-gradient(135deg,#4338ca,#6366f1);border-radius:8px;"></div>
              <?php endif; ?>
            </td>
            <td>
              <h1 class="cover-title">Relatório de Chamados de Iluminação Pública</h1>
              <p class="cover-sub"><?= $h($brand) ?><?php if ($tagline !== ''): ?> — <?= $h($tagline) ?><?php endif; ?></p>
            </td>
          </tr>
        </table>
      </div>

      <table class="cover-meta-table">
        <tr><th>Município / órgão</th><td><?= $h($orgao) ?></td></tr>
        <tr><th>Período (filtro)</th><td><?= $h($periodoLabel) ?></td></tr>
        <tr><th>Data de emissão</th><td><?= $h(date('d/m/Y H:i')) ?></td></tr>
        <tr><th>Total de chamados (período)</th><td><?= (int) $totalR ?></td></tr>
        <tr><th>Chamados neste documento</th><td><?= (int) $mostrados ?><?php if ($listaTruncada): ?> <span style="color:#b45309;">(lista limitada)</span><?php endif; ?></td></tr>
      </table>

      <p class="section-title" style="margin-top:18px;">Resumo por status</p>
      <table class="stat-strip">
        <tr>
          <th>Abertos</th>
          <th>Em andamento</th>
          <th>Aguardando</th>
          <th>Resolvidos</th>
          <th>Fechados</th>
          <th>Cancelados</th>
        </tr>
        <tr>
          <td><?= $nAberto ?></td>
          <td><?= $nAndamento ?></td>
          <td><?= $nAguardando ?></td>
          <td><?= $nResolvido ?></td>
          <td><?= $nFechado ?></td>
          <td><?= $nCancelado ?></td>
        </tr>
      </table>

      <?php if ($porPr !== []): ?>
      <p class="section-title" style="margin-top:16px;">Resumo por prioridade</p>
      <table class="stat-strip prio-grid">
        <tr>
          <?php foreach ($porPr as $label => $num): ?>
          <th><?= $h((string) $label) ?></th>
          <?php endforeach; ?>
        </tr>
        <tr>
          <?php foreach ($porPr as $num): ?>
          <td><?= (int) $num ?></td>
          <?php endforeach; ?>
        </tr>
      </table>
      <?php endif; ?>

      <?php if ($listaTruncada): ?>
      <div class="warn-box">
        A lista de chamados detalhados foi limitada aos primeiros <strong><?= (int) $mostrados ?></strong> registos.
        Refine filtros ou exporte CSV para o conjunto completo. Os totais da capa referem-se a <strong>todos</strong> os chamados do período com os filtros atuais.
      </div>
      <?php endif; ?>
    </div>

    <!-- Resumo executivo -->
    <div class="pdf-exec">
      <h2 class="section-title">Resumo executivo</h2>
      <table class="exec-table">
        <tr><th>Total de chamados (período)</th><td><?= (int) $totalR ?></td></tr>
        <tr><th>Abertos</th><td><?= $nAberto ?></td></tr>
        <tr><th>Em andamento</th><td><?= $nAndamento ?></td></tr>
        <tr><th>Resolvidos</th><td><?= $nResolvido ?></td></tr>
        <tr><th>Fechados</th><td><?= $nFechado ?></td></tr>
        <tr><th>Urgentes (Alta/Urgente, não encerrados)</th><td><?= $urgAb ?></td></tr>
        <tr><th>Chamados com anexos</th><td><?= $comAx ?></td></tr>
      </table>
      <div class="doc-footer">Documento gerado pelo sistema <?= $h($brand) ?> · Uso institucional e auditoria · Não substitui registos oficiais sem validação interna.</div>
    </div>

    <!-- Chamados -->
    <?php if ($items === []): ?>
    <p style="color:var(--muted);text-align:center;padding:32px 12px;">Nenhum chamado CRM encontrado com os filtros e período atuais.</p>
    <?php endif; ?>

    <?php
    $firstChamadoPdf = true;
    foreach ($items as $pack):
        $ch     = $pack['chamado'];
        $anexos = $pack['anexos'];
        $cid    = (int) ($ch['id'] ?? 0);
        $chClass = 'chamado-doc' . ($firstChamadoPdf ? ' chamado-doc-first' : '');
        $firstChamadoPdf = false;
        $dataCh = trim((string) ($ch['data'] ?? ''));
        ?>
    <section class="<?= $h($chClass) ?>">
      <div class="ch-head">
        <div class="ch-id">Chamado #<?= $h((string) $cid) ?></div>
        <div class="ch-date">Data: <?= $h($dataCh !== '' ? $dataCh : '—') ?></div>
      </div>

      <p class="section-title">Anexos</p>
      <?php $pdfImgSeqChamado = 0; ?>
      <?php if ($anexos === []): ?>
      <p style="font-size:10px;color:var(--muted);font-style:italic;">Sem anexos neste chamado.</p>
      <?php else: ?>
      <table class="anexo-table">
        <thead>
          <tr>
            <th width="32%">Ficheiro</th>
            <th width="12%">Tipo</th>
            <th width="12%">Tamanho</th>
            <th>Pré-visualização / link</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($anexos as $a):
            $aid  = (int) ($a['id'] ?? 0);
            $nome = (string) ($a['nome_original'] ?? '');
            $mime = (string) ($a['mime'] ?? '');
            $tam  = (int) ($a['tamanho'] ?? 0);
            $fn   = basename(trim((string) ($a['nome_arquivo'] ?? '')));
            $href = $anexoUrl($aid);
            $ext  = $extAnexo($a);
            $tipoLabel = $ext !== '' ? strtoupper($ext) : ($mime !== '' ? $mime : '—');
            $ehImg = $anexoEhImagem($a);
            $ehPdf = ($ext === 'pdf') || (stripos($mime, 'pdf') !== false);

            $srcImg = '';
            if ($embedImagesBase64 && $ehImg && $cid > 0) {
                $path = $fn !== '' ? upload_dir_chamado($cid) . DIRECTORY_SEPARATOR . $fn : '';
                $real = ($path !== '' && is_file($path)) ? realpath($path) : false;
                $readPath = $real !== false ? $real : $path;
                if ($readPath !== '' && is_file($readPath) && is_readable($readPath)) {
                    $rootNorm = str_replace('\\', '/', $projectRootFs);
                    $readNorm = str_replace('\\', '/', $readPath);
                    if ($rootNorm !== '' && str_starts_with($readNorm, $rootNorm)) {
                        /* Caminho absoluto sob a raiz do projecto: Dompdf lê via chroot sem data URI gigante. */
                        $srcImg = $readNorm;
                    } else {
                        $rawImg = @file_get_contents($readPath);
                        if ($rawImg !== false && $rawImg !== '') {
                            $dataMime = $mimeImagemParaDataUri($readPath, $mime, $nome, $fn);
                            $srcImg   = 'data:' . $dataMime . ';base64,' . base64_encode((string) $rawImg);
                        } else {
                            error_log('[crm_prefeitura] PDF chamado ' . $cid . ' anexo id=' . $aid . ': leitura vazia — ' . $readPath);
                        }
                    }
                } elseif ($fn !== '') {
                    error_log('[crm_prefeitura] PDF chamado ' . $cid . ' anexo id=' . $aid . ': ficheiro não encontrado — ' . $path);
                }
            }
            $rowClassTr = '';
            if ($ehImg) {
                ++$pdfImgSeqChamado;
                if ($pdfImgSeqChamado > 1) {
                    $rowClassTr = ' class="anexo-img-row-break"';
                }
            }
            ?>
          <tr<?= $rowClassTr ?>>
            <td><strong><?= $h($nome !== '' ? $nome : 'Anexo #' . (string) $aid) ?></strong></td>
            <td><?= $h($tipoLabel) ?></td>
            <td><?= $h($fmtBytes($tam)) ?></td>
            <td>
              <?php if ($ehImg): ?>
                <?php if ($srcImg !== ''): ?>
              <img class="anexo-img" src="<?= $h($srcImg) ?>" alt="<?= $h($nome) ?>" />
              <div class="anexo-meta"><?= $h($nome) ?> · <?= $h($fmtBytes($tam)) ?></div>
                <?php else: ?>
              <span style="color:#b45309;font-size:9px;">Imagem não incorporada (ficheiro em falta ou indisponível).</span>
              <div class="anexo-meta"><a href="<?= $href ?>">Tentar abrir no CRM</a></div>
                <?php endif; ?>
              <?php elseif ($ehPdf && $aid > 0 && in_array($aid, $anexoPdfIdsIncorporados, true)): ?>
              <div style="font-size:9px;color:var(--muted);margin-bottom:6px;">
                As páginas deste PDF foram <strong>acrescidas ao final deste relatório</strong> (único ficheiro descarregado). Ordem: relatório <?= $h($brand) ?>, depois anexos PDF por chamado.
              </div>
              <div class="anexo-meta"><a href="<?= $href ?>">Abrir também no CRM — anexo #<?= (int) $aid ?></a></div>
              <?php elseif ($ehPdf): ?>
              <div style="font-size:9px;color:var(--muted);margin-bottom:6px;">
                PDF no servidor não encontrado ou pacote FPDI indisponível — utilize o link (requer sessão no CRM).
              </div>
              <a href="<?= $href ?>"><?= $h('Descarregar / abrir — anexo #' . (string) $aid) ?></a>
              <?php else: ?>
              <div style="font-size:9px;color:var(--muted);margin-bottom:6px;">
                Ficheiro não incorporado no relatório PDF. Utilize o link para descarregar no CRM.
              </div>
              <a href="<?= $href ?>"><?= $h('Descarregar / abrir — anexo #' . (string) $aid) ?></a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>

      <div class="doc-footer">Documento gerado pelo sistema <?= $h($brand) ?> · Chamado #<?= (int) $cid ?> · <?= $h(date('d/m/Y H:i')) ?></div>
    </section>
    <?php endforeach; ?>

    <div class="doc-footer" style="margin-top:28px;">
      Relatório de chamados de iluminação pública — <?= $h($brand) ?> · Período: <?= $h($periodoLabel) ?>
    </div>
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
