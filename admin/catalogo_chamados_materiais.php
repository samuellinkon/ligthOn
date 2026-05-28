<?php
/**
 * Catálogo aplicado em chamados — lançamentos planos (exportável).
 */
$CRM_CATALOGO_APLICADO_PORTAL = !empty($CRM_CATALOGO_APLICADO_PORTAL);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';

if ($CRM_CATALOGO_APLICADO_PORTAL) {
    $CLIENTE = require_auth('cliente');
    require_once __DIR__ . '/../includes/modules.php';
    require_modulo_cliente('catalogo');
} else {
    $me = require_auth_gestao();
    require_once __DIR__ . '/../includes/modules.php';
    require_modulo_admin('catalogo');
}

if (!defined('CRM_ADMIN_CATALOGO_ENTRY')) {
    define('CRM_ADMIN_CATALOGO_ENTRY', 'cliente_itens.php');
}
$catalogoAdminScript = CRM_ADMIN_CATALOGO_ENTRY;

$pageTitle  = 'Catálogo aplicado em chamados';
$basePath   = '../';
$activePage = 'catalogo';

if (!db_ok()) {
    flash_set('err', 'Banco indisponível.');
    header('Location: ' . ($CRM_CATALOGO_APLICADO_PORTAL ? ($basePath . 'cliente/index.php') : 'clientes.php'));
    exit;
}

if ($CRM_CATALOGO_APLICADO_PORTAL) {
    $clienteId = repo_cliente_catalogo_dono_id((int) ($CLIENTE['cliente_id'] ?? 0));
} else {
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
}

if ($clienteId <= 0) {
    flash_set('err', 'Cadastre uma empresa raiz antes de continuar.');
    header('Location: ' . ($CRM_CATALOGO_APLICADO_PORTAL ? ($basePath . 'cliente/index.php') : 'clientes.php'));
    exit;
}

$cliente = repo_cliente($clienteId);
if (!$cliente) {
    flash_set('err', 'Empresa não encontrada.');
    header('Location: ' . ($CRM_CATALOGO_APLICADO_PORTAL ? ($basePath . 'cliente/index.php') : 'clientes.php'));
    exit;
}
if (!$CRM_CATALOGO_APLICADO_PORTAL) {
    gestor_assert_escopo_cliente($clienteId, $catalogoAdminScript);
}

$catalogoAplicadoSidebar = $CRM_CATALOGO_APLICADO_PORTAL ? 'sidebar-cliente.php' : 'sidebar-admin.php';
$catalogoAplicadoVoltarHref = $CRM_CATALOGO_APLICADO_PORTAL
    ? 'catalogo.php'
    : ('catalogo.php?cliente_id=' . (int) $clienteId);

$today      = date('Y-m-d');
$defaultDe  = date('Y-m-01');
$dataDeRaw  = trim((string) ($_GET['data_de'] ?? ''));
$dataAteRaw = trim((string) ($_GET['data_ate'] ?? ''));
$dataDe     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDeRaw) ? $dataDeRaw : $defaultDe;
$dataAte    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAteRaw) ? $dataAteRaw : $today;
if ($dataDe > $dataAte) {
    [$dataDe, $dataAte] = [$dataAte, $dataDe];
}

$movFiltro = strtolower(trim((string) ($_GET['movimento'] ?? '')));
if (!in_array($movFiltro, ['', 'utilizado', 'devolvido'], true)) {
    $movFiltro = '';
}
$tipoFiltro = strtolower(trim((string) ($_GET['tipo'] ?? '')));
if (!in_array($tipoFiltro, ['', 'produto', 'servico'], true)) {
    $tipoFiltro = '';
}

$filtroChamadoId = (int) ($_GET['chamado_id'] ?? 0);
$filtroItemId    = (int) ($_GET['item_id'] ?? 0);
$filtroTecId     = (int) ($_GET['tecnico_user_id'] ?? 0);

$filtrosRepo = [];
if ($movFiltro !== '') {
    $filtrosRepo['movimento'] = $movFiltro;
}
if ($tipoFiltro !== '') {
    $filtrosRepo['tipo'] = $tipoFiltro;
}
if ($filtroChamadoId > 0) {
    $filtrosRepo['chamado_id'] = $filtroChamadoId;
}
if ($filtroItemId > 0) {
    $filtrosRepo['item_id'] = $filtroItemId;
}
if ($filtroTecId > 0) {
    $filtrosRepo['tecnico_user_id'] = $filtroTecId;
}

$linhas = repo_catalogo_chamados_itens_linhas_filtradas($clienteId, $dataDe, $dataAte, $filtrosRepo);

$catalogoItensOpts = repo_cliente_itens_list($clienteId, false);
$catalogoItemOptsBusca = [];
$itemSelecionadoLabel = 'Todos os itens';
foreach ($catalogoItensOpts as $it) {
    $itId = (int) ($it['id'] ?? 0);
    if ($itId <= 0) {
        continue;
    }
    $itTipo = trim((string) ($it['tipo'] ?? ''));
    if ($tipoFiltro !== '' && $itTipo !== $tipoFiltro && $filtroItemId !== $itId) {
        continue;
    }
    $itNome = trim((string) ($it['nome'] ?? ''));
    $itLabel = trim(($itTipo !== '' ? ($itTipo . ' · ') : '') . $itNome);
    if ($itLabel === '') {
        $itLabel = 'Item #' . $itId;
    }
    $catalogoItemOptsBusca[] = [
        'id' => $itId,
        'label' => $itLabel,
        'search' => trim($itTipo . ' ' . $itNome . ' ' . (string) ($it['codigo'] ?? '')),
    ];
    if ($filtroItemId === $itId) {
        $itemSelecionadoLabel = $itLabel;
    }
}
$filtrosLimparQs = [];
if (!$CRM_CATALOGO_APLICADO_PORTAL) {
    $filtrosLimparQs['cliente_id'] = (int) $clienteId;
}
$limparHref = 'catalogo_chamados_materiais.php';
if ($filtrosLimparQs !== []) {
    $limparHref .= '?' . http_build_query($filtrosLimparQs);
}

$totalValorUtil = 0.0;
foreach ($linhas as $ln) {
    if (($ln['movimento'] ?? '') === 'utilizado') {
        $totalValorUtil += (float) ($ln['subtotal'] ?? 0);
    }
}

$topTitle = 'Catálogo aplicado em chamados';
$topSubtitle = (string) ($cliente['empresa'] ?? '');
$topSearch = '';
$topAction = ['label' => 'Voltar ao catálogo', 'href' => $catalogoAplicadoVoltarHref, 'icon' => '←'];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/' . $catalogoAplicadoSidebar; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">

  <div class="card catalogo-aplicado-card">
    <div class="panel-head catalogo-aplicado-head">
      <div>
        <h4>Catálogo aplicado em chamados</h4>
        <span class="panel-sub">Todos os lançamentos de itens do catálogo em chamados · data do chamado = abertura · adequado para exportação futura</span>
      </div>
      <div class="catalogo-aplicado-head-meta">
        <?= count($linhas) ?> lançamento(s)
        <?php if ($totalValorUtil > 0): ?>
          · Total utilizados: R$ <?= number_format($totalValorUtil, 2, ',', '.') ?>
        <?php endif; ?>
      </div>
    </div>
    <div class="panel-body catalogo-aplicado-body">
      <form class="catalogo-aplicado-filters" method="get" action="catalogo_chamados_materiais.php">
        <?php if (!$CRM_CATALOGO_APLICADO_PORTAL): ?>
        <input type="hidden" name="cliente_id" value="<?= (int) $clienteId ?>">
        <?php endif; ?>
        <?php if ($filtroTecId > 0): ?>
        <input type="hidden" name="tecnico_user_id" value="<?= (int) $filtroTecId ?>">
        <?php endif; ?>
        <div class="catalogo-aplicado-filters-row catalogo-aplicado-filters-row--top">
        <div class="form-group">
          <label for="data_de">Período — de</label>
          <input type="date" id="data_de" name="data_de" class="input" value="<?= htmlspecialchars($dataDe) ?>" required>
        </div>
        <div class="form-group">
          <label for="data_ate">até</label>
          <input type="date" id="data_ate" name="data_ate" class="input" value="<?= htmlspecialchars($dataAte) ?>" required>
        </div>
        <div class="form-group">
          <label for="movimento_f">Movimento</label>
          <select id="movimento_f" name="movimento" class="select">
            <option value="" <?= $movFiltro === '' ? 'selected' : '' ?>>Todos</option>
            <option value="utilizado" <?= $movFiltro === 'utilizado' ? 'selected' : '' ?>>Utilizado</option>
            <option value="devolvido" <?= $movFiltro === 'devolvido' ? 'selected' : '' ?>>Recolhido / devolvido</option>
          </select>
        </div>
        <div class="form-group">
          <label for="tipo_f">Tipo</label>
          <select id="tipo_f" name="tipo" class="select">
            <option value="" <?= $tipoFiltro === '' ? 'selected' : '' ?>>Todos</option>
            <option value="produto" <?= $tipoFiltro === 'produto' ? 'selected' : '' ?>>Produtos</option>
            <option value="servico" <?= $tipoFiltro === 'servico' ? 'selected' : '' ?>>Serviços</option>
          </select>
        </div>
        <div class="form-group form-group--chamado">
          <label for="chamado_id_f">Chamado (#)</label>
          <input type="number" min="1" id="chamado_id_f" name="chamado_id" class="input"
                 value="<?= $filtroChamadoId > 0 ? (int) $filtroChamadoId : '' ?>" placeholder="Todos">
        </div>
        </div>
        <div class="catalogo-aplicado-filters-row catalogo-aplicado-filters-row--bottom">
        <div class="form-group form-group--item-search">
          <label for="item_search_input">Item</label>
          <div class="crm-searchable-select" id="item_search_wrap" data-placeholder="Todos os itens">
            <input type="hidden" name="item_id" id="item_id_f" value="<?= $filtroItemId > 0 ? (int) $filtroItemId : '' ?>">
            <button type="button" class="crm-searchable-select__control" id="item_search_toggle" aria-expanded="false" aria-haspopup="listbox">
              <span class="crm-searchable-select__value" id="item_search_value"><?= htmlspecialchars($itemSelecionadoLabel) ?></span>
            </button>
            <div class="crm-searchable-select__dropdown" id="item_search_dropdown" hidden>
              <input type="search" id="item_search_input" class="input crm-searchable-select__input" placeholder="Buscar por tipo, nome ou código">
              <ul class="crm-searchable-select__list" id="item_search_list" role="listbox"></ul>
            </div>
          </div>
        </div>
        <div class="catalogo-aplicado-filters-actions">
          <button type="submit" class="btn btn-primary">Filtrar</button>
          <a href="<?= htmlspecialchars($limparHref) ?>" class="btn btn-secondary">Limpar</a>
        </div>
        </div>
      </form>

      <?php if (empty($linhas)): ?>
        <p class="muted" style="margin:0;">Nenhum lançamento neste filtro.</p>
      <?php else: ?>
        <div class="table-wrap catalogo-aplicado-table-wrap">
          <table class="catalogo-aplicado-table">
            <thead>
              <tr>
                <th>Chamado</th>
                <th>Data abertura</th>
                <th>Endereço</th>
                <th>Item</th>
                <th>Movimento</th>
                <th class="text-right">Qtd</th>
                <th>Un.</th>
                <th class="text-right">V. unit.</th>
                <th class="text-right">V. total</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($linhas as $ln): ?>
                <?php
                  $mov = (string) ($ln['movimento'] ?? '');
                  $isU = $mov !== 'devolvido';
                  $chId = (int) ($ln['chamado_id'] ?? 0);
                  $chHref = 'chamado_detalhe.php?id=' . $chId;
                  $endCh = trim((string) ($ln['chamado_endereco'] ?? ''));
                ?>
                <tr class="catalogo-aplicado-row-link" role="link" tabindex="0"
                    data-href="<?= htmlspecialchars($chHref, ENT_QUOTES, 'UTF-8') ?>"
                    title="Abrir chamado #<?= $chId ?>">
                  <td>
                    <strong>#<?= $chId ?></strong>
                    <?php if (($ln['chamado_titulo'] ?? '') !== ''): ?>
                      <div class="td-mute" style="font-size:12px;max-width:14rem;"><?= htmlspecialchars((string) $ln['chamado_titulo']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="td-mute"><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) ($ln['chamado_aberto_em'] ?? 'now')))) ?></td>
                  <td class="catalogo-aplicado-endereco">
                    <?php if ($endCh !== ''): ?>
                      <?= htmlspecialchars($endCh) ?>
                    <?php else: ?>
                      <span class="td-mute">—</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <strong><?= htmlspecialchars((string) ($ln['item_nome'] ?? '')) ?></strong>
                    <?php if (($ln['item_codigo'] ?? '') !== ''): ?>
                      <div class="muted" style="font-size:12px;">Cód. <?= htmlspecialchars((string) $ln['item_codigo']) ?></div>
                    <?php endif; ?>
                    <div class="td-mute" style="font-size:12px;"><?= htmlspecialchars((string) ($ln['item_tipo'] ?? '')) ?></div>
                  </td>
                  <td>
                    <span class="badge <?= $isU ? 'success' : 'info' ?>"><?= $isU ? 'Utilizado' : 'Recolhido' ?></span>
                  </td>
                  <td class="text-right"><?= htmlspecialchars(rtrim(rtrim(sprintf('%.4f', (float) ($ln['quantidade'] ?? 0)), '0'), '.')) ?></td>
                  <td class="td-mute"><?= htmlspecialchars((string) ($ln['catalogo_unidade'] ?? '')) ?></td>
                  <td class="text-right td-mute">R$ <?= number_format((float) ($ln['valor_unitario'] ?? 0), 4, ',', '.') ?></td>
                  <td class="text-right">
                    <?php if ($isU): ?>
                      <strong>R$ <?= number_format((float) ($ln['subtotal'] ?? 0), 2, ',', '.') ?></strong>
                    <?php else: ?>
                      <span class="td-mute">—</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

</section>
</main>
</div>
<style>
.catalogo-aplicado-head { flex-wrap: wrap; gap: 12px; }
.catalogo-aplicado-head-meta { font-size: 14px; font-weight: 600; }
.catalogo-aplicado-body { padding-top: 0; }
.catalogo-aplicado-filters {
  margin-bottom: 18px;
  border: 1px solid var(--border-color, #e5e7eb);
  border-radius: 12px;
  padding: 14px;
  background: var(--surface-secondary, #fafafa);
}
.catalogo-aplicado-filters-row {
  display: grid;
  gap: 12px;
}
.catalogo-aplicado-filters-row--top {
  grid-template-columns: repeat(5, minmax(0, 1fr));
}
.catalogo-aplicado-filters-row--bottom {
  margin-top: 10px;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: end;
}
.catalogo-aplicado-filters .form-group { margin: 0; min-width: 0; }
.catalogo-aplicado-filters label {
  font-size: 12px;
  line-height: 1.2;
  color: var(--text-muted, #6b7280);
  margin-bottom: 6px;
}
.catalogo-aplicado-filters .input,
.catalogo-aplicado-filters .select,
.crm-searchable-select__control {
  min-height: 40px;
}
.form-group--chamado .input { width: 100%; }
.form-group--item-search { min-width: 0; }
.catalogo-aplicado-filters-actions {
  display: flex;
  gap: 8px;
}
.crm-searchable-select { position: relative; }
.crm-searchable-select__control {
  width: 100%;
  border: 1px solid var(--border-color, #d1d5db);
  border-radius: 8px;
  padding: 8px 12px;
  text-align: left;
  background: #fff;
  cursor: pointer;
}
.crm-searchable-select__value {
  display: block;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.crm-searchable-select__dropdown {
  position: absolute;
  top: calc(100% + 6px);
  left: 0;
  right: 0;
  z-index: 25;
  border: 1px solid var(--border-color, #d1d5db);
  border-radius: 10px;
  background: #fff;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
  padding: 8px;
}
.crm-searchable-select__input { margin-bottom: 8px; }
.crm-searchable-select__list {
  margin: 0;
  padding: 0;
  list-style: none;
  max-height: 320px;
  overflow: auto;
}
.crm-searchable-select__option {
  padding: 8px 10px;
  border-radius: 8px;
  cursor: pointer;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.crm-searchable-select__option:hover,
.crm-searchable-select__option.is-active {
  background: var(--surface-hover, rgba(0, 0, 0, 0.06));
}
.catalogo-aplicado-row-link { cursor: pointer; }
.catalogo-aplicado-row-link:hover { background: var(--surface-hover, rgba(0, 0, 0, 0.04)); }
.catalogo-aplicado-endereco { max-width: 18rem; font-size: 13px; line-height: 1.4; }
@media (max-width: 1100px) {
  .catalogo-aplicado-filters-row--top { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (max-width: 760px) {
  .catalogo-aplicado-filters-row--top,
  .catalogo-aplicado-filters-row--bottom {
    grid-template-columns: 1fr;
  }
  .catalogo-aplicado-filters-actions {
    width: 100%;
  }
  .catalogo-aplicado-filters-actions .btn {
    flex: 1;
    text-align: center;
  }
}
</style>
<script>
(function () {
  var itemOptions = <?= json_encode($catalogoItemOptsBusca, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var itemWrap = document.getElementById('item_search_wrap');
  var itemHidden = document.getElementById('item_id_f');
  var itemToggle = document.getElementById('item_search_toggle');
  var itemValue = document.getElementById('item_search_value');
  var itemDropdown = document.getElementById('item_search_dropdown');
  var itemInput = document.getElementById('item_search_input');
  var itemList = document.getElementById('item_search_list');

  function normText(v) {
    return (v || '').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  }

  function closeItemDropdown() {
    if (!itemDropdown || !itemToggle) return;
    itemDropdown.hidden = true;
    itemToggle.setAttribute('aria-expanded', 'false');
  }

  function openItemDropdown() {
    if (!itemDropdown || !itemToggle || !itemInput) return;
    itemDropdown.hidden = false;
    itemToggle.setAttribute('aria-expanded', 'true');
    itemInput.focus();
    itemInput.select();
  }

  function selectItem(id, label) {
    if (!itemHidden || !itemValue) return;
    itemHidden.value = id ? String(id) : '';
    itemValue.textContent = label || 'Todos os itens';
    closeItemDropdown();
  }

  function renderItemOptions() {
    if (!itemList || !itemInput) return;
    var q = normText(itemInput.value);
    var terms = q ? q.split(/\s+/).filter(Boolean) : [];
    var matches = [];
    matches.push({ id: '', label: 'Todos os itens', search: 'todos os itens' });
    itemOptions.forEach(function (opt) {
      var hay = normText(opt.search + ' ' + opt.label);
      if (!terms.length || terms.every(function (t) { return hay.indexOf(t) !== -1; })) {
        matches.push(opt);
      }
    });
    matches = matches.slice(0, 10);
    itemList.innerHTML = '';
    if (!matches.length) {
      var emptyLi = document.createElement('li');
      emptyLi.className = 'crm-searchable-select__option';
      emptyLi.textContent = 'Nenhum item encontrado';
      emptyLi.setAttribute('aria-disabled', 'true');
      itemList.appendChild(emptyLi);
      return;
    }
    var currentVal = itemHidden ? String(itemHidden.value || '') : '';
    matches.forEach(function (opt) {
      var li = document.createElement('li');
      li.className = 'crm-searchable-select__option' + (String(opt.id) === currentVal ? ' is-active' : '');
      li.title = opt.label;
      li.textContent = opt.label;
      li.setAttribute('role', 'option');
      li.setAttribute('data-id', String(opt.id || ''));
      li.addEventListener('click', function () {
        selectItem(opt.id, opt.label);
      });
      itemList.appendChild(li);
    });
  }

  if (itemWrap && itemToggle && itemDropdown && itemInput && itemList && itemHidden && itemValue) {
    itemToggle.addEventListener('click', function () {
      if (itemDropdown.hidden) {
        renderItemOptions();
        openItemDropdown();
      } else {
        closeItemDropdown();
      }
    });
    itemInput.addEventListener('input', renderItemOptions);
    document.addEventListener('click', function (e) {
      if (!itemWrap.contains(e.target)) closeItemDropdown();
    });
    itemInput.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        e.preventDefault();
        closeItemDropdown();
      }
    });
  }

  document.querySelectorAll('.catalogo-aplicado-row-link').forEach(function (row) {
    row.addEventListener('click', function () {
      var href = row.getAttribute('data-href');
      if (href) window.location.href = href;
    });
    row.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        var href = row.getAttribute('data-href');
        if (href) window.location.href = href;
      }
    });
  });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
