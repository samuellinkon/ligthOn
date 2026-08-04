<?php
/**
 * Itens devolvidos / sucatas — lançamentos com movimento=devolvido.
 */
$CRM_CATALOGO_DEVOLVIDOS_PORTAL = !empty($CRM_CATALOGO_DEVOLVIDOS_PORTAL);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';

if ($CRM_CATALOGO_DEVOLVIDOS_PORTAL) {
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

$pageTitle  = 'Itens devolvidos / sucatas';
$basePath   = '../';
$activePage = 'catalogo';

if (!db_ok()) {
    flash_set('err', 'Banco indisponível.');
    header('Location: ' . ($CRM_CATALOGO_DEVOLVIDOS_PORTAL ? ($basePath . 'cliente/index.php') : 'clientes.php'));
    exit;
}

if ($CRM_CATALOGO_DEVOLVIDOS_PORTAL) {
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
    header('Location: ' . ($CRM_CATALOGO_DEVOLVIDOS_PORTAL ? ($basePath . 'cliente/index.php') : 'clientes.php'));
    exit;
}

$cliente = repo_cliente($clienteId);
if (!$cliente) {
    flash_set('err', 'Empresa não encontrada.');
    header('Location: ' . ($CRM_CATALOGO_DEVOLVIDOS_PORTAL ? ($basePath . 'cliente/index.php') : 'clientes.php'));
    exit;
}
if (!$CRM_CATALOGO_DEVOLVIDOS_PORTAL) {
    gestor_assert_escopo_cliente($clienteId, $catalogoAdminScript);
}

$catalogoDevSidebar = $CRM_CATALOGO_DEVOLVIDOS_PORTAL ? 'sidebar-cliente.php' : 'sidebar-admin.php';
$catalogoDevVoltarHref = $CRM_CATALOGO_DEVOLVIDOS_PORTAL
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

$filtroChamadoId = (int) ($_GET['chamado_id'] ?? 0);
$filtroItemId    = (int) ($_GET['item_id'] ?? 0);
$filtroTecId     = (int) ($_GET['tecnico_user_id'] ?? 0);
$qBusca          = trim((string) ($_GET['q'] ?? ''));

$filtrosRepo = ['movimento' => 'devolvido'];
if ($filtroChamadoId > 0) {
    $filtrosRepo['chamado_id'] = $filtroChamadoId;
}
if ($filtroItemId > 0) {
    $filtrosRepo['item_id'] = $filtroItemId;
}
if ($filtroTecId > 0) {
    $filtrosRepo['tecnico_user_id'] = $filtroTecId;
}
if ($qBusca !== '') {
    $filtrosRepo['q'] = $qBusca;
}

$linhas = repo_catalogo_chamados_itens_linhas_filtradas($clienteId, $dataDe, $dataAte, $filtrosRepo);

if (strtolower(trim((string) ($_GET['export'] ?? ''))) === 'xlsx') {
    require_once __DIR__ . '/../includes/catalogo_itens_devolvidos_export_xlsx.php';
    $periodoLabel = date('d/m/Y', strtotime($dataDe)) . ' a ' . date('d/m/Y', strtotime($dataAte));
    if (function_exists('audit_log_registar')) {
        audit_log_registar('catalogo.exportar_devolvidos_xlsx', 'catalogo', null, $clienteId > 0 ? $clienteId : null, [
            'data_de'    => $dataDe,
            'data_ate'   => $dataAte,
            'chamado_id' => $filtroChamadoId > 0 ? $filtroChamadoId : null,
            'item_id'    => $filtroItemId > 0 ? $filtroItemId : null,
            'q'          => $qBusca !== '' ? $qBusca : null,
            'n_linhas'   => count($linhas),
            'portal'     => $CRM_CATALOGO_DEVOLVIDOS_PORTAL ? 1 : 0,
        ]);
    }
    catalogo_itens_devolvidos_export_xlsx_send($linhas, [
        'empresa'       => (string) ($cliente['empresa'] ?? ''),
        'periodo_label' => $periodoLabel,
        'busca'         => $qBusca,
    ]);
    exit;
}

$catalogoItensOpts = repo_cliente_itens_list($clienteId, false);
$catalogoItemOptsBusca = [];
$itemSelecionadoLabel = 'Todos os itens';
foreach ($catalogoItensOpts as $it) {
    $itId = (int) ($it['id'] ?? 0);
    if ($itId <= 0) {
        continue;
    }
    $itTipo = trim((string) ($it['tipo'] ?? ''));
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
if (!$CRM_CATALOGO_DEVOLVIDOS_PORTAL) {
    $filtrosLimparQs['cliente_id'] = (int) $clienteId;
}
$limparHref = 'catalogo_itens_devolvidos.php';
if ($filtrosLimparQs !== []) {
    $limparHref .= '?' . http_build_query($filtrosLimparQs);
}

$totalLinhas = count($linhas);
$totalQtd    = 0.0;
$totalValor  = 0.0;
foreach ($linhas as $ln) {
    $totalQtd   += (float) ($ln['quantidade'] ?? 0);
    $totalValor += (float) ($ln['subtotal'] ?? 0);
}

$fmtQtd = static function (float $n): string {
    return rtrim(rtrim(sprintf('%.4f', $n), '0'), '.');
};

$topTitle    = 'Itens devolvidos / sucatas';
$topSubtitle = (string) ($cliente['empresa'] ?? '');
$topSearch   = '';
$topAction   = ['label' => 'Voltar ao catálogo', 'href' => $catalogoDevVoltarHref, 'icon' => '←'];
$topActions  = [];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/' . $catalogoDevSidebar; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">

  <div class="cards-metrics" style="grid-template-columns:repeat(3,1fr);margin-bottom:16px;">
    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">Lançamentos</div>
          <div class="metric-value"><?= (int) $totalLinhas ?></div>
        </div>
        <div class="icon-box red">DV</div>
      </div>
      <div class="metric-change muted">Recolhidos / sucatas no período</div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">Quantidade</div>
          <div class="metric-value"><?= htmlspecialchars($fmtQtd($totalQtd)) ?></div>
        </div>
        <div class="icon-box blue">Qtd</div>
      </div>
      <div class="metric-change muted">Soma das quantidades</div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">Valor referência</div>
          <div class="metric-value">R$ <?= number_format($totalValor, 2, ',', '.') ?></div>
        </div>
        <div class="icon-box green">R$</div>
      </div>
      <div class="metric-change muted">Não compõe a medição (BM)</div>
    </div>
  </div>

<?php
$qsBase = !$CRM_CATALOGO_DEVOLVIDOS_PORTAL ? ['cliente_id' => (int) $clienteId] : [];
$hrefPeriodo = static function (string $de, string $ate) use ($qsBase): string {
    return 'catalogo_itens_devolvidos.php?' . http_build_query(array_merge($qsBase, [
        'data_de'  => $de,
        'data_ate' => $ate,
    ]));
};
$mesIni = date('Y-m-01');
$mesFim = date('Y-m-t');
$dia    = date('Y-m-d');
$todoDe = '2020-01-01';
$isDia  = ($dataDe === $dia && $dataAte === $dia);
$isMes  = ($dataDe === $mesIni && ($dataAte === $mesFim || $dataAte === $dia || $dataAte === $today));
$isTodo = ($dataDe === $todoDe && $dataAte === $today);
$temFiltroExtra = $filtroChamadoId > 0 || $filtroItemId > 0 || $qBusca !== '' || $filtroTecId > 0
    || $dataDe !== $defaultDe || $dataAte !== $today;

$exportQs = array_merge($qsBase, [
    'data_de'  => $dataDe,
    'data_ate' => $dataAte,
    'export'   => 'xlsx',
]);
if ($filtroChamadoId > 0) {
    $exportQs['chamado_id'] = $filtroChamadoId;
}
if ($filtroItemId > 0) {
    $exportQs['item_id'] = $filtroItemId;
}
if ($filtroTecId > 0) {
    $exportQs['tecnico_user_id'] = $filtroTecId;
}
if ($qBusca !== '') {
    $exportQs['q'] = $qBusca;
}
$exportHref = 'catalogo_itens_devolvidos.php?' . http_build_query($exportQs);
?>
  <div class="card">
    <div class="panel-head" style="flex-wrap:wrap;gap:12px;align-items:center;">
      <div style="flex:1;min-width:0;">
        <h4 style="margin:0;">Itens devolvidos / sucatas</h4>
        <span class="panel-sub"><?= (int) $totalLinhas ?> lançamento(s) · recolhimentos e itens criados via «Criar e lançar recolhimento»</span>
      </div>
      <a class="btn btn-secondary btn-sm js-crm-export-link" href="<?= htmlspecialchars($exportHref) ?>" title="Exporta a listagem filtrada de itens devolvidos/sucatas">Exportar XLS</a>
    </div>

    <div class="filters" style="padding:12px 20px;border-bottom:1px solid var(--border);display:grid;gap:12px;">
      <div class="chamados-periodo-quick" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <a class="btn btn-secondary btn-sm<?= $isDia ? ' chamados-periodo-quick__btn--active' : '' ?>" href="<?= htmlspecialchars($hrefPeriodo($dia, $dia)) ?>">Dia atual</a>
        <a class="btn btn-secondary btn-sm<?= $isMes ? ' chamados-periodo-quick__btn--active' : '' ?>" href="<?= htmlspecialchars($hrefPeriodo($mesIni, $mesFim)) ?>">Mês atual</a>
        <a class="btn btn-secondary btn-sm<?= $isTodo ? ' chamados-periodo-quick__btn--active' : '' ?>" href="<?= htmlspecialchars($hrefPeriodo($todoDe, $today)) ?>">Todo o período</a>
      </div>

      <form method="get" action="catalogo_itens_devolvidos.php" style="display:flex;flex-wrap:nowrap;gap:12px;align-items:flex-end;width:100%;min-width:0;overflow-x:auto;">
        <?php if (!$CRM_CATALOGO_DEVOLVIDOS_PORTAL): ?>
        <input type="hidden" name="cliente_id" value="<?= (int) $clienteId ?>">
        <?php endif; ?>
        <?php if ($filtroTecId > 0): ?>
        <input type="hidden" name="tecnico_user_id" value="<?= (int) $filtroTecId ?>">
        <?php endif; ?>
        <div class="form-group" style="margin:0;flex:0 0 142px;min-width:0;">
          <label for="data_de" style="font-size:12px;">De</label>
          <input type="date" id="data_de" name="data_de" class="input" value="<?= htmlspecialchars($dataDe) ?>" required>
        </div>
        <div class="form-group" style="margin:0;flex:0 0 142px;min-width:0;">
          <label for="data_ate" style="font-size:12px;">Até</label>
          <input type="date" id="data_ate" name="data_ate" class="input" value="<?= htmlspecialchars($dataAte) ?>" required>
        </div>
        <div class="form-group" style="margin:0;flex:0 0 110px;min-width:0;">
          <label for="chamado_id_f" style="font-size:12px;">Chamado (#)</label>
          <input type="number" min="1" id="chamado_id_f" name="chamado_id" class="input"
                 value="<?= $filtroChamadoId > 0 ? (int) $filtroChamadoId : '' ?>" placeholder="Todos">
        </div>
        <div class="form-group" style="margin:0;flex:1 1 0;min-width:140px;">
          <label for="q_f" style="font-size:12px;">Buscar item</label>
          <input type="search" id="q_f" name="q" class="input" value="<?= htmlspecialchars($qBusca) ?>" placeholder="Nome ou código" style="width:100%;min-width:0;min-height:40px;font-size:14px;">
        </div>
        <div class="form-group" style="margin:0;flex:1 1 0;min-width:160px;max-width:280px;">
          <label for="item_search_input" style="font-size:12px;">Item</label>
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
        <button type="submit" class="btn btn-primary" style="justify-content:center;flex:0 0 auto;flex-shrink:0;">Aplicar filtros</button>
        <?php if ($temFiltroExtra): ?>
        <a href="<?= htmlspecialchars($limparHref) ?>" class="btn btn-secondary" style="flex:0 0 auto;flex-shrink:0;">Limpar</a>
        <?php endif; ?>
      </form>
    </div>

    <?php if (empty($linhas)): ?>
      <div class="panel-body"><p class="muted" style="margin:0;">Nenhum item devolvido/sucata neste filtro.</p></div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Data</th>
              <th>Chamado</th>
              <th>Item</th>
              <th class="text-right">Qtd</th>
              <th class="text-right">V. unit. (ref.)</th>
              <th class="text-right">Subtotal (ref.)</th>
              <th>Origem</th>
              <th>Técnico</th>
              <th>Observação</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($linhas as $ln): ?>
              <?php
                $chId = (int) ($ln['chamado_id'] ?? 0);
                $chHref = 'chamado_detalhe.php?id=' . $chId;
                $origem = (string) ($ln['origem'] ?? 'Catálogo');
                $isCriado = $origem === 'Criado';
                $dataLinha = (string) ($ln['lancamento_em'] ?? $ln['chamado_aberto_em'] ?? '');
                $dataFmt = $dataLinha !== '' ? date('d/m/Y H:i', strtotime($dataLinha)) : '—';
                $obs = trim((string) ($ln['observacao'] ?? ''));
                $tec = trim((string) ($ln['tecnico_nomes'] ?? ''));
              ?>
              <tr class="catalogo-devolvidos-row-link" role="link" tabindex="0"
                  data-href="<?= htmlspecialchars($chHref, ENT_QUOTES, 'UTF-8') ?>"
                  title="Abrir chamado #<?= $chId ?>">
                <td class="td-mute"><?= htmlspecialchars($dataFmt) ?></td>
                <td>
                  <a class="td-id" href="<?= htmlspecialchars($chHref) ?>">#<?= $chId ?></a>
                  <?php if (($ln['chamado_titulo'] ?? '') !== ''): ?>
                    <div class="td-title" style="font-size:13px;max-width:16rem;"><?= htmlspecialchars((string) $ln['chamado_titulo']) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="td-title"><?= htmlspecialchars((string) ($ln['item_nome'] ?? '')) ?></div>
                  <?php if (($ln['item_codigo'] ?? '') !== ''): ?>
                    <small class="muted">Cód. <?= htmlspecialchars((string) $ln['item_codigo']) ?></small>
                  <?php endif; ?>
                  <div class="td-mute" style="font-size:12px;"><?= htmlspecialchars((string) ($ln['item_tipo'] ?? '')) ?></div>
                </td>
                <td class="text-right"><?= htmlspecialchars($fmtQtd((float) ($ln['quantidade'] ?? 0))) ?></td>
                <td class="text-right td-mute">R$ <?= number_format((float) ($ln['valor_unitario'] ?? 0), 4, ',', '.') ?></td>
                <td class="text-right">R$ <?= number_format((float) ($ln['subtotal'] ?? 0), 2, ',', '.') ?></td>
                <td>
                  <span class="badge <?= $isCriado ? 'waiting' : 'info' ?>"><?= $isCriado ? 'Criado' : 'Catálogo' ?></span>
                </td>
                <td class="td-mute"><?= $tec !== '' ? htmlspecialchars($tec) : '—' ?></td>
                <td class="td-mute"><?= $obs !== '' ? htmlspecialchars($obs) : '—' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

</section>
</main>
</div>
<style>
.chamados-periodo-quick__btn--active {
  background: var(--primary, #534ab7);
  border-color: var(--primary, #534ab7);
  color: #fff;
}
.crm-searchable-select { position: relative; }
.crm-searchable-select__control {
  width: 100%;
  min-height: 40px;
  border: 1px solid var(--border, #d1d5db);
  border-radius: 8px;
  padding: 8px 12px;
  text-align: left;
  background: #fff;
  cursor: pointer;
  font-size: 14px;
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
  border: 1px solid var(--border, #d1d5db);
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
  max-height: 280px;
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
.catalogo-devolvidos-row-link { cursor: pointer; }
.catalogo-devolvidos-row-link:hover { background: var(--surface-hover, rgba(0, 0, 0, 0.04)); }
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

  document.querySelectorAll('.catalogo-devolvidos-row-link').forEach(function (row) {
    row.addEventListener('click', function (e) {
      if (e.target.closest('a')) return;
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
