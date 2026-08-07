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
 * @param list<array<string,mixed>> $fotosPonto Imagens do cadastro do poste (ponto_iluminacao_imagens)
 * @param list<array<string,mixed>> $historico Eventos do histórico do chamado
 * @param list<array<string,mixed>> $tecnicos Equipe atribuída
 */
function chamado_export_document_html(
    array $chamado,
    array $respostas,
    array $materiais,
    array $anexos,
    bool $autoprint,
    array $fotosPonto = [],
    bool $resumoConversa = false,
    array $historico = [],
    array $tecnicos = []
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

    $mimeIsImage = static function (?string $mime): bool {
        return strncmp(strtolower(trim((string) $mime)), 'image/', 6) === 0;
    };

    $fmtBytes = static function (int $bytes): string {
        if ($bytes < 1024) {
            return (string) $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB'];
        $v     = $bytes / 1024;
        $i     = 0;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }

        return number_format($v, $v >= 10 ? 0 : 1, ',', '.') . ' ' . $units[$i];
    };

    $extFromMimeOrName = static function (?string $mime, string $nome): string {
        $nome = trim($nome);
        if ($nome !== '' && str_contains($nome, '.')) {
            return strtolower(pathinfo($nome, PATHINFO_EXTENSION) ?: '');
        }
        $m = strtolower(trim((string) $mime));
        if ($m === 'application/pdf') {
            return 'pdf';
        }
        if (strncmp($m, 'image/', 6) === 0) {
            return substr($m, 6) ?: 'img';
        }

        return '';
    };

    $excerptMsg = static function (string $t, int $max = 200): string {
        $t = trim($t);
        if ($t === '') {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($t) <= $max) {
                return $t;
            }

            return rtrim(mb_substr($t, 0, $max)) . '…';
        }
        if (strlen($t) <= $max) {
            return $t;
        }

        return substr($t, 0, $max) . '…';
    };

    $roleFromTipo = static function (string $tipo): string {
        $t = trim($tipo);
        if ($t === '') {
            return 'Interlocutor';
        }
        $tl = function_exists('mb_strtolower') ? mb_strtolower($t, 'UTF-8') : strtolower($t);
        if (str_contains($tl, 'gestor')) {
            return 'Gestor';
        }
        if (str_contains($tl, 'operador') || str_contains($tl, 'técnico') || str_contains($tl, 'tecnico')) {
            return 'Operador';
        }
        if (str_contains($tl, 'cliente') || str_contains($tl, 'cidadao') || str_contains($tl, 'cidadão')) {
            return 'Cliente';
        }

        return $t;
    };

    $cid = (int) ($chamado['id'] ?? 0);
    $fmtMoney = static function (float $v): string {
        return 'R$ ' . number_format($v, 2, ',', '.');
    };

    $matsUtil = [];
    $matsDev  = [];
    foreach ($materiais as $m) {
        $mov = strtolower(trim((string) ($m['movimento'] ?? 'utilizado')));
        if ($mov === 'devolvido') {
            $matsDev[] = $m;
        } else {
            $matsUtil[] = $m;
        }
    }

    $totalMat = 0.0;
    foreach ($matsUtil as $m) {
        $totalMat += (float) ($m['subtotal'] ?? 0);
    }

    $emitidoEm = date('d/m/Y \à\s H:i');
    $emitidoIso = date('c');

    $fmtDt = static function (?string $raw): string {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }
        $ts = strtotime($raw);

        return $ts ? date('d/m/Y \à\s H:i', $ts) : $raw;
    };

    $fmtCpf = static function (?string $cpf): string {
        $cpf = trim((string) $cpf);
        if ($cpf === '') {
            return '';
        }
        if (function_exists('chamado_os_sanitize_cpf')) {
            return (string) (chamado_os_sanitize_cpf($cpf) ?? $cpf);
        }
        $d = preg_replace('/\D/', '', $cpf) ?? '';
        if (strlen($d) === 11) {
            return substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9, 2);
        }

        return $cpf;
    };

    $vOuTraco = static function (string $s): string {
        $s = trim($s);

        return $s !== '' ? $s : '—';
    };

    $abertoEm = '';
    if (!empty($chamado['data_abertura_os'])) {
        $abertoEm = $fmtDt((string) $chamado['data_abertura_os']);
    }
    if ($abertoEm === '' && !empty($chamado['data'])) {
        $abertoEm = $fmtDt((string) $chamado['data']);
    }

    $posteTxt = '';
    $pid = (int) ($chamado['ponto_iluminacao_id'] ?? 0);
    if ($pid > 0) {
        $cod = trim((string) ($chamado['ponto_codigo_poste'] ?? ''));
        $posteTxt = $cod !== '' ? $cod : ('Poste #' . $pid);
    } else {
        $posteTxt = '— Sem vínculo com ponto —';
    }

    $equipeNomes = [];
    if ($tecnicos !== []) {
        foreach ($tecnicos as $tec) {
            $nm = trim((string) ($tec['nome'] ?? ''));
            if ($nm !== '') {
                $equipeNomes[] = $nm;
            }
        }
    }
    if ($equipeNomes === [] && !empty($chamado['tecnicos']) && is_array($chamado['tecnicos'])) {
        foreach ($chamado['tecnicos'] as $tec) {
            if (is_array($tec)) {
                $nm = trim((string) ($tec['nome'] ?? ''));
            } else {
                $nm = trim((string) $tec);
            }
            if ($nm !== '') {
                $equipeNomes[] = $nm;
            }
        }
    }
    if ($equipeNomes === []) {
        $tn = trim((string) ($chamado['tecnico_nome'] ?? ''));
        if ($tn !== '') {
            $equipeNomes[] = $tn;
        }
    }
    $nTec = count($equipeNomes);
    $equipeTxt = $equipeNomes !== [] ? implode(', ', $equipeNomes) : '';

    $categoriaTxt = trim((string) ($chamado['tipo_os'] ?? ''));
    if ($categoriaTxt === '') {
        $categoriaTxt = trim((string) ($chamado['servico_tipo'] ?? ''));
    }
    $canalTxt = trim((string) ($chamado['origem_os'] ?? ''));
    $problemaOs = trim((string) ($chamado['problema_os'] ?? ''));
    $tipoChamadoHdr = $categoriaTxt !== '' ? $categoriaTxt : trim((string) ($chamado['servico_tipo'] ?? ''));
    if ($tipoChamadoHdr === '' && $problemaOs !== '') {
        $tipoChamadoHdr = $problemaOs;
    }

    $stLabel = trim((string) ($chamado['status'] ?? ''));
    $prLabel = trim((string) ($chamado['prioridade'] ?? ''));
    $nAnexos = count($anexos);
    $paginaAnexos = ($anexos !== [] || $fotosPonto !== []);

    require_once __DIR__ . '/chamado_os_fields.php';

    $cpfFmt = $fmtCpf((string) ($chamado['contribuinte_cpf'] ?? ''));
    $nomeSol = trim((string) ($chamado['contribuinte_nome'] ?? ''));
    $telSol = trim((string) ($chamado['contribuinte_telefone'] ?? ''));
    $emailSol = trim((string) ($chamado['contribuinte_email'] ?? ''));
    $osCep = trim((string) ($chamado['os_cep'] ?? ''));
    $osLog = trim((string) ($chamado['os_logradouro'] ?? ''));
    $osNum = trim((string) ($chamado['os_numero'] ?? ''));
    $osComp = trim((string) ($chamado['os_complemento'] ?? ''));
    $osBairro = trim((string) ($chamado['os_bairro'] ?? ''));
    $osCidade = trim((string) ($chamado['os_cidade'] ?? ''));
    $osUf = trim((string) ($chamado['os_uf'] ?? ''));
    $pontoRef = trim((string) ($chamado['ponto_referencia'] ?? ''));
    $latOs = $chamado['latitude'] ?? null;
    $lngOs = $chamado['longitude'] ?? null;
    $validadoEm = !empty($chamado['validado_em']) ? $fmtDt((string) $chamado['validado_em']) : '';
    $finOpEm = !empty($chamado['finalizado_operador_em']) ? $fmtDt((string) $chamado['finalizado_operador_em']) : '';
    $aprovEm = !empty($chamado['aprovado_gestor_em']) ? $fmtDt((string) $chamado['aprovado_gestor_em']) : '';
    $aprovNome = trim((string) ($chamado['aprovado_gestor_nome'] ?? ''));

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex,nofollow" />
  <title>Chamado #<?= $h((string) $cid) ?> — <?= $h($brand) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root {
      --ink: #0f172a;
      --muted: #64748b;
      --line: #e2e8f0;
      --panel: #f1f5f9;
      --panel2: #f8fafc;
      --accent: #1d4ed8;
      --accent-soft: #eff6ff;
      --accent2: #0d9488;
      --accent2-soft: #ecfdf5;
      --ok: #059669;
      --shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
      --radius: 10px;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
      font-size: 8.5px;
      line-height: 1.25;
      color: var(--ink);
      background: #eef2f7;
    }
    @page {
      size: A4;
      margin: 6mm 7mm 7mm;
    }
    @media print {
      body { background: #fff; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      .no-print { display: none !important; }
      a[href^="http"]::after { content: ''; }
      .sheet { box-shadow: none !important; padding: 4mm 5mm 5mm !important; }
      /* Faixa KPI sempre em 1 linha na impressão */
      .kpi-strip {
        display: flex !important;
        flex-wrap: nowrap !important;
      }
      .doc-section-title {
        white-space: nowrap;
      }
      .field-grid--dados-chamado {
        grid-template-columns: repeat(4, 1fr) !important;
      }
      .anexo-gallery img,
      .ponto-gallery img {
        max-height: 200px !important;
      }
    }
    .sheet {
      max-width: 820px;
      margin: 0 auto;
      padding: 8px 10px 10px;
      background: #fff;
      box-shadow: var(--shadow);
      border-radius: 0;
    }

    /* Anexos/fotos seguem o fluxo (sem forçar nova folha) */
    .doc-attachments-page {
      page-break-before: auto;
      break-before: auto;
      margin-top: 6px;
      padding-top: 4px;
      border-top: 1px dashed var(--line);
    }

    /* Cabeçalho institucional */
    .doc-hero {
      display: grid;
      grid-template-columns: 1fr minmax(0, 150px);
      gap: 6px 10px;
      align-items: start;
      padding-bottom: 4px;
      margin-bottom: 0;
    }
    @media (max-width: 720px) {
      .doc-hero { grid-template-columns: 1fr; }
    }
    .doc-brand-row {
      display: flex;
      align-items: flex-start;
      gap: 8px;
    }
    .doc-brand-img {
      max-height: 26px;
      max-width: 120px;
      width: auto;
      object-fit: contain;
      display: block;
    }
    .doc-logo-svg {
      width: 26px;
      height: 26px;
      flex-shrink: 0;
      display: block;
    }
    .doc-brand-text .product {
      font-size: 11px;
      font-weight: 800;
      letter-spacing: -0.03em;
      line-height: 1.1;
      color: var(--ink);
    }
    .doc-brand-text .product-tag {
      font-size: 7.5px;
      color: var(--muted);
      font-weight: 500;
      margin-top: 1px;
    }
    .doc-pref {
      margin-top: 3px;
      font-size: 9.5px;
      font-weight: 700;
      letter-spacing: -0.02em;
      color: #0c1324;
    }
    .doc-system {
      font-size: 8.5px;
      color: var(--muted);
      margin-top: 1px;
      font-weight: 500;
    }
    .doc-kind {
      display: inline-block;
      margin-top: 3px;
      font-size: 7.5px;
      font-weight: 800;
      letter-spacing: 0.12em;
      color: var(--accent);
      text-transform: uppercase;
    }
    .doc-hero-aside {
      border: 1px solid var(--line);
      border-radius: 6px;
      background: linear-gradient(180deg, var(--panel2) 0%, #fff 45%);
      padding: 5px 7px;
      box-shadow: none;
    }
    .doc-ch-num {
      font-size: 16px;
      font-weight: 800;
      letter-spacing: -0.04em;
      line-height: 1;
      text-align: right;
      color: var(--ink);
    }
    .doc-ch-num span { font-weight: 800; color: var(--accent); }
    .doc-meta-grid {
      margin-top: 6px;
      display: grid;
      gap: 4px;
    }
    .doc-meta-row {
      display: flex;
      justify-content: space-between;
      gap: 8px;
      font-size: 9px;
      border-top: 1px solid var(--line);
      padding-top: 4px;
    }
    .doc-meta-row:first-of-type { border-top: 0; padding-top: 0; }
    .doc-meta-row .mk {
      color: var(--muted);
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      font-size: 8px;
      flex: 0 0 auto;
    }
    .doc-meta-row .mv {
      font-weight: 700;
      text-align: right;
      word-break: break-word;
      font-size: 8px;
    }
    .doc-rule {
      height: 2px;
      background: linear-gradient(90deg, var(--accent) 0%, var(--accent2) 100%);
      border-radius: 2px;
      margin: 4px 0 6px;
    }

    .doc-section {
      margin-top: 5px;
      page-break-inside: auto;
    }
    .doc-section-title {
      font-size: 7.5px;
      font-weight: 800;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: #334155;
      margin: 0 0 3px;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .doc-section-title::after {
      content: '';
      flex: 1;
      height: 1px;
      background: var(--line);
    }

    /* KPI strip — flex + nowrap evita colunas a ~0px (grelha minmax(0,1fr) partia o texto no PDF) */
    .kpi-strip {
      display: flex;
      flex-wrap: nowrap;
      gap: 4px;
      align-items: stretch;
    }
    @media (max-width: 760px) {
      .kpi-strip { flex-wrap: wrap; }
      .kpi-card { flex: 1 1 calc(50% - 4px); min-width: calc(50% - 4px); }
    }
    .kpi-card {
      flex: 1 1 0;
      min-width: 56px;
      border: 1px solid var(--line);
      border-radius: 4px;
      padding: 3px 4px 2px;
      background: var(--panel2);
      box-shadow: none;
    }
    .kpi-card .kl {
      font-size: 6.5px;
      font-weight: 700;
      letter-spacing: 0.03em;
      text-transform: uppercase;
      color: var(--muted);
      word-break: normal;
      overflow-wrap: normal;
      hyphens: manual;
      line-height: 1.15;
    }
    .kpi-card .kv {
      margin-top: 1px;
      font-size: 9px;
      font-weight: 800;
      letter-spacing: -0.02em;
      color: #0c1324;
      word-break: normal;
      overflow-wrap: normal;
      white-space: nowrap;
      line-height: 1.15;
    }
    .kpi-card--accent { background: var(--accent-soft); border-color: #bfdbfe; }
    .kpi-card--money { background: var(--accent2-soft); border-color: #a7f3d0; }
    .kpi-card--money .kv { color: var(--ok); }

    /* Campos 2 colunas (secções genéricas) */
    .field-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 3px 5px;
    }
    /* Dados do chamado: 4×2 (oito campos em duas linhas no PDF) */
    .field-grid--dados-chamado {
      grid-template-columns: repeat(4, 1fr);
    }
    @media (max-width: 560px) {
      .field-grid { grid-template-columns: 1fr; }
      .field-grid--dados-chamado { grid-template-columns: repeat(2, 1fr); }
    }
    .field {
      padding: 2px 5px;
      border: 1px solid var(--line);
      border-radius: 4px;
      background: #fff;
    }
    .field .k {
      font-size: 6.5px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--muted);
      margin-bottom: 1px;
    }
    .field .v { font-size: 8px; font-weight: 700; color: #1e293b; word-break: break-word; line-height: 1.2; }
    .field--wide { grid-column: 1 / -1; }

    /* Descrição */
    .desc-box {
      border: 1px solid #cbd5e1;
      border-radius: 4px;
      background: var(--panel);
      padding: 4px 6px;
    }
    .desc-box .inner-title {
      font-size: 6.5px;
      font-weight: 800;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: #475569;
      margin-bottom: 2px;
    }
    .prose {
      white-space: pre-wrap;
      word-break: break-word;
      color: #334155;
      line-height: 1.3;
      margin: 0;
      font-size: 8px;
    }
    .muted { color: var(--muted); font-size: 8px; }

    /* Localização card */
    .loc-card {
      display: flex;
      gap: 5px;
      align-items: flex-start;
      border: 1px solid var(--line);
      border-radius: 4px;
      padding: 3px 6px;
      background: linear-gradient(135deg, #fff 0%, var(--panel2) 100%);
      box-shadow: none;
    }
    .loc-pin {
      font-size: 12px;
      line-height: 1;
      flex-shrink: 0;
      filter: grayscale(0.2);
    }
    .loc-body { flex: 1; min-width: 0; }
    .coords-line {
      margin-top: 2px;
      font-size: 7.5px;
      color: var(--muted);
      font-variant-numeric: tabular-nums;
    }

    /* Tabelas */
    .table-block {
      border: 1px solid var(--line);
      border-radius: 4px;
      overflow: hidden;
      background: #fff;
      margin-top: 3px;
    }
    .table-block:first-child { margin-top: 0; }
    .table-head {
      padding: 3px 6px;
      font-size: 7.5px;
      font-weight: 800;
      letter-spacing: 0.05em;
      text-transform: uppercase;
      background: linear-gradient(180deg, #fafbff 0%, var(--panel) 100%);
      border-bottom: 1px solid var(--line);
      color: #334155;
    }
    .table-head--neutral { background: #f8fafc; }
    table.data {
      width: 100%;
      border-collapse: collapse;
      font-size: 7.5px;
    }
    table.data th, table.data td {
      border-top: 1px solid var(--line);
      padding: 2px 4px;
      text-align: left;
      vertical-align: top;
    }
    table.data thead th {
      border-top: 0;
      background: var(--panel2);
      font-weight: 700;
      font-size: 7px;
      text-transform: uppercase;
      letter-spacing: 0.03em;
      color: var(--muted);
    }
    table.data tfoot td {
      font-weight: 800;
      background: var(--accent2-soft);
      color: #065f46;
      border-top: 1px solid #6ee7b7;
      font-size: 8px;
    }
    .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }

    /* Timeline */
    .timeline-wrap {
      border: 1px solid var(--line);
      border-radius: 4px;
      padding: 3px 6px 2px 10px;
      background: #fff;
    }
    .timeline {
      position: relative;
      padding-left: 11px;
    }
    .timeline::before {
      content: '';
      position: absolute;
      left: 3px;
      top: 2px;
      bottom: 2px;
      width: 1.5px;
      background: linear-gradient(180deg, var(--accent), #94a3b8);
      border-radius: 2px;
    }
    .tl-item {
      position: relative;
      padding-bottom: 3px;
    }
    .tl-item:last-child { padding-bottom: 1px; }
    .tl-dot {
      position: absolute;
      left: -10px;
      top: 2px;
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: #fff;
      border: 1.5px solid var(--accent);
      box-shadow: 0 0 0 1px #fff;
    }
    .tl-head {
      display: flex;
      flex-wrap: wrap;
      gap: 2px 5px;
      align-items: baseline;
      margin-bottom: 0;
      font-size: 7.5px;
    }
    .tl-role {
      font-weight: 800;
      color: var(--accent);
      font-size: 7px;
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }
    .tl-name { font-weight: 700; color: #1e293b; }
    .tl-when { color: var(--muted); font-weight: 500; font-variant-numeric: tabular-nums; }
    .pill-int {
      font-size: 7px;
      padding: 1px 5px;
      border-radius: 999px;
      background: #fff7ed;
      color: #9a3412;
      font-weight: 700;
      letter-spacing: 0.03em;
      text-transform: uppercase;
    }
    .tl-text {
      margin: 0;
      white-space: pre-wrap;
      word-break: break-word;
      color: #475569;
      line-height: 1.25;
      font-size: 7.5px;
    }

    /* Anexos + galeria */
    .gallery-title {
      font-size: 7.5px;
      font-weight: 800;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      margin: 4px 6px 3px;
      color: #475569;
    }
    .anexo-gallery {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 8px;
      padding: 0 6px 8px;
    }
    @media (max-width: 560px) { .anexo-gallery { grid-template-columns: 1fr; } }
    .anexo-gallery figure {
      margin: 0;
      border: 1px solid var(--line);
      border-radius: 6px;
      overflow: hidden;
      background: var(--panel2);
      box-shadow: none;
    }
    .anexo-gallery .img-a {
      display: block;
      background: #fff;
      text-align: center;
      padding: 6px;
    }
    .anexo-gallery img {
      max-width: 100%;
      max-height: 220px;
      height: auto;
      object-fit: contain;
      vertical-align: middle;
    }
    .anexo-gallery figcaption {
      padding: 4px 6px 5px;
      font-size: 7.5px;
      color: var(--muted);
      line-height: 1.2;
    }

    /* Fotos ponto */
    .ponto-gallery {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 8px;
      padding: 0 6px 8px;
    }
    @media (max-width: 560px) { .ponto-gallery { grid-template-columns: 1fr; } }
    .ponto-gallery figure {
      margin: 0;
      border: 1px dashed #94a3b8;
      border-radius: 6px;
      overflow: hidden;
      background: #fff;
      box-shadow: none;
    }
    .ponto-gallery .img-a { display: block; padding: 6px; text-align: center; background: #f8fafc; }
    .ponto-gallery img {
      max-width: 100%;
      max-height: 220px;
      object-fit: contain;
    }
    .ponto-gallery figcaption {
      padding: 4px 6px 5px;
      font-size: 7.5px;
      color: var(--muted);
    }

    .footer-block {
      margin-top: 6px;
      padding: 4px 6px;
      border: 1px dashed var(--line);
      border-radius: 6px;
      background: var(--panel2);
      font-size: 7px;
      color: var(--muted);
      line-height: 1.3;
      page-break-inside: avoid;
    }
    .footer-block strong { color: #475569; }

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
      background: rgba(255,255,255,.94);
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
    .toolbar-copy {
      display: flex;
      flex-direction: column;
      gap: 8px;
      max-width: min(560px, 100%);
      text-align: center;
    }
    .toolbar-copy > span:first-child { font-size: 12px; color: var(--muted); }
    .toolbar-hint {
      font-size: 11px;
      line-height: 1.45;
      color: #92400e;
      background: #fffbeb;
      border: 1px solid #fcd34d;
      border-radius: 8px;
      padding: 10px 12px;
      text-align: left;
    }
    .toolbar-hint strong { color: #78350f; }
  </style>
</head>
<body>
  <div class="toolbar no-print">
    <button type="button" onclick="window.print()">Imprimir / Guardar como PDF</button>
    <div class="toolbar-copy">
      <span>Escolha <strong>Guardar como PDF</strong> como destino.</span>
      <p class="toolbar-hint">
        <strong>Para o PDF não incluir URL, data, número de página (ex.: 1/2) nem título extra:</strong>
        na janela de impressão, desative <strong>Cabeçalhos e rodapés</strong>.
        No Chrome e Edge: secção <em>Mais definições</em> → desmarque essa opção.
        No Firefox: em <em>Imprimir…</em> use <em>Configuração da página</em> / opções de margens e desative cabeçalhos se existir.
        No Safari (macOS): em Imprimir, reveja as opções de cabeçalho e rodapé conforme a versão.
        O navegador acrescenta esses dados por defeito; não é possível removê‑los só pelo HTML do relatório.
      </p>
    </div>
  </div>
  <div class="sheet">
    <header class="doc-hero">
      <div class="doc-hero-main">
        <div class="doc-brand-row">
          <?php if ($logoImgUrl !== ''): ?>
          <img class="doc-brand-img" src="<?= htmlspecialchars($logoImgUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= $h($brand) ?>" />
          <?php elseif ($logoInline !== ''): ?>
          <?= $logoInline ?>
          <?php else: ?>
          <div class="doc-logo-svg" style="border-radius:14px;background:linear-gradient(135deg,#1d4ed8,#0d9488);"></div>
          <?php endif; ?>
          <div class="doc-brand-text">
            <div class="product"><?= $h($brand) ?></div>
            <?php if ($tagline !== ''): ?><div class="product-tag"><?= $h($tagline) ?></div><?php endif; ?>
          </div>
        </div>
        <div class="doc-pref"><?= $h(trim((string) ($chamado['cliente'] ?? '')) ?: '—') ?></div>
        <div class="doc-system"><?= $h($brand) ?> · relatório operacional</div>
        <div class="doc-kind">Relatório de atendimento</div>
      </div>
      <aside class="doc-hero-aside" aria-label="Metadados do documento">
        <div class="doc-ch-num"><span>#</span><?= $h((string) $cid) ?></div>
        <div class="doc-meta-grid">
          <div class="doc-meta-row">
            <span class="mk">Emissão</span>
            <span class="mv"><?= $h($emitidoEm) ?></span>
          </div>
          <div class="doc-meta-row">
            <span class="mk">Status</span>
            <span class="mv"><?= $h($stLabel !== '' ? $stLabel : '—') ?></span>
          </div>
          <div class="doc-meta-row">
            <span class="mk">Prioridade</span>
            <span class="mv"><?= $h($prLabel !== '' ? $prLabel : '—') ?></span>
          </div>
          <div class="doc-meta-row">
            <span class="mk">Tipo</span>
            <span class="mv"><?= $h($tipoChamadoHdr !== '' ? $tipoChamadoHdr : '—') ?></span>
          </div>
        </div>
      </aside>
    </header>
    <div class="doc-rule" role="presentation"></div>

    <?php if (trim((string) ($chamado['titulo'] ?? '')) !== ''): ?>
    <p style="margin:0 0 4px;font-size:9px;font-weight:700;color:#0f172a;letter-spacing:-0.02em;">
      <?= $h((string) $chamado['titulo']) ?>
    </p>
    <?php endif; ?>

    <section class="doc-section" aria-labelledby="sec-kpi">
      <h2 id="sec-kpi" class="doc-section-title">Resumo executivo</h2>
      <div class="kpi-strip">
        <div class="kpi-card kpi-card--accent">
          <div class="kl">Status</div>
          <div class="kv"><?= $h($stLabel !== '' ? $stLabel : '—') ?></div>
        </div>
        <div class="kpi-card">
          <div class="kl">Prioridade</div>
          <div class="kv"><?= $h($prLabel !== '' ? $prLabel : '—') ?></div>
        </div>
        <div class="kpi-card">
          <div class="kl">Técnicos</div>
          <div class="kv"><?= $h((string) max(0, $nTec)) ?></div>
        </div>
        <div class="kpi-card kpi-card--money">
          <div class="kl">Itens utilizados</div>
          <div class="kv"><?= $h($fmtMoney($totalMat)) ?></div>
        </div>
        <div class="kpi-card">
          <div class="kl">Anexos</div>
          <div class="kv"><?= $h((string) $nAnexos) ?></div>
        </div>
      </div>
    </section>

    <section class="doc-section" aria-labelledby="sec-solicitante">
      <h2 id="sec-solicitante" class="doc-section-title">Ordem de serviço — Dados do solicitante</h2>
      <div class="field-grid field-grid--dados-chamado">
        <div class="field"><div class="k">CPF</div><div class="v"><?= $h($vOuTraco($cpfFmt)) ?></div></div>
        <div class="field"><div class="k">Nome</div><div class="v"><?= $h($vOuTraco($nomeSol)) ?></div></div>
        <div class="field"><div class="k">Data de abertura</div><div class="v"><?= $h($vOuTraco($abertoEm)) ?></div></div>
        <div class="field"><div class="k">Telefone</div><div class="v"><?= $h($vOuTraco($telSol)) ?></div></div>
        <div class="field field--wide"><div class="k">E-mail</div><div class="v"><?= $h($vOuTraco($emailSol)) ?></div></div>
        <div class="field"><div class="k">Prefeitura / órgão</div><div class="v"><?= $h($vOuTraco(trim((string) ($chamado['cliente'] ?? '')))) ?></div></div>
        <div class="field"><div class="k">Responsável</div><div class="v"><?= $h($vOuTraco(trim((string) ($chamado['responsavel'] ?? '')))) ?></div></div>
      </div>
    </section>

    <section class="doc-section" aria-labelledby="sec-endereco">
      <h2 id="sec-endereco" class="doc-section-title">Endereço e problema no local</h2>
      <div class="field-grid field-grid--dados-chamado">
        <div class="field"><div class="k">CEP</div><div class="v"><?= $h($vOuTraco($osCep)) ?></div></div>
        <div class="field"><div class="k">Endereço</div><div class="v"><?= $h($vOuTraco($osLog)) ?></div></div>
        <div class="field"><div class="k">Número</div><div class="v"><?= $h($vOuTraco($osNum)) ?></div></div>
        <div class="field"><div class="k">Complemento</div><div class="v"><?= $h($vOuTraco($osComp)) ?></div></div>
        <div class="field"><div class="k">Bairro</div><div class="v"><?= $h($vOuTraco($osBairro)) ?></div></div>
        <div class="field"><div class="k">Cidade</div><div class="v"><?= $h($vOuTraco($osCidade)) ?></div></div>
        <div class="field"><div class="k">UF</div><div class="v"><?= $h($vOuTraco($osUf)) ?></div></div>
      </div>
    </section>

    <section class="doc-section" aria-labelledby="sec-coords">
      <h2 id="sec-coords" class="doc-section-title">Coordenadas e referência</h2>
      <div class="field-grid field-grid--dados-chamado">
        <div class="field"><div class="k">Latitude</div><div class="v"><?= $h($vOuTraco($latOs !== null && $latOs !== '' ? (string) $latOs : '')) ?></div></div>
        <div class="field"><div class="k">Longitude</div><div class="v"><?= $h($vOuTraco($lngOs !== null && $lngOs !== '' ? (string) $lngOs : '')) ?></div></div>
        <div class="field field--wide"><div class="k">Ponto de referência</div><div class="v"><?= $h($vOuTraco($pontoRef)) ?></div></div>
        <div class="field field--wide"><div class="k">Ponto de iluminação</div><div class="v"><?= $h($posteTxt) ?></div></div>
      </div>
    </section>

    <section class="doc-section" aria-labelledby="sec-desc">
      <h2 id="sec-desc" class="doc-section-title">Descrição do problema</h2>
      <div class="desc-box">
        <div class="inner-title">Descrição</div>
        <div class="prose"><?php
        $__d = trim((string) ($chamado['descricao'] ?? ''));
        echo $__d !== '' ? nl2br($h($__d)) : '<span class="muted">Sem texto registado.</span>';
        ?></div>
      </div>
    </section>

    <section class="doc-section" aria-labelledby="sec-itens">
      <h2 id="sec-itens" class="doc-section-title">Materiais e movimentação</h2>

      <div class="table-block">
        <div class="table-head">Materiais e serviços utilizados</div>
        <?php if ($matsUtil === []): ?>
          <p class="muted" style="margin:3px 6px;">Nenhum item utilizado registado.</p>
        <?php else: ?>
          <table class="data">
            <thead>
              <tr>
                <th>Item</th>
                <th>Código</th>
                <th>Tipo</th>
                <th class="num">Qtd</th>
                <th class="num">V. unit.</th>
                <th class="num">Subtotal</th>
                <th>Observação</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($matsUtil as $m): ?>
              <tr>
                <td><?= $h((string) ($m['item_nome'] ?? '')) ?></td>
                <td><?= $h((string) ($m['item_codigo'] ?? '')) ?></td>
                <td><?= $h((string) ($m['item_tipo'] ?? '')) ?></td>
                <td class="num"><?= $h(number_format((float) ($m['quantidade'] ?? 0), 4, ',', '.')) ?></td>
                <td class="num"><?= $h($fmtMoney((float) ($m['valor_unitario'] ?? 0))) ?></td>
                <td class="num"><?= $h($fmtMoney((float) ($m['subtotal'] ?? 0))) ?></td>
                <td><?= $h(trim((string) ($m['observacao'] ?? ''))) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="5" style="text-align:right;font-weight:800;color:#334155;">Total utilizado</td>
                <td class="num"><?= $h($fmtMoney($totalMat)) ?></td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        <?php endif; ?>
      </div>

      <div class="table-block" style="margin-top:8px;">
        <div class="table-head table-head--neutral">Itens recolhidos / devolvidos</div>
        <?php if ($matsDev === []): ?>
          <p class="muted" style="margin:3px 6px;">Nenhum recolhimento ou devolução registado.</p>
        <?php else: ?>
          <table class="data">
            <thead>
              <tr>
                <th>Item</th>
                <th>Código</th>
                <th>Tipo</th>
                <th class="num">Qtd</th>
                <th class="num">V. unit.</th>
                <th class="num">Subtotal</th>
                <th>Observação</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($matsDev as $m): ?>
              <tr>
                <td><?= $h((string) ($m['item_nome'] ?? '')) ?></td>
                <td><?= $h((string) ($m['item_codigo'] ?? '')) ?></td>
                <td><?= $h((string) ($m['item_tipo'] ?? '')) ?></td>
                <td class="num"><?= $h(number_format((float) ($m['quantidade'] ?? 0), 4, ',', '.')) ?></td>
                <td class="num"><?= $h($fmtMoney((float) ($m['valor_unitario'] ?? 0))) ?></td>
                <td class="num"><?= $h($fmtMoney((float) ($m['subtotal'] ?? 0))) ?></td>
                <td><?= $h(trim((string) ($m['observacao'] ?? ''))) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <p class="muted" style="margin:2px 6px 4px;font-size:7px;">Valores de devolução não entram no total financeiro do utilizado.</p>
        <?php endif; ?>
      </div>
    </section>

    <?php if ($paginaAnexos): ?>
    <div class="doc-attachments-page">
    <?php endif; ?>

    <?php if ($anexos !== []): ?>
    <section class="doc-section" aria-labelledby="sec-anexos">
      <h2 id="sec-anexos" class="doc-section-title">Anexos</h2>
      <div class="table-block">
        <div class="table-head">Lista de ficheiros</div>
          <table class="data">
            <thead>
              <tr>
                <th>Nome</th>
                <th>Tipo</th>
                <th>Tamanho</th>
                <th>Enviado por</th>
                <th>Data</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($anexos as $a):
                  $nomeA = (string) ($a['nome_original'] ?? '');
                  $mimeA = (string) ($a['mime'] ?? '');
                  $ext = $extFromMimeOrName($mimeA, $nomeA);
                  $tipoCell = $mimeA !== '' ? $mimeA : ($ext !== '' ? '.' . $ext : '—');
                  ?>
              <tr>
                <td><?= $h($nomeA) ?></td>
                <td><?= $h($tipoCell) ?></td>
                <td><?= $h($fmtBytes((int) ($a['tamanho'] ?? 0))) ?></td>
                <td><?= $h(trim((string) ($a['enviado_por'] ?? '')) ?: '—') ?> <?= $h((string) ($a['enviado_tipo'] ?? '') !== '' ? '(' . (string) ($a['enviado_tipo'] ?? '') . ')' : '') ?></td>
                <td><?= $h((string) ($a['enviado_em'] ?? '')) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php
          $anexosImg = [];
            foreach ($anexos as $ax) {
                if ($mimeIsImage($ax['mime'] ?? null)) {
                    $anexosImg[] = $ax;
                }
            }
          ?>
          <?php if ($anexosImg !== []): ?>
          <div class="gallery-title">Galeria de imagens</div>
          <div class="anexo-gallery">
            <?php foreach ($anexosImg as $a):
                $aidImg = (int) ($a['id'] ?? 0);
                $nomeImg = (string) ($a['nome_original'] ?? '');
                $tamImg = (int) ($a['tamanho'] ?? 0);
                $srcImg = 'chamado_download.php?id=' . $aidImg;
                ?>
            <figure>
              <a class="img-a" href="<?= $h($srcImg) ?>">
                <img src="<?= $h($srcImg) ?>" alt="<?= $h($nomeImg !== '' ? $nomeImg : 'Anexo') ?>" loading="lazy" />
              </a>
              <figcaption><?= $h($nomeImg) ?> · <?= $h($fmtBytes($tamImg)) ?></figcaption>
            </figure>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($fotosPonto !== []): ?>
    <section class="doc-section" aria-labelledby="sec-ponto">
      <h2 id="sec-ponto" class="doc-section-title">Fotos do ponto de iluminação (cadastro)</h2>
      <div class="table-block">
        <div class="table-head">Registo no sistema</div>
        <table class="data">
          <thead>
            <tr>
              <th>Ficheiro</th>
              <th>Tamanho</th>
              <th>Principal</th>
              <th>Enviado em</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($fotosPonto as $fp): ?>
            <tr>
              <td><?= $h((string) ($fp['nome_original'] ?? '')) ?></td>
              <td><?= $h($fmtBytes((int) ($fp['tamanho'] ?? 0))) ?></td>
              <td><?= !empty($fp['principal']) ? 'Sim' : '—' ?></td>
              <td><?= $h((string) ($fp['enviado_em'] ?? '')) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div class="gallery-title">Imagens</div>
        <div class="ponto-gallery">
          <?php foreach ($fotosPonto as $fp):
              $fid = (int) ($fp['id'] ?? 0);
              $fnome = (string) ($fp['nome_original'] ?? '');
              $srcPt = 'ponto_iluminacao_imagem.php?id=' . $fid;
              ?>
          <figure>
            <a class="img-a" href="<?= $h($srcPt) ?>">
              <img src="<?= $h($srcPt) ?>" alt="<?= $h($fnome !== '' ? $fnome : 'Foto do ponto') ?>" loading="lazy" />
            </a>
            <figcaption><?= $h($fnome) ?><?= !empty($fp['principal']) ? ' · Principal' : '' ?></figcaption>
          </figure>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($paginaAnexos): ?>
    </div>
    <?php endif; ?>

    <footer class="footer-block">
      <strong>Documento gerado automaticamente</strong> pelo <?= $h($brand) ?> · Chamado #<?= $h((string) $cid) ?> · <?= $h($emitidoEm) ?>
      (ISO <?= $h($emitidoIso) ?>).<br />
      Não substitui registos oficiais sem validação interna da prefeitura ou concessionária.<br />
      <!-- Espaço reservado: código QR de verificação (URL assinada) — integração futura. -->
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
