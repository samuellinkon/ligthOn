<?php
require_once __DIR__ . '/../includes/auth.php';
$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_once __DIR__ . '/../includes/medicao_helpers.php';

$pageTitle  = 'Dashboard Admin';
$basePath   = '../';
$activePage = 'dashboard';

$escopoDash = gestao_scope_cliente_id($me);

$mapPeriodoRaw = strtolower(trim((string) ($_GET['map_periodo'] ?? '')));
$mapPeriodoAllowed = ['hoje', 'ontem', 'semana', 'mes'];
$mapPeriodo = in_array($mapPeriodoRaw, $mapPeriodoAllowed, true) ? $mapPeriodoRaw : 'mes';

$todayAdmin = new DateTimeImmutable('today');
switch ($mapPeriodo) {
    case 'hoje':
        $mapDe = $mapAte = $todayAdmin->format('Y-m-d');
        break;
    case 'ontem':
        $ontem = $todayAdmin->modify('-1 day');
        $mapDe = $mapAte = $ontem->format('Y-m-d');
        break;
    case 'semana':
        $segunda = $todayAdmin->modify('monday this week');
        $mapDe = $segunda->format('Y-m-d');
        $mapAte = $todayAdmin->format('Y-m-d');
        break;
    case 'mes':
    default:
        $mapDe = $todayAdmin->format('Y-m-01');
        $mapAte = $todayAdmin->format('Y-m-d');
        break;
}

$moduleChamadosMap = db_ok() && app_modulo_painel_habilitado('chamados', $me);
$modulePontosMap   = db_ok() && app_modulo_painel_habilitado('pontos_iluminacao', $me);

/** Aba «Mapa combinado» (dash_mapa=ambos): desligada temporariamente no UI. */
$dashMapaCombinadoHabilitado = false;

/** Mapa admin: matriz padrão (sem seletor de empresa na URL). */
$modoClienteUnicoAdmin = db_ok();

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

$mapPins = $moduleChamadosMap
    ? repo_chamados_mapa_pins($mapDe, $mapAte, $escopoDash)
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

$pontoMapFiltro = strtolower(trim((string) ($_GET['ponto_filtro'] ?? '')));
if (!in_array($pontoMapFiltro, ['', 'chamados'], true)) {
    $pontoMapFiltro = '';
}

$scopeIdPontos           = 0;
$pontosPinsPass          = [];
$pontosPinsTodos         = [];
$bairrosPontosDash       = [];
$totalPontosComChamados  = 0;
$empresasDashOptions     = [];

if ($modulePontosMap) {
    $escopoEmpresaPontos = gestao_scope_cliente_id($me);
    if ($escopoEmpresaPontos !== null) {
        $scopeIdPontos = $escopoEmpresaPontos;
    } elseif ($modoClienteUnicoAdmin) {
        $scopeIdPontos = (int) (repo_catalogo_cliente_id_padrao_admin() ?? 0);
        if ($scopeIdPontos <= 0) {
            $scopeIdPontos = (int) (repo_cliente_raiz_principal_id() ?? 0);
        }
        if ($scopeIdPontos > 0) {
            $scopeIdPontos = repo_cliente_matriz_raiz_id($scopeIdPontos);
        }
        if ($scopeIdPontos <= 0) {
            $empresasDashOptionArr = repo_clientes_empresas();
            $scopeIdPontos = (int) ($empresasDashOptionArr[0]['id'] ?? 0);
            if ($scopeIdPontos > 0) {
                $scopeIdPontos = repo_cliente_matriz_raiz_id($scopeIdPontos);
            }
        }
        $empresasDashOptions = [];
    } else {
        $scopeIdPontos = (int) ($_GET['dash_cliente_id'] ?? 0);
        if ($scopeIdPontos <= 0) {
            $scopeIdPontos = (int) (repo_catalogo_cliente_id_padrao_admin() ?? 0);
        }
        if ($scopeIdPontos > 0) {
            $scopeIdPontos = repo_cliente_matriz_raiz_id($scopeIdPontos);
        }
        if ($scopeIdPontos <= 0) {
            $empresasDashOptionArr = repo_clientes_empresas();
            $scopeIdPontos = (int) ($empresasDashOptionArr[0]['id'] ?? 0);
        }
        $empresasDashOptions = repo_clientes_empresas();
    }
    if ($scopeIdPontos > 0) {
        gestor_assert_escopo_cliente($scopeIdPontos, 'index.php');
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

/** Preserva filtros do dashboard nos links e formulários */
$dashQsPreserve = static function (array $override = []) use ($mapPeriodo, $escopoDash, $scopeIdPontos, $pontoMapFiltro, $dashMapaAba, $modoClienteUnicoAdmin): string {
    $qs = ['map_periodo' => $mapPeriodo, 'dash_mapa' => $dashMapaAba];
    if ($escopoDash === null && $scopeIdPontos > 0 && !$modoClienteUnicoAdmin) {
        $qs['dash_cliente_id'] = $scopeIdPontos;
    }
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

$refYmDashboard = date('Y-m');
$dash = db_ok() ? repo_dashboard_admin_stats($escopoDash) : null;

if ($dash) {
    if ($escopoDash !== null) {
        $ultimosCh = repo_chamados_admin_list('', '', 1, 5, $escopoDash)['rows'];
    } else {
        $ultimosCh = array_slice(repo_chamados(), 0, 5);
    }
} else {
    $ultimosCh = array_slice($MOCK_CHAMADOS, 0, 5);
}

$topTitle    = 'Dashboard';
$topSubtitle = 'Visão executiva de chamados e indicadores.';
$topSearch   = 'Buscar chamado ou prefeitura...';
$topAction   = app_modulo_painel_habilitado('chamados', $me)
    ? ['label' => 'Novo chamado', 'href' => 'chamado_novo.php', 'icon' => '+']
    : null;

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <div class="dashboard-admin-metrics">
  <div class="cards-metrics">
    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">Chamados abertos</div>
          <div class="metric-value"><?= $dash ? (int) $dash['ch_abertos'] : count(array_filter($MOCK_CHAMADOS, fn ($c) => ($c['status'] ?? '') === 'Aberto')) ?></div>
        </div>
        <div class="icon-box">CH</div>
      </div>
      <div class="metric-change metric-change--admin"><?= $dash ? 'Em tempo real' : 'Sem conexão ao banco' ?></div>
    </div>

    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">Chamados em andamento</div>
          <div class="metric-value"><?= $dash ? (int) $dash['ch_andamento'] : count(array_filter($MOCK_CHAMADOS, fn ($c) => ($c['status'] ?? '') === 'Em andamento')) ?></div>
        </div>
        <div class="icon-box">AN</div>
      </div>
      <div class="metric-change metric-change--admin"><?= $dash ? 'Atribuídos / em execução' : 'Sem conexão ao banco' ?></div>
    </div>

    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">Total de pontos</div>
          <div class="metric-value"><?= $dash ? (int) ($dash['pontos_total'] ?? 0) : 0 ?></div>
        </div>
        <div class="icon-box">PT</div>
      </div>
      <div class="metric-change metric-change--admin"><?= $dash ? ($escopoDash !== null ? 'Iluminação no escopo da gestão' : 'Pontos de iluminação cadastrados') : 'Sem conexão ao banco' ?></div>
    </div>

    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">Medição do mês (BM)</div>
          <div class="metric-value" style="font-size:1.25rem;">R$ <?= $dash ? number_format((float) ($dash['medicao_mes_valor'] ?? 0), 2, ',', '.') : '0,00' ?></div>
        </div>
        <div class="icon-box">BM</div>
      </div>
      <div class="metric-change metric-change--admin"><?= $dash ? htmlspecialchars(medicao_mes_label_pt($refYmDashboard)) . ' · soma do valor medido nas linhas importadas' : 'Sem conexão ao banco' ?></div>
    </div>
  </div>
  </div>

  <?php if ($moduleChamadosMap || $modulePontosMap): ?>
  <div class="dashboard-maps-row" style="grid-template-columns:1fr;">
    <?php if ($loadLeafletChamados): ?>
    <div class="card dashboard-map-card">
      <div class="panel-head">
        <h4>Mapa de chamados</h4>
        <span class="panel-sub">Geolocalização dos chamados pela data de abertura</span>
      </div>
      <div class="panel-body">
        <form class="dashboard-map-filters dashboard-map-filters--chamados" method="get" action="index.php" aria-label="Filtros e ferramentas do mapa de chamados">
          <input type="hidden" name="dash_mapa" value="chamados">
          <input type="hidden" name="map_periodo" value="<?= htmlspecialchars($mapPeriodo) ?>">
          <?php if ($escopoDash === null && $scopeIdPontos > 0 && !$modoClienteUnicoAdmin): ?>
          <input type="hidden" name="dash_cliente_id" value="<?= (int) $scopeIdPontos ?>">
          <?php endif; ?>
          <?php if ($pontoMapFiltro !== ''): ?>
          <input type="hidden" name="ponto_filtro" value="<?= htmlspecialchars($pontoMapFiltro) ?>">
          <?php endif; ?>
          <div class="dashboard-map-chamados-toolbar">
            <div class="dashboard-map-type-tabs" role="group" aria-label="Tipo de mapa">
              <?php if ($moduleChamadosMap): ?>
              <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'chamados'])) ?>" class="btn btn-sm <?= $dashMapaAba === 'chamados' ? 'btn-primary' : 'btn-secondary' ?>">Chamados</a>
              <?php endif; ?>
              <?php if ($modulePontosMap): ?>
              <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'pontos'])) ?>" class="btn btn-sm <?= $dashMapaAba === 'pontos' ? 'btn-primary' : 'btn-secondary' ?>">Pontos</a>
              <?php endif; ?>
              <?php if ($dashMapaCombinadoHabilitado && $moduleChamadosMap && $modulePontosMap && $scopeIdPontos > 0): ?>
              <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'ambos'])) ?>" class="btn btn-sm <?= $dashMapaAba === 'ambos' ? 'btn-primary' : 'btn-secondary' ?>">Mapa combinado</a>
              <?php endif; ?>
            </div>
            <div class="dashboard-map-chamados-period" role="group" aria-label="Período do mapa">
              <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'chamados', 'map_periodo' => 'hoje'])) ?>" class="btn btn-sm <?= $mapPeriodo === 'hoje' ? 'btn-primary' : 'btn-secondary' ?>">Dia atual</a>
              <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'chamados', 'map_periodo' => 'ontem'])) ?>" class="btn btn-sm <?= $mapPeriodo === 'ontem' ? 'btn-primary' : 'btn-secondary' ?>">Dia anterior</a>
              <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'chamados', 'map_periodo' => 'semana'])) ?>" class="btn btn-sm <?= $mapPeriodo === 'semana' ? 'btn-primary' : 'btn-secondary' ?>">Semana atual</a>
              <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'chamados', 'map_periodo' => 'mes'])) ?>" class="btn btn-sm <?= $mapPeriodo === 'mes' ? 'btn-primary' : 'btn-secondary' ?>">Mês atual</a>
            </div>
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
        <div class="dashboard-map-resize-wrap" data-map-resize-key="admin_chamados">
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
      <div class="panel-head">
        <h4>Mapa de iluminação</h4>
        <span class="panel-sub"><?= count($pontosPinsPass) ?> de <?= count($pontosPinsTodos) ?> ponto(s) · postes em vermelho com chamados abertos</span>
      </div>
      <div class="panel-body">
        <div class="dashboard-map-filters dashboard-map-filters--pontos" aria-label="Filtros e ferramentas do mapa de postes">
          <div class="dashboard-map-pontos-toolbar">
            <div class="dashboard-map-type-tabs" role="group" aria-label="Tipo de mapa">
              <?php if ($moduleChamadosMap): ?>
              <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'chamados'])) ?>" class="btn btn-sm <?= $dashMapaAba === 'chamados' ? 'btn-primary' : 'btn-secondary' ?>">Chamados</a>
              <?php endif; ?>
              <?php if ($modulePontosMap): ?>
              <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'pontos'])) ?>" class="btn btn-sm <?= $dashMapaAba === 'pontos' ? 'btn-primary' : 'btn-secondary' ?>">Pontos</a>
              <?php endif; ?>
              <?php if ($dashMapaCombinadoHabilitado && $moduleChamadosMap && $modulePontosMap && $scopeIdPontos > 0): ?>
              <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'ambos'])) ?>" class="btn btn-sm <?= $dashMapaAba === 'ambos' ? 'btn-primary' : 'btn-secondary' ?>">Mapa combinado</a>
              <?php endif; ?>
            </div>
            <div class="dashboard-map-pontos-presets" role="group" aria-label="Exibição dos postes">
              <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'pontos', 'ponto_filtro' => null])) ?>" class="btn btn-sm <?= $pontoMapFiltro === '' ? 'btn-primary' : 'btn-secondary' ?>">Todos os pontos</a>
              <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'pontos', 'ponto_filtro' => 'chamados'])) ?>" class="btn btn-sm <?= $pontoMapFiltro === 'chamados' ? 'btn-primary' : 'btn-secondary' ?>">Com chamados (<?= (int) $totalPontosComChamados ?>)</a>
            </div>
          </div>
          <?php if ($escopoDash === null && !empty($empresasDashOptions) && !$modoClienteUnicoAdmin): ?>
          <form class="dashboard-map-filters--pontos-empresa" method="get" action="index.php">
            <input type="hidden" name="dash_mapa" value="pontos">
            <input type="hidden" name="map_periodo" value="<?= htmlspecialchars($mapPeriodo) ?>">
            <?php if ($pontoMapFiltro !== ''): ?>
            <input type="hidden" name="ponto_filtro" value="<?= htmlspecialchars($pontoMapFiltro) ?>">
            <?php endif; ?>
            <div class="form-group form-group--grow">
              <label for="dash_cliente_id">Empresa (mapa)</label>
              <select id="dash_cliente_id" name="dash_cliente_id" class="select" onchange="this.form.submit()">
                <?php foreach ($empresasDashOptions as $empOp): ?>
                <option value="<?= (int) $empOp['id'] ?>" <?= (int) $scopeIdPontos === (int) $empOp['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars((string) ($empOp['empresa'] ?? $empOp['nome'] ?? 'Cadastro')) ?> #<?= (int) $empOp['id'] ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
          </form>
          <?php endif; ?>

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
            <input id="map-filter-search" class="input" type="search" placeholder="Poste, endereço, bairro ou referência">
          </div>
          <label class="dashboard-pontos-tools-toggle" for="map-toggle-cluster">
            <input type="checkbox" id="map-toggle-cluster" checked>
            Agrupar clusters
          </label>
          <div class="dashboard-pontos-tools-status dashboard-map-filters-pontos-meta" id="map-visible-count"><?= count($pontosPinsPass) ?> ponto(s) visível(is)</div>
        </div>

        <div class="dashboard-map-resize-wrap" data-map-resize-key="admin_pontos">
          <div id="pontos-iluminacao-map" class="dashboard-map-leaflet-host" role="region" aria-label="Mapa de pontos de iluminação"></div>
          <button type="button" class="dashboard-map-resize-handle" aria-label="Redimensionar altura do mapa" title="Arraste para ajustar a altura (salvo neste navegador)"></button>
        </div>
      </div>
    </div>
    <?php elseif ($modulePontosMap && $scopeIdPontos <= 0): ?>
    <div class="card dashboard-map-card dashboard-map-card--half">
      <div class="panel-head"><h4>Mapa de iluminação</h4></div>
      <div class="panel-body">
        <p class="muted" style="margin:0;">Cadastre a prefeitura (matriz) para ver postes no mapa.</p>
        <a href="clientes.php" class="btn btn-secondary btn-sm" style="margin-top:12px;">Ir à prefeitura</a>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($loadMapaCombinado): ?>
    <div class="card dashboard-map-card">
      <div class="panel-head">
        <h4>Mapa combinado</h4>
        <span class="panel-sub">Chamados no período e postes no mesmo mapa — use as camadas e os filtros abaixo.</span>
      </div>
      <div class="panel-body">
        <div class="dashboard-map-filters" style="margin-bottom:12px;">
          <div class="dashboard-map-chamados-period" role="group" aria-label="Período dos chamados no mapa">
            <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'ambos', 'map_periodo' => 'hoje'])) ?>" class="btn btn-sm <?= $mapPeriodo === 'hoje' ? 'btn-primary' : 'btn-secondary' ?>">Dia atual</a>
            <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'ambos', 'map_periodo' => 'ontem'])) ?>" class="btn btn-sm <?= $mapPeriodo === 'ontem' ? 'btn-primary' : 'btn-secondary' ?>">Dia anterior</a>
            <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'ambos', 'map_periodo' => 'semana'])) ?>" class="btn btn-sm <?= $mapPeriodo === 'semana' ? 'btn-primary' : 'btn-secondary' ?>">Semana atual</a>
            <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'ambos', 'map_periodo' => 'mes'])) ?>" class="btn btn-sm <?= $mapPeriodo === 'mes' ? 'btn-primary' : 'btn-secondary' ?>">Mês atual</a>
          </div>
        </div>

        <?php if ($escopoDash === null && !empty($empresasDashOptions) && !$modoClienteUnicoAdmin): ?>
        <form class="dashboard-map-filters" method="get" action="index.php" style="margin-bottom:12px;">
          <input type="hidden" name="dash_mapa" value="ambos">
          <input type="hidden" name="map_periodo" value="<?= htmlspecialchars($mapPeriodo) ?>">
          <?php if ($pontoMapFiltro !== ''): ?>
          <input type="hidden" name="ponto_filtro" value="<?= htmlspecialchars($pontoMapFiltro) ?>">
          <?php endif; ?>
          <div class="form-group form-group--grow">
            <label for="combo_dash_cliente_id">Empresa (postes no mapa)</label>
            <select id="combo_dash_cliente_id" name="dash_cliente_id" class="select" onchange="this.form.submit()">
              <?php foreach ($empresasDashOptions as $empOp): ?>
              <option value="<?= (int) $empOp['id'] ?>" <?= (int) $scopeIdPontos === (int) $empOp['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars((string) ($empOp['empresa'] ?? $empOp['nome'] ?? 'Cadastro')) ?> #<?= (int) $empOp['id'] ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </form>
        <?php endif; ?>

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

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
          <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'ambos', 'ponto_filtro' => null])) ?>" class="btn btn-sm <?= $pontoMapFiltro === '' ? 'btn-primary' : 'btn-secondary' ?>">Todos os pontos</a>
          <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'ambos', 'ponto_filtro' => 'chamados'])) ?>" class="btn btn-sm <?= $pontoMapFiltro === 'chamados' ? 'btn-primary' : 'btn-secondary' ?>">Postes com chamados (<?= (int) $totalPontosComChamados ?>)</a>
          <a href="pontos_iluminacao_mapa.php?cliente_id=<?= (int) $scopeIdPontos ?>" class="btn btn-sm btn-secondary">Mapa completo de postes</a>
        </div>

        <p class="muted" style="font-size:12px;margin:0 0 10px;">
          <?= count($mapPins) ?> chamado(s) com coordenadas no período · <?= count($pontosPinsPass) ?> de <?= count($pontosPinsTodos) ?> poste(s) carregado(s)
        </p>

        <div class="dashboard-pontos-tools dashboard-chamados-tools" aria-label="Filtros dos chamados no mapa combinado" style="margin-bottom:10px;">
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

        <div class="dashboard-pontos-tools" aria-label="Filtros dos postes no mapa combinado" style="margin-bottom:10px;">
          <div>
            <label for="combo-pontos-filter-area">Postes — área / bairro</label>
            <select id="combo-pontos-filter-area" class="input">
              <option value="">Todas as áreas</option>
              <?php foreach ($bairrosPontosDash as $bairro): ?>
              <option value="<?= htmlspecialchars($bairro) ?>"><?= htmlspecialchars($bairro) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="combo-pontos-filter-search">Postes — buscar</label>
            <input id="combo-pontos-filter-search" class="input" type="search" placeholder="Poste, endereço, bairro ou referência">
          </div>
          <label class="dashboard-pontos-tools-toggle" for="combo-map-toggle-cluster">
            <input type="checkbox" id="combo-map-toggle-cluster" checked>
            Agrupar clusters
          </label>
          <div class="dashboard-pontos-tools-status" id="combo-map-visible-chamados">—</div>
          <div class="dashboard-pontos-tools-status" id="combo-map-visible-pontos">—</div>
        </div>

        <div class="dashboard-map-resize-wrap" data-map-resize-key="admin_combinado">
          <div id="dashboard-mapa-combinado" class="dashboard-map-leaflet-host" role="region" aria-label="Mapa combinado: chamados e postes"></div>
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
              <th>Órgão</th>
              <th>Status</th>
              <th>Prioridade</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($ultimosCh as $c): ?>
              <tr onclick="location.href='chamado_detalhe.php?id=<?= (int) $c['id'] ?>'" style="cursor:pointer;">
                <td class="td-id">#<?= (int) $c['id'] ?></td>
                <td class="td-title"><?= htmlspecialchars($c['titulo']) ?></td>
                <td class="td-mute"><?= htmlspecialchars($c['cliente'] ?? '') ?></td>
                <td><span class="badge <?= status_class($c['status']) ?>"><?= htmlspecialchars($c['status']) ?></span></td>
                <td><span class="badge <?= status_class($c['prioridade']) ?>"><?= htmlspecialchars($c['prioridade']) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
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
