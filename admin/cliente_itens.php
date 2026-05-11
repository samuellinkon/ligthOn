<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';

$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';

if (!defined('CRM_ADMIN_CATALOGO_ENTRY')) {
    define('CRM_ADMIN_CATALOGO_ENTRY', 'cliente_itens.php');
}
$catalogoAdminScript = CRM_ADMIN_CATALOGO_ENTRY;

$pageTitle  = 'Catálogo';
$basePath   = '../';
$activePage = 'catalogo';

if (!db_ok()) {
    flash_set('err', 'Banco indisponível.');
    header('Location: clientes.php');
    exit;
}

$escopoEmpresa = gestao_scope_cliente_id($me);
$empresas = $escopoEmpresa !== null
    ? (($c0 = repo_cliente($escopoEmpresa)) ? [$c0] : [])
    : repo_clientes_empresas();

$reqClienteId = (int) ($_GET['cliente_id'] ?? $_GET['id'] ?? 0);
if ($escopoEmpresa !== null) {
    $clienteId = $escopoEmpresa;
} elseif ($reqClienteId > 0) {
    $clienteId = repo_cliente_catalogo_dono_id($reqClienteId);
} else {
    $clienteId = 0;
    $pid = repo_catalogo_cliente_id_padrao_admin();
    if ($pid !== null && $pid > 0) {
        $clienteId = $pid;
    }
    if ($clienteId <= 0) {
        $clienteId = (int) ($empresas[0]['id'] ?? 0);
    }
    if ($clienteId <= 0) {
        $fallback = repo_catalogo_cliente_id_padrao_admin();
        if ($fallback !== null && $fallback > 0) {
            $clienteId = $fallback;
        }
    }
}

if ($clienteId <= 0) {
    flash_set('err', 'Cadastre uma empresa raiz antes de continuar.');
    header('Location: clientes.php');
    exit;
}

$cliente = repo_cliente($clienteId);
if (!$cliente) {
    flash_set('err', 'Empresa não encontrada.');
    header('Location: clientes.php');
    exit;
}
gestor_assert_escopo_cliente($clienteId, $catalogoAdminScript);

require_modulo_admin('catalogo');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string) ($_POST['acao'] ?? '');
    if ($acao === 'item_salvar') {
        $id    = (int) ($_POST['item_id'] ?? 0);
        $id    = $id > 0 ? $id : null;
        $tipo  = (string) ($_POST['tipo'] ?? 'produto');
        $nome  = trim((string) ($_POST['nome'] ?? ''));
        $cod   = trim((string) ($_POST['codigo'] ?? ''));
        $unid  = trim((string) ($_POST['unidade'] ?? 'UN'));
        $val   = (float) str_replace(',', '.', (string) ($_POST['valor_unitario'] ?? '0'));
        $desc  = trim((string) ($_POST['descricao'] ?? ''));
        $ativo = !empty($_POST['ativo']) ? 1 : 0;
        $r = repo_cliente_item_salvar(
            $clienteId,
            $id,
            $tipo,
            $nome,
            $cod !== '' ? $cod : null,
            $unid,
            $val,
            $desc !== '' ? $desc : null,
            $ativo
        );
        flash_set($r['ok'] ? 'ok' : 'err', $r['ok'] ? 'Item salvo no catálogo.' : $r['err']);
    } elseif ($acao === 'item_excluir') {
        $iid = (int) ($_POST['item_id'] ?? 0);
        if ($iid > 0 && repo_cliente_item_excluir($clienteId, $iid)) {
            flash_set('ok', 'Item excluído.');
        } else {
            flash_set('err', 'Não foi possível excluir (verifique se o item está em uso em algum chamado).');
        }
    }

    header('Location: ' . $catalogoAdminScript . '?cliente_id=' . $clienteId);
    exit;
}

$tipoFiltro = strtolower(trim((string) ($_GET['tipo'] ?? '')));
if (!in_array($tipoFiltro, ['', 'produto', 'servico'], true)) {
    $tipoFiltro = '';
}
$statusFiltro = strtolower(trim((string) ($_GET['status'] ?? '')));
if (!in_array($statusFiltro, ['', 'ativo', 'inativo'], true)) {
    $statusFiltro = '';
}
$q = trim((string) ($_GET['q'] ?? ''));

$todosItens = repo_cliente_itens_list($clienteId, false);
$totalProdutos = count(array_filter($todosItens, fn ($it) => ($it['tipo'] ?? '') === 'produto'));
$totalServicos = count(array_filter($todosItens, fn ($it) => ($it['tipo'] ?? '') === 'servico'));
$totalAtivos   = count(array_filter($todosItens, fn ($it) => !empty($it['ativo'])));

$itens = array_values(array_filter($todosItens, function (array $it) use ($tipoFiltro, $statusFiltro, $q): bool {
    if ($tipoFiltro !== '' && ($it['tipo'] ?? '') !== $tipoFiltro) {
        return false;
    }
    if ($statusFiltro === 'ativo' && empty($it['ativo'])) {
        return false;
    }
    if ($statusFiltro === 'inativo' && !empty($it['ativo'])) {
        return false;
    }
    if ($q !== '') {
        $hay = mb_strtolower(
            (string) ($it['nome'] ?? '') . ' ' .
            (string) ($it['codigo'] ?? '') . ' ' .
            (string) ($it['descricao'] ?? '')
        );
        if (mb_strpos($hay, mb_strtolower($q)) === false) {
            return false;
        }
    }

    return true;
}));

$catalogoUrl = static function (int $empresaId, string $tipo, string $status, string $busca) use ($catalogoAdminScript): string {
    $qs = ['cliente_id' => $empresaId];
    if ($tipo !== '') {
        $qs['tipo'] = $tipo;
    }
    if ($status !== '') {
        $qs['status'] = $status;
    }
    if ($busca !== '') {
        $qs['q'] = $busca;
    }

    return $catalogoAdminScript . '?' . http_build_query($qs);
};

$topTitle    = 'Catálogo';
$topSubtitle = 'Produtos e serviços';
$topSearch   = '';
$topAction   = ['label' => 'Voltar ao cliente', 'href' => 'cliente_detalhe.php?id=' . $clienteId, 'icon' => '←'];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">

  <div class="cards-metrics" style="grid-template-columns:repeat(3,1fr);">
    <div class="card metric">
      <div class="metric-top">
        <div><div class="metric-label">Ativos</div><div class="metric-value"><?= (int) $totalAtivos ?></div></div>
        <div class="icon-box green">OK</div>
      </div>
      <div class="metric-change success">Disponíveis para chamados</div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div><div class="metric-label">Produtos</div><div class="metric-value"><?= (int) $totalProdutos ?></div></div>
        <div class="icon-box purple">PR</div>
      </div>
      <div class="metric-change muted">Materiais e itens físicos</div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div><div class="metric-label">Serviços</div><div class="metric-value"><?= (int) $totalServicos ?></div></div>
        <div class="icon-box orange">SV</div>
      </div>
      <div class="metric-change muted">Atividades executadas</div>
    </div>
  </div>

  <div class="card" style="margin-top:20px;">
    <div class="panel-head" style="flex-wrap:wrap;gap:12px;">
      <div>
        <h4 style="margin:0;">Listagem do catálogo</h4>
        <span class="panel-sub"><?= count($itens) ?> item(ns) no filtro</span>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <button class="btn btn-primary btn-sm" type="button" data-open-item-modal data-tipo="produto">+ Produto</button>
        <button class="btn btn-secondary btn-sm" type="button" data-open-item-modal data-tipo="servico">+ Serviço</button>
        <a class="btn btn-secondary btn-sm" href="cliente_itens_importar.php?cliente_id=<?= $clienteId ?>">Importar planilha</a>
        <a class="btn btn-secondary btn-sm" href="catalogo_chamados_materiais.php?cliente_id=<?= $clienteId ?>" title="Lançamentos de itens do catálogo em chamados">Catálogo aplicado em chamados</a>
      </div>
    </div>

    <form class="filters filters--form" method="get" action="<?= htmlspecialchars($catalogoAdminScript) ?>">
      <input type="hidden" name="cliente_id" value="<?= (int) $clienteId ?>">
      <div class="form-group">
        <label for="tipo_f">Tipo</label>
        <select id="tipo_f" name="tipo" class="select">
          <option value="">Todos</option>
          <option value="produto" <?= $tipoFiltro === 'produto' ? 'selected' : '' ?>>Produtos</option>
          <option value="servico" <?= $tipoFiltro === 'servico' ? 'selected' : '' ?>>Serviços</option>
        </select>
      </div>
      <div class="form-group">
        <label for="status_f">Status</label>
        <select id="status_f" name="status" class="select">
          <option value="">Todos</option>
          <option value="ativo" <?= $statusFiltro === 'ativo' ? 'selected' : '' ?>>Ativos</option>
          <option value="inativo" <?= $statusFiltro === 'inativo' ? 'selected' : '' ?>>Inativos</option>
        </select>
      </div>
      <div class="form-group form-group--grow">
        <label for="q">Buscar</label>
        <input id="q" name="q" class="input" type="search" value="<?= htmlspecialchars($q) ?>" placeholder="Nome, código ou descrição">
      </div>
      <div class="filters-form-actions">
        <button type="submit" class="btn btn-primary">Filtrar</button>
        <?php if ($tipoFiltro !== '' || $statusFiltro !== '' || $q !== ''): ?>
        <a class="btn btn-secondary" href="<?= htmlspecialchars($catalogoAdminScript) ?>?cliente_id=<?= $clienteId ?>">Limpar</a>
        <?php endif; ?>
      </div>
    </form>

    <div class="filters" style="padding:12px 20px;display:flex;flex-wrap:wrap;gap:8px;">
      <a href="<?= htmlspecialchars($catalogoUrl($clienteId, '', $statusFiltro, $q)) ?>" class="chip <?= $tipoFiltro === '' ? 'active' : '' ?>">Todos</a>
      <a href="<?= htmlspecialchars($catalogoUrl($clienteId, 'produto', $statusFiltro, $q)) ?>" class="chip <?= $tipoFiltro === 'produto' ? 'active' : '' ?>">Produtos</a>
      <a href="<?= htmlspecialchars($catalogoUrl($clienteId, 'servico', $statusFiltro, $q)) ?>" class="chip <?= $tipoFiltro === 'servico' ? 'active' : '' ?>">Serviços</a>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Tipo</th>
            <th>Nome</th>
            <th>Código</th>
            <th>Un.</th>
            <th class="text-right">Valor unit.</th>
            <th>Status</th>
            <th class="text-right">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($itens)): ?>
          <tr><td colspan="7" class="muted" style="padding:24px;text-align:center;">Nenhum item encontrado.</td></tr>
          <?php else: foreach ($itens as $it): ?>
          <tr>
            <td><span class="badge badge-plain"><?= (($it['tipo'] ?? '') === 'produto') ? 'Produto' : 'Serviço' ?></span></td>
            <td>
              <strong><?= htmlspecialchars((string) ($it['nome'] ?? '')) ?></strong>
              <?php if (!empty($it['descricao'])): ?>
                <div class="muted" style="font-size:12px;margin-top:4px;"><?= htmlspecialchars((string) $it['descricao']) ?></div>
              <?php endif; ?>
            </td>
            <td class="td-mute"><?= htmlspecialchars((string) ($it['codigo'] ?? '—')) ?></td>
            <td class="td-mute"><?= htmlspecialchars((string) ($it['unidade'] ?? '')) ?></td>
            <td class="text-right">R$ <?= number_format((float) ($it['valor_unitario'] ?? 0), 4, ',', '.') ?></td>
            <td><?= !empty($it['ativo']) ? '<span class="badge done">Ativo</span>' : '<span class="badge muted">Inativo</span>' ?></td>
            <td class="td-actions">
              <button
                type="button"
                class="action primary"
                data-open-item-modal
                data-id="<?= (int) ($it['id'] ?? 0) ?>"
                data-tipo="<?= htmlspecialchars((string) ($it['tipo'] ?? 'produto')) ?>"
                data-nome="<?= htmlspecialchars((string) ($it['nome'] ?? ''), ENT_QUOTES) ?>"
                data-codigo="<?= htmlspecialchars((string) ($it['codigo'] ?? ''), ENT_QUOTES) ?>"
                data-unidade="<?= htmlspecialchars((string) ($it['unidade'] ?? 'UN'), ENT_QUOTES) ?>"
                data-valor="<?= htmlspecialchars((string) ($it['valor_unitario'] ?? '0'), ENT_QUOTES) ?>"
                data-descricao="<?= htmlspecialchars((string) ($it['descricao'] ?? ''), ENT_QUOTES) ?>"
                data-ativo="<?= !empty($it['ativo']) ? '1' : '0' ?>"
              >Editar</button>
              <form method="post" style="display:inline;" data-confirm="Excluir este item do catálogo?" data-confirm-danger>
                <input type="hidden" name="acao" value="item_excluir">
                <input type="hidden" name="item_id" value="<?= (int) ($it['id'] ?? 0) ?>">
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

<div id="item-modal" class="kb-modal" role="dialog" aria-modal="true" aria-labelledby="item-modal-title" aria-hidden="true">
  <form method="post" class="kb-modal-card" style="max-width:680px;">
    <input type="hidden" name="acao" value="item_salvar">
    <input type="hidden" name="item_id" id="modal_item_id" value="0">
    <header class="kb-modal-head">
      <h4 id="item-modal-title">Novo produto</h4>
      <button type="button" class="kb-close" data-close-item-modal>×</button>
    </header>
    <div class="kb-modal-body form form-grid">
      <div class="form-group">
        <label for="modal_tipo">Tipo</label>
        <select id="modal_tipo" name="tipo" class="select">
          <option value="produto">Produto</option>
          <option value="servico">Serviço</option>
        </select>
      </div>
      <div class="form-group">
        <label for="modal_nome">Nome</label>
        <input type="text" id="modal_nome" name="nome" class="input" required maxlength="160" placeholder="Nome do item ou serviço">
      </div>
      <div class="form-group">
        <label for="modal_codigo">Código</label>
        <input type="text" id="modal_codigo" name="codigo" class="input" maxlength="64" placeholder="SKU / interno">
      </div>
      <div class="form-group">
        <label for="modal_unidade">Unidade</label>
        <input type="text" id="modal_unidade" name="unidade" class="input" value="UN" maxlength="20" placeholder="UN, m, kg, h">
      </div>
      <div class="form-group">
        <label for="modal_valor">Valor unitário (R$)</label>
        <input type="text" id="modal_valor" name="valor_unitario" class="input" inputmode="decimal" value="0" placeholder="0,00">
      </div>
      <div class="form-group" style="display:flex;align-items:flex-end;">
        <label class="checkbox" style="margin-bottom:10px;">
          <input type="checkbox" name="ativo" id="modal_ativo" value="1" checked> Ativo no catálogo
        </label>
      </div>
      <div class="form-group full">
        <label for="modal_descricao">Descrição</label>
        <textarea id="modal_descricao" name="descricao" class="textarea" rows="3" maxlength="500" placeholder="Descrição opcional para o catálogo"></textarea>
      </div>
    </div>
    <footer class="kb-modal-foot">
      <button type="button" class="btn btn-secondary" data-close-item-modal>Cancelar</button>
      <button type="submit" class="btn btn-primary">Salvar item</button>
    </footer>
  </form>
</div>

<script>
(function () {
  var modal = document.getElementById('item-modal');
  if (!modal) return;
  var title = document.getElementById('item-modal-title');
  var id = document.getElementById('modal_item_id');
  var tipo = document.getElementById('modal_tipo');
  var nome = document.getElementById('modal_nome');
  var codigo = document.getElementById('modal_codigo');
  var unidade = document.getElementById('modal_unidade');
  var valor = document.getElementById('modal_valor');
  var desc = document.getElementById('modal_descricao');
  var ativo = document.getElementById('modal_ativo');

  function open(btn) {
    var editing = !!btn.getAttribute('data-id');
    id.value = btn.getAttribute('data-id') || '0';
    tipo.value = btn.getAttribute('data-tipo') || 'produto';
    nome.value = btn.getAttribute('data-nome') || '';
    codigo.value = btn.getAttribute('data-codigo') || '';
    unidade.value = btn.getAttribute('data-unidade') || 'UN';
    valor.value = btn.getAttribute('data-valor') || '0';
    desc.value = btn.getAttribute('data-descricao') || '';
    ativo.checked = (btn.getAttribute('data-ativo') || '1') === '1';
    title.textContent = editing ? 'Editar item' : (tipo.value === 'servico' ? 'Novo serviço' : 'Novo produto');
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    setTimeout(function () { nome.focus(); }, 50);
  }
  function close() {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
  }

  document.querySelectorAll('[data-open-item-modal]').forEach(function (btn) {
    btn.addEventListener('click', function () { open(btn); });
  });
  document.querySelectorAll('[data-close-item-modal]').forEach(function (btn) {
    btn.addEventListener('click', close);
  });
  modal.addEventListener('click', function (ev) {
    if (ev.target === modal) close();
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && modal.classList.contains('is-open')) close();
  });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
