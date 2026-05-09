<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('usuarios');

$escopoUsu = gestao_scope_cliente_id($me);

/**
 * @return list<int>
 */
function usuarios_pagination_visible_pages(int $current, int $lastPage): array
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

$pageTitle   = 'Usuários';
$basePath    = '../';
$activePage  = 'usuarios';

$perPage = 15;
$perfilFil = (string) ($_GET['perfil'] ?? '');
if (!in_array($perfilFil, ['', 'admin', 'cliente', 'operador', 'gestor'], true)) {
    $perfilFil = '';
}
$q = trim((string) ($_GET['q'] ?? ''));

$lista     = [];
$totalRows = 0;
$page      = max(1, (int) ($_GET['page'] ?? 1));
$totalPages = 1;
$paginasVisiveis = [];
if (db_ok()) {
    $res       = repo_usuarios_list_for_admin($perfilFil, $q, $page, $perPage, $escopoUsu, (int) ($me['id'] ?? 0));
    $totalRows = $res['total'];
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
        $res = repo_usuarios_list_for_admin($perfilFil, $q, $page, $perPage, $escopoUsu, (int) ($me['id'] ?? 0));
    }
    $lista             = $res['rows'];
    $paginasVisiveis   = usuarios_pagination_visible_pages($page, $totalPages);
}

if ($totalRows === 0) {
    $mostrarDe = $mostrarAte = 0;
} else {
    $mostrarDe  = ($page - 1) * $perPage + 1;
    $mostrarAte = min(($page - 1) * $perPage + count($lista), $totalRows);
}

function usuarios_page_url(int $p, string $perfil, string $search): string
{
    $q = [];
    if ($perfil !== '') {
        $q['perfil'] = $perfil;
    }
    if ($search !== '') {
        $q['q'] = $search;
    }
    if ($p > 1) {
        $q['page'] = $p;
    }
    return 'usuarios.php' . ($q ? '?' . http_build_query($q) : '');
}

$topTitle    = 'Usuários do sistema';
$topSubtitle = 'Acessos ao painel admin e ao portal do cliente.';
$topSearch   = 'Buscar por nome, e-mail ou empresa...';
$topAction   = ['label' => 'Novo usuário', 'href' => 'usuario_novo.php', 'icon' => '+'];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <div class="card">
    <div class="panel-head">
      <h4>Usuários cadastrados</h4>
      <span class="panel-sub">Acessos de login do sistema</span>
    </div>

    <?php if (!db_ok()): ?>
    <div class="panel-body">
      <p class="muted">O MySQL não está disponível. Conecte o banco para listar e editar usuários.</p>
    </div>
    <?php else: ?>
    <form class="filters" method="get" action="usuarios.php" style="padding:12px 20px;border-bottom:1px solid var(--border);display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
      <div class="form-group" style="margin:0;min-width:160px;">
        <label for="perfil" style="font-size:12px;">Perfil</label>
        <select id="perfil" name="perfil" class="select" onchange="this.form.submit()">
          <option value="" <?= $perfilFil === '' ? 'selected' : '' ?>>Todos</option>
          <?php if ($escopoUsu === null): ?>
          <option value="admin" <?= $perfilFil === 'admin' ? 'selected' : '' ?>>Administrador</option>
          <option value="gestor" <?= $perfilFil === 'gestor' ? 'selected' : '' ?>>Gestor (empresa)</option>
          <?php endif; ?>
          <option value="cliente" <?= $perfilFil === 'cliente' ? 'selected' : '' ?>>Portal (prefeitura)</option>
          <option value="operador" <?= $perfilFil === 'operador' ? 'selected' : '' ?>>Operador</option>
        </select>
      </div>
      <div class="form-group" style="margin:0;flex:1;min-width:200px;">
        <label for="q" style="font-size:12px;">Busca</label>
        <input type="search" id="q" name="q" class="input" value="<?= htmlspecialchars($q) ?>" placeholder="Nome, e-mail ou empresa">
      </div>
      <button type="submit" class="btn btn-primary">Filtrar</button>
      <?php if ($q !== '' || $perfilFil !== ''): ?>
      <a href="usuarios.php" class="btn">Limpar</a>
      <?php endif; ?>
    </form>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Usuário</th>
            <th>E-mail</th>
            <th>Perfil</th>
            <th>Prefeitura</th>
            <th>Cadastro</th>
            <th class="text-right">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$lista): ?>
          <tr>
            <td colspan="6" class="td-mute">Nenhum usuário encontrado.</td>
          </tr>
          <?php else: ?>
          <?php foreach ($lista as $u): ?>
          <tr>
            <td>
              <div class="cell-client">
                <div class="avatar avatar-sm"><?= htmlspecialchars((string) ($u['iniciais'] ?? '?')) ?></div>
                <div>
                  <strong><?= htmlspecialchars((string) ($u['nome'] ?? '')) ?></strong>
                  <small class="muted">#<?= (int) ($u['id'] ?? 0) ?></small>
                </div>
              </div>
            </td>
            <td class="td-mute"><?= htmlspecialchars((string) ($u['email'] ?? '')) ?></td>
            <td>
              <?php if (($u['perfil'] ?? '') === 'admin'): ?>
                <span class="badge done">Admin</span>
              <?php elseif (($u['perfil'] ?? '') === 'gestor'): ?>
                <span class="badge done">Gestor</span>
              <?php elseif (($u['perfil'] ?? '') === 'operador'): ?>
                <span class="badge waiting">Operador</span>
              <?php else: ?>
                <span class="badge progress">Portal</span>
              <?php endif; ?>
            </td>
            <td class="td-mute">
              <?php if (in_array(($u['perfil'] ?? ''), ['cliente', 'operador', 'gestor'], true) && !empty($u['cliente_empresa'])): ?>
                <?= htmlspecialchars((string) $u['cliente_empresa']) ?>
              <?php elseif (in_array(($u['perfil'] ?? ''), ['cliente', 'operador', 'gestor'], true)): ?>
                <em class="muted">Sem vínculo</em>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
            <td class="td-mute"><small><?= htmlspecialchars((string) ($u['criado_em'] ?? '')) ?></small></td>
            <td class="td-actions">
              <a class="action primary" href="usuario_editar.php?id=<?= (int) ($u['id'] ?? 0) ?>">Editar</a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <span class="pag-info">Mostrando <?= (int) $mostrarDe ?>–<?= (int) $mostrarAte ?> de <?= (int) $totalRows ?></span>
      <div class="pag-controls">
        <?php if ($page <= 1): ?>
          <span class="pag-btn pag-btn--static" aria-hidden="true">‹</span>
        <?php else: ?>
          <a class="pag-btn" href="<?= htmlspecialchars(usuarios_page_url($page - 1, $perfilFil, $q)) ?>" aria-label="Página anterior">‹</a>
        <?php endif; ?>

        <?php foreach ($paginasVisiveis as $pv): ?>
          <?php if ($pv === 0): ?>
            <span class="pag-ellipsis" aria-hidden="true">…</span>
          <?php elseif ($pv === $page): ?>
            <span class="pag-btn active" aria-current="page"><?= (int) $pv ?></span>
          <?php else: ?>
            <a class="pag-btn" href="<?= htmlspecialchars(usuarios_page_url((int) $pv, $perfilFil, $q)) ?>"><?= (int) $pv ?></a>
          <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($page >= $totalPages): ?>
          <span class="pag-btn pag-btn--static" aria-hidden="true">›</span>
        <?php else: ?>
          <a class="pag-btn" href="<?= htmlspecialchars(usuarios_page_url($page + 1, $perfilFil, $q)) ?>" aria-label="Próxima página">›</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
