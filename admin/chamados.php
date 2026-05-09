<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('chamados');

require_once __DIR__ . '/../includes/medicao_helpers.php';

$escopoCh = gestao_scope_cliente_id($me);

$clienteIdListagem = max(0, (int) ($_GET['cliente_id'] ?? 0));
if ($clienteIdListagem > 0) {
    gestor_assert_escopo_cliente($clienteIdListagem, 'chamados.php');
}
$envolvidoUser = max(0, (int) ($_GET['envolvido_user'] ?? 0));

$escopoLista = $escopoCh;
if ($clienteIdListagem > 0 && db_ok()) {
    $midList = repo_cliente_matriz_raiz_id($clienteIdListagem);
    if ($midList > 0) {
        $escopoLista = $midList;
    }
}

$medicaoMes = trim((string) ($_GET['medicao_mes'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $medicaoMes)) {
    $medicaoMes = '';
}

$periodoLimpar = isset($_GET['periodo_limpar']) && (string) $_GET['periodo_limpar'] === '1';
$periodoDeRaw = trim((string) ($_GET['periodo_de'] ?? ''));
$periodoAteRaw = trim((string) ($_GET['periodo_ate'] ?? ''));

$periodoDe  = null;
$periodoAte = null;
if ($periodoLimpar) {
    // sem filtro de data
} elseif ($medicaoMes !== '') {
    $periodoDe  = $medicaoMes . '-01';
    $periodoAte = date('Y-m-t', strtotime($periodoDe));
} elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodoDeRaw)
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodoAteRaw)
    && $periodoDeRaw <= $periodoAteRaw) {
    $periodoDe  = $periodoDeRaw;
    $periodoAte = $periodoAteRaw;
} else {
    $periodoDe  = date('Y-m-01');
    $periodoAte = date('Y-m-t');
}

/** YYYY-MM para importação BM quando o período é um mês civil completo ou veio medicao_mes. */
$refYmBm = '';
if ($medicaoMes !== '') {
    $refYmBm = $medicaoMes;
} elseif ($periodoDe !== null && $periodoAte !== null && !$periodoLimpar) {
    if (preg_match('/^(\d{4}-\d{2})-\d{2}$/', $periodoDe, $m) && $periodoAte === date('Y-m-t', strtotime($periodoDe))) {
        $refYmBm = $m[1];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir') {
    $delId = (int) ($_POST['id'] ?? 0);
    if (db_ok() && $delId > 0) {
        $chDel = repo_chamado($delId);
        if ($chDel) {
            gestor_assert_escopo_cliente((int) ($chDel['cliente_id'] ?? 0), 'chamados.php');
        }
        if (repo_delete_chamado($delId)) {
            flash_set('ok', 'Chamado #' . $delId . ' excluído.');
        } else {
            flash_set('err', 'Não foi possível excluir o chamado.');
        }
    } else {
        flash_set('err', 'Exclusão requer banco de dados ativo.');
    }
    $redirMes = trim((string) ($_POST['medicao_mes'] ?? ''));
    $rd       = trim((string) ($_POST['periodo_de'] ?? ''));
    $ra       = trim((string) ($_POST['periodo_ate'] ?? ''));
    $rLim     = !empty($_POST['periodo_limpar']);
    $qsRed    = [];
    if (preg_match('/^\d{4}-\d{2}$/', $redirMes)) {
        $qsRed['medicao_mes'] = $redirMes;
    } elseif ($rLim) {
        $qsRed['periodo_limpar'] = '1';
    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rd) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ra)) {
        $qsRed['periodo_de']  = $rd;
        $qsRed['periodo_ate'] = $ra;
    }
    $pcid = max(0, (int) ($_POST['cliente_id_ctx'] ?? 0));
    if ($pcid > 0) {
        $qsRed['cliente_id'] = $pcid;
    }
    $peu = max(0, (int) ($_POST['envolvido_user_ctx'] ?? 0));
    if ($peu > 0) {
        $qsRed['envolvido_user'] = $peu;
    }
    $redir = 'chamados.php' . ($qsRed !== [] ? '?' . http_build_query($qsRed) : '');
    header('Location: ' . $redir);
    exit;
}

$f = strtolower(trim((string) ($_GET['f'] ?? '')));
if (!in_array($f, ['', 'abertos', 'andamento', 'aguardando', 'resolvidos', 'cancelados', 'urgentes'], true)) {
    $f = '';
}
$q = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;
$medicaoMatrizId = 0;
$medicaoMatrizNome = '';
if (db_ok()) {
    if ($escopoCh !== null && $escopoCh > 0) {
        $medicaoMatrizId = $escopoCh;
    } else {
        $medicaoMatrizId = (int) (repo_catalogo_cliente_id_padrao_admin() ?? 0);
        if ($medicaoMatrizId <= 0) {
            $empF = repo_clientes_empresas();
            $medicaoMatrizId = (int) (($empF[0]['id'] ?? 0));
        }
    }
    if ($medicaoMatrizId > 0) {
        $mcRow = repo_cliente($medicaoMatrizId);
        $medicaoMatrizNome = (string) ($mcRow['empresa'] ?? '');
    }
}

$bmLista = [];
$bmMergeActive = $medicaoMatrizId > 0 && $refYmBm !== '' && $f === '' && $envolvidoUser <= 0 && $clienteIdListagem <= 0 && db_ok();
if ($bmMergeActive) {
    $impPkg = repo_medicao_import_fetch($medicaoMatrizId, $refYmBm);
    $bmLista = medicao_import_linhas_para_chamados_listagem(
        is_array($impPkg['linhas'] ?? null) ? $impPkg['linhas'] : [],
        $refYmBm,
        $medicaoMatrizNome,
        $q
    );
}
$nBm = count($bmLista);

$envolvidoRepo = $envolvidoUser > 0 ? $envolvidoUser : null;

if (db_ok()) {
    if ($periodoDe !== null && $periodoAte !== null && $nBm > 0 && $f === '' && $bmMergeActive) {
        $resT     = repo_chamados_admin_list($f, $q, 1, 1, $escopoLista, $periodoDe, $periodoAte, null, $envolvidoRepo);
        $totalCh  = $resT['total'];
        $totalRows = $nBm + $totalCh;
        $totalPagesCalc = max(1, (int) ceil($totalRows / $perPage));
        $page            = min(max(1, $page), $totalPagesCalc);
        $start           = ($page - 1) * $perPage;
        $lista           = [];
        $remain          = $perPage;
        for ($bi = $start; $bi < $nBm && $remain > 0; $bi++) {
            $lista[] = $bmLista[$bi];
            $remain--;
        }
        if ($remain > 0) {
            $chOff = max(0, $start - $nBm);
            $resCh = repo_chamados_admin_list($f, $q, 1, $remain, $escopoLista, $periodoDe, $periodoAte, $chOff, $envolvidoRepo);
            foreach ($resCh['rows'] as $row) {
                $lista[] = $row;
            }
        }
    } else {
        $res       = repo_chamados_admin_list($f, $q, $page, $perPage, $escopoLista, $periodoDe, $periodoAte, null, $envolvidoRepo);
        $lista     = $res['rows'];
        $totalRows = $res['total'];
    }
} else {
    $lista = $MOCK_CHAMADOS;
    if ($f === 'abertos') {
        $lista = array_values(array_filter($lista, fn ($c) => ($c['status'] ?? '') === 'Aberto'));
    } elseif ($f === 'andamento') {
        $lista = array_values(array_filter($lista, fn ($c) => ($c['status'] ?? '') === 'Em andamento'));
    } elseif ($f === 'aguardando') {
        $lista = array_values(array_filter($lista, fn ($c) => ($c['status'] ?? '') === 'Aguardando'));
    } elseif ($f === 'resolvidos') {
        $lista = array_values(array_filter($lista, fn ($c) => in_array($c['status'] ?? '', ['Resolvido', 'Fechado'], true)));
    } elseif ($f === 'cancelados') {
        $lista = array_values(array_filter($lista, fn ($c) => ($c['status'] ?? '') === 'Cancelado'));
    } elseif ($f === 'urgentes') {
        $lista = array_values(array_filter($lista, fn ($c) => in_array($c['prioridade'] ?? '', ['Alta', 'Urgente'], true)
            && !in_array($c['status'] ?? '', ['Resolvido', 'Fechado', 'Cancelado'], true)));
    }
    if ($q !== '') {
        if (ctype_digit($q)) {
            $lista = array_values(array_filter($lista, fn ($c) => (int) ($c['id'] ?? 0) === (int) $q));
        } else {
            $ql = mb_strtolower($q);
            $lista = array_values(array_filter($lista, function ($c) use ($ql) {
                $hay = mb_strtolower(($c['titulo'] ?? '') . ' ' . ($c['cliente'] ?? ''));

                return mb_strpos($hay, $ql) !== false;
            }));
        }
    }
    $totalRows = count($lista);
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    $page       = min($page, $totalPages);
    $offset     = ($page - 1) * $perPage;
    $lista      = array_slice($lista, $offset, $perPage);
}

$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page       = min($page, $totalPages);

$dash = db_ok() ? repo_dashboard_admin_stats() : null;

/**
 * @param array{medicao_mes?:string,periodo_de?:string,periodo_ate?:string,periodo_limpar?:bool,cliente_id?:int,envolvido_user?:int} $periodoCtx
 */
function adm_chamados_url(int $p, string $filtro, string $busca, array $periodoCtx): string
{
    $qs = [];
    if ($filtro !== '') {
        $qs['f'] = $filtro;
    }
    if ($busca !== '') {
        $qs['q'] = $busca;
    }
    if ($p > 1) {
        $qs['page'] = $p;
    }
    if (!empty($periodoCtx['periodo_limpar'])) {
        $qs['periodo_limpar'] = '1';
    } elseif (($periodoCtx['medicao_mes'] ?? '') !== '') {
        $qs['medicao_mes'] = $periodoCtx['medicao_mes'];
    } else {
        if (($periodoCtx['periodo_de'] ?? '') !== '') {
            $qs['periodo_de'] = $periodoCtx['periodo_de'];
        }
        if (($periodoCtx['periodo_ate'] ?? '') !== '') {
            $qs['periodo_ate'] = $periodoCtx['periodo_ate'];
        }
    }
    if (!empty($periodoCtx['cliente_id'])) {
        $qs['cliente_id'] = (int) $periodoCtx['cliente_id'];
    }
    if (!empty($periodoCtx['envolvido_user'])) {
        $qs['envolvido_user'] = (int) $periodoCtx['envolvido_user'];
    }

    return 'chamados.php' . ($qs ? '?' . http_build_query($qs) : '');
}

/**
 * URL de exportação com os mesmos filtros da listagem.
 */
function adm_chamados_export_url(string $format, string $filtro, string $busca, array $periodoCtx): string
{
    $base = adm_chamados_url(1, $filtro, $busca, $periodoCtx);

    return $base . ((strpos($base, '?') !== false) ? '&' : '?') . 'export=' . rawurlencode($format);
}

/**
 * Recolhe linhas para exportação (CRM; + BM na mesma ordem da listagem quando aplicável).
 *
 * @return list<array<string,mixed>>
 */
function adm_chamados_collect_export_rows(
    bool $mergeBm,
    array $bmLista,
    string $f,
    string $q,
    ?int $escopoLista,
    ?string $periodoDe,
    ?string $periodoAte,
    int $maxRows,
    ?int $envolvidoRepo = null
): array {
    $out = [];
    if ($mergeBm) {
        foreach ($bmLista as $r) {
            if (count($out) >= $maxRows) {
                return $out;
            }
            $out[] = $r;
        }
    }
    $tot = repo_chamados_admin_list($f, $q, 1, 1, $escopoLista, $periodoDe, $periodoAte, null, $envolvidoRepo)['total'];
    $off = 0;
    $batch = 2000;
    while ($off < $tot && count($out) < $maxRows) {
        $take = min($batch, $tot - $off, $maxRows - count($out));
        if ($take <= 0) {
            break;
        }
        $chunk = repo_chamados_admin_list($f, $q, 1, $take, $escopoLista, $periodoDe, $periodoAte, $off, $envolvidoRepo)['rows'];
        foreach ($chunk as $r) {
            $out[] = $r;
        }
        $off += $take;
    }

    return $out;
}

$admChPeriodoCtx = [
    'medicao_mes'     => $medicaoMes,
    'periodo_de'      => ($medicaoMes === '' && !$periodoLimpar && $periodoDe !== null) ? $periodoDe : '',
    'periodo_ate'     => ($medicaoMes === '' && !$periodoLimpar && $periodoAte !== null) ? $periodoAte : '',
    'periodo_limpar'  => $periodoLimpar,
    'cliente_id'      => $clienteIdListagem > 0 ? $clienteIdListagem : null,
    'envolvido_user'  => $envolvidoUser > 0 ? $envolvidoUser : null,
];

$exportFmt = strtolower(trim((string) ($_GET['export'] ?? '')));
$mergeBmExport = $periodoDe !== null && $periodoAte !== null && $nBm > 0 && $f === '' && $bmMergeActive;
$maxExportRows = 20000;

if (in_array($exportFmt, ['csv', 'json'], true)) {
    if (!db_ok()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Exportação disponível com base de dados ativa.';
        exit;
    }
    $rowsEx = adm_chamados_collect_export_rows(
        $mergeBmExport,
        $bmLista,
        $f,
        $q,
        $escopoLista,
        $periodoDe,
        $periodoAte,
        $maxExportRows,
        $envolvidoRepo
    );
    $stamp = date('Y-m-d_His');
    if ($exportFmt === 'json') {
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="chamados_' . $stamp . '.json"');
        echo json_encode(
            [
                'exportado_em' => date('c'),
                'filtro'       => $f,
                'busca'        => $q,
                'periodo'      => $periodoLimpar ? null : (($periodoDe ?? '') !== '' ? ['de' => $periodoDe, 'ate' => $periodoAte] : null),
                'medicao_mes'  => $medicaoMes !== '' ? $medicaoMes : null,
                'limite'       => $maxExportRows,
                'total'        => count($rowsEx),
                'chamados'     => $rowsEx,
            ],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
        exit;
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="chamados_' . $stamp . '.csv"');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        http_response_code(500);
        exit;
    }
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    $sep = ';';
    fputcsv($out, [
        'Tipo',
        'ID',
        'Ref_medição_BM',
        'Prefeitura',
        'Título',
        'Descrição',
        'Prioridade',
        'Status',
        'Responsável',
        'Técnico',
        'Data_abertura',
        'Endereço',
        'Latitude',
        'Longitude',
        'Aguardando_aprovação_gestor',
    ], $sep);
    foreach ($rowsEx as $c) {
        $isBm = !empty($c['medicao_bm']);
        $tipo = $isBm ? 'BM' : 'CRM';
        $idDisp = $isBm ? ('BM-' . (int) ($c['medicao_bm_linha_id'] ?? 0)) : (string) (int) ($c['id'] ?? 0);
        $refBm = $isBm ? (string) ($c['medicao_bm_ref_ym'] ?? '') : '';
        $aguarda = (!$isBm && !empty($c['finalizado_operador_em']) && empty($c['aprovado_gestor_em'])) ? 'Sim' : 'Não';
        fputcsv($out, [
            $tipo,
            $idDisp,
            $refBm,
            (string) ($c['cliente'] ?? ''),
            (string) ($c['titulo'] ?? ''),
            str_replace(["\r", "\n"], ' ', (string) ($c['descricao'] ?? '')),
            (string) ($c['prioridade'] ?? ''),
            (string) ($c['status'] ?? ''),
            (string) ($c['responsavel'] ?? ''),
            (string) ($c['tecnico_nome'] ?? $c['responsavel'] ?? ''),
            (string) ($c['data'] ?? ''),
            (string) ($c['endereco_completo'] ?? ''),
            isset($c['latitude']) ? (string) $c['latitude'] : '',
            isset($c['longitude']) ? (string) $c['longitude'] : '',
            $aguarda,
        ], $sep);
    }
    fclose($out);
    exit;
}

$fromN = $totalRows === 0 ? 0 : ($page - 1) * $perPage + 1;
$toN   = $totalRows === 0 ? 0 : min(($page - 1) * $perPage + count($lista), $totalRows);

$pageTitle  = 'Chamados';
$basePath   = '../';
$activePage = 'chamados';

$topTitle    = 'Chamados';
$topSubtitle = 'Lista filtrada pela data de abertura (por defeito: mês atual).';
$topSearch   = 'Buscar por ID, título ou prefeitura...';
$topAction   = ['label' => 'Novo chamado', 'href' => 'chamado_novo.php', 'icon' => '+'];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <div class="dashboard-admin-metrics">
  <div class="cards-metrics">
    <div class="card metric">
      <div class="metric-top">
        <div><div class="metric-label">Abertos</div><div class="metric-value"><?= $dash ? (int) $dash['ch_abertos'] : count(array_filter($MOCK_CHAMADOS, fn ($c) => ($c['status'] ?? '') === 'Aberto')) ?></div></div>
        <div class="icon-box">AB</div>
      </div>
      <div class="metric-change metric-change--admin"><?= $dash ? 'Ao vivo' : 'Mock' ?></div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div><div class="metric-label">Em andamento</div><div class="metric-value"><?= $dash ? (int) $dash['ch_andamento'] : count(array_filter($MOCK_CHAMADOS, fn ($c) => ($c['status'] ?? '') === 'Em andamento')) ?></div></div>
        <div class="icon-box">EA</div>
      </div>
      <div class="metric-change metric-change--admin">Em atendimento</div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div><div class="metric-label">Urgentes</div><div class="metric-value"><?= $dash ? (int) $dash['ch_urgentes'] : count(array_filter($MOCK_CHAMADOS, fn ($c) => in_array($c['prioridade'] ?? '', ['Alta', 'Urgente'], true))) ?></div></div>
        <div class="icon-box">!!</div>
      </div>
      <div class="metric-change metric-change--admin">Prioridade alta</div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div><div class="metric-label">Resolvidos 7d</div><div class="metric-value"><?= $dash ? (int) $dash['ch_resolvidos_7d'] : count(array_filter($MOCK_CHAMADOS, fn ($c) => in_array($c['status'] ?? '', ['Resolvido', 'Fechado'], true))) ?></div></div>
        <div class="icon-box">OK</div>
      </div>
      <div class="metric-change metric-change--admin"><?= $dash ? 'Últimos 7 dias' : 'Todos (mock)' ?></div>
    </div>
  </div>
  </div>

  <div class="card">
    <div class="panel-head" style="flex-wrap:wrap;gap:12px;">
      <div style="flex:1;min-width:0;">
        <h4 style="margin:0;">Todos os chamados</h4>
        <span class="panel-sub"><?= (int) $totalRows ?> registro(s)</span>
      </div>
      <?php if (db_ok()): ?>
      <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
        <span class="muted" style="font-size:12px;">Exportar:</span>
        <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars(adm_chamados_export_url('csv', $f, $q, $admChPeriodoCtx)) ?>">CSV (Excel)</a>
        <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars(adm_chamados_export_url('json', $f, $q, $admChPeriodoCtx)) ?>">JSON</a>
      </div>
      <?php endif; ?>
    </div>

    <div class="filters" style="padding:12px 20px;border-bottom:1px solid var(--border);display:grid;gap:12px;">
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <?php
          $qsPeriodoLimpar = array_filter([
              'periodo_limpar' => '1',
              'cliente_id'     => $clienteIdListagem > 0 ? $clienteIdListagem : null,
              'envolvido_user' => $envolvidoUser > 0 ? $envolvidoUser : null,
          ], static fn ($v) => $v !== null && $v !== '');
          $qsMesAtual = array_filter([
              'cliente_id'     => $clienteIdListagem > 0 ? $clienteIdListagem : null,
              'envolvido_user' => $envolvidoUser > 0 ? $envolvidoUser : null,
          ], static fn ($v) => $v !== null && $v !== '');
          $urlPeriodoLimpar = 'chamados.php' . ($qsPeriodoLimpar !== [] ? '?' . http_build_query($qsPeriodoLimpar) : '');
          $urlMesAtual      = 'chamados.php' . ($qsMesAtual !== [] ? '?' . http_build_query($qsMesAtual) : '');
        ?>
        <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($urlPeriodoLimpar) ?>">Todo o período</a>
        <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($urlMesAtual) ?>">Mês atual</a>
      </div>

      <form method="get" action="chamados.php" style="display:grid;grid-template-columns:repeat(2,minmax(150px,180px)) minmax(220px,1fr) auto auto;gap:12px;align-items:end;">
        <?php if ($f !== ''): ?><input type="hidden" name="f" value="<?= htmlspecialchars($f) ?>"><?php endif; ?>
        <?php if ($clienteIdListagem > 0): ?><input type="hidden" name="cliente_id" value="<?= (int) $clienteIdListagem ?>"><?php endif; ?>
        <?php if ($envolvidoUser > 0): ?><input type="hidden" name="envolvido_user" value="<?= (int) $envolvidoUser ?>"><?php endif; ?>
        <div class="form-group" style="margin:0;">
          <label for="periodo_de" style="font-size:12px;">De</label>
          <input type="date" id="periodo_de" name="periodo_de" class="input" value="<?= $periodoLimpar ? '' : htmlspecialchars((string) $periodoDe) ?>">
        </div>
        <div class="form-group" style="margin:0;">
          <label for="periodo_ate" style="font-size:12px;">Até</label>
          <input type="date" id="periodo_ate" name="periodo_ate" class="input" value="<?= $periodoLimpar ? '' : htmlspecialchars((string) $periodoAte) ?>">
        </div>
        <div class="form-group" style="margin:0;min-width:0;">
          <label for="q" style="font-size:12px;">Buscar</label>
          <input type="search" id="q" name="q" class="input" value="<?= htmlspecialchars($q) ?>" placeholder="ID, título ou órgão">
        </div>
        <button type="submit" class="btn btn-primary" style="justify-content:center;">Aplicar filtros</button>
        <?php if ($q !== ''): ?>
        <a href="<?= htmlspecialchars(adm_chamados_url(1, $f, '', $admChPeriodoCtx)) ?>" class="btn">Limpar</a>
        <?php endif; ?>
      </form>

      <div style="display:flex;flex-wrap:wrap;gap:8px;">
        <a href="<?= htmlspecialchars(adm_chamados_url(1, '', $q, $admChPeriodoCtx)) ?>" class="chip <?= $f === '' ? 'active' : '' ?>">Todos</a>
        <a href="<?= htmlspecialchars(adm_chamados_url(1, 'abertos', $q, $admChPeriodoCtx)) ?>" class="chip <?= $f === 'abertos' ? 'active' : '' ?>">Abertos</a>
        <a href="<?= htmlspecialchars(adm_chamados_url(1, 'andamento', $q, $admChPeriodoCtx)) ?>" class="chip <?= $f === 'andamento' ? 'active' : '' ?>">Em andamento</a>
        <a href="<?= htmlspecialchars(adm_chamados_url(1, 'aguardando', $q, $admChPeriodoCtx)) ?>" class="chip <?= $f === 'aguardando' ? 'active' : '' ?>">Aguardando</a>
        <a href="<?= htmlspecialchars(adm_chamados_url(1, 'resolvidos', $q, $admChPeriodoCtx)) ?>" class="chip <?= $f === 'resolvidos' ? 'active' : '' ?>">Resolvidos</a>
        <a href="<?= htmlspecialchars(adm_chamados_url(1, 'cancelados', $q, $admChPeriodoCtx)) ?>" class="chip <?= $f === 'cancelados' ? 'active' : '' ?>">Cancelados</a>
        <a href="<?= htmlspecialchars(adm_chamados_url(1, 'urgentes', $q, $admChPeriodoCtx)) ?>" class="chip <?= $f === 'urgentes' ? 'active' : '' ?>">Urgentes</a>
      </div>
    </div>

    <div class="table-wrap">
      <table style="min-width:780px;">
        <thead>
          <tr>
            <th>ID</th>
            <th>Título</th>
            <th>Prioridade</th>
            <th>Status</th>
            <th>Data</th>
            <th class="text-right">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($lista)): ?>
          <tr><td colspan="6" class="muted" style="padding:24px;text-align:center;">Nenhum chamado encontrado.</td></tr>
          <?php else: ?>
          <?php foreach ($lista as $c): ?>
          <?php $isBm = !empty($c['medicao_bm']); ?>
          <tr>
            <td class="td-id"><?= $isBm ? 'BM-' . (int) ($c['medicao_bm_linha_id'] ?? 0) : '#' . (int) $c['id'] ?></td>
            <td>
              <div class="td-title"><?= htmlspecialchars((string) ($c['titulo'] ?? '')) ?></div>
              <?php if ($isBm && !empty($c['medicao_bm_resumo'])): ?>
              <small class="muted"><?= htmlspecialchars((string) $c['medicao_bm_resumo']) ?></small>
              <?php else: ?>
              <small class="muted">Técnico: <?= htmlspecialchars((string) ($c['tecnico_nome'] ?? $c['responsavel'] ?? '—')) ?></small>
              <?php endif; ?>
            </td>
            <td><span class="badge <?= status_class((string) ($c['prioridade'] ?? '')) ?>"><?= htmlspecialchars((string) ($c['prioridade'] ?? '')) ?></span></td>
            <td>
              <span class="badge <?= status_class((string) ($c['status'] ?? '')) ?>"><?= htmlspecialchars((string) ($c['status'] ?? '')) ?></span>
              <?php if (!$isBm && !empty($c['finalizado_operador_em']) && empty($c['aprovado_gestor_em'])): ?>
                <small class="muted" style="display:block;margin-top:4px;">Aguardando aprovação</small>
              <?php endif; ?>
            </td>
            <td class="td-mute"><?= date('d/m/Y H:i', strtotime((string) ($c['data'] ?? 'now'))) ?></td>
            <td class="td-actions">
              <?php if ($isBm): ?>
              <a class="action primary" href="<?= htmlspecialchars('medicao_ver.php?' . http_build_query(['mes' => (string) ($c['medicao_bm_ref_ym'] ?? $refYmBm)])) ?>">Ver chamado</a>
              <?php else: ?>
              <a class="action primary" href="chamado_detalhe.php?id=<?= (int) $c['id'] ?>">Ver</a>
              <?php if (db_ok()): ?>
              <form method="post" action="chamados.php" style="display:inline;" data-confirm="Excluir chamado #<?= (int) $c['id'] ?> e todo o histórico?" data-confirm-danger>
                <input type="hidden" name="acao" value="excluir">
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <?php if ($clienteIdListagem > 0): ?><input type="hidden" name="cliente_id_ctx" value="<?= (int) $clienteIdListagem ?>"><?php endif; ?>
                <?php if ($envolvidoUser > 0): ?><input type="hidden" name="envolvido_user_ctx" value="<?= (int) $envolvidoUser ?>"><?php endif; ?>
                <?php if ($periodoLimpar): ?><input type="hidden" name="periodo_limpar" value="1"><?php elseif ($medicaoMes !== ''): ?><input type="hidden" name="medicao_mes" value="<?= htmlspecialchars($medicaoMes) ?>"><?php else: ?><input type="hidden" name="periodo_de" value="<?= htmlspecialchars((string) $periodoDe) ?>"><input type="hidden" name="periodo_ate" value="<?= htmlspecialchars((string) $periodoAte) ?>"><?php endif; ?>
                <button type="submit" class="action danger" style="background:none;border:none;cursor:pointer;padding:0;font:inherit;">Excluir</button>
              </form>
              <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <span class="pag-info">Mostrando <?= (int) $fromN ?>–<?= (int) $toN ?> de <?= (int) $totalRows ?></span>
      <div class="pag-controls">
        <?php if ($page <= 1): ?>
          <span class="pag-btn" style="opacity:.4;">‹</span>
        <?php else: ?>
          <a class="pag-btn" href="<?= htmlspecialchars(adm_chamados_url($page - 1, $f, $q, $admChPeriodoCtx)) ?>">‹</a>
        <?php endif; ?>
        <?php
        $window = 5;
        $start = max(1, $page - (int) floor($window / 2));
        $end   = min($totalPages, $start + $window - 1);
        $start = max(1, $end - $window + 1);
        for ($pi = $start; $pi <= $end; $pi++):
        ?>
          <?php if ($pi === $page): ?>
            <span class="pag-btn active"><?= $pi ?></span>
          <?php else: ?>
            <a class="pag-btn" href="<?= htmlspecialchars(adm_chamados_url($pi, $f, $q, $admChPeriodoCtx)) ?>"><?= $pi ?></a>
          <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page >= $totalPages): ?>
          <span class="pag-btn" style="opacity:.4;">›</span>
        <?php else: ?>
          <a class="pag-btn" href="<?= htmlspecialchars(adm_chamados_url($page + 1, $f, $q, $admChPeriodoCtx)) ?>">›</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
