<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';

$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('pontos_iluminacao');

$pageTitle  = 'Pontos de iluminação';
$basePath   = '../';
$activePage = 'pontos_iluminacao';

if (!db_ok()) {
    flash_set('err', 'Banco indisponível.');
    header('Location: clientes.php');
    exit;
}

$escopoEmpresa = gestao_scope_cliente_id($me);
$clienteIdUrl = (int) ($_GET['cliente_id'] ?? 0);
if ($escopoEmpresa !== null) {
    $scopeId = $escopoEmpresa;
} else {
    $scopeId = $clienteIdUrl;
    if ($scopeId <= 0) {
        $scopeId = (int) (repo_catalogo_cliente_id_padrao_admin() ?? 0);
    }
    if ($scopeId > 0) {
        $scopeId = repo_cliente_matriz_raiz_id($scopeId);
    }
    if ($scopeId <= 0) {
        $empresas = repo_clientes_empresas();
        $scopeId = (int) ($empresas[0]['id'] ?? 0);
    }
}

if ($scopeId <= 0) {
    flash_set('err', 'Cadastre uma empresa ou cliente antes de acompanhar pontos.');
    header('Location: clientes.php');
    exit;
}
gestor_assert_escopo_cliente($scopeId, 'clientes.php');

$q = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
if (!in_array($status, ['', 'Ativo', 'Inativo'], true)) {
    $status = '';
}

$filtroMapa = strtolower(trim((string) ($_GET['filtro'] ?? '')));
if (!in_array($filtroMapa, ['', 'chamados'], true)) {
    $filtroMapa = '';
}

$pontosTodosEscopo = repo_pontos_iluminacao_list($scopeId, true, '', '');
$totalPontosStats  = count($pontosTodosEscopo);
$comChamadoStats   = count(array_filter($pontosTodosEscopo, static function (array $p): bool {
    return ((int) ($p['chamados_abertos'] ?? 0)) > 0;
}));
$totalAtivosStats = count(array_filter($pontosTodosEscopo, static function (array $p): bool {
    return ($p['status'] ?? '') === 'Ativo';
}));
$totalInativosStats = count(array_filter($pontosTodosEscopo, static function (array $p): bool {
    return ($p['status'] ?? '') === 'Inativo';
}));

$pontos = repo_pontos_iluminacao_list($scopeId, true, $q, $status);

$pinsTodos = repo_pontos_iluminacao_mapa($scopeId, true);
$pinsMapa  = $pinsTodos;
if ($filtroMapa === 'chamados') {
    $pinsMapa = array_values(array_filter($pinsTodos, static function (array $p): bool {
        return ((int) ($p['chamados_abertos'] ?? 0)) > 0;
    }));
}
$bairrosMapa = [];
foreach ($pinsMapa as $p) {
    $bairro = trim((string) ($p['bairro'] ?? ''));
    if ($bairro !== '') {
        $bairrosMapa[$bairro] = true;
    }
}
$bairrosMapa = array_keys($bairrosMapa);
natcasesort($bairrosMapa);
$bairrosMapa = array_values($bairrosMapa);

$pontosListaQs = static function (array $override = []) use ($scopeId, $q, $status, $filtroMapa): string {
    $p = ['cliente_id' => (string) (int) $scopeId];
    if ($q !== '') {
        $p['q'] = $q;
    }
    if ($status !== '') {
        $p['status'] = $status;
    }
    if ($filtroMapa !== '') {
        $p['filtro'] = $filtroMapa;
    }
    foreach ($override as $k => $v) {
        if ($v === null || $v === '') {
            unset($p[$k]);
        } else {
            $p[$k] = $v;
        }
    }

    return http_build_query($p);
};

$loadLeaflet              = true;
$loadLeafletMarkerCluster = true;

$topTitle    = 'Pontos de iluminação';
$topSubtitle = 'Postes cadastrados e situação por chamados.';
$topSearch   = 'Buscar ponto...';
$clienteNovoPonto = $clienteIdUrl > 0 ? $clienteIdUrl : $scopeId;
$topAction   = ['label' => 'Novo poste', 'href' => 'ponto_iluminacao_novo.php?cliente_id=' . (int) $clienteNovoPonto, 'icon' => '+'];
$topActions  = [
    ['label' => 'Importar parque', 'href' => 'pontos_iluminacao_importar.php?cliente_id=' . (int) $clienteNovoPonto, 'icon' => '⇪'],
];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content pontos-iluminacao-lista-layout">
  <div class="cards-metrics" style="margin-bottom:20px;">
    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">Total de pontos</div>
          <div class="metric-value"><?= (int) $totalPontosStats ?></div>
        </div>
        <div class="icon-box purple">PT</div>
      </div>
      <div class="metric-change info">Parque no escopo da empresa</div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">Em chamados abertos</div>
          <div class="metric-value"><?= (int) $comChamadoStats ?></div>
        </div>
        <div class="icon-box red">!</div>
      </div>
      <div class="metric-change warning">Postes com chamado em aberto</div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">Ativos</div>
          <div class="metric-value"><?= (int) $totalAtivosStats ?></div>
        </div>
        <div class="icon-box green">✓</div>
      </div>
      <div class="metric-change success">Em operação</div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">Inativos</div>
          <div class="metric-value"><?= (int) $totalInativosStats ?></div>
        </div>
        <div class="icon-box neutral">—</div>
      </div>
      <div class="metric-change">Fora de uso / desativados</div>
    </div>
  </div>

  <div class="card" id="mapa-pontos">
    <div class="panel-head">
      <h4>Mapa de acompanhamento</h4>
      <span class="panel-sub"><?= count($pinsMapa) ?> de <?= count($pinsTodos) ?> ponto(s) ativo(s) com coordenadas · vermelho = chamados abertos</span>
    </div>
    <div class="panel-body">
      <div class="pontos-mapa-toolbar" aria-label="Vistas do mapa">
        <a href="pontos_iluminacao.php?<?= htmlspecialchars($pontosListaQs(['filtro' => null])) ?>" class="btn btn-sm <?= $filtroMapa === '' ? 'btn-primary' : 'btn-secondary' ?>">Todos no mapa</a>
        <a href="pontos_iluminacao.php?<?= htmlspecialchars($pontosListaQs(['filtro' => 'chamados'])) ?>" class="btn btn-sm <?= $filtroMapa === 'chamados' ? 'btn-primary' : 'btn-secondary' ?>">Chamados (<?= (int) $comChamadoStats ?>)</a>
        <label class="pontos-mapa-cluster-toggle" for="map-toggle-cluster">
          <input type="checkbox" id="map-toggle-cluster" checked>
          <span>Agrupar em clusters</span>
        </label>
        <span class="pontos-mapa-toolbar-spacer" aria-hidden="true"></span>
        <a href="pontos_iluminacao_rotas.php?cliente_id=<?= (int) $scopeId ?>" class="btn btn-sm btn-primary">Rotas</a>
      </div>
      <div class="dashboard-pontos-tools" aria-label="Ferramentas do mapa">
        <div>
          <label for="map-filter-area">Área / bairro</label>
          <select id="map-filter-area" class="input">
            <option value="">Todas as áreas</option>
            <?php foreach ($bairrosMapa as $bairro): ?>
            <option value="<?= htmlspecialchars($bairro) ?>"><?= htmlspecialchars($bairro) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="map-filter-search">Buscar no mapa</label>
          <input id="map-filter-search" class="input" type="search" placeholder="Poste, endereço, bairro ou referência">
        </div>
        <div class="dashboard-pontos-tools-status" id="map-visible-count"><?= count($pinsMapa) ?> ponto(s) visível(is)</div>
      </div>
      <div id="pontos-iluminacao-map" role="region" aria-label="Mapa de pontos de iluminação"></div>
    </div>
  </div>

  <div class="card">
    <div class="panel-head">
      <h4>Listagem de pontos</h4>
      <span class="panel-sub"><?= count($pontos) ?> ponto(s) no filtro atual</span>
    </div>

    <form class="filters filters--form" method="get" action="pontos_iluminacao.php">
      <input type="hidden" name="cliente_id" value="<?= (int) $scopeId ?>">
      <?php if ($filtroMapa !== ''): ?>
      <input type="hidden" name="filtro" value="<?= htmlspecialchars($filtroMapa) ?>">
      <?php endif; ?>
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
        <a href="#mapa-pontos" class="btn btn-secondary">Ir ao mapa</a>
        <a href="ponto_iluminacao_novo.php?cliente_id=<?= (int) $clienteNovoPonto ?>" class="btn btn-secondary">Adicionar poste</a>
      </div>
    </form>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Poste</th>
            <th>Prefeitura</th>
            <th>Local</th>
            <th>Chamados</th>
            <th>Status</th>
            <th class="text-right">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$pontos): ?>
          <tr><td colspan="6" class="muted" style="padding:24px;text-align:center;">Nenhum ponto encontrado.</td></tr>
          <?php else: foreach ($pontos as $p): ?>
          <tr>
            <td><strong><?= htmlspecialchars((string) ($p['codigo_poste'] ?? '')) ?></strong></td>
            <td><?= htmlspecialchars((string) ($p['cliente_empresa'] ?? '')) ?></td>
            <td class="td-mute"><?= htmlspecialchars((string) ($p['bairro'] ?? '')) ?><?php if (!empty($p['endereco_completo'])): ?><br><small><?= htmlspecialchars((string) $p['endereco_completo']) ?></small><?php endif; ?></td>
            <td>
              <span class="badge <?= ((int) ($p['chamados_abertos'] ?? 0)) > 0 ? 'urgent' : 'done' ?>">
                <?= (int) ($p['chamados_abertos'] ?? 0) ?> aberto(s)
              </span>
            </td>
            <td><span class="badge <?= ($p['status'] ?? '') === 'Ativo' ? 'done' : 'plain' ?>"><?= htmlspecialchars((string) ($p['status'] ?? '')) ?></span></td>
            <td class="td-actions">
              <a class="action primary" href="ponto_iluminacao_novo.php?id=<?= (int) $p['id'] ?>&amp;cliente_id=<?= (int) ($p['cliente_id'] ?? $clienteNovoPonto) ?>">Editar</a>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js" crossorigin=""></script>
<script>
  window.PONTOS_ILUMINACAO_MAP = <?= json_encode($pinsMapa, JSON_UNESCAPED_UNICODE) ?: '[]' ?>;
</script>
<script src="<?= $basePath ?>assets/js/pontos-iluminacao-map.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/pontos-iluminacao-map.js') ?>"></script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
