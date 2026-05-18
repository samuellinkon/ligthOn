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

$filtroChamadoId = (int) ($_GET['chamado_id'] ?? 0);
$filtroItemId    = (int) ($_GET['item_id'] ?? 0);
$filtroTecId     = (int) ($_GET['tecnico_user_id'] ?? 0);

$filtrosRepo = [];
if ($movFiltro !== '') {
    $filtrosRepo['movimento'] = $movFiltro;
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
$operadoresOpts    = repo_operadores_empresa($clienteId);

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
    <div class="panel-head" style="flex-wrap:wrap;gap:12px;">
      <div>
        <h4>Catálogo aplicado em chamados</h4>
        <span class="panel-sub">Todos os lançamentos de itens do catálogo em chamados · data do chamado = abertura · adequado para exportação futura</span>
      </div>
      <div style="font-size:14px;font-weight:600;">
        <?= count($linhas) ?> lançamento(s)
        <?php if ($totalValorUtil > 0): ?>
          · Total utilizados: R$ <?= number_format($totalValorUtil, 2, ',', '.') ?>
        <?php endif; ?>
      </div>
    </div>
    <div class="panel-body" style="padding-top:0;">
      <form class="filters filters--form catalogo-aplicado-filters" method="get" action="catalogo_chamados_materiais.php" style="margin-bottom:18px;display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
        <?php if (!$CRM_CATALOGO_APLICADO_PORTAL): ?>
        <input type="hidden" name="cliente_id" value="<?= (int) $clienteId ?>">
        <?php endif; ?>
        <div class="form-group" style="margin:0;">
          <label for="data_de">Período — de</label>
          <input type="date" id="data_de" name="data_de" class="input" value="<?= htmlspecialchars($dataDe) ?>" required>
        </div>
        <div class="form-group" style="margin:0;">
          <label for="data_ate">até</label>
          <input type="date" id="data_ate" name="data_ate" class="input" value="<?= htmlspecialchars($dataAte) ?>" required>
        </div>
        <div class="form-group" style="margin:0;">
          <label for="movimento_f">Movimento</label>
          <select id="movimento_f" name="movimento" class="select">
            <option value="" <?= $movFiltro === '' ? 'selected' : '' ?>>Todos</option>
            <option value="utilizado" <?= $movFiltro === 'utilizado' ? 'selected' : '' ?>>Utilizado</option>
            <option value="devolvido" <?= $movFiltro === 'devolvido' ? 'selected' : '' ?>>Recolhido / devolvido</option>
          </select>
        </div>
        <div class="form-group" style="margin:0;">
          <label for="chamado_id_f">Chamado (#)</label>
          <input type="number" min="1" id="chamado_id_f" name="chamado_id" class="input" style="width:7rem;"
                 value="<?= $filtroChamadoId > 0 ? (int) $filtroChamadoId : '' ?>" placeholder="Todos">
        </div>
        <div class="form-group" style="margin:0;">
          <label for="item_id_f">Item</label>
          <select id="item_id_f" name="item_id" class="select" style="min-width:12rem;max-width:18rem;">
            <option value="">Todos</option>
            <?php foreach ($catalogoItensOpts as $it): ?>
              <option value="<?= (int) $it['id'] ?>"<?= $filtroItemId === (int) $it['id'] ? ' selected' : '' ?>>
                <?= htmlspecialchars((string) ($it['tipo'] ?? '') . ' · ' . ($it['nome'] ?? '')) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="margin:0;">
          <label for="tecnico_user_id_f">Técnico (chamado)</label>
          <select id="tecnico_user_id_f" name="tecnico_user_id" class="select" style="min-width:11rem;">
            <option value="">Todos</option>
            <?php foreach ($operadoresOpts as $op): ?>
              <option value="<?= (int) ($op['id'] ?? 0) ?>"<?= $filtroTecId === (int) ($op['id'] ?? 0) ? ' selected' : '' ?>>
                <?= htmlspecialchars((string) ($op['nome'] ?? '')) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filters-form-actions">
          <button type="submit" class="btn btn-primary">Filtrar</button>
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
                <th>Técnico no chamado</th>
                <th>Item</th>
                <th>Movimento</th>
                <th class="text-right">Qtd</th>
                <th>Un.</th>
                <th class="text-right">V. unit.</th>
                <th class="text-right">V. total</th>
                <th>Observação</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($linhas as $ln): ?>
                <?php
                  $mov = (string) ($ln['movimento'] ?? '');
                  $isU = $mov !== 'devolvido';
                ?>
                <tr>
                  <td>
                    <a href="chamado_detalhe.php?id=<?= (int) ($ln['chamado_id'] ?? 0) ?>">#<?= (int) ($ln['chamado_id'] ?? 0) ?></a>
                    <?php if (($ln['chamado_titulo'] ?? '') !== ''): ?>
                      <div class="td-mute" style="font-size:12px;max-width:14rem;"><?= htmlspecialchars((string) $ln['chamado_titulo']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="td-mute"><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) ($ln['chamado_aberto_em'] ?? 'now')))) ?></td>
                  <td><?= htmlspecialchars((string) ($ln['tecnico_nome'] ?? '') ?: '—') ?></td>
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
                  <td class="td-mute"><?= htmlspecialchars((string) ($ln['observacao'] ?? '')) ?></td>
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
<?php include __DIR__ . '/../includes/footer.php'; ?>
