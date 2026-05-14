<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/medicao_helpers.php';
require_once __DIR__ . '/../includes/modules.php';
$CLIENTE = require_auth('cliente');

$basePath   = '../';
$activePage = 'dashboard';

$cid = (int) ($CLIENTE['cliente_id'] ?? 0);
$scopeDash = $cid > 0 ? repo_cliente_matriz_raiz_id($cid) : 0;

$refYmDashboard = date('Y-m');
$osMesResumo    = ['n_total' => 0, 'valor_total' => 0.0];
$totalMedicaoMes = 0.0;

$mapDeRaw  = (string) ($_GET['map_de'] ?? '');
$mapAteRaw = (string) ($_GET['map_ate'] ?? '');
$mapDe     = $mapDeRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $mapDeRaw) ? $mapDeRaw : date('Y-m-d', strtotime('-30 days'));
$mapAte    = $mapAteRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $mapAteRaw) ? $mapAteRaw : date('Y-m-d');

$moduleChamadosMap = db_ok() && $scopeDash > 0 && app_modulo_habilitado('cliente', 'chamados');
$modulePontosMap   = db_ok() && $scopeDash > 0 && app_modulo_habilitado('cliente', 'pontos_iluminacao');

/** Aba «Mapa combinado» (dash_mapa=ambos): desligada temporariamente no UI. */
$dashMapaCombinadoHabilitado = false;

$dashMapaRaw = isset($_GET['mapa']) ? (string) $_GET['mapa'] : (string) ($_GET['dash_mapa'] ?? '');
$dashMapaAba = strtolower(trim($dashMapaRaw !== '' ? $dashMapaRaw : 'chamados'));
if (!in_array($dashMapaAba, ['chamados', 'pontos', 'ambos'], true)) {
    $dashMapaAba = 'chamados';
}
if ($dashMapaAba === 'ambos' && (!$moduleChamadosMap || !$modulePontosMap)) {
    $dashMapaAba = $moduleChamadosMap ? 'chamados' : 'pontos';
}
if ($dashMapaAba === 'chamados' && !$moduleChamadosMap && $modulePontosMap) {
    $dashMapaAba = 'pontos';
}
if ($dashMapaAba === 'pontos' && !$modulePontosMap && $moduleChamadosMap) {
    $dashMapaAba = 'chamados';
}

$pontoMapFiltro = strtolower(trim((string) ($_GET['ponto_filtro'] ?? '')));
if (!in_array($pontoMapFiltro, ['', 'chamados'], true)) {
    $pontoMapFiltro = '';
}

$mapPins = $moduleChamadosMap
    ? repo_chamados_mapa_pins($mapDe, $mapAte, $scopeDash)
    : [];
$statusChamadosMap = [];
foreach ($mapPins as $pinChamado) {
    $statusPin = trim((string) ($pinChamado['status'] ?? ''));
    if ($statusPin !== '') {
        $statusChamadosMap[$statusPin] = true;
    }
}
$statusChamadosMap = array_keys($statusChamadosMap);
natcasesort($statusChamadosMap);
$statusChamadosMap = array_values($statusChamadosMap);

$scopeIdPontos           = 0;
$pontosPinsPass          = [];
$pontosPinsTodos         = [];
$bairrosPontosDash       = [];
$totalPontosComChamados  = 0;

if ($modulePontosMap) {
    $scopeIdPontos = $scopeDash;
    if ($scopeIdPontos > 0) {
        $pontosPinsTodos = repo_pontos_iluminacao_mapa($scopeIdPontos, true);
        $totalPontosComChamados = count(array_filter($pontosPinsTodos, static function (array $p): bool {
            return ((int) ($p['chamados_abertos'] ?? 0)) > 0;
        }));
        $pontosPinsPass = $pontosPinsTodos;
        if ($pontoMapFiltro === 'chamados') {
            $pontosPinsPass = array_values(array_filter($pontosPinsTodos, static function (array $p): bool {
                return ((int) ($p['chamados_abertos'] ?? 0)) > 0;
            }));
        }
        $bMap = [];
        foreach ($pontosPinsTodos as $pp) {
            $br = trim((string) ($pp['bairro'] ?? ''));
            if ($br !== '') {
                $bMap[$br] = true;
            }
        }
        $bairrosPontosDash = array_keys($bMap);
        natcasesort($bairrosPontosDash);
        $bairrosPontosDash = array_values($bairrosPontosDash);
    }
}

if ($dashMapaAba === 'ambos') {
    if (!$dashMapaCombinadoHabilitado || !$moduleChamadosMap || !$modulePontosMap || $scopeIdPontos <= 0) {
        $dashMapaAba = $moduleChamadosMap ? 'chamados' : ($modulePontosMap ? 'pontos' : 'chamados');
    }
}

$loadLeafletChamados      = $moduleChamadosMap && $dashMapaAba === 'chamados';
$loadPontosMap            = $modulePontosMap && $scopeIdPontos > 0 && $dashMapaAba === 'pontos';
$loadMapaCombinado        = $moduleChamadosMap && $modulePontosMap && $scopeIdPontos > 0 && $dashMapaAba === 'ambos';
$loadLeaflet              = $loadLeafletChamados || $loadPontosMap || $loadMapaCombinado;
$loadLeafletMarkerCluster = $loadLeaflet;

$dashQsPreserve = static function (array $override = []) use ($mapDe, $mapAte, $pontoMapFiltro, $dashMapaAba): string {
    $qs = ['map_de' => $mapDe, 'map_ate' => $mapAte, 'dash_mapa' => $dashMapaAba];
    if ($pontoMapFiltro !== '') {
        $qs['ponto_filtro'] = $pontoMapFiltro;
    }
    foreach ($override as $k => $v) {
        if ($v === null || $v === '') {
            unset($qs[$k]);
        } else {
            $qs[$k] = $v;
        }
    }

    return http_build_query($qs);
};

if (db_ok() && $scopeDash > 0) {
    $dash = repo_dashboard_admin_stats($scopeDash);

    $ultimosRes = repo_chamados_admin_list('', '', 1, 5, $scopeDash);
    $ultimosCh  = $ultimosRes['rows'];

    $todosRes      = repo_chamados_admin_list('', '', 1, 500, $scopeDash);
    $todosCh       = $todosRes['rows'];
    $totalChamados = (int) $todosRes['total'];

    $clientesEmpresa = repo_clientes_na_empresa($scopeDash);
    if (empty($clientesEmpresa)) {
        $cliOne = repo_cliente($scopeDash);
        $clientesEmpresa = $cliOne ? [$cliOne] : [];
    }

    $contagemPorCliente = [];
    foreach ($todosCh as $ch) {
        $chClienteId = (int) ($ch['cliente_id'] ?? 0);
        if (!isset($contagemPorCliente[$chClienteId])) {
            $contagemPorCliente[$chClienteId] = ['chamados' => 0, 'urgentes' => 0];
        }
        $contagemPorCliente[$chClienteId]['chamados']++;
        if (in_array($ch['prioridade'] ?? '', ['Alta', 'Urgente'], true) && !in_array($ch['status'] ?? '', ['Resolvido', 'Fechado'], true)) {
            $contagemPorCliente[$chClienteId]['urgentes']++;
        }
    }

    $recentesCli = [];
    foreach (array_slice($clientesEmpresa, 0, 4) as $cli) {
        $cliId = (int) ($cli['id'] ?? 0);
        $recentesCli[] = [
            'empresa'   => $cli['empresa'] ?? '',
            'status'    => $cli['status'] ?? 'Ativo',
            'chamados'  => (int) ($contagemPorCliente[$cliId]['chamados'] ?? 0),
            'pendentes' => (int) ($contagemPorCliente[$cliId]['urgentes'] ?? 0),
        ];
    }

    $supAll   = repo_suporte($scopeDash);
    $supSlice = array_slice(array_filter($supAll, fn ($s) => in_array($s['status'] ?? '', ['Aberta', 'Respondendo', 'Pendente'], true)), 0, 3);
    if (empty($supSlice)) {
        $supSlice = array_slice($supAll, 0, 3);
    }

    if (function_exists('repo_os_pedido_resumo_mes')) {
        $osMesResumo = repo_os_pedido_resumo_mes($scopeDash, $refYmDashboard);
    }
    $impMes = repo_medicao_import_fetch($scopeDash, $refYmDashboard);
    foreach ($impMes['linhas'] ?? [] as $il) {
        $totalMedicaoMes += (float) ($il['valor_medido_periodo'] ?? 0);
    }
} else {
    $dash = null;
    $todosCh = array_values(array_filter($MOCK_CHAMADOS, fn ($c) => (int) ($c['cliente_id'] ?? 0) === $scopeDash));
    if (count($todosCh) === 0) {
        $todosCh = $MOCK_CHAMADOS;
    }
    $totalChamados = count($todosCh);
    $ultimosCh = array_slice($todosCh, 0, 5);
    $recentesCli = array_slice($MOCK_CLIENTES, 0, 4);
    $supSlice = array_slice(
        array_values(array_filter($MOCK_SUPORTE, fn ($s) => (int) ($s['cliente_id'] ?? 0) === $scopeDash)),
        0,
        3
    );
    $osMesResumo     = ['n_total' => 0, 'valor_total' => 0.0];
    $totalMedicaoMes = 0.0;
}

$topTitle    = 'Painel';
$topSubtitle = 'Visão executiva de chamados, mapa de atendimento e suporte.';
$topSearch   = 'Buscar chamado...';
$topAction   = ['label' => 'Abrir novo chamado', 'href' => 'chamado_novo.php', 'icon' => '+'];

$pageTitle = 'Painel';

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-cliente.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <div class="cards-metrics">
    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">Chamados abertos</div>
          <div class="metric-value"><?= $dash ? (int) $dash['ch_abertos'] : count(array_filter($todosCh, fn ($c) => ($c['status'] ?? '') === 'Aberto')) ?></div>
        </div>
        <div class="icon-box purple">CH</div>
      </div>
      <div class="metric-change warning"><?= $dash ? 'Prefeitura e unidades vinculadas' : 'Sem conexão ao banco' ?></div>
    </div>

    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">Chamados em andamento</div>
          <div class="metric-value"><?= $dash ? (int) $dash['ch_andamento'] : count(array_filter($todosCh, fn ($c) => ($c['status'] ?? '') === 'Em andamento')) ?></div>
        </div>
        <div class="icon-box green">→</div>
      </div>
      <div class="metric-change success"><?= $dash ? 'Atribuídos / em execução' : 'Conecte o MySQL' ?></div>
    </div>

    <?php if (app_modulo_habilitado('cliente', 'os')): ?>
    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">OS no mês</div>
          <div class="metric-value"><?= (int) ($osMesResumo['n_total'] ?? 0) ?></div>
        </div>
        <div class="icon-box" style="background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;font-weight:800;">OS</div>
      </div>
      <div class="metric-change info"><?= htmlspecialchars(medicao_mes_label_pt($refYmDashboard)) ?> · R$ <?= number_format((float) ($osMesResumo['valor_total'] ?? 0), 2, ',', '.') ?> em itens</div>
    </div>
    <?php endif; ?>

    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">Total da medição do mês</div>
          <div class="metric-value" style="font-size:1.25rem;">R$ <?= number_format($totalMedicaoMes, 2, ',', '.') ?></div>
        </div>
        <div class="icon-box red">BM</div>
      </div>
      <div class="metric-change info"><?= htmlspecialchars(medicao_mes_label_pt($refYmDashboard)) ?> · importação BM (valor medido no período)</div>
    </div>
  </div>

  <?php if ($moduleChamadosMap || $modulePontosMap): ?>
  <div class="dashboard-maps-row" style="grid-template-columns:1fr;margin-bottom:24px;">
    <div class="card dashboard-map-card">
      <div class="panel-head" style="flex-wrap:wrap;gap:10px;">
        <div>
          <h4>Mapas</h4>
          <span class="panel-sub">Chamados e pontos de iluminação.</span>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <?php if ($moduleChamadosMap): ?>
          <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'chamados'])) ?>" class="btn btn-sm <?= $dashMapaAba === 'chamados' ? 'btn-primary' : 'btn-secondary' ?>">Chamados</a>
          <?php endif; ?>
          <?php if ($modulePontosMap): ?>
          <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'pontos'])) ?>" class="btn btn-sm <?= $dashMapaAba === 'pontos' ? 'btn-primary' : 'btn-secondary' ?>">Postes</a>
          <?php endif; ?>
          <?php if ($dashMapaCombinadoHabilitado && $moduleChamadosMap && $modulePontosMap && $scopeIdPontos > 0): ?>
          <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'ambos'])) ?>" class="btn btn-sm <?= $dashMapaAba === 'ambos' ? 'btn-primary' : 'btn-secondary' ?>">Mapa combinado</a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($loadLeafletChamados): ?>
    <div class="card dashboard-map-card">
      <div class="panel-head">
        <h4>Mapa de chamados</h4>
        <span class="panel-sub">Geolocalização pela data de abertura</span>
      </div>
      <div class="panel-body">
        <form class="dashboard-map-filters dashboard-map-filters--chamados" method="get" action="index.php" aria-label="Filtros e ferramentas do mapa de chamados">
          <input type="hidden" name="dash_mapa" value="chamados">
          <?php if ($pontoMapFiltro !== ''): ?>
          <input type="hidden" name="ponto_filtro" value="<?= htmlspecialchars($pontoMapFiltro) ?>">
          <?php endif; ?>
          <div class="form-group">
            <label for="map_de">De</label>
            <input type="date" id="map_de" name="map_de" class="input" value="<?= htmlspecialchars($mapDe) ?>">
          </div>
          <div class="form-group">
            <label for="map_ate">Até</label>
            <input type="date" id="map_ate" name="map_ate" class="input" value="<?= htmlspecialchars($mapAte) ?>">
          </div>
          <div class="form-group form-group--chamados-map-status">
            <label for="chamados-map-filter-status">Status</label>
            <select id="chamados-map-filter-status" class="input">
              <option value="">Todos os status</option>
              <?php foreach ($statusChamadosMap as $statusMap): ?>
              <option value="<?= htmlspecialchars($statusMap) ?>"><?= htmlspecialchars($statusMap) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-primary">Atualizar mapa</button>
          <label class="dashboard-pontos-tools-toggle" for="chamados-map-toggle-cluster">
            <input type="checkbox" id="chamados-map-toggle-cluster" checked>
            Agrupar clusters
          </label>
          <div class="dashboard-pontos-tools-status dashboard-map-filters-chamados-meta" id="chamados-map-visible-count"><?php $nCh = count($mapPins); ?><?= (int) $nCh ?> de <?= (int) $nCh ?> chamado(s) visível(is)</div>
        </form>
        <div class="dashboard-map-resize-wrap" data-map-resize-key="cliente_chamados">
          <div id="chamados-map" class="dashboard-map-leaflet-host" role="region" aria-label="Mapa de chamados"></div>
          <button type="button" class="dashboard-map-resize-handle" aria-label="Redimensionar altura do mapa" title="Arraste para ajustar a altura (salvo neste navegador)"></button>
        </div>
        <p class="muted" style="font-size:12px;margin-top:10px;margin-bottom:0;">
          <?= count($mapPins) ?> chamado(s) com coordenadas no período.
        </p>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($loadPontosMap): ?>
    <div class="card dashboard-map-card">
      <div class="panel-head dashboard-map-pontos-panel-head">
        <div>
          <h4>Mapa de iluminação</h4>
          <span class="panel-sub"><?= count($pontosPinsPass) ?> de <?= count($pontosPinsTodos) ?> poste(s) · vermelho = chamados abertos no poste</span>
        </div>
        <div class="dashboard-map-pontos-head-actions">
          <div class="dashboard-map-pontos-presets">
            <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'pontos', 'ponto_filtro' => null])) ?>" class="btn btn-sm <?= $pontoMapFiltro === '' ? 'btn-primary' : 'btn-secondary' ?>">Todos os pontos</a>
            <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'pontos', 'ponto_filtro' => 'chamados'])) ?>" class="btn btn-sm <?= $pontoMapFiltro === 'chamados' ? 'btn-primary' : 'btn-secondary' ?>">Com chamados (<?= (int) $totalPontosComChamados ?>)</a>
          </div>
          <a class="btn btn-secondary btn-sm" href="pontos_iluminacao.php">Lista de postes</a>
        </div>
      </div>
      <div class="panel-body">
        <div class="dashboard-map-filters dashboard-map-filters--pontos" aria-label="Filtros e ferramentas do mapa de postes">
          <div class="form-group">
            <label for="map-filter-area">Área / bairro</label>
            <select id="map-filter-area" class="input">
              <option value="">Todas as áreas</option>
              <?php foreach ($bairrosPontosDash as $bairro): ?>
              <option value="<?= htmlspecialchars($bairro) ?>"><?= htmlspecialchars($bairro) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="map-filter-search">Buscar no mapa</label>
            <input id="map-filter-search" class="input" type="search" placeholder="Poste, endereço ou bairro">
          </div>
          <label class="dashboard-pontos-tools-toggle" for="map-toggle-cluster">
            <input type="checkbox" id="map-toggle-cluster" checked>
            Agrupar clusters
          </label>
          <div class="dashboard-pontos-tools-status dashboard-map-filters-pontos-meta" id="map-visible-count"><?= count($pontosPinsPass) ?> ponto(s) visível(is)</div>
        </div>
        <div class="dashboard-map-resize-wrap" data-map-resize-key="cliente_pontos">
          <div id="pontos-iluminacao-map" class="dashboard-map-leaflet-host" role="region" aria-label="Mapa de pontos de iluminação"></div>
          <button type="button" class="dashboard-map-resize-handle" aria-label="Redimensionar altura do mapa" title="Arraste para ajustar a altura (salvo neste navegador)"></button>
        </div>
      </div>
    </div>
    <?php elseif ($modulePontosMap && $scopeIdPontos <= 0): ?>
    <div class="card dashboard-map-card">
      <div class="panel-head"><h4>Mapa de iluminação</h4></div>
      <div class="panel-body">
        <p class="muted" style="margin:0;">Não foi possível carregar os postes no mapa.</p>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($loadMapaCombinado): ?>
    <div class="card dashboard-map-card">
      <div class="panel-head" style="flex-wrap:wrap;gap:10px;">
        <div>
          <h4>Mapa combinado</h4>
          <span class="panel-sub">Chamados no período e postes — marque ou desmarque camadas abaixo.</span>
        </div>
        <a class="btn btn-secondary btn-sm" href="pontos_iluminacao.php">Lista de postes</a>
      </div>
      <div class="panel-body">
        <form class="dashboard-map-filters" method="get" action="index.php" style="margin-bottom:12px;">
          <input type="hidden" name="dash_mapa" value="ambos">
          <?php if ($pontoMapFiltro !== ''): ?>
          <input type="hidden" name="ponto_filtro" value="<?= htmlspecialchars($pontoMapFiltro) ?>">
          <?php endif; ?>
          <div class="form-group">
            <label for="combo_map_de">Chamados — de</label>
            <input type="date" id="combo_map_de" name="map_de" class="input" value="<?= htmlspecialchars($mapDe) ?>">
          </div>
          <div class="form-group">
            <label for="combo_map_ate">Chamados — até</label>
            <input type="date" id="combo_map_ate" name="map_ate" class="input" value="<?= htmlspecialchars($mapAte) ?>">
          </div>
          <button type="submit" class="btn btn-primary" style="margin-top:18px;">Atualizar</button>
        </form>

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
          <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'ambos', 'ponto_filtro' => null])) ?>" class="btn btn-sm <?= $pontoMapFiltro === '' ? 'btn-primary' : 'btn-secondary' ?>">Todos os pontos</a>
          <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'ambos', 'ponto_filtro' => 'chamados'])) ?>" class="btn btn-sm <?= $pontoMapFiltro === 'chamados' ? 'btn-primary' : 'btn-secondary' ?>">Postes com chamados (<?= (int) $totalPontosComChamados ?>)</a>
        </div>

        <div style="display:flex;flex-wrap:wrap;gap:12px 20px;align-items:center;margin-bottom:14px;padding:10px 12px;background:var(--card-inner-bg, rgba(0,0,0,.04));border-radius:8px;">
          <span class="muted" style="font-size:13px;margin:0;">Exibir no mapa:</span>
          <label class="dashboard-pontos-tools-toggle" style="margin:0;">
            <input type="checkbox" id="combo-layer-chamados" checked>
            Chamados
          </label>
          <label class="dashboard-pontos-tools-toggle" style="margin:0;">
            <input type="checkbox" id="combo-layer-pontos" checked>
            Postes
          </label>
        </div>

        <p class="muted" style="font-size:12px;margin:0 0 10px;">
          <?= count($mapPins) ?> chamado(s) no período · <?= count($pontosPinsPass) ?> de <?= count($pontosPinsTodos) ?> poste(s)
        </p>

        <div class="dashboard-pontos-tools dashboard-chamados-tools" aria-label="Filtros dos chamados" style="margin-bottom:10px;">
          <div>
            <label for="combo-chamados-filter-status">Chamados — status</label>
            <select id="combo-chamados-filter-status" class="input">
              <option value="">Todos os status</option>
              <?php foreach ($statusChamadosMap as $statusMap): ?>
              <option value="<?= htmlspecialchars($statusMap) ?>"><?= htmlspecialchars($statusMap) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="combo-chamados-filter-search">Chamados — buscar</label>
            <input id="combo-chamados-filter-search" class="input" type="search" placeholder="Chamado, status ou endereço">
          </div>
        </div>

        <div class="dashboard-pontos-tools" aria-label="Filtros dos postes" style="margin-bottom:10px;">
          <div>
            <label for="combo-pontos-filter-area">Postes — bairro</label>
            <select id="combo-pontos-filter-area" class="input">
              <option value="">Todas as áreas</option>
              <?php foreach ($bairrosPontosDash as $bairro): ?>
              <option value="<?= htmlspecialchars($bairro) ?>"><?= htmlspecialchars($bairro) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="combo-pontos-filter-search">Postes — buscar</label>
            <input id="combo-pontos-filter-search" class="input" type="search" placeholder="Poste, endereço ou bairro">
          </div>
          <label class="dashboard-pontos-tools-toggle" for="combo-map-toggle-cluster">
            <input type="checkbox" id="combo-map-toggle-cluster" checked>
            Agrupar clusters
          </label>
          <div class="dashboard-pontos-tools-status" id="combo-map-visible-chamados">—</div>
          <div class="dashboard-pontos-tools-status" id="combo-map-visible-pontos">—</div>
        </div>

        <div class="dashboard-map-resize-wrap" data-map-resize-key="cliente_combinado">
          <div id="dashboard-mapa-combinado" class="dashboard-map-leaflet-host" role="region" aria-label="Mapa combinado"></div>
          <button type="button" class="dashboard-map-resize-handle" aria-label="Redimensionar altura do mapa" title="Arraste para ajustar a altura (salvo neste navegador)"></button>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="grid-main" style="grid-template-columns:1fr;">
    <div class="card">
      <div class="panel-head">
        <h4>Últimos chamados</h4>
        <a href="chamados.php">Ver todos</a>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Título</th>
              <th>Status</th>
              <th>Prioridade</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($ultimosCh)): ?>
            <tr>
              <td colspan="4" class="muted" style="padding:24px;text-align:center;">Nenhum chamado encontrado.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($ultimosCh as $c): ?>
              <tr onclick="location.href='chamado_detalhe.php?id=<?= (int) $c['id'] ?>'" style="cursor:pointer;">
                <td class="td-id">#<?= (int) $c['id'] ?></td>
                <td class="td-title"><?= htmlspecialchars($c['titulo']) ?></td>
                <td><span class="badge <?= status_class($c['status']) ?>"><?= htmlspecialchars($c['status']) ?></span></td>
                <td><span class="badge <?= status_class($c['prioridade']) ?>"><?= htmlspecialchars($c['prioridade']) ?></span></td>
              </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="bottom-grid">
    <div class="card">
      <div class="panel-head">
        <h4>Unidades e pontos de atendimento</h4>
        <span class="panel-sub"><?= count($recentesCli) ?> conta(s) na matriz (amostra)</span>
      </div>
      <div class="list">
        <?php if (empty($recentesCli)): ?>
          <p class="muted" style="padding:16px;margin:0;">Nenhuma unidade vinculada encontrada.</p>
        <?php else: ?>
        <?php foreach ($recentesCli as $cli): ?>
          <div class="list-item">
            <div class="cell-client">
              <div class="avatar"><?= initials($cli['empresa']) ?></div>
              <div>
                <strong><?= htmlspecialchars($cli['empresa']) ?></strong>
                <small><?= (int) ($cli['chamados'] ?? 0) ?> chamados · <?= (int) ($cli['pendentes'] ?? 0) ?> urgentes</small>
              </div>
            </div>
            <span class="badge <?= status_class($cli['status']) ?>"><?= htmlspecialchars($cli['status']) ?></span>
          </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="panel-head">
        <h4>Suporte / Dúvidas</h4>
        <a href="suporte.php">Abrir dúvida</a>
      </div>
      <?php if (empty($supSlice)): ?>
        <p class="muted" style="padding:16px;margin:0;">Você ainda não enviou dúvidas pelo portal.</p>
      <?php else: ?>
      <?php foreach ($supSlice as $s): ?>
        <div class="support-item">
          <strong><?= htmlspecialchars($s['pergunta']) ?></strong>
          <p><?= htmlspecialchars((string) ($s['detalhe'] ?? '')) ?></p>
          <div class="support-meta">
            <span><?= htmlspecialchars($s['cliente'] ?? '') ?> · <?= date('d/m', strtotime($s['data'])) ?></span>
            <span class="badge <?= status_class($s['status']) ?>"><?= htmlspecialchars((string) $s['status']) ?></span>
          </div>
        </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php if ($loadLeaflet): ?>
<script src="<?= $basePath ?>assets/js/dashboard-map-resize.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/dashboard-map-resize.js') ?>"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<?php endif; ?>
<?php if ($loadLeafletMarkerCluster): ?>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js" crossorigin=""></script>
<?php endif; ?>
<?php if ($loadLeafletChamados): ?>
<script>
  window.CHAMADOS_MAP_PINS = <?= json_encode($mapPins, JSON_UNESCAPED_UNICODE) ?: '[]' ?>;
</script>
<script src="<?= $basePath ?>assets/js/dashboard-map.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/dashboard-map.js') ?>"></script>
<?php endif; ?>
<?php if ($loadPontosMap): ?>
<script>
  window.PONTOS_ILUMINACAO_MAP = <?= json_encode($pontosPinsPass, JSON_UNESCAPED_UNICODE) ?: '[]' ?>;
</script>
<script src="<?= $basePath ?>assets/js/pontos-iluminacao-map.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/pontos-iluminacao-map.js') ?>"></script>
<?php endif; ?>
<?php if ($loadMapaCombinado): ?>
<script>
  window.DASHBOARD_MAP_COMBINED = {
    chamados: <?= json_encode($mapPins, JSON_UNESCAPED_UNICODE) ?: '[]' ?>,
    pontos: <?= json_encode($pontosPinsPass, JSON_UNESCAPED_UNICODE) ?: '[]' ?>
  };
</script>
<script src="<?= $basePath ?>assets/js/dashboard-map-combined.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/dashboard-map-combined.js') ?>"></script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
