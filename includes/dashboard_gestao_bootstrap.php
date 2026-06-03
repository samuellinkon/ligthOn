<?php
/**
 * Bootstrap compartilhado do dashboard (admin/gestor e portal cliente).
 * Requer: $dashPainel ('admin'|'cliente'), $dashUser (array do usuário logado).
 * Define: mapas, métricas, $ultimosCh, $dashQsPreserve, flags de carregamento Leaflet, etc.
 */

require_once __DIR__ . '/chamado_geo.php';

$dashPainel = (string) ($dashPainel ?? 'admin');
$dashUser   = $dashUser ?? [];

if ($dashPainel === 'cliente') {
    $escopoDash = (int) ($dashEscopoFixo ?? 0);
    if ($escopoDash <= 0) {
        $cid = (int) ($dashUser['cliente_id'] ?? 0);
        $escopoDash = $cid > 0 ? repo_cliente_matriz_raiz_id($cid) : 0;
    }
} else {
    $escopoDash = gestao_scope_cliente_id($dashUser);
}

$mapPeriodoRaw = strtolower(trim((string) ($_GET['map_periodo'] ?? '')));
$mapPeriodoAllowed = ['hoje', 'ontem', 'semana', 'mes'];
$mapPeriodo = in_array($mapPeriodoRaw, $mapPeriodoAllowed, true) ? $mapPeriodoRaw : 'mes';
$mapMesRaw = trim((string) ($_GET['map_mes'] ?? ''));
$mapMes = preg_match('/^\d{4}-\d{2}$/', $mapMesRaw) ? $mapMesRaw : '';

$todayDash = new DateTimeImmutable('today');
switch ($mapPeriodo) {
    case 'hoje':
        $mapDe = $mapAte = $todayDash->format('Y-m-d');
        break;
    case 'ontem':
        $ontem = $todayDash->modify('-1 day');
        $mapDe = $mapAte = $ontem->format('Y-m-d');
        break;
    case 'semana':
        $mapDe = $todayDash->modify('monday this week')->format('Y-m-d');
        $mapAte = $todayDash->modify('sunday this week')->format('Y-m-d');
        break;
    case 'mes':
    default:
        $ym = $mapMes !== '' ? $mapMes : $todayDash->format('Y-m');
        $mesRef = DateTimeImmutable::createFromFormat('!Y-m-d', $ym . '-01');
        if (!$mesRef) {
            $mesRef = $todayDash->modify('first day of this month');
            $ym = $mesRef->format('Y-m');
        }
        $mapMes = $ym;
        $mapDe = $mesRef->format('Y-m-d');
        $mapAte = $mesRef->modify('last day of this month')->format('Y-m-d');
        break;
}

if ($dashPainel === 'cliente') {
    $moduleChamadosMap = db_ok() && $escopoDash > 0 && app_modulo_habilitado('cliente', 'chamados');
    $modulePontosMap   = db_ok() && $escopoDash > 0 && app_modulo_habilitado('cliente', 'pontos_iluminacao');
} else {
    $moduleChamadosMap = db_ok() && app_modulo_painel_habilitado('chamados', $dashUser);
    $modulePontosMap   = db_ok() && app_modulo_painel_habilitado('pontos_iluminacao', $dashUser);
}

/** Aba «Mapa combinado» (dash_mapa=ambos): desligada temporariamente no UI. */
$dashMapaCombinadoHabilitado = false;

$modoClienteUnicoAdmin = ($dashPainel === 'cliente') || db_ok();

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

if (
    $mapPeriodoRaw === ''
    && ($moduleChamadosMap || $modulePontosMap)
    && strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET'
    && !headers_sent()
) {
    $params = $_GET;
    $params['map_periodo'] = 'mes';
    if (!isset($params['dash_mapa']) && !isset($params['mapa'])) {
        $params['dash_mapa'] = $dashMapaAba;
    }
    header('Location: index.php?' . http_build_query($params));
    exit;
}

$pontoMapFiltro = strtolower(trim((string) ($_GET['ponto_filtro'] ?? '')));
if (!in_array($pontoMapFiltro, ['', 'chamados', 'ativo', 'inativo'], true)) {
    $pontoMapFiltro = '';
}

$scopeIdPontos            = 0;
$pontosPinsPass           = [];
$pontosPinsTodos          = [];
$totalPontosComGeo        = 0;
$bairrosPontosDash        = [];
$totalPontosComChamados   = 0;
$totalAtivosPontosDash    = 0;
$totalInativosPontosDash  = 0;
$empresasDashOptions      = [];
$dashPontosHrefRotas      = '';
$dashPontosMapSubtitulo   = 'Todos os pontos com coordenadas';

if ($modulePontosMap || $moduleChamadosMap) {
    if ($dashPainel === 'cliente') {
        $scopeIdPontos = $escopoDash;
    } else {
        $escopoEmpresaPontos = gestao_scope_cliente_id($dashUser);
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
    }

    if ($scopeIdPontos > 0) {
        if ($dashPainel === 'admin') {
            gestor_assert_escopo_cliente($scopeIdPontos, 'index.php');
        }
        $pontosStatsEscopo = repo_pontos_iluminacao_estatisticas_escopo($scopeIdPontos, true);
        $totalPontosComGeo = (int) ($pontosStatsEscopo['com_geo'] ?? 0);
        $totalPontosComChamados = (int) ($pontosStatsEscopo['com_chamados_abertos'] ?? 0);
        $totalAtivosPontosDash = (int) ($pontosStatsEscopo['ativos'] ?? 0);
        $totalInativosPontosDash = (int) ($pontosStatsEscopo['inativos'] ?? 0);
        $pontosPinsPass = [];
        $bairrosPontosDash = repo_pontos_iluminacao_bairros_distintos($scopeIdPontos, true);
        $pontoMapStatusFiltro = match ($pontoMapFiltro) {
            'ativo' => 'Ativo',
            'inativo' => 'Inativo',
            default => '',
        };
        $crmPontosMapaConfigDash = crm_pontos_mapa_js_config(
            $scopeIdPontos,
            [
                'status' => $pontoMapStatusFiltro,
                'somente_chamados_abertos' => $pontoMapFiltro === 'chamados',
            ],
            $basePath ?? '../'
        );
        $dashPontosHrefRotas = ($dashPainel !== 'cliente' && $scopeIdPontos > 0)
            ? 'pontos_iluminacao_rotas.php?cliente_id=' . $scopeIdPontos
            : '';
    }
}

$mapChamadosScope = null;
if ($escopoDash !== null && $escopoDash > 0) {
    $mapChamadosScope = $escopoDash;
} elseif ($scopeIdPontos > 0) {
    $mapChamadosScope = $scopeIdPontos;
}

$mapChamadosData = $moduleChamadosMap
    ? repo_chamados_mapa_data($mapDe, $mapAte, $mapChamadosScope)
    : ['pins' => [], 'stats' => ['total_periodo' => 0, 'ready' => 0, 'pending_geocode' => 0, 'sem_localizacao' => 0]];
$mapPins      = $mapChamadosData['pins'];
$mapPinsStats = $mapChamadosData['stats'];

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

if (!function_exists('medicao_mes_label_pt')) {
    require_once __DIR__ . '/medicao_helpers.php';
}
$mapPeriodoLabel = $mapPeriodo === 'mes'
    ? medicao_mes_label_pt($mapMes)
    : date('d/m/Y', strtotime($mapDe)) . ' a ' . date('d/m/Y', strtotime($mapAte));
$mapEmptyMsg     = null;
$mapLoadingMsg   = null;
if ($moduleChamadosMap) {
    $mapPendingCount = (int) ($mapPinsStats['pending_geocode'] ?? 0);
    if ($mapPendingCount > 0 && $mapPins !== []) {
        $mapLoadingMsg = 'Localizando ' . $mapPendingCount . ' chamado(s) pelo endereço no mapa…';
    }
    if ($mapPins === []) {
        $totalPeriodo = (int) ($mapPinsStats['total_periodo'] ?? 0);
        if ($totalPeriodo === 0) {
            $mapEmptyMsg = sprintf(
                'Nenhum chamado no período %s. Tente «Hoje», «Semana» ou confira a data de abertura (aberto_em / data da OS).',
                $mapPeriodoLabel
            );
        } else {
            $semLoc = (int) ($mapPinsStats['sem_localizacao'] ?? 0);
            $mapEmptyMsg = sprintf(
                '%d chamado(s) no período %s, mas nenhum com coordenadas ou endereço para localizar no mapa. Cadastre endereço na OS ou vincule um poste com latitude/longitude.',
                $totalPeriodo,
                $mapPeriodoLabel
            );
            if ($semLoc > 0 && $semLoc < $totalPeriodo) {
                $mapEmptyMsg = sprintf(
                    '%d chamado(s) no período %s sem localização utilizável (%d sem endereço/coords). Os demais podem estar fora do escopo do mapa.',
                    $semLoc,
                    $mapPeriodoLabel,
                    $semLoc
                );
            }
        }
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
$dashMapsAtivos           = $loadLeafletChamados || $loadPontosMap || $loadMapaCombinado;
$loadPontosIluminacaoPageLoader = $loadPontosMap;
$pontosPageLoaderMsg      = $loadPontosMap ? 'Preparando mapa de pontos…' : null;

$dashPontosMapSubtitulo = match ($pontoMapFiltro) {
    'chamados' => 'Somente postes com chamado em aberto',
    'ativo' => 'Somente postes ativos',
    'inativo' => 'Somente postes inativos',
    default => 'Todos os pontos com coordenadas',
};
$loadGoogleMapsJs         = $dashMapsAtivos && crm_google_maps_has_api_key();
$loadLeaflet              = $dashMapsAtivos && !$loadGoogleMapsJs;
$loadLeafletMarkerCluster = $loadLeaflet;

$dashHrefChamados       = 'chamados.php';
$dashHrefChamadosAbertos = 'chamados.php?f=Aberto';

$dashQsPreserve = static function (array $override = []) use ($mapPeriodo, $mapMes, $escopoDash, $scopeIdPontos, $pontoMapFiltro, $dashMapaAba, $modoClienteUnicoAdmin, $dashPainel): string {
    $qs = ['map_periodo' => $mapPeriodo, 'dash_mapa' => $dashMapaAba];
    if ($mapPeriodo === 'mes' && $mapMes !== '') {
        $qs['map_mes'] = $mapMes;
    }
    if ($dashPainel === 'admin' && $escopoDash === null && $scopeIdPontos > 0 && !$modoClienteUnicoAdmin) {
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
$dashStatsScope = ($escopoDash !== null && $escopoDash > 0) ? $escopoDash : null;
$dash           = db_ok() ? repo_dashboard_admin_stats($dashStatsScope) : null;

if ($dash) {
    if ($escopoDash !== null && $escopoDash > 0) {
        $ultimosCh = repo_chamados_admin_list('', '', 1, 5, $escopoDash)['rows'];
    } else {
        $ultimosCh = array_slice(repo_chamados(), 0, 5);
    }
} else {
    $ultimosCh = array_slice($MOCK_CHAMADOS, 0, 5);
}

$dashMostrarColunaOrgao = (bool) ($dashMostrarColunaOrgao ?? ($dashPainel === 'admin'));
$dashMapResizePrefix    = (string) ($dashMapResizePrefix ?? ($dashPainel === 'cliente' ? 'cliente' : ($escopoDash !== null ? 'gestor' : 'admin')));
