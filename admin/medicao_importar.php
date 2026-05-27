<?php
/**
 * Importação de planilha BM (CSV ou .xlsx) — pré-visualização e confirmação em duas etapas.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/medicao_helpers.php';
require_once __DIR__ . '/../includes/medicao_csv_import.php';
require_once __DIR__ . '/../includes/medicao_xlsx_import.php';
require_once __DIR__ . '/../includes/medicao_relatorio_import.php';

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
        echo 'DATA;PROTOCOLO;CÓDIGO;DESCRIÇÃO DOS ITENS;QTD.;VALOR UNITÁRIO (R$);VALOR TOTAL (R$)' . "\r\n";
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

    $por    = trim((string) (($me['nome'] ?? '') !== '' ? $me['nome'] : ($me['email'] ?? '')));
    $uid    = (int) ($me['id'] ?? 0);
    $tipoImp = (string) ($prev['import_tipo'] ?? 'bm');

    if ($tipoImp === 'chamados') {
        $grupos = is_array($prev['chamados_grupos'] ?? null) ? $prev['chamados_grupos'] : [];
        $impCh  = medicao_relatorio_import_criar_chamados(
            $clienteId,
            $refYm,
            $grupos,
            $uid > 0 ? $uid : null,
            true,
            empty($prev['modo_teste'])
        );
        if (!$impCh['ok']) {
            flash_set('err', $impCh['erro'] !== '' ? $impCh['erro'] : 'Falha ao criar chamados.');
            $ymp = explode('-', $refYm);
            header('Location: medicao_importar.php?' . http_build_query([
                'step' => 'confirm', 'ref_ano' => (int) ($ymp[0] ?? $anoForm), 'ref_mes' => (int) ($ymp[1] ?? $mesForm),
            ]));
            exit;
        }
        unset($_SESSION[$previewSessKey]);
        $msgOk = !empty($prev['modo_teste'])
            ? 'Teste: ' . (int) $impCh['n_chamados'] . ' chamado(s), ' . (int) $impCh['n_itens'] . ' item(ns) — ' . medicao_mes_label_pt($refYm) . '.'
            : 'Chamados criados: ' . (int) $impCh['n_chamados'] . ' OS, ' . (int) $impCh['n_itens'] . ' lançamento(s) de item. '
            . (int) ($impCh['n_chamados_pulados'] ?? 0) . ' já existiam no período.';
        flash_set('ok', $msgOk);
        header('Location: medicao_mes.php?' . http_build_query(['mes' => $refYm]));
        exit;
    }

    $grav = repo_medicao_import_substituir(
        $clienteId,
        $refYm,
        (string) ($prev['nome_arquivo'] ?? 'import.csv'),
        $por !== '' ? $por : null,
        isset($prev['idx_qtd_medido']) ? (int) $prev['idx_qtd_medido'] : null,
        isset($prev['idx_valor_medido']) ? (int) $prev['idx_valor_medido'] : null,
        is_array($prev['linhas'] ?? null) ? $prev['linhas'] : [],
        $uid,
        empty($prev['modo_teste'])
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
    $msgOk = !empty($prev['modo_teste'])
        ? 'Importação de teste gravada: ' . $n . ' item(ns) para ' . medicao_mes_label_pt($refYm) . ' (' . $refYm . ').'
        : 'Importação confirmada e gravada: ' . $n . ' item(ns) para ' . medicao_mes_label_pt($refYm) . ' (' . $refYm . ').';
    flash_set('ok', $msgOk);
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

    $tmp         = (string) $_FILES['planilha']['tmp_name'];
    $importTipo  = (string) ($_POST['import_tipo'] ?? 'bm');
    if (!in_array($importTipo, ['bm', 'chamados'], true)) {
        $importTipo = 'bm';
    }
    $abaPost   = (string) ($_POST['aba_xlsx'] ?? 'medicao');
    $preferAba = $abaPost === 'detalhado' ? 'DETALHADO' : 'medicao';
    if ($importTipo === 'chamados') {
        $preferAba = 'DETALHADO';
    }

    $modoTeste = !empty($_POST['modo_teste']) && (string) $_POST['modo_teste'] === '1';
    $chamadosGrupos = [];
    $parse          = ['ok' => false, 'erro' => '', 'linhas' => [], 'idx_qtd_medido' => null, 'idx_valor_medido' => null];

    if ($importTipo === 'chamados') {
        $raw = medicao_upload_file_to_rows($tmp, $ext, $preferAba);
        if (!$raw['ok']) {
            flash_set('err', $raw['erro'] !== '' ? $raw['erro'] : 'Falha ao ler o arquivo.');
            header('Location: medicao_importar.php?ref_ano=' . $anoForm . '&ref_mes=' . $mesForm);
            exit;
        }
        $pkg = medicao_relatorio_parse_grupos_chamados($raw['rows']);
        if (!$pkg['ok']) {
            flash_set('err', $pkg['erro']);
            header('Location: medicao_importar.php?ref_ano=' . $anoForm . '&ref_mes=' . $mesForm);
            exit;
        }
        $chamadosGrupos = $pkg['grupos'];
        if ($modoTeste) {
            $chamadosGrupos = array_slice($chamadosGrupos, 0, 10);
        }
        $linhas = [];
        foreach ($chamadosGrupos as $g) {
            foreach ($g['itens'] ?? [] as $it) {
                $linhas[] = [
                    'item_codigo'          => (string) ($it['codigo'] ?? ''),
                    'descricao'            => 'OS ' . ($g['protocolo'] ?? '') . ' · ' . ($it['descricao'] ?? ''),
                    'unidade'              => (string) ($it['unidade'] ?? ''),
                    'qtd_medido_periodo'   => $it['quantidade'] ?? null,
                    'valor_medido_periodo' => $it['valor_total'] ?? null,
                ];
            }
        }
        $parse = [
            'ok'               => true,
            'erro'             => '',
            'linhas'           => $linhas,
            'idx_qtd_medido'   => null,
            'idx_valor_medido' => null,
        ];
    } else {
        if ($ext === 'xlsx') {
            $parse = medicao_xlsx_parse_bm_upload($tmp, $refYm, $preferAba);
        } else {
            $parse = medicao_csv_parse_bm_planilha($tmp, $refYm);
        }
        if (!$parse['ok']) {
            flash_set('err', $parse['erro'] !== '' ? $parse['erro'] : 'Falha ao interpretar o arquivo.');
            header('Location: medicao_importar.php?ref_ano=' . $anoForm . '&ref_mes=' . $mesForm);
            exit;
        }
        $linhas = is_array($parse['linhas'] ?? null) ? $parse['linhas'] : [];
        if ($modoTeste) {
            $linhas = array_slice($linhas, 0, 10);
        }
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
        'linhas'            => $linhas,
        'modo_teste'        => $modoTeste,
        'aba_xlsx'          => $preferAba,
        'import_tipo'       => $importTipo,
        'chamados_grupos'   => $chamadosGrupos,
        'n_chamados_prev'   => count($chamadosGrupos),
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
    $modoTestePrev   = !empty($preview['modo_teste']);
    $importTipoPrev  = (string) ($preview['import_tipo'] ?? 'bm');
    $nChamadosPrev   = (int) ($preview['n_chamados_prev'] ?? 0);
    $mostrarLinhas = array_slice($linhasPrev, 0, 120);
    $restam        = max(0, $nLinhas - count($mostrarLinhas));
}

$topTitle    = 'Importar medição (BM)';
$topSubtitle = ($clienteMatriz['empresa'] ?? '') . ' · CSV ou Excel (.xlsx)';
$topSearch   = '';
$topAction   = ['label' => 'Medições mensais', 'href' => 'medicao.php', 'icon' => '←'];

$modeloHref = 'medicao_importar.php?exportar_modelo=relatorio';
$cancelPreviewHref = 'medicao_importar.php?' . http_build_query([
    'cancel_preview' => '1',
    'ref_ano'        => $anoForm,
    'ref_mes'        => $mesForm,
]);

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <?php if (!empty($stepConfirm) && !empty($previewValid)): ?>

  <div class="cards-metrics mb-24">
    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">Arquivo</div>
          <div class="metric-value" style="font-size:1rem;line-height:1.35;word-break:break-word;"><?= htmlspecialchars($nomeArqPrev) ?></div>
        </div>
        <div class="icon-box purple">BM</div>
      </div>
      <div class="metric-change muted">Planilha enviada</div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">Referência</div>
          <div class="metric-value"><?= htmlspecialchars($refYmPreview) ?></div>
        </div>
        <div class="icon-box green">REF</div>
      </div>
      <div class="metric-change success"><?= htmlspecialchars($mesLabelPrev) ?></div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label"><?= $importTipoPrev === 'chamados' ? 'Chamados (OS)' : 'Linhas de item' ?></div>
          <div class="metric-value"><?= $importTipoPrev === 'chamados' ? (int) $nChamadosPrev : (int) $nLinhas ?></div>
        </div>
        <div class="icon-box orange">#</div>
      </div>
      <div class="metric-change info"><?= $importTipoPrev === 'chamados'
          ? (int) $nLinhas . ' lançamento(s) de item · status Validado'
          : 'Itens na pré-visualização' ?></div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">Valor total (prévia)</div>
          <div class="metric-value" style="font-size:1.35rem;">R$ <?= htmlspecialchars(number_format($somaValor, 2, ',', '.')) ?></div>
        </div>
        <div class="icon-box purple">Σ</div>
      </div>
      <div class="metric-change muted">Qtd somada: <?= $somaQtd != 0.0 ? htmlspecialchars(number_format($somaQtd, 4, ',', '.')) : '—' ?></div>
    </div>
  </div>

  <div class="card">
    <div class="panel-head">
      <div>
        <h4>Confirmar importação</h4>
        <span class="panel-sub"><?php if ($importTipoPrev === 'chamados'): ?>
          Serão criados <strong><?= (int) $nChamadosPrev ?></strong> chamado(s) com <strong><?= (int) $nLinhas ?></strong> item(ns) (status <strong>Validado</strong>) para integrar à medição de <strong><?= htmlspecialchars($mesLabelPrev) ?></strong>.
          <?php else: ?>
          Revise os dados abaixo. Ao confirmar, substitui qualquer BM importado anterior de <strong><?= htmlspecialchars($refYmPreview) ?></strong> (<?= htmlspecialchars($mesLabelPrev) ?>).
          <?php endif; ?>
          <?php if (!empty($modoTestePrev)): ?> <strong>Modo teste</strong>.<?php endif; ?></span>
      </div>
      <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($cancelPreviewHref) ?>">Cancelar</a>
    </div>
    <div class="panel-body" style="padding-top:0;">
      <div class="table-wrap" style="max-height:min(520px, calc(100vh - 420px));overflow:auto;border:1px solid var(--border);border-radius:12px;">
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
      <p class="muted" style="margin:14px 0 0;font-size:13px;">… e mais <?= (int) $restam ?> linha(s) (todas serão gravadas).</p>
      <?php endif; ?>
    </div>
    <div class="panel-body">
      <form method="post" action="medicao_importar.php" class="form">
        <input type="hidden" name="confirm_import" value="1">
        <input type="hidden" name="preview_token" value="<?= htmlspecialchars($previewToken) ?>">
        <p class="muted" style="margin:0 0 16px;line-height:1.55;">Só depois de confirmar os dados são gravados no banco. Esta ação não pode ser desfeita automaticamente.</p>
        <div class="form-actions" style="padding:0;border:0;background:transparent;">
          <a class="btn btn-secondary" href="<?= htmlspecialchars($cancelPreviewHref) ?>">Voltar e enviar outro arquivo</a>
          <button type="submit" class="btn btn-primary">Confirmar importação</button>
        </div>
      </form>
    </div>
  </div>

  <?php else: ?>

  <div class="content-grid-2">
    <form class="card" method="post" action="medicao_importar.php" enctype="multipart/form-data">
      <div class="panel-head">
        <h4>Enviar planilha BM</h4>
        <span class="panel-sub"><?= htmlspecialchars((string) ($clienteMatriz['empresa'] ?? '')) ?> · mês de referência e arquivo (.csv ou .xlsx)</span>
      </div>
      <div class="panel-body form form-grid">
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
          <label for="import_tipo">Tipo de importação</label>
          <select id="import_tipo" name="import_tipo" class="select">
            <option value="chamados" selected>Relatório detalhado → chamados + medição (recomendado)</option>
            <option value="bm">Somente linhas BM (matriz ou relatório agregado)</option>
          </select>
          <span class="hint">O modelo tratado com protocolo, itens e valores deve usar «chamados + medição». Itens com código <strong>2.</strong> entram como serviço; <strong>3.</strong> como material (produto).</span>
        </div>
        <div class="form-group full" id="wrap_aba_xlsx">
          <label for="aba_xlsx">Aba do Excel (.xlsx)</label>
          <select id="aba_xlsx" name="aba_xlsx" class="select">
            <option value="detalhado" selected>Relatório detalhado</option>
            <option value="medicao">MEDIÇÃO NN (matriz por item)</option>
          </select>
        </div>
        <div class="form-group full">
          <label for="planilha">Arquivo</label>
          <input type="file" id="planilha" name="planilha" class="input" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
          <span class="hint">Pré-visualização antes de gravar. Uma importação confirmada para o mesmo mês substitui a anterior.</span>
        </div>
        <div class="form-group full">
          <label class="checkbox-label" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="checkbox" name="modo_teste" value="1">
            Teste (10 primeiros chamados ou itens)
          </label>
          <span class="hint">Chamados: limita a 10 OS; BM: limita a 10 linhas.</span>
        </div>
      </div>
      <div class="panel-body">
        <div class="form-actions" style="padding:0;border:0;background:transparent;">
          <a class="btn btn-secondary" href="medicao.php">Cancelar</a>
          <button type="submit" class="btn btn-primary">Pré-visualizar importação</button>
        </div>
      </div>
    </form>

    <div class="card">
      <div class="panel-head">
        <div>
          <h4>Formato da planilha</h4>
          <span class="panel-sub">MEDIÇÃO NN (matriz) ou relatório detalhado</span>
        </div>
        <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($modeloHref) ?>">Baixar CSV modelo</a>
      </div>
      <div class="panel-body">
        <p class="muted" style="line-height:1.6;margin-top:0;">
          O modelo <strong>Relatório detalhado tratado</strong> (protocolo, coordenadas, itens, qtd, valores) deve usar <strong>«Relatório detalhado → chamados + medição»</strong>: cria OS no CRM com status <strong>Validado</strong>, cadastra itens ausentes no catálogo e alimenta a medição mensal (totais, BM, BM completo, fotos). Para totais só por item de contrato, use aba <strong>MEDIÇÃO NN</strong> + importação «Somente linhas BM». Extensão PHP <code>zip</code> obrigatória para .xlsx.
        </p>
        <div class="table-wrap" style="border:1px solid var(--border);border-radius:12px;">
          <table>
            <thead>
              <tr><th>Origem</th><th>Colunas esperadas</th></tr>
            </thead>
            <tbody>
              <tr>
                <td>Relatório detalhado → chamados</td>
                <td class="td-mute"><code>PROTOCOLO</code>, coordenadas, bairro, rua, equipe, <code>CÓDIGO</code>, descrição, <code>QTD.</code>, <code>UN</code>, valores</td>
              </tr>
              <tr>
                <td>MEDIÇÃO NN (só BM)</td>
                <td class="td-mute"><code>ITEM</code>, «MEDIDO NO PERÍODO» do BM do mês</td>
              </tr>
              <tr>
                <td>Relatório detalhado (só BM)</td>
                <td class="td-mute">Uma linha BM por intervenção (sem criar chamados)</td>
              </tr>
              <tr>
                <td>Matriz oculta</td>
                <td class="td-mute">Aba «MATRIZ» sem colunas de período — use «MEDIÇÃO NN»</td>
              </tr>
            </tbody>
          </table>
        </div>
        <pre style="white-space:pre-wrap;background:#0f172a;color:#e2e8f0;padding:14px;border-radius:12px;margin-top:14px;font-size:12px;">DATA;PROTOCOLO;CÓDIGO;DESCRIÇÃO DOS ITENS;QTD.;VALOR UNITÁRIO (R$);VALOR TOTAL (R$)
01/05/2026;;001;Serviço exemplo;10;50,00;500,00</pre>
      </div>
    </div>
  </div>

  <?php endif; ?>
</section>

</main>
</div>

<script>
(function () {
  var tipo = document.getElementById('import_tipo');
  var wrap = document.getElementById('wrap_aba_xlsx');
  var aba = document.getElementById('aba_xlsx');
  if (!tipo || !wrap || !aba) return;
  function sync() {
    var ch = tipo.value === 'chamados';
    wrap.style.display = ch ? 'none' : '';
    if (ch) aba.value = 'detalhado';
  }
  tipo.addEventListener('change', sync);
  sync();
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
