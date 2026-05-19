<?php
/**
 * Bootstrap compartilhado do dashboard (admin/gestor e portal cliente).
 * Requer: $dashPainel ('admin'|'cliente'), $dashUser (array do usuário logado).
 * Define: mapas, métricas, $ultimosCh, $dashQsPreserve, flags de carregamento Leaflet, etc.
 */

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
        $segunda = $todayDash->modify('monday this week');
        $mapDe = $segunda->format('Y-m-d');
        $mapAte = $todayDash->format('Y-m-d');
        break;
    case 'mes':
    default:
        $mapDe = $todayDash->format('Y-m-01');
        $mapAte = $todayDash->format('Y-m-d');
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

$mapPins = $moduleChamadosMap
    ? repo_chamados_mapa_pins($mapDe, $mapAte, $escopoDash > 0 ? $escopoDash : null)
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

$scopeIdPontos          = 0;
$pontosPinsPass         = [];
$pontosPinsTodos        = [];
$bairrosPontosDash      = [];
$totalPontosComChamados = 0;
$empresasDashOptions    = [];

if ($modulePontosMap) {
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

$dashQsPreserve = static function (array $override = []) use ($mapPeriodo, $escopoDash, $scopeIdPontos, $pontoMapFiltro, $dashMapaAba, $modoClienteUnicoAdmin, $dashPainel): string {
    $qs = ['map_periodo' => $mapPeriodo, 'dash_mapa' => $dashMapaAba];
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
