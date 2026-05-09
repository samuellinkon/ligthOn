<?php
require_once __DIR__ . '/../includes/auth.php';
require_auth('cliente');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_cliente('chamados');

require_once __DIR__ . '/../includes/medicao_helpers.php';

$pageTitle  = 'Meus Chamados';
$basePath   = '../';
$activePage = 'chamados';

$user      = current_user() ?: $MOCK_USER_CLIENTE;
$clienteId = (int) ($user['cliente_id'] ?? 0);

$medicaoMes = trim((string) ($_GET['medicao_mes'] ?? ''));
$medicaoMesDe = null;
$medicaoMesAte = null;
if (preg_match('/^\d{4}-\d{2}$/', $medicaoMes)) {
    $medicaoMesDe = $medicaoMes . '-01';
    $medicaoMesAte = date('Y-m-t', strtotime($medicaoMesDe));
} else {
    $medicaoMes = '';
}

$abertoDeRaw  = trim((string) ($_GET['aberto_de'] ?? ''));
$abertoAteRaw = trim((string) ($_GET['aberto_ate'] ?? ''));
$dataAbertaDe  = null;
$dataAbertaAte = null;
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $abertoDeRaw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $abertoAteRaw) && $abertoDeRaw <= $abertoAteRaw) {
    $dataAbertaDe  = $abertoDeRaw;
    $dataAbertaAte = $abertoAteRaw;
} elseif ($medicaoMesDe !== null && $medicaoMesAte !== null) {
    $dataAbertaDe  = $medicaoMesDe;
    $dataAbertaAte = $medicaoMesAte;
}

$abertoDeInput  = $abertoDeRaw !== '' ? $abertoDeRaw : ($medicaoMesDe ?? '');
$abertoAteInput = $abertoAteRaw !== '' ? $abertoAteRaw : ($medicaoMesAte ?? '');

$qsAbDe = '';
$qsAbAte = '';
if ($abertoDeRaw !== '' && $abertoAteRaw !== ''
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $abertoDeRaw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $abertoAteRaw)
    && $abertoDeRaw <= $abertoAteRaw) {
    $qsAbDe  = $abertoDeRaw;
    $qsAbAte = $abertoAteRaw;
}
$hojeData = date('Y-m-d');
$semanaDe = date('Y-m-d', strtotime('monday this week'));
$semanaAte = date('Y-m-d', strtotime('sunday this week'));
$mesDe = date('Y-m-01');
$mesAte = date('Y-m-t');

$perPage = 12;
$filtro  = strtolower(trim((string) ($_GET['f'] ?? '')));
if (!in_array($filtro, ['', 'andamento', 'aguardando', 'resolvido'], true)) {
    $filtro = '';
}
$q    = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$ordem = strtolower(trim((string) ($_GET['ordem'] ?? 'recentes')));
if (!in_array($ordem, ['recentes', 'antigos', 'status', 'prioridade'], true)) {
    $ordem = 'recentes';
}

$matrizId = 0;
$matrizNome = '';
if (db_ok() && $clienteId > 0) {
    $matrizId = repo_cliente_matriz_raiz_id($clienteId);
    if ($matrizId > 0) {
        $cm = repo_cliente($matrizId);
        $matrizNome = (string) ($cm['empresa'] ?? '');
    }
}

$bmLista = [];
if ($matrizId > 0 && $filtro === '' && db_ok() && $clienteId > 0) {
    if ($medicaoMes !== '') {
        $impPkg = repo_medicao_import_fetch($matrizId, $medicaoMes);
        $bmLista = medicao_import_linhas_para_chamados_listagem(
            is_array($impPkg['linhas'] ?? null) ? $impPkg['linhas'] : [],
            $medicaoMes,
            $matrizNome,
            $q
        );
    } else {
        $impLinhasTodas = repo_medicao_import_linhas_fetch_all($matrizId, 24);
        $porMes = [];
        foreach ($impLinhasTodas as $linhaBm) {
            $ym = (string) ($linhaBm['ref_ym'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
                continue;
            }
            $porMes[$ym][] = $linhaBm;
        }
        foreach ($porMes as $ym => $linhasMes) {
            $bmLista = array_merge(
                $bmLista,
                medicao_import_linhas_para_chamados_listagem($linhasMes, (string) $ym, $matrizNome, $q)
            );
        }
    }
}
$nBm = count($bmLista);

function cliente_chamados_ordenar_rows(array &$rows, string $ordem): void
{
    $statusPeso = ['Aberto' => 1, 'Em andamento' => 2, 'Aguardando' => 3, 'Resolvido' => 4, 'Fechado' => 5];
    $prioridadePeso = ['Urgente' => 1, 'Alta' => 2, 'Normal' => 3, 'Baixa' => 4];
    usort($rows, function ($a, $b) use ($ordem, $statusPeso, $prioridadePeso) {
        $aData = strtotime((string) ($a['data'] ?? '')) ?: 0;
        $bData = strtotime((string) ($b['data'] ?? '')) ?: 0;
        if ($ordem === 'antigos') {
            return $aData <=> $bData ?: ((int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0));
        }
        if ($ordem === 'status') {
            return ($statusPeso[(string) ($a['status'] ?? '')] ?? 99) <=> ($statusPeso[(string) ($b['status'] ?? '')] ?? 99)
                ?: $bData <=> $aData;
        }
        if ($ordem === 'prioridade') {
            return ($prioridadePeso[(string) ($a['prioridade'] ?? '')] ?? 99) <=> ($prioridadePeso[(string) ($b['prioridade'] ?? '')] ?? 99)
                ?: $bData <=> $aData;
        }

        return $bData <=> $aData ?: ((int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0));
    });
}

if (db_ok() && $clienteId > 0) {
    if ($nBm > 0 && $filtro === '') {
        $resT      = repo_chamados_portal_list($clienteId, $filtro, $q, 1, 1, $dataAbertaDe, $dataAbertaAte, null, $ordem);
        $totalCh   = $resT['total'];
        $totalRows = $nBm + $totalCh;
        $totalPagesCalc = max(1, (int) ceil($totalRows / $perPage));
        $page            = min(max(1, $page), $totalPagesCalc);
        $chamadosRows = [];
        if ($totalCh > 0) {
            $resCh = repo_chamados_portal_list($clienteId, $filtro, $q, 1, $totalCh, $dataAbertaDe, $dataAbertaAte, null, $ordem);
            $chamadosRows = $resCh['rows'];
        }
        $todos = array_merge($bmLista, $chamadosRows);
        cliente_chamados_ordenar_rows($todos, $ordem);
        $meus = array_slice($todos, ($page - 1) * $perPage, $perPage);
    } else {
        $res       = repo_chamados_portal_list($clienteId, $filtro, $q, $page, $perPage, $dataAbertaDe, $dataAbertaAte, null, $ordem);
        $meus      = $res['rows'];
        $totalRows = $res['total'];
    }
} else {
    $all = array_values(array_filter($MOCK_CHAMADOS, fn ($c) => (int) ($c['cliente_id'] ?? 0) === $clienteId));
    if (count($all) === 0) {
        $all = $MOCK_CHAMADOS;
    }
    if ($filtro === 'andamento') {
        $all = array_values(array_filter($all, fn ($c) => in_array($c['status'] ?? '', ['Aberto', 'Em andamento'], true)));
    } elseif ($filtro === 'aguardando') {
        $all = array_values(array_filter($all, fn ($c) => ($c['status'] ?? '') === 'Aguardando'));
    } elseif ($filtro === 'resolvido') {
        $all = array_values(array_filter($all, fn ($c) => in_array($c['status'] ?? '', ['Resolvido', 'Fechado'], true)));
    }
    if ($q !== '') {
        if (ctype_digit($q)) {
            $all = array_values(array_filter($all, fn ($c) => (int) ($c['id'] ?? 0) === (int) $q));
        } else {
            $ql  = mb_strtolower($q);
            $all = array_values(array_filter($all, function ($c) use ($ql) {
                $t = mb_strtolower(($c['titulo'] ?? '') . ' ' . ($c['descricao'] ?? ''));

                return mb_strpos($t, $ql) !== false;
            }));
        }
    }
    cliente_chamados_ordenar_rows($all, $ordem);
    $totalRows  = count($all);
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    $page       = min($page, $totalPages);
    $offset     = ($page - 1) * $perPage;
    $meus       = array_slice($all, $offset, $perPage);
}

$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page       = min($page, $totalPages);

$fromN = $totalRows === 0 ? 0 : ($page - 1) * $perPage + 1;
$toN   = $totalRows === 0 ? 0 : min(($page - 1) * $perPage + count($meus), $totalRows);

function cliente_chamados_url(int $p, string $fil, string $busca, string $medicaoMesParam = '', string $abDe = '', string $abAte = '', string $ordemParam = 'recentes'): string
{
    $qs = [];
    if ($fil !== '') {
        $qs['f'] = $fil;
    }
    if ($busca !== '') {
        $qs['q'] = $busca;
    }
    if ($p > 1) {
        $qs['page'] = $p;
    }
    if ($medicaoMesParam !== '') {
        $qs['medicao_mes'] = $medicaoMesParam;
    }
    if ($abDe !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $abDe)) {
        $qs['aberto_de'] = $abDe;
    }
    if ($abAte !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $abAte)) {
        $qs['aberto_ate'] = $abAte;
    }
    if ($ordemParam !== 'recentes' && in_array($ordemParam, ['antigos', 'status', 'prioridade'], true)) {
        $qs['ordem'] = $ordemParam;
    }

    return 'chamados.php' . ($qs ? '?' . http_build_query($qs) : '');
}

$topTitle    = 'Meus Chamados';
$topSubtitle = 'Histórico completo dos seus tickets.';
$topSearch   = 'Buscar por título...';
$topAction   = ['label' => 'Abrir chamado', 'href' => 'chamado_novo.php', 'icon' => '+'];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-cliente.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <?php if ($medicaoMes !== ''): ?>
  <div class="card" style="margin-bottom:18px;border-left:4px solid var(--primary);">
    <div class="panel-body" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;">
      <div>
        <strong>Medição</strong>
        <span class="muted" style="margin-left:8px;">Chamados abertos em <?= htmlspecialchars(medicao_mes_label_pt($medicaoMes)) ?><?= $nBm > 0 && $filtro === '' ? ' · linhas da importação BM listadas abaixo.' : '' ?></span>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars('medicao_mes.php?' . http_build_query(['mes' => $medicaoMes])) ?>">Boletim do mês</a>
        <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars(cliente_chamados_url(1, '', '', '', '', '')) ?>">Limpar filtro</a>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <div class="card">
    <div class="panel-head">
      <h4>Chamados abertos e histórico</h4>
      <span class="panel-sub"><?= (int) $totalRows ?> chamado(s)</span>
    </div>

    <form class="filters" method="get" action="chamados.php" style="padding:12px 20px;border-bottom:1px solid var(--border);display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
      <?php if ($medicaoMes !== ''): ?><input type="hidden" name="medicao_mes" value="<?= htmlspecialchars($medicaoMes) ?>"><?php endif; ?>
      <?php if ($filtro !== ''): ?><input type="hidden" name="f" value="<?= htmlspecialchars($filtro) ?>"><?php endif; ?>
      <div class="form-group" style="margin:0;flex:1;min-width:200px;">
        <label for="q" style="font-size:12px;">Buscar</label>
        <input type="search" id="q" name="q" class="input" value="<?= htmlspecialchars($q) ?>" placeholder="ID ou palavra no assunto">
      </div>
      <div class="form-group" style="margin:0;min-width:150px;">
        <label for="aberto_de" style="font-size:12px;">Aberto de</label>
        <input type="date" id="aberto_de" name="aberto_de" class="input" value="<?= htmlspecialchars($abertoDeInput) ?>">
      </div>
      <div class="form-group" style="margin:0;min-width:150px;">
        <label for="aberto_ate" style="font-size:12px;">Aberto até</label>
        <input type="date" id="aberto_ate" name="aberto_ate" class="input" value="<?= htmlspecialchars($abertoAteInput) ?>">
      </div>
      <div class="form-group" style="margin:0;min-width:170px;">
        <label for="ordem" style="font-size:12px;">Ordenar por</label>
        <select id="ordem" name="ordem" class="select" onchange="this.form.submit()">
          <option value="recentes" <?= $ordem === 'recentes' ? 'selected' : '' ?>>Mais recentes</option>
          <option value="antigos" <?= $ordem === 'antigos' ? 'selected' : '' ?>>Mais antigos</option>
          <option value="status" <?= $ordem === 'status' ? 'selected' : '' ?>>Status</option>
          <option value="prioridade" <?= $ordem === 'prioridade' ? 'selected' : '' ?>>Prioridade</option>
        </select>
      </div>
      <button type="submit" class="btn btn-primary">Buscar</button>
      <?php if ($q !== '' || $qsAbDe !== ''): ?>
      <a href="<?= htmlspecialchars(cliente_chamados_url(1, $filtro, '', $medicaoMes, '', '', $ordem)) ?>" class="btn">Limpar busca e datas</a>
      <?php endif; ?>
    </form>

    <div class="filters" style="padding:12px 20px;display:flex;flex-wrap:wrap;gap:8px;">
      <a href="<?= htmlspecialchars(cliente_chamados_url(1, '', $q, $medicaoMes, $qsAbDe, $qsAbAte, $ordem)) ?>" class="chip <?= $filtro === '' ? 'active' : '' ?>">Todos</a>
      <a href="<?= htmlspecialchars(cliente_chamados_url(1, 'andamento', $q, $medicaoMes, $qsAbDe, $qsAbAte, $ordem)) ?>" class="chip <?= $filtro === 'andamento' ? 'active' : '' ?>">Em andamento</a>
      <a href="<?= htmlspecialchars(cliente_chamados_url(1, 'aguardando', $q, $medicaoMes, $qsAbDe, $qsAbAte, $ordem)) ?>" class="chip <?= $filtro === 'aguardando' ? 'active' : '' ?>">Aguardando</a>
      <a href="<?= htmlspecialchars(cliente_chamados_url(1, 'resolvido', $q, $medicaoMes, $qsAbDe, $qsAbAte, $ordem)) ?>" class="chip <?= $filtro === 'resolvido' ? 'active' : '' ?>">Resolvidos</a>
      <span class="muted" style="align-self:center;font-size:12px;margin-left:6px;">Data:</span>
      <a href="<?= htmlspecialchars(cliente_chamados_url(1, $filtro, $q, $medicaoMes, $hojeData, $hojeData, $ordem)) ?>" class="chip <?= $qsAbDe === $hojeData && $qsAbAte === $hojeData ? 'active' : '' ?>">Hoje</a>
      <a href="<?= htmlspecialchars(cliente_chamados_url(1, $filtro, $q, $medicaoMes, $semanaDe, $semanaAte, $ordem)) ?>" class="chip <?= $qsAbDe === $semanaDe && $qsAbAte === $semanaAte ? 'active' : '' ?>">Esta semana</a>
      <a href="<?= htmlspecialchars(cliente_chamados_url(1, $filtro, $q, $medicaoMes, $mesDe, $mesAte, $ordem)) ?>" class="chip <?= $qsAbDe === $mesDe && $qsAbAte === $mesAte ? 'active' : '' ?>">Este mês</a>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Assunto</th>
            <th>Prioridade</th>
            <th>Status</th>
            <th>Aberto em</th>
            <th class="text-right">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($meus)): ?>
          <tr>
            <td colspan="6" class="muted" style="padding:24px;text-align:center;">Nenhum chamado encontrado.</td>
          </tr>
          <?php else: ?>
          <?php foreach ($meus as $c): ?>
          <?php $isBm = !empty($c['medicao_bm']); ?>
          <tr>
            <td class="td-id"><?= $isBm ? 'BM-' . (int) ($c['medicao_bm_linha_id'] ?? 0) : '#' . (int) $c['id'] ?></td>
            <td class="td-title">
              <?= htmlspecialchars((string) ($c['titulo'] ?? '')) ?>
              <?php if ($isBm && !empty($c['medicao_bm_resumo'])): ?>
              <br><small class="muted"><?= htmlspecialchars((string) $c['medicao_bm_resumo']) ?></small>
              <?php endif; ?>
            </td>
            <td><span class="badge <?= status_class((string) ($c['prioridade'] ?? '')) ?>"><?= htmlspecialchars((string) ($c['prioridade'] ?? '')) ?></span></td>
            <td><span class="badge <?= status_class((string) ($c['status'] ?? '')) ?>"><?= htmlspecialchars((string) ($c['status'] ?? '')) ?></span></td>
            <td class="td-mute"><?= date('d/m/Y H:i', strtotime((string) ($c['data'] ?? 'now'))) ?></td>
            <td class="td-actions">
              <?php if ($isBm): ?>
              <a class="action primary" href="<?= htmlspecialchars('medicao_mes.php?' . http_build_query(['mes' => (string) ($c['medicao_bm_ref_ym'] ?? $medicaoMes)])) ?>">Boletim</a>
              <?php else: ?>
              <a class="action primary" href="chamado_detalhe.php?id=<?= (int) $c['id'] ?>">Ver</a>
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
          <span class="pag-btn" style="opacity:.4;pointer-events:none;">‹</span>
        <?php else: ?>
          <a class="pag-btn" href="<?= htmlspecialchars(cliente_chamados_url($page - 1, $filtro, $q, $medicaoMes, $qsAbDe, $qsAbAte, $ordem)) ?>">‹</a>
        <?php endif; ?>
        <?php
        $window = 5;
        $start  = max(1, $page - (int) floor($window / 2));
        $end    = min($totalPages, $start + $window - 1);
        $start  = max(1, $end - $window + 1);
        for ($pi = $start; $pi <= $end; $pi++):
        ?>
          <?php if ($pi === $page): ?>
            <span class="pag-btn active"><?= $pi ?></span>
          <?php else: ?>
            <a class="pag-btn" href="<?= htmlspecialchars(cliente_chamados_url($pi, $filtro, $q, $medicaoMes, $qsAbDe, $qsAbAte, $ordem)) ?>"><?= $pi ?></a>
          <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page >= $totalPages): ?>
          <span class="pag-btn" style="opacity:.4;pointer-events:none;">›</span>
        <?php else: ?>
          <a class="pag-btn" href="<?= htmlspecialchars(cliente_chamados_url($page + 1, $filtro, $q, $medicaoMes, $qsAbDe, $qsAbAte, $ordem)) ?>">›</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
