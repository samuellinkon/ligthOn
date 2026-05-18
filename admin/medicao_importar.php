<?php
/**
 * Importação de planilha BM (CSV ou .xlsx) — pré-visualização e confirmação em duas etapas.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/medicao_helpers.php';
require_once __DIR__ . '/../includes/medicao_csv_import.php';
require_once __DIR__ . '/../includes/medicao_xlsx_import.php';

$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('medicao');

$pageTitle  = 'Importar medição (BM)';
$basePath   = '../';
$activePage = 'medicao';

$previewSessKey = 'medicao_import_preview_v1';
$previewTtlSec  = 3600;

if (!db_ok()) {
    flash_set('err', 'Banco indisponível.');
    header('Location: index.php');
    exit;
}

$escopoEmpresa = gestao_scope_cliente_id($me);
if ($escopoEmpresa !== null) {
    $clienteId = $escopoEmpresa;
} else {
    $clienteId = (int) (repo_catalogo_cliente_id_padrao_admin() ?? 0);
    if ($clienteId <= 0) {
        $empresasFallback = repo_clientes_empresas();
        $clienteId = (int) (($empresasFallback[0]['id'] ?? 0));
    }
}

if ($clienteId <= 0) {
    flash_set('err', 'Defina a empresa padrão do catálogo em Configurações (super admin) ou cadastre uma empresa raiz.');
    $redir = (($me['perfil'] ?? '') === 'gestor') ? 'clientes.php' : 'configuracoes.php';
    header('Location: ' . $redir);
    exit;
}

$clienteMatriz = repo_cliente($clienteId);
if (!$clienteMatriz) {
    flash_set('err', 'Empresa não encontrada.');
    header('Location: clientes.php');
    exit;
}
gestor_assert_escopo_cliente($clienteId, 'medicao_importar.php');

if (!empty($_GET['exportar_modelo']) && (string) $_GET['exportar_modelo'] === 'relatorio') {
    $fn  = 'modelo_importacao_medicao_relatorio_detalhado_bm.csv';
    $src = __DIR__ . '/../database/modelo_importacao_medicao_relatorio_detalhado_bm.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF";
    if (is_readable($src)) {
        readfile($src);
    } else {
        echo "\xEF\xBB\xBF";
        echo 'DATA;PROTOCOLO GIP;CÓDIGO;DESCRIÇÃO DOS ITENS;QTD.;VALOR UNITÁRIO (R$);VALOR TOTAL (R$)' . "\r\n";
    }
    exit;
}

$anoAtual = (int) date('Y');
$anoForm  = (int) ($_POST['ref_ano'] ?? $_GET['ref_ano'] ?? $anoAtual);
$mesForm  = (int) ($_POST['ref_mes'] ?? $_GET['ref_mes'] ?? (int) date('n'));
$anoForm  = max(2000, min(2100, $anoForm));
$mesForm  = max(1, min(12, $mesForm));

if (isset($_GET['cancel_preview']) && (string) $_GET['cancel_preview'] === '1') {
    unset($_SESSION[$previewSessKey]);
    flash_set('ok', 'Pré-visualização cancelada. Pode enviar outro arquivo quando quiser.');
    header('Location: medicao_importar.php?ref_ano=' . $anoForm . '&ref_mes=' . $mesForm);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import']) && (string) $_POST['confirm_import'] === '1') {
    $prev = $_SESSION[$previewSessKey] ?? null;
    $tokenPost = (string) ($_POST['preview_token'] ?? '');
    $refYm = is_array($prev) ? (string) ($prev['ref_ym'] ?? '') : '';
    if (!preg_match('/^\d{4}-\d{2}$/', $refYm)) {
        unset($_SESSION[$previewSessKey]);
        flash_set('err', 'Pré-visualização inválida ou expirada. Envie o arquivo novamente.');
        header('Location: medicao_importar.php?ref_ano=' . $anoForm . '&ref_mes=' . $mesForm);
        exit;
    }

    if (!is_array($prev)
        || empty($prev['token'])
        || !hash_equals((string) $prev['token'], $tokenPost)
        || (int) ($prev['cliente_matriz_id'] ?? 0) !== $clienteId
    ) {
        unset($_SESSION[$previewSessKey]);
        flash_set('err', 'Pré-visualização inválida ou expirada. Envie o arquivo novamente.');
        header('Location: medicao_importar.php?ref_ano=' . $anoForm . '&ref_mes=' . $mesForm);
        exit;
    }

    if ((time() - (int) ($prev['ts'] ?? 0)) > $previewTtlSec) {
        unset($_SESSION[$previewSessKey]);
        flash_set('err', 'A pré-visualização expirou (limite de ' . (int) ($previewTtlSec / 60) . ' minutos). Envie o arquivo de novo.');
        header('Location: medicao_importar.php?ref_ano=' . $anoForm . '&ref_mes=' . $mesForm);
        exit;
    }

    $por = trim((string) (($me['nome'] ?? '') !== '' ? $me['nome'] : ($me['email'] ?? '')));
    $grav = repo_medicao_import_substituir(
        $clienteId,
        $refYm,
        (string) ($prev['nome_arquivo'] ?? 'import.csv'),
        $por !== '' ? $por : null,
        isset($prev['idx_qtd_medido']) ? (int) $prev['idx_qtd_medido'] : null,
        isset($prev['idx_valor_medido']) ? (int) $prev['idx_valor_medido'] : null,
        is_array($prev['linhas'] ?? null) ? $prev['linhas'] : []
    );

    if (!$grav['ok']) {
        flash_set('err', $grav['erro']);
        $ymp = explode('-', $refYm);
        $ay  = (int) ($ymp[0] ?? $anoForm);
        $am  = (int) ($ymp[1] ?? $mesForm);
        header('Location: medicao_importar.php?' . http_build_query(['step' => 'confirm', 'ref_ano' => $ay, 'ref_mes' => $am]));
        exit;
    }

    unset($_SESSION[$previewSessKey]);

    $n = is_array($prev['linhas'] ?? null) ? count($prev['linhas']) : 0;
    flash_set('ok', 'Importação confirmada e gravada: ' . $n . ' item(ns) para ' . medicao_mes_label_pt($refYm) . ' (' . $refYm . ').');
    header('Location: medicao_mes.php?' . http_build_query(['mes' => $refYm]));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['confirm_import'])) {
    $refYm = sprintf('%04d-%02d', $anoForm, $mesForm);
    if (empty($_FILES['planilha']['tmp_name']) || !is_uploaded_file($_FILES['planilha']['tmp_name'])) {
        flash_set('err', 'Selecione o arquivo (.csv ou .xlsx) da planilha BM.');
        header('Location: medicao_importar.php?ref_ano=' . $anoForm . '&ref_mes=' . $mesForm);
        exit;
    }

    $nomeUp  = (string) ($_FILES['planilha']['name'] ?? '');
    $ext     = strtolower(pathinfo($nomeUp, PATHINFO_EXTENSION));
    $allowed = ['csv', 'xlsx'];
    if (!in_array($ext, $allowed, true)) {
        flash_set('err', 'Formato não suportado. Envie um ficheiro .csv (UTF-8) ou .xlsx (Excel).');
        header('Location: medicao_importar.php?ref_ano=' . $anoForm . '&ref_mes=' . $mesForm);
        exit;
    }

    $tmp = (string) $_FILES['planilha']['tmp_name'];
    if ($ext === 'xlsx') {
        $parse = medicao_xlsx_parse_bm_upload($tmp, $refYm);
    } else {
        $parse = medicao_csv_parse_bm_planilha($tmp, $refYm);
    }
    if (!$parse['ok']) {
        flash_set('err', $parse['erro'] !== '' ? $parse['erro'] : 'Falha ao interpretar o arquivo.');
        header('Location: medicao_importar.php?ref_ano=' . $anoForm . '&ref_mes=' . $mesForm);
        exit;
    }

    $nomeArq = $nomeUp !== '' ? $nomeUp : ($ext === 'xlsx' ? 'import.xlsx' : 'import.csv');
    $_SESSION[$previewSessKey] = [
        'token'             => bin2hex(random_bytes(16)),
        'ts'                => time(),
        'cliente_matriz_id' => $clienteId,
        'ref_ym'            => $refYm,
        'nome_arquivo'      => mb_substr($nomeArq, 0, 255),
        'idx_qtd_medido'    => $parse['idx_qtd_medido'],
        'idx_valor_medido'  => $parse['idx_valor_medido'],
        'linhas'            => $parse['linhas'],
    ];

    header('Location: medicao_importar.php?' . http_build_query([
        'step'    => 'confirm',
        'ref_ano' => $anoForm,
        'ref_mes' => $mesForm,
    ]));
    exit;
}

$preview = $_SESSION[$previewSessKey] ?? null;
$stepConfirm = (string) ($_GET['step'] ?? '') === 'confirm';
$previewValid = is_array($preview)
    && (int) ($preview['cliente_matriz_id'] ?? 0) === $clienteId
    && !empty($preview['token'])
    && (time() - (int) ($preview['ts'] ?? 0)) <= $previewTtlSec;

if ($stepConfirm) {
    if (!$previewValid) {
        unset($_SESSION[$previewSessKey]);
        flash_set('err', 'Não há pré-visualização ativa. Envie o arquivo para analisar.');
        header('Location: medicao_importar.php?ref_ano=' . $anoForm . '&ref_mes=' . $mesForm);
        exit;
    }
    $refYmPreview = (string) ($preview['ref_ym'] ?? '');
    $linhasPrev   = is_array($preview['linhas'] ?? null) ? $preview['linhas'] : [];
    $nLinhas      = count($linhasPrev);
    $somaValor    = 0.0;
    $somaQtd      = 0.0;
    foreach ($linhasPrev as $L) {
        $somaValor += (float) ($L['valor_medido_periodo'] ?? 0);
        $q = $L['qtd_medido_periodo'] ?? null;
        if ($q !== null && $q !== '') {
            $somaQtd += (float) $q;
        }
    }
    $previewToken = (string) $preview['token'];
    $nomeArqPrev  = (string) ($preview['nome_arquivo'] ?? '');
    $mesLabelPrev = medicao_mes_label_pt($refYmPreview);
    $mostrarLinhas = array_slice($linhasPrev, 0, 120);
    $restam        = max(0, $nLinhas - count($mostrarLinhas));
}

$topTitle    = 'Importar medição (BM)';
$topSubtitle = ($clienteMatriz['empresa'] ?? '') . ' · CSV ou Excel (.xlsx)';
$topSearch   = '';
$topAction   = ['label' => '← Medições mensais', 'href' => 'medicao.php', 'icon' => '←'];

include __DIR__ . '/../includes/head.php';
?>
<style>
  .medicao-import-page {
    width: 100%;
    max-width: none;
  }

  .medicao-import-card {
    width: 100%;
    max-width: none;
  }

  .medicao-import-preview {
    max-height: calc(100vh - 330px);
    min-height: 320px;
  }

  .medicao-import-preview .td-title {
    max-width: none;
  }

  /* Especificidade ≥ .form-group.full para não cair na coluna estreita (180px) do grid */
  .medicao-import-form .form-group.full.form-group--help {
    grid-column: 1 / -1;
    width: 100%;
    max-width: min(52rem, 100%);
    justify-self: start;
    box-sizing: border-box;
  }

  .medicao-import-help-text {
    font-size: 13px;
    line-height: 1.45;
  }

  @media (min-width: 1180px) {
    .medicao-import-form {
      grid-template-columns: minmax(0, 180px) minmax(0, 220px) minmax(0, 1fr);
      align-items: end;
    }

    .medicao-import-form .form-group.full:not(.form-group--help) {
      grid-column: auto;
    }

    .medicao-import-form .form-group.full.form-group--help,
    .medicao-import-form .form-actions {
      grid-column: 1 / -1;
    }
  }
</style>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content medicao-import-page">
  <?php if (!empty($stepConfirm) && !empty($previewValid)): ?>
  <div class="card medicao-import-card">
    <div class="panel-head">
      <div>
        <h4>Confirmar importação</h4>
        <span class="panel-sub">Revise os dados abaixo. Ao confirmar, a importação substitui qualquer BM anterior para <strong><?= htmlspecialchars($refYmPreview) ?></strong> (<?= htmlspecialchars($mesLabelPrev) ?>).</span>
      </div>
      <a class="btn btn-secondary btn-sm" href="medicao_importar.php?<?= htmlspecialchars(http_build_query(['cancel_preview' => '1', 'ref_ano' => $anoForm, 'ref_mes' => $mesForm])) ?>">Cancelar</a>
    </div>
    <div class="panel-body" style="padding-top:0;">
      <dl class="grid-2" style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px 24px;margin:0 0 20px;font-size:14px;">
        <div><dt class="muted" style="margin:0 0 4px;">Arquivo</dt><dd class="td-strong" style="margin:0;"><?= htmlspecialchars($nomeArqPrev) ?></dd></div>
        <div><dt class="muted" style="margin:0 0 4px;">Referência</dt><dd class="td-strong" style="margin:0;"><?= htmlspecialchars($refYmPreview) ?> · <?= htmlspecialchars($mesLabelPrev) ?></dd></div>
        <div><dt class="muted" style="margin:0 0 4px;">Linhas de item</dt><dd class="td-strong" style="margin:0;"><?= (int) $nLinhas ?></dd></div>
        <div><dt class="muted" style="margin:0 0 4px;">Totais (pré-visualização)</dt><dd class="td-strong" style="margin:0;">Qtd somada: <?= $somaQtd != 0.0 ? htmlspecialchars(number_format($somaQtd, 4, ',', '.')) : '—' ?> · Valor: R$ <?= htmlspecialchars(number_format($somaValor, 2, ',', '.')) ?></dd></div>
      </dl>

      <div class="table-wrap medicao-import-preview" style="overflow:auto;margin-bottom:24px;border:1px solid var(--border,#e5e7eb);border-radius:8px;">
        <table>
          <thead>
            <tr>
              <th>Item</th>
              <th>Descrição</th>
              <th>Unid.</th>
              <th class="text-right">Qtd medido</th>
              <th class="text-right">Valor medido</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($mostrarLinhas as $L): ?>
            <tr>
              <td class="td-mute"><?= htmlspecialchars((string) ($L['item_codigo'] ?? '')) ?></td>
              <td><div class="td-title"><?= htmlspecialchars((string) ($L['descricao'] ?? '')) ?></div></td>
              <td class="td-mute"><?= htmlspecialchars((string) ($L['unidade'] ?? '')) ?></td>
              <td class="text-right td-mute"><?php
                $qm = $L['qtd_medido_periodo'] ?? null;
                echo ($qm !== null && $qm !== '') ? htmlspecialchars((string) $qm) : '—';
              ?></td>
              <td class="text-right"><?php
                $vm = $L['valor_medido_periodo'] ?? null;
                echo ($vm !== null && $vm !== '') ? 'R$ ' . htmlspecialchars(number_format((float) $vm, 2, ',', '.')) : '—';
              ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($restam > 0): ?>
      <p class="muted" style="margin:-12px 0 20px;font-size:13px;">… e mais <?= (int) $restam ?> linha(s) (todas serão gravadas).</p>
      <?php endif; ?>

      <form method="post" action="medicao_importar.php" class="form" style="margin:0;">
        <input type="hidden" name="confirm_import" value="1">
        <input type="hidden" name="preview_token" value="<?= htmlspecialchars($previewToken) ?>">
        <button type="submit" class="btn btn-primary btn-lg" style="width:100%;max-width:100%;padding:18px 24px;font-size:17px;font-weight:600;">
          Confirmar importação
        </button>
        <p class="muted" style="margin:12px 0 0;font-size:13px;text-align:center;">Só depois deste passo os dados são gravados no banco.</p>
      </form>
    </div>
  </div>
  <?php else: ?>
  <div class="card medicao-import-card">
    <div class="panel-head">
      <div>
        <h4>Planilha BM (boletim)</h4>
        <span class="panel-sub">Indique o <strong>mês</strong> e <strong>ano</strong> de referência e envie o arquivo (.csv ou .xlsx). Será mostrada uma <strong>pré-visualização</strong> antes de gravar.</span>
      </div>
    </div>
    <form class="panel-body form form-grid medicao-import-form" method="post" action="medicao_importar.php" enctype="multipart/form-data">
      <div class="form-group">
        <label for="ref_ano">Ano</label>
        <input type="number" id="ref_ano" name="ref_ano" class="input" min="2000" max="2100" step="1" value="<?= (int) $anoForm ?>" required placeholder="Ex.: 2026">
      </div>
      <div class="form-group">
        <label for="ref_mes">Mês</label>
        <select id="ref_mes" name="ref_mes" class="select" required>
          <?php
            $nomesMes = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho',
                7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];
            foreach ($nomesMes as $nm => $label):
          ?>
          <option value="<?= (int) $nm ?>" <?= $mesForm === $nm ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group full">
        <label for="planilha">Arquivo</label>
        <input type="file" id="planilha" name="planilha" class="input" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
      </div>
      <div class="form-group full muted form-group--help medicao-import-help-text">
        Formatos aceites: (1) ficheiro <strong>.xlsx</strong> (aba «RELATÓRIO DETALHADO BM» ou matriz) ou <strong>.csv</strong> exportado do Excel, com colunas DATA, CÓDIGO, DESCRIÇÃO DOS ITENS, QTD., VALOR TOTAL no relatório detalhado; ou (2) matriz BM com coluna <strong>ITEM</strong> e valores «medido no período». O .xlsx é internamente um ZIP; o PHP precisa da extensão <strong>zip</strong> (<code>extension=zip</code> no <code>php.ini</code> + reiniciar Apache) — não significa que a tua planilha esteja «comprimida» errado.
        Uma importação confirmada para o mesmo mês substitui a anterior.
      </div>
      <div class="form-group full">
        <a class="btn btn-secondary btn-sm" href="medicao_importar.php?exportar_modelo=relatorio">↓ Baixar modelo (relatório detalhado)</a>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Pré-visualizar importação</button>
        <a class="btn btn-secondary" href="medicao.php">Voltar</a>
      </div>
    </form>
  </div>
  <?php endif; ?>
</section>

</main>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
