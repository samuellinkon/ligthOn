<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_auth('operador');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_operador('chamados');

$pageTitle   = 'Chamados';
$basePath    = '../';
$activePage  = 'chamados';
$operadorPwa = true;

$user       = current_user() ?: ($GLOBALS['MOCK_USER_OPERADOR'] ?? []);
$empresaId  = operador_empresa_id($user);

$perPage = 15;
$filtro  = strtolower(trim((string) ($_GET['f'] ?? '')));
if (!in_array($filtro, ['', 'andamento', 'aguardando', 'resolvido'], true)) {
    $filtro = '';
}
$q    = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));

if (db_ok() && $empresaId > 0) {
    $res       = repo_chamados_operador_list($empresaId, $filtro, $q, $page, $perPage, (int) ($user['id'] ?? 0));
    $meus      = $res['rows'];
    $totalRows = $res['total'];
} else {
    $meus      = [];
    $totalRows = 0;
}

$totalPages = max(1, (int) ceil(max(1, $totalRows) / $perPage));
$page       = min($page, $totalPages);
$fromN      = $totalRows === 0 ? 0 : ($page - 1) * $perPage + 1;
$toN        = $totalRows === 0 ? 0 : min(($page - 1) * $perPage + count($meus), $totalRows);

function operador_chamados_url(int $p, string $fil, string $busca): string
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

    return 'chamados.php' . ($qs ? '?' . http_build_query($qs) : '');
}

$topTitle    = 'Chamados';
$topSubtitle = 'Chamados atribuídos ao seu usuário';
$topSearch   = 'Buscar...';
$topAction   = ['label' => 'Início', 'href' => 'index.php', 'icon' => '←'];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-operador.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <style>
    .op-list-card{overflow:hidden}
    .op-search{padding:12px 20px;border-bottom:1px solid var(--border);display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end}
    .op-chips{padding:12px 20px;display:flex;flex-wrap:wrap;gap:8px}
    .op-call-cards{display:none}
    .op-call-card{display:block;text-decoration:none;color:inherit;border:1px solid var(--border);border-radius:18px;background:#fff;padding:14px;box-shadow:0 10px 24px rgba(15,23,42,.06)}
    .op-call-card + .op-call-card{margin-top:10px}
    .op-call-top{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}
    .op-call-id{font-size:12px;font-weight:800;color:var(--primary);letter-spacing:.04em}
    .op-call-title{font-size:15px;font-weight:800;color:var(--text);line-height:1.35;margin:0 0 8px}
    .op-call-meta{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px}
    .op-call-meta span{display:flex;flex-direction:column;gap:2px;border-radius:12px;background:#f8fafc;padding:8px;font-size:12px;color:var(--muted)}
    .op-call-meta strong{font-size:13px;color:var(--text);font-weight:800}
    .op-call-action{margin-top:12px;width:100%;justify-content:center}
    @media(max-width:720px){
      .content{padding:12px}
      .op-list-card{border-radius:18px}
      .op-list-card .panel-head{padding:16px}
      .op-search{padding:12px 16px;display:grid;grid-template-columns:1fr auto;gap:10px;align-items:end}
      .op-search .form-group{min-width:0!important}
      .op-search .input{height:52px}
      .op-search .btn{height:52px;padding:0 16px}
      .op-search .btn:not(.btn-primary){grid-column:1 / -1;height:44px}
      .op-chips{padding:12px 16px;flex-wrap:nowrap;overflow-x:auto;-webkit-overflow-scrolling:touch}
      .op-chips .chip{white-space:nowrap;flex:0 0 auto}
      .op-desktop-table{display:none}
      .op-call-cards{display:block;padding:12px 16px 16px;background:#f8fafc;border-top:1px solid var(--border)}
      .pagination{padding:12px 16px;align-items:flex-start}
      .pag-info{display:block;margin-bottom:8px}
    }
  </style>

  <div class="card op-list-card">
    <div class="panel-head">
      <h4>Lista de chamados</h4>
      <span class="panel-sub"><?= (int) $totalRows ?> registro(s)</span>
    </div>

    <?php if ($empresaId <= 0): ?>
      <p class="muted" style="padding:20px;">Acesso sem empresa vinculada. Peça ao gestor para vincular o operador à empresa (cadastro raiz).</p>
    <?php else: ?>

    <form class="filters op-search" method="get" action="chamados.php">
      <?php if ($filtro !== ''): ?><input type="hidden" name="f" value="<?= htmlspecialchars($filtro) ?>"><?php endif; ?>
      <div class="form-group" style="margin:0;flex:1;min-width:200px;">
        <label for="q" style="font-size:12px;">Buscar</label>
        <input type="search" id="q" name="q" class="input" value="<?= htmlspecialchars($q) ?>" placeholder="ID ou palavra no assunto">
      </div>
      <button type="submit" class="btn btn-primary">Buscar</button>
      <?php if ($q !== ''): ?>
      <a href="<?= htmlspecialchars(operador_chamados_url(1, $filtro, '')) ?>" class="btn">Limpar</a>
      <?php endif; ?>
    </form>

    <div class="filters op-chips">
      <a href="<?= htmlspecialchars(operador_chamados_url(1, '', $q)) ?>" class="chip <?= $filtro === '' ? 'active' : '' ?>">Atribuídos a mim</a>
      <a href="<?= htmlspecialchars(operador_chamados_url(1, 'andamento', $q)) ?>" class="chip <?= $filtro === 'andamento' ? 'active' : '' ?>">Em andamento</a>
      <a href="<?= htmlspecialchars(operador_chamados_url(1, 'aguardando', $q)) ?>" class="chip <?= $filtro === 'aguardando' ? 'active' : '' ?>">Aguardando</a>
      <a href="<?= htmlspecialchars(operador_chamados_url(1, 'resolvido', $q)) ?>" class="chip <?= $filtro === 'resolvido' ? 'active' : '' ?>">Resolvidos</a>
    </div>

    <div class="op-call-cards">
      <?php if (empty($meus)): ?>
        <div class="empty-state" style="padding:20px;">
          <p class="muted" style="margin:0;">Nenhum chamado encontrado.</p>
        </div>
      <?php else: ?>
        <?php foreach ($meus as $c): ?>
          <a class="op-call-card" href="chamado_detalhe.php?id=<?= (int) $c['id'] ?>">
            <div class="op-call-top">
              <span class="op-call-id">#<?= (int) $c['id'] ?></span>
              <span class="badge <?= status_class($c['status'] ?? '') ?>"><?= htmlspecialchars((string) ($c['status'] ?? '')) ?></span>
            </div>
            <h3 class="op-call-title"><?= htmlspecialchars((string) ($c['titulo'] ?? '')) ?></h3>
            <?php if (!empty($c['cliente'])): ?>
              <div class="muted" style="font-size:13px;line-height:1.4;"><?= htmlspecialchars((string) $c['cliente']) ?></div>
            <?php endif; ?>
            <div class="op-call-meta">
              <span>
                Aberto em
                <strong><?= !empty($c['data']) ? date('d/m H:i', strtotime((string) $c['data'])) : '—' ?></strong>
              </span>
              <span>
                Prioridade
                <strong><?= htmlspecialchars((string) ($c['prioridade'] ?? 'Normal')) ?></strong>
              </span>
            </div>
            <span class="btn btn-primary op-call-action">Abrir chamado</span>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="table-wrap op-desktop-table">
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Assunto</th>
            <th>Status</th>
            <th>Aberto em</th>
            <th class="text-right">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($meus)): ?>
          <tr>
            <td colspan="5" class="muted" style="padding:24px;text-align:center;">Nenhum chamado encontrado.</td>
          </tr>
          <?php else: ?>
          <?php foreach ($meus as $c): ?>
          <tr>
            <td class="td-id">#<?= (int) $c['id'] ?></td>
            <td class="td-title"><?= htmlspecialchars((string) ($c['titulo'] ?? '')) ?></td>
            <td><span class="badge <?= status_class($c['status'] ?? '') ?>"><?= htmlspecialchars((string) ($c['status'] ?? '')) ?></span></td>
            <td class="td-mute"><?= !empty($c['data']) ? date('d/m/Y H:i', strtotime((string) $c['data'])) : '—' ?></td>
            <td class="td-actions">
              <a class="action primary" href="chamado_detalhe.php?id=<?= (int) $c['id'] ?>">Abrir</a>
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
          <a class="pag-btn" href="<?= htmlspecialchars(operador_chamados_url($page - 1, $filtro, $q)) ?>">‹</a>
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
            <a class="pag-btn" href="<?= htmlspecialchars(operador_chamados_url($pi, $filtro, $q)) ?>"><?= $pi ?></a>
          <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page >= $totalPages): ?>
          <span class="pag-btn" style="opacity:.4;pointer-events:none;">›</span>
        <?php else: ?>
          <a class="pag-btn" href="<?= htmlspecialchars(operador_chamados_url($page + 1, $filtro, $q)) ?>">›</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
