<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';

$user = require_auth('cliente');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_cliente('pontos_iluminacao');

$pageTitle  = 'Pontos de iluminação';
$basePath   = '../';
$activePage = 'pontos_iluminacao';
$clienteId  = (int) ($user['cliente_id'] ?? 0);

if (!db_ok() || $clienteId <= 0) {
    flash_set('err', 'Banco indisponível ou cliente inválido.');
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0 && repo_ponto_iluminacao_pertence_cliente($id, $clienteId) && repo_ponto_iluminacao_delete($id)) {
        flash_set('ok', 'Ponto de iluminação removido.');
    } else {
        flash_set('err', 'Não foi possível remover o ponto.');
    }
    header('Location: pontos_iluminacao.php');
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
if (!in_array($status, ['', 'Ativo', 'Inativo'], true)) {
    $status = '';
}
$pontos = repo_pontos_iluminacao_list($clienteId, false, $q, $status);

$topTitle    = 'Pontos de iluminação';
$topSubtitle = 'Cadastre postes com endereço e coordenadas para vincular aos chamados.';
$topSearch   = 'Buscar poste...';
$topAction   = ['label' => 'Novo ponto', 'href' => 'ponto_iluminacao_novo.php', 'icon' => '+'];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-cliente.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <div class="card">
    <div class="panel-head">
      <h4>Pontos cadastrados</h4>
      <span class="panel-sub"><?= count($pontos) ?> ponto(s) no filtro</span>
    </div>

    <form class="filters filters--form" method="get" action="pontos_iluminacao.php">
      <div class="form-group form-group--grow">
        <label for="q">Buscar</label>
        <input type="search" id="q" name="q" class="input" value="<?= htmlspecialchars($q) ?>" placeholder="ID do poste, bairro, endereço ou referência">
      </div>
      <div class="form-group">
        <label for="status">Status</label>
        <select id="status" name="status" class="select">
          <option value="">Todos</option>
          <option value="Ativo" <?= $status === 'Ativo' ? 'selected' : '' ?>>Ativos</option>
          <option value="Inativo" <?= $status === 'Inativo' ? 'selected' : '' ?>>Inativos</option>
        </select>
      </div>
      <div class="filters-form-actions">
        <button type="submit" class="btn btn-primary">Filtrar</button>
        <?php if ($q !== '' || $status !== ''): ?><a href="pontos_iluminacao.php" class="btn btn-secondary">Limpar</a><?php endif; ?>
      </div>
    </form>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Poste</th>
            <th>Local</th>
            <th>Chamados</th>
            <th>Status</th>
            <th class="text-right">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$pontos): ?>
          <tr><td colspan="5" class="muted" style="padding:24px;text-align:center;">Nenhum ponto cadastrado.</td></tr>
          <?php else: foreach ($pontos as $p): ?>
          <tr>
            <td>
              <strong><?= htmlspecialchars((string) ($p['codigo_poste'] ?? '')) ?></strong>
              <?php if (!empty($p['identificador_externo'])): ?><br><small class="muted"><?= htmlspecialchars((string) $p['identificador_externo']) ?></small><?php endif; ?>
            </td>
            <td class="td-mute">
              <?= htmlspecialchars((string) ($p['bairro'] ?? '')) ?>
              <?php if (!empty($p['endereco_completo'])): ?><br><small><?= htmlspecialchars((string) $p['endereco_completo']) ?></small><?php endif; ?>
            </td>
            <td><strong><?= (int) ($p['chamados_abertos'] ?? 0) ?></strong> <small class="muted">aberto(s)</small></td>
            <td><span class="badge <?= ($p['status'] ?? '') === 'Ativo' ? 'done' : 'plain' ?>"><?= htmlspecialchars((string) ($p['status'] ?? '')) ?></span></td>
            <td class="td-actions">
              <a class="action primary" href="ponto_iluminacao_novo.php?id=<?= (int) $p['id'] ?>">Editar</a>
              <form method="post" action="pontos_iluminacao.php" style="display:inline;margin:0;" data-confirm="Remover este ponto de iluminação?">
                <input type="hidden" name="acao" value="excluir">
                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <button type="submit" class="action danger">Excluir</button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

</main>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
