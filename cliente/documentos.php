<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/upload.php';
$user = require_auth('cliente');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_cliente('documentos');

$pageTitle  = 'Meus documentos';
$basePath   = '../';
$activePage = 'documentos';

$clienteId = (int) ($user['cliente_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['acao'] ?? '') === 'upload') {
    $anoRedir = max(1990, min(2100, (int) ($_POST['ano_ctx'] ?? (int) date('Y'))));
    if (!db_ok()) {
        flash_set('err', 'Banco indisponível.');
        header('Location: documentos.php?' . http_build_query(['ano' => $anoRedir]));
        exit;
    }
    if ($clienteId <= 0) {
        flash_set('err', 'Sessão sem empresa vinculada.');
        header('Location: documentos.php?' . http_build_query(['ano' => $anoRedir]));
        exit;
    }
    $tiposPerm = ['Contrato', 'Documento', 'Identidade', 'Outro'];
    $tipoRaw   = trim((string) ($_POST['tipo_anexo'] ?? 'Outro'));
    $tipo      = in_array($tipoRaw, $tiposPerm, true) ? $tipoRaw : 'Outro';
    $desc      = trim((string) ($_POST['descricao_anexo'] ?? ''));
    $envPor    = trim((string) ($user['nome'] ?? '')) !== '' ? trim((string) $user['nome']) : 'Cliente';

    try {
        if (empty($_FILES['anexos']['name'][0])) {
            flash_set('err', 'Selecione ao menos um arquivo.');
        } else {
            $res = upload_gravar_multiplos($_FILES['anexos'], $clienteId);
            foreach ($res['salvos'] as $arq) {
                repo_create_cliente_anexo([
                    'cliente_id'    => $clienteId,
                    'nome_original' => $arq['nome_original'],
                    'nome_arquivo'  => $arq['nome_arquivo'],
                    'mime'          => $arq['mime'],
                    'tamanho'       => $arq['tamanho'],
                    'tipo'          => $tipo,
                    'descricao'     => $desc !== '' ? $desc : null,
                    'enviado_por'   => $envPor,
                ]);
            }
            if ($res['salvos']) {
                flash_set('ok', count($res['salvos']) . ' arquivo(s) enviado(s).');
            }
            if ($res['erros']) {
                flash_set('err', 'Falhas: ' . implode(' | ', $res['erros']));
            }
        }
    } catch (Throwable $e) {
        flash_set('err', 'Falha no upload: ' . $e->getMessage());
    }
    header('Location: documentos.php?' . http_build_query(['ano' => $anoRedir]));
    exit;
}

$anexos = $clienteId && db_ok() ? repo_cliente_anexos($clienteId) : [];

$anoFiltro = (int) ($_GET['ano'] ?? 0);
if ($anoFiltro < 1990 || $anoFiltro > 2100) {
    $anoFiltro = (int) date('Y');
}

$anosNosDados = [];
foreach ($anexos as $ax) {
    $em = (string) ($ax['enviado_em'] ?? '');
    if (strlen($em) >= 4 && ctype_digit(substr($em, 0, 4))) {
        $anosNosDados[] = (int) substr($em, 0, 4);
    }
}
$anosNosDados  = array_values(array_unique($anosNosDados));
$anosSeletor   = $anosNosDados;
$anosSeletor[] = (int) date('Y');
$anosSeletor   = array_values(array_unique(array_filter($anosSeletor, static fn ($y) => $y >= 1990 && $y <= 2100)));
rsort($anosSeletor, SORT_NUMERIC);
if ($anosSeletor === []) {
    $anosSeletor = [(int) date('Y')];
}

$anexosNoAno = [];
foreach ($anexos as $ax) {
    $em = (string) ($ax['enviado_em'] ?? '');
    if (strlen($em) >= 4 && (int) substr($em, 0, 4) === $anoFiltro) {
        $anexosNoAno[] = $ax;
    }
}

$porMes = [];
foreach ($anexosNoAno as $ax) {
    $em = (string) ($ax['enviado_em'] ?? '');
    $mes = 0;
    if (strlen($em) >= 7 && $em[4] === '-') {
        $mes = (int) substr($em, 5, 2);
    }
    if ($mes < 1 || $mes > 12) {
        $mes = 1;
    }
    if (!isset($porMes[$mes])) {
        $porMes[$mes] = [];
    }
    $porMes[$mes][] = $ax;
}
$mesesOrd = array_keys($porMes);
rsort($mesesOrd, SORT_NUMERIC);
$mesesPt = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho',
    7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro',
];
$mesAtual = (int) date('n');
$anoAtual = (int) date('Y');

$topTitle    = 'Meus documentos';
$topSubtitle = 'Contratos e arquivos: envie documentos ou baixe o que a equipe anexou.';
$topSearch   = 'Buscar documento...';
$topAction   = ['label' => 'Suporte', 'href' => 'suporte.php', 'icon' => '?'];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-cliente.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content content-grid-2">
  <div class="card">
    <div class="panel-head">
      <h4>Arquivos disponíveis</h4>
      <span class="panel-sub"><?= count($anexos) ?> documento(s) no total · <?= count($anexosNoAno) ?> em <?= (int) $anoFiltro ?></span>
    </div>

    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:12px 20px;border-bottom:1px solid var(--border);">
      <span class="muted" style="font-size:12px;font-weight:600;">Ano</span>
      <?php foreach ($anosSeletor as $y): ?>
        <a class="btn btn-sm <?= $y === $anoFiltro ? 'btn-primary' : 'btn-secondary' ?>"
           href="<?= htmlspecialchars('documentos.php?' . http_build_query(['ano' => $y])) ?>"><?= (int) $y ?></a>
      <?php endforeach; ?>
    </div>

    <style>
      .anexo-pasta { border:1px solid var(--border); border-radius:12px; margin:12px 20px; overflow:hidden; background:var(--panel,#fff); }
      .anexo-pasta--atual { border-color:rgba(83,74,183,.45); box-shadow:0 0 0 1px rgba(83,74,183,.12); }
      .anexo-pasta-head { display:flex; align-items:center; gap:10px; padding:12px 16px; font-weight:700; background:var(--panel-highlight,#f4f6fb); border-bottom:1px solid var(--border-soft,#e8e8ef); }
      .anexo-pasta-list { margin:0; padding:0; list-style:none; }
      .anexo-pasta-list li { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px; padding:12px 16px; border-bottom:1px solid var(--border-soft,#eee); }
      .anexo-pasta-list li:last-child { border-bottom:0; }
    </style>

    <?php if (empty($anexos)): ?>
      <div class="empty-state">
        <div class="empty-icon">📁</div>
        <p>Você ainda não tem documentos na lista.</p>
        <small>Use o formulário ao lado para enviar arquivos ou aguarde anexos da equipe.</small>
      </div>
    <?php elseif (empty($anexosNoAno)): ?>
      <div class="empty-state">
        <div class="empty-icon">📂</div>
        <p>Nenhum documento em <?= (int) $anoFiltro ?>.</p>
        <small>Escolha outro ano na barra acima.</small>
      </div>
    <?php else: ?>
      <?php foreach ($mesesOrd as $mesNum):
          $pastaAtual = ($anoFiltro === $anoAtual && $mesNum === $mesAtual);
          $listaMes = $porMes[$mesNum] ?? [];
          ?>
      <details class="anexo-pasta<?= $pastaAtual ? ' anexo-pasta--atual' : '' ?>"<?= $pastaAtual ? ' open' : '' ?>>
        <summary class="anexo-pasta-head" style="cursor:pointer;list-style:none;">
          <span aria-hidden="true">📁</span>
          <span><?= htmlspecialchars($mesesPt[$mesNum] ?? ('Mês ' . $mesNum)) ?> <?= (int) $anoFiltro ?></span>
          <?php if ($pastaAtual): ?>
            <span class="badge open" style="margin-left:auto;">Mês atual</span>
          <?php endif; ?>
        </summary>
        <ul class="anexo-pasta-list">
          <?php foreach ($listaMes as $a): ?>
          <li>
            <div class="cell-client" style="min-width:0;flex:1;">
              <div class="avatar avatar-sm" aria-hidden="true"><?= upload_icone_por_ext($a['nome_original']) ?></div>
              <div style="min-width:0;">
                <strong style="word-break:break-word;"><?= htmlspecialchars($a['nome_original']) ?></strong>
                <div class="muted" style="font-size:12px;">
                  <span class="badge badge-plain" style="font-size:11px;"><?= htmlspecialchars($a['tipo']) ?></span>
                  <?= htmlspecialchars(upload_formatar_tamanho($a['tamanho'])) ?>
                  · <?= htmlspecialchars($a['enviado_em']) ?>
                  <?php if (!empty($a['descricao'])): ?>
                    · <?= htmlspecialchars($a['descricao']) ?>
                  <?php elseif (!empty($a['enviado_por'])): ?>
                    · <?= htmlspecialchars($a['enviado_por']) ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <div class="td-actions" style="flex-shrink:0;">
              <a class="action primary" href="download.php?id=<?= (int) $a['id'] ?>">Baixar</a>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
      </details>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="panel-head">
      <h4>Enviar documento</h4>
      <span class="panel-sub">Contrato, comprovante, identidade, etc.</span>
    </div>

    <form action="<?= htmlspecialchars('documentos.php?' . http_build_query(['ano' => $anoFiltro])) ?>" method="post" enctype="multipart/form-data" class="form">
      <input type="hidden" name="acao" value="upload">
      <input type="hidden" name="ano_ctx" value="<?= (int) $anoFiltro ?>">

      <div class="form-group">
        <label for="tipo_anexo">Tipo</label>
        <select id="tipo_anexo" name="tipo_anexo" class="select">
          <option>Contrato</option>
          <option>Documento</option>
          <option>Identidade</option>
          <option>Outro</option>
        </select>
      </div>

      <div class="form-group">
        <label for="descricao_anexo">Descrição <span class="muted">(opcional)</span></label>
        <input type="text" id="descricao_anexo" name="descricao_anexo" class="input"
               placeholder="Ex.: Comprovante de pagamento jan/2026">
      </div>

      <div class="form-group">
        <label for="anexos">Arquivos</label>
        <input type="file" id="anexos" name="anexos[]" class="input" multiple required
               accept=".pdf,.doc,.docx,.odt,.rtf,.txt,.xls,.xlsx,.ods,.csv,.png,.jpg,.jpeg,.gif,.webp,.zip,.rar,.7z">
        <span class="hint">PDF, Word, Excel, imagens ou ZIP. Máximo 15 MB por arquivo.</span>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary"<?= $clienteId <= 0 || !db_ok() ? ' disabled' : '' ?>>Enviar</button>
      </div>
    </form>
  </div>
</section>

</main>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
