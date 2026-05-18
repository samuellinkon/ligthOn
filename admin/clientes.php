<?php
require_once __DIR__ . '/../includes/auth.php';
$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('clientes');
$escopoGestor = gestao_scope_cliente_id($me);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir_cliente') {
    $delId = (int) ($_POST['cliente_id'] ?? 0);
    if (!db_ok()) {
        flash_set('err', 'Exclusão requer banco de dados ativo.');
    } elseif ($delId <= 0) {
        flash_set('err', 'Cadastro inválido.');
    } elseif ($escopoGestor !== null && !repo_cliente_pertence_empresa($delId, $escopoGestor)) {
        flash_set('err', 'Cadastro fora da sua empresa de gestão.');
    } else {
        $cliDel = repo_cliente($delId);
        if (!$cliDel) {
            flash_set('err', 'Cadastro não encontrado.');
        } else {
            try {
                if (repo_delete_cliente($delId)) {
                    flash_set('ok', 'Cadastro excluído.');
                } else {
                    flash_set('err', 'Não foi possível excluir o cadastro.');
                }
            } catch (Throwable $e) {
                flash_set('err', 'Não foi possível excluir o cadastro. Pode haver registros vinculados (chamados, usuários etc.).');
            }
        }
    }
    $stR = trim((string) ($_POST['redir_status'] ?? ''));
    if ($stR !== '' && !in_array($stR, ['Ativo', 'Pendente', 'Fechado'], true)) {
        $stR = '';
    }
    $qR  = trim((string) ($_POST['redir_q'] ?? ''));
    $pgR = max(1, (int) ($_POST['redir_page'] ?? 1));
    $qs  = [];
    if ($stR !== '') {
        $qs['status'] = $stR;
    }
    if ($qR !== '') {
        $qs['q'] = $qR;
    }
    if ($pgR > 1) {
        $qs['page'] = $pgR;
    }
    if (!empty($_POST['redir_listar'])) {
        $qs['listar'] = '1';
    }
    header('Location: clientes.php' . ($qs !== [] ? '?' . http_build_query($qs) : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && db_ok() && empty($_GET['listar'])) {
    $unicoId = null;
    if ($escopoGestor !== null) {
        foreach (repo_clientes_na_empresa($escopoGestor) as $cliEmpresa) {
            if ((int) ($cliEmpresa['id'] ?? 0) !== $escopoGestor) {
                $unicoId = (int) $cliEmpresa['id'];
                break;
            }
        }
        $unicoId = $unicoId ?: $escopoGestor;
    } else {
        $unicoId = repo_catalogo_cliente_id_padrao_admin() ?? repo_cliente_raiz_principal_id();
    }
    if ($unicoId !== null && $unicoId > 0) {
        header('Location: cliente_detalhe.php?id=' . $unicoId);
        exit;
    }
}

/**
 * @return list<int>
 */
function admin_pagination_visible_pages(int $current, int $lastPage): array
{
    if ($lastPage <= 1) {
        return [];
    }
    if ($lastPage <= 9) {
        return range(1, $lastPage);
    }
    $set = [1, $lastPage];
    for ($i = max(2, $current - 2); $i <= min($lastPage - 1, $current + 2); $i++) {
        $set[] = $i;
    }
    $set = array_values(array_unique($set));
    sort($set, SORT_NUMERIC);
    $out = [];
    $prev = 0;
    foreach ($set as $p) {
        if ($prev > 0 && $p - $prev > 1) {
            $out[] = 0;
        }
        $out[] = $p;
        $prev = $p;
    }

    return $out;
}

$pageTitle  = 'Prefeitura';
$basePath   = '../';
$activePage = 'clientes';

$statusFil = (string) ($_GET['status'] ?? '');
if ($statusFil !== '' && !in_array($statusFil, ['Ativo', 'Pendente', 'Fechado'], true)) {
    $statusFil = '';
}
$qBusca = trim((string) ($_GET['q'] ?? ''));
$listarModo = !empty($_GET['listar']);

$todosCli = $MOCK_CLIENTES;
if ($escopoGestor !== null) {
    $todosCli = array_values(array_filter($todosCli, function ($c) use ($escopoGestor) {
        $id = (int) ($c['id'] ?? 0);
        if ($id <= 0 || !repo_cliente_pertence_empresa($id, $escopoGestor)) {
            return false;
        }

        return $id !== $escopoGestor || empty(array_filter(repo_clientes_na_empresa($escopoGestor), function ($cli) use ($escopoGestor) {
            return (int) ($cli['id'] ?? 0) !== $escopoGestor;
        }));
    }));
}
if ($statusFil !== '') {
    $todosCli = array_values(array_filter($todosCli, fn ($c) => ($c['status'] ?? '') === $statusFil));
}
if ($qBusca !== '') {
    $ql = mb_strtolower($qBusca);
    $todosCli = array_values(array_filter($todosCli, function ($c) use ($ql) {
        $hay = mb_strtolower(
            ($c['nome'] ?? '') . ' ' . ($c['empresa'] ?? '') . ' ' . ($c['email'] ?? '') . ' ' . ($c['telefone'] ?? '')
        );

        return mb_strpos($hay, $ql) !== false;
    }));
}

$perPage        = 10;
$totalClientes  = count($todosCli);
$totalPages     = max(1, (int) ceil($totalClientes / $perPage));
$page           = max(1, (int) ($_GET['page'] ?? 1));
$page           = min($page, $totalPages);
$offset         = ($page - 1) * $perPage;
$listaClientes  = array_slice($todosCli, $offset, $perPage);
$paginasVisiveis = admin_pagination_visible_pages($page, $totalPages);

if ($totalClientes === 0) {
    $mostrarDe = $mostrarAte = 0;
} else {
    $mostrarDe  = $offset + 1;
    $mostrarAte = $offset + count($listaClientes);
}

function admin_clientes_url(int $p, string $st, string $q, bool $listar = false): string
{
    $qs = [];
    if ($st !== '') {
        $qs['status'] = $st;
    }
    if ($q !== '') {
        $qs['q'] = $q;
    }
    if ($p > 1) {
        $qs['page'] = $p;
    }
    if ($listar) {
        $qs['listar'] = '1';
    }

    return 'clientes.php' . ($qs ? '?' . http_build_query($qs) : '');
}

$totalGeral = count($MOCK_CLIENTES);
$ativos     = count(array_filter($MOCK_CLIENTES, fn ($c) => ($c['status'] ?? '') === 'Ativo'));
$pendencias = count(array_filter($MOCK_CLIENTES, fn ($c) => ((int) ($c['pendentes'] ?? 0)) > 0));
$fechados   = count(array_filter($MOCK_CLIENTES, fn ($c) => ($c['status'] ?? '') === 'Fechado'));

$topTitle    = 'Prefeitura';
$topSubtitle = 'Cadastro do órgão atendido (prefeitura), contatos e status.';
$topSearch   = 'Buscar por nome, órgão ou e-mail...';
$topAction   = ['label' => 'Novo cadastro', 'href' => 'cliente_novo.php', 'icon' => '+'];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <div class="cards-metrics">
    <div class="card metric">
      <div class="metric-top">
        <div><div class="metric-label">Cadastros</div><div class="metric-value"><?= $totalGeral ?></div></div>
        <div class="icon-box purple">CL</div>
      </div>
      <div class="metric-change success">Base cadastrada</div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div><div class="metric-label">Ativos</div><div class="metric-value"><?= $ativos ?></div></div>
        <div class="icon-box green">OK</div>
      </div>
      <div class="metric-change success">Em operação</div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div><div class="metric-label">Com pendências</div><div class="metric-value"><?= $pendencias ?></div></div>
        <div class="icon-box orange">!</div>
      </div>
      <div class="metric-change warning">Chamados em aberto</div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div><div class="metric-label">Fechados</div><div class="metric-value"><?= $fechados ?></div></div>
        <div class="icon-box red">X</div>
      </div>
      <div class="metric-change muted">Arquivo</div>
    </div>
  </div>

  <div class="card">
    <div class="panel-head">
      <h4>Listagem</h4>
      <span class="panel-sub"><?= $totalClientes ?> registro(s) no filtro · <?= $totalGeral ?> no total</span>
    </div>

    <form method="get" action="clientes.php" class="filters filters--form">
      <?php if ($listarModo): ?><input type="hidden" name="listar" value="1"><?php endif; ?>
      <?php if ($statusFil !== ''): ?><input type="hidden" name="status" value="<?= htmlspecialchars($statusFil) ?>"><?php endif; ?>
      <div class="form-group form-group--grow">
        <label for="q">Buscar</label>
        <input type="search" id="q" name="q" class="input" value="<?= htmlspecialchars($qBusca) ?>" placeholder="Nome, empresa, e-mail ou telefone">
      </div>
      <div class="filters-form-actions">
        <button type="submit" class="btn btn-primary">Filtrar</button>
        <?php if ($qBusca !== '' || $statusFil !== ''): ?>
        <a href="clientes.php" class="btn btn-secondary">Limpar</a>
        <?php endif; ?>
      </div>
    </form>

    <div class="filters" style="padding:12px 20px;display:flex;flex-wrap:wrap;gap:8px;">
      <a href="<?= htmlspecialchars(admin_clientes_url(1, '', $qBusca, $listarModo)) ?>" class="chip <?= $statusFil === '' ? 'active' : '' ?>">Todos</a>
      <a href="<?= htmlspecialchars(admin_clientes_url(1, 'Ativo', $qBusca, $listarModo)) ?>" class="chip <?= $statusFil === 'Ativo' ? 'active' : '' ?>">Ativos</a>
      <a href="<?= htmlspecialchars(admin_clientes_url(1, 'Pendente', $qBusca, $listarModo)) ?>" class="chip <?= $statusFil === 'Pendente' ? 'active' : '' ?>">Pendentes</a>
      <a href="<?= htmlspecialchars(admin_clientes_url(1, 'Fechado', $qBusca, $listarModo)) ?>" class="chip <?= $statusFil === 'Fechado' ? 'active' : '' ?>">Fechados</a>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Órgão</th>
            <th>Contato</th>
            <th>Chamados</th>
            <th>Status</th>
            <th class="text-right">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($listaClientes as $c): ?>
          <tr>
            <td>
              <a href="cliente_detalhe.php?id=<?= (int) $c['id'] ?>" style="text-decoration:none; color:inherit;">
                <div class="cell-client">
                  <div class="avatar avatar-sm"><?= initials($c['empresa']) ?></div>
                  <div>
                    <strong><?= htmlspecialchars($c['empresa']) ?></strong>
                    <small><?= htmlspecialchars($c['nome']) ?></small>
                  </div>
                </div>
              </a>
            </td>
            <td class="td-mute">
              <?= htmlspecialchars($c['email']) ?><br>
              <small><?= htmlspecialchars($c['telefone']) ?></small>
            </td>
            <td><strong><?= (int) ($c['chamados'] ?? 0) ?></strong> <small class="muted">· <?= (int) ($c['pendentes'] ?? 0) ?> pendentes</small></td>
            <td><span class="badge <?= status_class($c['status']) ?>"><?= htmlspecialchars($c['status']) ?></span></td>
            <td class="td-actions">
              <a class="action primary" href="cliente_detalhe.php?id=<?= (int) $c['id'] ?>">Ver</a>
              <a class="action" href="cliente_editar.php?id=<?= (int) $c['id'] ?>">Editar</a>
              <a class="action" href="cliente_anexos.php?id=<?= (int) $c['id'] ?>">Anexos</a>
              <?php if (db_ok()): ?>
              <form method="post" action="clientes.php" style="display:inline;margin:0;vertical-align:middle;" data-confirm="Excluir o cadastro &quot;<?= htmlspecialchars((string) ($c['empresa'] ?? $c['nome'] ?? ''), ENT_QUOTES) ?>&quot; e todos os dados vinculados? Esta ação não pode ser desfeita." data-confirm-danger>
                <input type="hidden" name="acao" value="excluir_cliente">
                <input type="hidden" name="cliente_id" value="<?= (int) $c['id'] ?>">
                <input type="hidden" name="redir_status" value="<?= htmlspecialchars($statusFil) ?>">
                <input type="hidden" name="redir_q" value="<?= htmlspecialchars($qBusca) ?>">
                <input type="hidden" name="redir_page" value="<?= (int) $page ?>">
                <?php if ($listarModo): ?><input type="hidden" name="redir_listar" value="1"><?php endif; ?>
                <button type="submit" class="action danger" title="Excluir cadastro" aria-label="Excluir cadastro" style="display:inline-flex;align-items:center;justify-content:center;padding:6px 8px;">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                </button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="pagination">
      <span class="pag-info">Mostrando <?= (int) $mostrarDe ?>–<?= (int) $mostrarAte ?> de <?= (int) $totalClientes ?></span>
      <?php if ($totalPages > 1): ?>
      <div class="pag-controls">
        <?php if ($page <= 1): ?>
          <span class="pag-btn pag-btn--static" aria-hidden="true">‹</span>
        <?php else: ?>
          <a class="pag-btn" href="<?= htmlspecialchars(admin_clientes_url($page - 1, $statusFil, $qBusca, $listarModo)) ?>" aria-label="Página anterior">‹</a>
        <?php endif; ?>

        <?php foreach ($paginasVisiveis as $pv): ?>
          <?php if ($pv === 0): ?>
            <span class="pag-ellipsis" aria-hidden="true">…</span>
          <?php elseif ($pv === $page): ?>
            <span class="pag-btn active" aria-current="page"><?= (int) $pv ?></span>
          <?php else: ?>
            <a class="pag-btn" href="<?= htmlspecialchars(admin_clientes_url((int) $pv, $statusFil, $qBusca, $listarModo)) ?>"><?= (int) $pv ?></a>
          <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($page >= $totalPages): ?>
          <span class="pag-btn pag-btn--static" aria-hidden="true">›</span>
        <?php else: ?>
          <a class="pag-btn" href="<?= htmlspecialchars(admin_clientes_url($page + 1, $statusFil, $qBusca, $listarModo)) ?>" aria-label="Próxima página">›</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
