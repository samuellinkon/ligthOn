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
$statusOperadorMapa = ['Aberto', 'Em andamento', 'Aguardando'];

$chamadosAbertos = [];
$mapPins = [];
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
                'data' => (string) ($ch['data'] ?? ''),
                'cliente' => (string) ($ch['cliente'] ?? ''),
                'endereco_completo' => !empty($ch['endereco_completo']) ? (string) $ch['endereco_completo'] : null,
                'lat' => (float) $lat,
                'lng' => (float) $lng,
            ];
        }
    }
}
$loadLeaflet = db_ok() && $empresaId > 0;

$topTitle    = 'Painel do operador';
$topSubtitle = htmlspecialchars((string) ($user['empresa'] ?? 'Sua empresa'));
$topSearch   = '';
$topAction   = ['label' => 'Chamados', 'href' => 'chamados.php', 'icon' => '→'];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-operador.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <?php if ($loadLeaflet): ?>
  <div class="card dashboard-map-card" style="margin-bottom:24px;">
    <div class="panel-head">
      <h4>Mapa dos meus chamados em atendimento</h4>
      <span class="panel-sub"><?= count($mapPins) ?> com latitude e longitude · <?= count($chamadosAbertos) ?> ativo(s) atribuído(s) a você</span>
    </div>
    <div class="panel-body">
      <div id="chamados-map" role="region" aria-label="Mapa dos chamados atribuídos ao operador"></div>
      <p class="muted" style="font-size:12px;margin-top:10px;margin-bottom:0;">
        Pins: chamados <strong>atribuídos a você</strong>, em <strong>Aberto</strong>, <strong>Em andamento</strong> ou <strong>Aguardando</strong>, com coordenadas. A lista ao lado conta os mesmos status (com ou sem GPS).
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
<script>
  window.CHAMADOS_MAP_PINS = <?= json_encode($mapPins, JSON_UNESCAPED_UNICODE) ?: '[]' ?>;
</script>
<script src="<?= $basePath ?>assets/js/dashboard-map.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/dashboard-map.js') ?>"></script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
