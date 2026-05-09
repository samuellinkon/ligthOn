<?php
declare(strict_types=1);

/**
 * Documento HTML autónomo para impressão ou PDF (Ctrl+P → Guardar como PDF).
 */

/**
 * @param array<string,mixed> $chamado
 * @param list<array<string,mixed>> $respostas
 * @param list<array<string,mixed>> $materiais
 * @param list<array<string,mixed>> $anexos
 */
function chamado_export_document_html(
    array $chamado,
    array $respostas,
    array $materiais,
    array $anexos,
    bool $autoprint
): string {
    if (!defined('APP_BRAND_NAME')) {
        require_once __DIR__ . '/config.php';
    }
    $brand   = defined('APP_BRAND_NAME') ? (string) APP_BRAND_NAME : 'CRM';
    $tagline = defined('APP_BRAND_TAGLINE') ? (string) APP_BRAND_TAGLINE : '';

    $logoSvgPath = dirname(__DIR__) . '/assets/img/logo.svg';
    $logoInline  = '';
    if (is_readable($logoSvgPath)) {
        $raw = (string) file_get_contents($logoSvgPath);
        $raw = preg_replace('/\s(width|height)="[^"]*"/i', '', $raw) ?? $raw;
        $raw = str_replace('<svg ', '<svg class="doc-logo-svg" ', $raw);
        $logoInline = $raw;
    }

    $logoImgUrl = '';
    if (defined('APP_BRAND_LOGO') && is_string(APP_BRAND_LOGO) && APP_BRAND_LOGO !== '') {
        $rel = ltrim(APP_BRAND_LOGO, '/');
        $abs = dirname(__DIR__) . '/' . $rel;
        if (is_readable($abs)) {
            $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            $host  = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
            $adminDir = str_replace('\\', '/', dirname($script));
            $rootWeb = rtrim(dirname($adminDir), '/');
            $logoImgUrl = ($https ? 'https://' : 'http://') . $host . $rootWeb . '/' . $rel;
        }
    }

    $h = static function (?string $s): string {
        return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };

    $cid = (int) ($chamado['id'] ?? 0);
    $fmtMoney = static function (float $v): string {
        return 'R$ ' . number_format($v, 2, ',', '.');
    };

    $totalMat = 0.0;
    foreach ($materiais as $m) {
        if (($m['movimento'] ?? '') === 'utilizado') {
            $totalMat += (float) ($m['subtotal'] ?? 0);
        }
    }

    $abertoEm = '';
    if (!empty($chamado['data'])) {
        $ts = strtotime((string) $chamado['data']);
        $abertoEm = $ts ? date('d/m/Y \à\s H:i', $ts) : (string) $chamado['data'];
    }

    $posteTxt = '';
    $pid = (int) ($chamado['ponto_iluminacao_id'] ?? 0);
    if ($pid > 0) {
        $cod = trim((string) ($chamado['ponto_codigo_poste'] ?? ''));
        $posteTxt = $cod !== '' ? $cod : ('Poste #' . $pid);
    }

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex,nofollow" />
  <title>Ficha do chamado #<?= $h((string) $cid) ?> — <?= $h($brand) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --ink: #0f172a;
      --muted: #64748b;
      --line: #e2e8f0;
      --panel: #f8fafc;
      --accent: #534ab7;
      --accent-soft: #eef2ff;
      --ok: #059669;
      --warn: #d97706;
      --danger: #dc2626;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
      font-size: 13px;
      line-height: 1.5;
      color: var(--ink);
      background: #fff;
    }
    @page { size: A4; margin: 14mm 12mm; }
    @media print {
      body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      .no-print { display: none !important; }
      a[href^="http"]::after { content: ''; }
    }
    .sheet {
      max-width: 800px;
      margin: 0 auto;
      padding: 24px 20px 48px;
    }
    .doc-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 20px;
      padding-bottom: 20px;
      border-bottom: 3px solid var(--accent);
      margin-bottom: 22px;
    }
    .doc-brand {
      display: flex;
      align-items: center;
      gap: 14px;
    }
    .doc-brand-img {
      max-height: 44px;
      max-width: 180px;
      width: auto;
      object-fit: contain;
      display: block;
    }
    .doc-logo-svg {
      width: 52px;
      height: 52px;
      flex-shrink: 0;
      display: block;
    }
    .doc-brand-text .name {
      font-size: 20px;
      font-weight: 800;
      letter-spacing: -0.02em;
      color: var(--ink);
      line-height: 1.15;
    }
    .doc-brand-text .tag {
      font-size: 12px;
      color: var(--muted);
      font-weight: 500;
      margin-top: 2px;
    }
    .doc-type {
      text-align: right;
    }
    .doc-type .label {
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--accent);
    }
    .doc-type .ch-num {
      font-size: 28px;
      font-weight: 800;
      letter-spacing: -0.03em;
      line-height: 1.1;
    }
    .doc-type .when {
      font-size: 12px;
      color: var(--muted);
      margin-top: 6px;
    }
    .title-block {
      margin-bottom: 20px;
    }
    .title-block h1 {
      margin: 0 0 10px;
      font-size: 22px;
      font-weight: 700;
      letter-spacing: -0.02em;
      line-height: 1.25;
    }
    .badges { display: flex; flex-wrap: wrap; gap: 8px; }
    .badge {
      display: inline-flex;
      align-items: center;
      padding: 4px 10px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 600;
      border: 1px solid var(--line);
      background: var(--panel);
    }
    .badge--status { background: var(--accent-soft); border-color: #c7d2fe; color: #3730a3; }
    .badge--pri-alta, .badge--pri-urgente { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
    .badge--pri-baixa, .badge--pri-normal { background: #f0fdf4; border-color: #bbf7d0; color: #166534; }
    .grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px 18px;
      margin-bottom: 22px;
    }
    @media (max-width: 560px) { .grid { grid-template-columns: 1fr; } }
    .field {
      padding: 12px 14px;
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 10px;
    }
    .field .k {
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: var(--muted);
      margin-bottom: 4px;
    }
    .field .v { font-size: 13px; font-weight: 500; word-break: break-word; }
    .section {
      margin-top: 22px;
      border: 1px solid var(--line);
      border-radius: 12px;
      overflow: hidden;
    }
    .section-h {
      padding: 12px 16px;
      background: linear-gradient(180deg, #fafbff 0%, #f1f5f9 100%);
      border-bottom: 1px solid var(--line);
      font-size: 12px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--ink);
    }
    .section-b { padding: 16px; }
    .prose {
      white-space: pre-wrap;
      word-break: break-word;
      color: #334155;
      line-height: 1.65;
    }
    .muted { color: var(--muted); font-size: 12px; }
    table.data {
      width: 100%;
      border-collapse: collapse;
      font-size: 12px;
    }
    table.data th, table.data td {
      border: 1px solid var(--line);
      padding: 8px 10px;
      text-align: left;
      vertical-align: top;
    }
    table.data th {
      background: var(--panel);
      font-weight: 600;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: var(--muted);
    }
    .msg {
      border: 1px solid var(--line);
      border-radius: 10px;
      padding: 12px 14px;
      margin-bottom: 10px;
      background: #fff;
    }
    .msg:last-child { margin-bottom: 0; }
    .msg-head {
      display: flex;
      flex-wrap: wrap;
      gap: 8px 12px;
      align-items: baseline;
      margin-bottom: 8px;
      font-size: 12px;
    }
    .msg-head strong { font-weight: 700; }
    .msg-head .tipo { color: var(--muted); font-weight: 500; }
    .msg .text { white-space: pre-wrap; word-break: break-word; color: #334155; }
    .pill-int { font-size: 10px; padding: 2px 8px; border-radius: 6px; background: #fff7ed; color: #9a3412; font-weight: 600; }
    .footer {
      margin-top: 32px;
      padding-top: 16px;
      border-top: 1px dashed var(--line);
      font-size: 11px;
      color: var(--muted);
      text-align: center;
    }
    .toolbar {
      position: sticky;
      top: 0;
      z-index: 10;
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      align-items: center;
      justify-content: center;
      padding: 12px 16px;
      background: rgba(255,255,255,.92);
      backdrop-filter: blur(8px);
      border-bottom: 1px solid var(--line);
    }
    .toolbar button {
      font-family: inherit;
      font-size: 13px;
      font-weight: 600;
      padding: 10px 18px;
      border-radius: 10px;
      border: none;
      cursor: pointer;
      background: var(--accent);
      color: #fff;
    }
    .toolbar button:hover { filter: brightness(1.05); }
    .toolbar span { font-size: 12px; color: var(--muted); max-width: 420px; text-align: center; }
  </style>
</head>
<body>
  <div class="toolbar no-print">
    <button type="button" onclick="window.print()">Imprimir / Guardar como PDF</button>
    <span>Use o destino <strong>Guardar como PDF</strong> na impressora do sistema para obter o ficheiro PDF.</span>
  </div>
  <div class="sheet">
    <header class="doc-header">
      <div class="doc-brand">
        <?php if ($logoImgUrl !== ''): ?>
        <img class="doc-brand-img" src="<?= htmlspecialchars($logoImgUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= $h($brand) ?>" />
        <?php elseif ($logoInline !== ''): ?>
        <?= $logoInline ?>
        <?php else: ?>
        <div class="doc-logo-svg" style="width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#534AB7,#7F77DD);"></div>
        <?php endif; ?>
        <div class="doc-brand-text">
          <div class="name"><?= $h($brand) ?></div>
          <?php if ($tagline !== ''): ?><div class="tag"><?= $h($tagline) ?></div><?php endif; ?>
        </div>
      </div>
      <div class="doc-type">
        <div class="label">Chamado</div>
        <div class="ch-num">#<?= $h((string) $cid) ?></div>
        <div class="when">Emitido em <?= $h(date('d/m/Y H:i')) ?></div>
      </div>
    </header>

    <div class="title-block">
      <h1><?= $h((string) ($chamado['titulo'] ?? '')) ?></h1>
      <div class="badges">
        <?php
        $st = (string) ($chamado['status'] ?? '');
        $pr = (string) ($chamado['prioridade'] ?? '');
        $priClass = 'badge--pri-normal';
        if (in_array($pr, ['Alta', 'Urgente'], true)) {
            $priClass = 'badge--pri-alta';
        }
        ?>
        <span class="badge badge--status"><?= $h($st !== '' ? $st : '—') ?></span>
        <span class="badge <?= $h($priClass) ?>">Prioridade: <?= $h($pr !== '' ? $pr : '—') ?></span>
        <?php if ($abertoEm !== ''): ?>
        <span class="badge">Aberto em <?= $h($abertoEm) ?></span>
        <?php endif; ?>
        <?php if ($posteTxt !== ''): ?>
        <span class="badge">Poste: <?= $h($posteTxt) ?></span>
        <?php endif; ?>
      </div>
    </div>

    <div class="grid">
      <div class="field"><div class="k">Prefeitura / órgão</div><div class="v"><?= $h((string) ($chamado['cliente'] ?? '')) ?></div></div>
      <div class="field"><div class="k">Responsável</div><div class="v"><?= $h((string) ($chamado['responsavel'] ?? '—')) ?></div></div>
      <div class="field"><div class="k">Técnico</div><div class="v"><?= $h((string) ($chamado['tecnico_nome'] ?? '—')) ?></div></div>
      <div class="field"><div class="k">Serviço (catálogo)</div><div class="v"><?= $h(trim((string) ($chamado['servico_nome'] ?? '')) ?: '—') ?></div></div>
      <?php if (!empty($chamado['finalizado_operador_em'])): ?>
      <div class="field"><div class="k">Finalizado pelo operador</div><div class="v"><?= $h((string) $chamado['finalizado_operador_em']) ?></div></div>
      <?php endif; ?>
      <?php if (!empty($chamado['aprovado_gestor_em'])): ?>
      <div class="field"><div class="k">Aprovado pelo gestor</div><div class="v"><?= $h((string) $chamado['aprovado_gestor_em']) ?> <?= !empty($chamado['aprovado_gestor_nome']) ? ' · ' . $h((string) $chamado['aprovado_gestor_nome']) : '' ?></div></div>
      <?php endif; ?>
      <?php if (!empty(trim((string) ($chamado['checklist_realizado'] ?? '')))): ?>
      <div class="field" style="grid-column:1/-1;"><div class="k">Checklist (execução)</div><div class="v prose" style="white-space:pre-wrap;"><?= nl2br($h(trim((string) $chamado['checklist_realizado']))) ?></div></div>
      <?php endif; ?>
    </div>

    <section class="section">
      <div class="section-h">Descrição</div>
      <div class="section-b">
        <div class="prose"><?php
        $__d = trim((string) ($chamado['descricao'] ?? ''));
        echo $__d !== '' ? nl2br($h($__d)) : '<span class="muted">Sem texto.</span>';
        ?></div>
      </div>
    </section>

    <?php
    $end = trim((string) ($chamado['endereco_completo'] ?? ''));
    $la  = $chamado['latitude'] ?? null;
    $lo  = $chamado['longitude'] ?? null;
    if ($end !== '' || ($la !== null && $la !== '' && $lo !== null && $lo !== '')):
    ?>
    <section class="section" style="margin-top:16px;">
      <div class="section-h">Localização</div>
      <div class="section-b">
        <?php if ($end !== ''): ?><p class="prose" style="margin:0 0 10px;"><?= nl2br($h($end)) ?></p><?php endif; ?>
        <?php if ($la !== null && $la !== '' && $lo !== null && $lo !== ''): ?>
        <p class="muted" style="margin:0;">Coordenadas: <?= $h((string) $la) ?>, <?= $h((string) $lo) ?></p>
        <?php endif; ?>
      </div>
    </section>
    <?php endif; ?>

    <section class="section" style="margin-top:16px;">
      <div class="section-h">Histórico de mensagens</div>
      <div class="section-b">
        <?php if ($respostas === []): ?>
          <p class="muted" style="margin:0;">Nenhuma resposta registada.</p>
        <?php else: ?>
          <?php foreach ($respostas as $r): ?>
            <div class="msg">
              <div class="msg-head">
                <strong><?= $h((string) ($r['autor'] ?? '')) ?></strong>
                <span class="tipo"><?= $h((string) ($r['tipo'] ?? '')) ?> · <?= $h((string) ($r['data'] ?? '')) ?></span>
                <?php if (!empty($r['interna'])): ?><span class="pill-int">Interna</span><?php endif; ?>
              </div>
              <div class="text"><?= nl2br($h((string) ($r['texto'] ?? ''))) ?></div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>

    <section class="section" style="margin-top:16px;">
      <div class="section-h">Itens do atendimento<?php if ($totalMat > 0): ?> — total utilizado: <?= $h($fmtMoney($totalMat)) ?><?php endif; ?></div>
      <div class="section-b" style="padding:0;">
        <?php if ($materiais === []): ?>
          <p class="muted" style="margin:16px;">Nenhum item lançado.</p>
        <?php else: ?>
          <table class="data">
            <thead>
              <tr>
                <th>Movimento</th>
                <th>Item</th>
                <th>Código</th>
                <th>Tipo</th>
                <th>Qtd</th>
                <th>Subtotal</th>
                <th>Obs.</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($materiais as $m): ?>
              <tr>
                <td><?= $h((string) ($m['movimento'] ?? '')) ?></td>
                <td><?= $h((string) ($m['item_nome'] ?? '')) ?></td>
                <td><?= $h((string) ($m['item_codigo'] ?? '')) ?></td>
                <td><?= $h((string) ($m['item_tipo'] ?? '')) ?></td>
                <td><?= $h((string) ($m['quantidade'] ?? '')) ?></td>
                <td><?= $h($fmtMoney((float) ($m['subtotal'] ?? 0))) ?></td>
                <td><?= $h(trim((string) ($m['observacao'] ?? ''))) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </section>

    <section class="section" style="margin-top:16px;">
      <div class="section-h">Anexos (metadados)</div>
      <div class="section-b" style="padding:0;">
        <?php if ($anexos === []): ?>
          <p class="muted" style="margin:16px;">Sem anexos.</p>
        <?php else: ?>
          <table class="data">
            <thead>
              <tr>
                <th>Ficheiro</th>
                <th>Tamanho</th>
                <th>Enviado por</th>
                <th>Data</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($anexos as $a): ?>
              <tr>
                <td><?= $h((string) ($a['nome_original'] ?? '')) ?></td>
                <td><?= $h((string) ($a['tamanho'] ?? '')) ?> bytes</td>
                <td><?= $h((string) ($a['enviado_por'] ?? '')) ?> (<?= $h((string) ($a['enviado_tipo'] ?? '')) ?>)</td>
                <td><?= $h((string) ($a['enviado_em'] ?? '')) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </section>

    <footer class="footer">
      Documento gerado pelo sistema <?= $h($brand) ?> · Chamado #<?= $h((string) $cid) ?> · Não substitui registos oficiais sem validação interna.
    </footer>
  </div>
  <?php if ($autoprint): ?>
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
