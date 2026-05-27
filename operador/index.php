<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_auth('operador');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_operador('chamados');

$pageTitle    = 'Operador';
$basePath     = '../';
$activePage   = 'dashboard';
$operadorPwa  = true;

$user      = current_user() ?: ($GLOBALS['MOCK_USER_OPERADOR'] ?? []);
$empresaId = operador_empresa_id($user);
$operadorId = (int) ($user['id'] ?? 0);

/** Em atendimento no mapa / resumo: não só "Aberto", senão chamados "Em andamento" ou "Aguardando" somem do painel. */
$statusOperadorMapa = ['Aberto', 'Em andamento', 'Aguardando Aprovação'];

$chamadosAbertos = [];
$mapPins = [];
$chamadosAtivosSemGps = 0;
if (db_ok() && $empresaId > 0 && $operadorId > 0) {
    $resChamados = repo_chamados_operador_list($empresaId, '', '', 1, 200, $operadorId);
    foreach ($resChamados['rows'] as $ch) {
        if (!in_array((string) ($ch['status'] ?? ''), $statusOperadorMapa, true)) {
            continue;
        }
        $chamadosAbertos[] = $ch;
        $lat = $ch['latitude'] ?? null;
        $lng = $ch['longitude'] ?? null;
        if ($lat !== null && $lng !== null && $lat !== '' && $lng !== '') {
            $mapPins[] = [
                'id' => (int) ($ch['id'] ?? 0),
                'titulo' => (string) ($ch['titulo'] ?? ''),
                'status' => (string) ($ch['status'] ?? ''),
                'prioridade' => (string) ($ch['prioridade'] ?? ''),
                'origem_os' => (string) ($ch['origem_os'] ?? ''),
                'problema_os' => (string) ($ch['problema_os'] ?? ''),
                'data' => (string) ($ch['data'] ?? ''),
                'cliente' => (string) ($ch['cliente'] ?? ''),
                'endereco_completo' => !empty($ch['endereco_completo']) ? (string) $ch['endereco_completo'] : null,
                'lat' => (float) $lat,
                'lng' => (float) $lng,
                'foto_url' => '',
            ];
        } else {
            $chamadosAtivosSemGps++;
        }
    }
}
$loadLeaflet = db_ok() && $empresaId > 0;
$loadLeafletChamados = $loadLeaflet;

$dash = db_ok() && $empresaId > 0 && $operadorId > 0
    ? repo_dashboard_operador_stats($empresaId, $operadorId)
    : null;

$mapEmptyMsg = null;
if (count($mapPins) === 0 && $loadLeaflet) {
    if (count($chamadosAbertos) === 0) {
        $mapEmptyMsg = 'Nenhum chamado em Aberto, Em andamento ou Aguardando Aprovação está atribuído a você. '
            . 'Peça ao gestor para vincular técnicos no chamado (equipe / responsável).';
    } elseif ($chamadosAtivosSemGps > 0) {
        $mapEmptyMsg = 'Você tem ' . (int) $chamadosAtivosSemGps . ' chamado(s) atribuído(s) sem latitude e longitude. '
            . 'Abra o chamado e informe a localização para aparecer no mapa.';
    } else {
        $mapEmptyMsg = 'Nenhum chamado atribuído a você tem coordenadas para exibir no mapa.';
    }
}

$topTitle    = 'Painel';
$topSubtitle = '';
$topSearch   = '';
$topAction   = ['label' => 'Chamados', 'href' => 'chamados.php', 'icon' => '→'];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-operador.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content ch-op-page">
  <div class="ch-op-metrics-wrap">
    <div class="dashboard-admin-metrics">
      <div class="cards-metrics">
        <div class="card metric">
          <div class="metric-top">
            <div>
              <div class="metric-label">Abertos</div>
              <div class="metric-value"><?= $dash ? (int) $dash['ch_abertos'] : 0 ?></div>
            </div>
            <div class="icon-box">AB</div>
          </div>
          <div class="metric-change metric-change--admin"><?= $dash ? 'Ao vivo' : '—' ?></div>
        </div>
        <div class="card metric">
          <div class="metric-top">
            <div>
              <div class="metric-label">Em andamento</div>
              <div class="metric-value"><?= $dash ? (int) $dash['ch_andamento'] : 0 ?></div>
            </div>
            <div class="icon-box">EA</div>
          </div>
          <div class="metric-change metric-change--admin">Em atendimento</div>
        </div>
        <div class="card metric">
          <div class="metric-top">
            <div>
              <div class="metric-label">Urgentes</div>
              <div class="metric-value"><?= $dash ? (int) $dash['ch_urgentes'] : 0 ?></div>
            </div>
            <div class="icon-box">!!</div>
          </div>
          <div class="metric-change metric-change--admin">Prioridade alta</div>
        </div>
        <div class="card metric">
          <div class="metric-top">
            <div>
              <div class="metric-label">Resolvidos 7d</div>
              <div class="metric-value"><?= $dash ? (int) $dash['ch_resolvidos_7d'] : 0 ?></div>
            </div>
            <div class="icon-box">OK</div>
          </div>
          <div class="metric-change metric-change--admin"><?= $dash ? 'Últimos 7 dias' : '—' ?></div>
        </div>
      </div>
    </div>
  </div>

  <?php if ($loadLeaflet): ?>
  <div class="card dashboard-map-card" style="margin-bottom:24px;">
    <div class="panel-head">
      <h4>Mapa dos meus chamados em atendimento</h4>
      <span class="panel-sub"><?= count($mapPins) ?> com latitude e longitude · <?= count($chamadosAbertos) ?> ativo(s) atribuído(s) a você</span>
    </div>
    <div class="panel-body">
      <div id="chamados-map" role="region" aria-label="Mapa dos chamados atribuídos ao operador"></div>
      <p class="muted" style="font-size:12px;margin-top:10px;margin-bottom:0;">
        Pins: chamados <strong>atribuídos a você</strong>, em <strong>Aberto</strong>, <strong>Em andamento</strong> ou <strong>Aguardando Aprovação</strong>, com coordenadas. A lista ao lado conta os mesmos status (com ou sem GPS).
      </p>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="panel-head">
      <h4>Atendimento em campo</h4>
      <span class="panel-sub"><?= count($chamadosAbertos) ?> chamado(s) em andamento atribuído(s) a você</span>
    </div>
    <div class="panel-body">
      <p class="muted" style="line-height:1.65;margin:0 0 14px;">
        Use <strong>Chamados</strong> para abrir os tickets atribuídos a você, enviar sua localização, registrar itens usados/devolvidos e enviar para aprovação do gestor.
      </p>
      <?php if ($empresaId <= 0): ?>
        <p style="color:var(--danger);font-weight:600;">Sua conta não está vinculada a uma empresa. Peça ao gestor ou administrador para definir a empresa (cadastro raiz) do operador.</p>
      <?php else: ?>
        <a class="btn btn-primary" href="chamados.php">Abrir chamados</a>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php if (!empty($loadLeaflet)): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<?php include __DIR__ . '/../includes/partials/leaflet_basemap_script.php'; ?>
<?php include __DIR__ . '/../includes/partials/leaflet_chamado_popup_assets.php'; ?>
<script>
  window.CHAMADOS_MAP_PINS = <?= json_encode($mapPins, JSON_UNESCAPED_UNICODE) ?: '[]' ?>;
  <?php if ($mapEmptyMsg !== null): ?>
  window.CHAMADOS_MAP_EMPTY_MSG = <?= json_encode($mapEmptyMsg, JSON_UNESCAPED_UNICODE) ?>;
  <?php endif; ?>
</script>
<script src="<?= $basePath ?>assets/js/dashboard-map.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/dashboard-map.js') ?>"></script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
