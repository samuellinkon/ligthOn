<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';

$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('pontos_iluminacao');

$pageTitle  = 'Mapa de pontos';
$basePath   = '../';
$activePage = 'pontos_iluminacao';

if (!db_ok()) {
    flash_set('err', 'Banco indisponível.');
    header('Location: clientes.php');
    exit;
}

$escopoEmpresa = gestao_scope_cliente_id($me);
if ($escopoEmpresa !== null) {
    $scopeId = $escopoEmpresa;
} else {
    $scopeId = (int) ($_GET['cliente_id'] ?? 0);
    if ($scopeId <= 0) {
        $scopeId = (int) (repo_catalogo_cliente_id_padrao_admin() ?? 0);
    }
    if ($scopeId > 0) {
        $scopeId = repo_cliente_matriz_raiz_id($scopeId);
    }
}
if ($scopeId <= 0) {
    flash_set('err', 'Empresa não informada.');
    header('Location: pontos_iluminacao.php');
    exit;
}
gestor_assert_escopo_cliente($scopeId, 'pontos_iluminacao.php');

$filtroMapa = strtolower(trim((string) ($_GET['filtro'] ?? '')));
if (!in_array($filtroMapa, ['', 'chamados'], true)) {
    $filtroMapa = '';
}
$pinsTodos = repo_pontos_iluminacao_mapa($scopeId, true);
$pins = $pinsTodos;
if ($filtroMapa === 'chamados') {
    $pins = array_values(array_filter($pinsTodos, function (array $p): bool {
        return ((int) ($p['chamados_abertos'] ?? 0)) > 0;
    }));
}
$totalComChamados = count(array_filter($pinsTodos, function (array $p): bool {
    return ((int) ($p['chamados_abertos'] ?? 0)) > 0;
}));
$bairrosMapa = [];
foreach ($pins as $p) {
    $bairro = trim((string) ($p['bairro'] ?? ''));
    if ($bairro !== '') {
        $bairrosMapa[$bairro] = true;
    }
}
$bairrosMapa = array_keys($bairrosMapa);
natcasesort($bairrosMapa);
$bairrosMapa = array_values($bairrosMapa);

$topTitle    = 'Mapa de pontos de iluminação';
$topSubtitle = $filtroMapa === 'chamados'
    ? 'Exibindo apenas postes com chamados abertos ou em andamento.'
    : 'Postes em vermelho possuem chamados abertos ou em andamento.';
$topSearch   = '';
$topAction   = ['label' => 'Lista', 'href' => 'pontos_iluminacao.php?cliente_id=' . (int) $scopeId, 'icon' => '←'];

include __DIR__ . '/../includes/head.php';
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" crossorigin="">
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" crossorigin="">
<style>
  #pontos-iluminacao-map{height:620px;border-radius:18px;border:1px solid var(--border);overflow:hidden;background:#eef1f7}
  .pontos-map-empty{display:flex;align-items:center;justify-content:center;height:100%;color:var(--muted);font-weight:700;text-align:center;padding:24px}
  .ponto-marker{display:block;width:10px;height:10px;border-radius:50%;border:1px solid #15803d;box-shadow:0 1px 4px rgba(0,0,0,.3);background:#22c55e;transform-origin:center center;transition:transform .15s ease,background .15s ease,border-color .15s ease,box-shadow .15s ease}
  .ponto-marker--ativo{border-color:#15803d;background:#22c55e}
  .ponto-marker--inativo{border-color:#64748b;background:#94a3b8}
  .ponto-marker--alert{border-color:#7f1d1d;background:#dc2626}
  .leaflet-marker-icon:hover{z-index:10050!important}
  .leaflet-marker-icon:hover .ponto-marker{transform:scale(1.38)}
  .leaflet-marker-icon:hover .ponto-marker--ativo{background:#4ade80!important;border-color:#166534!important}
  .leaflet-marker-icon:hover .ponto-marker--inativo{background:#cbd5e1!important;border-color:#475569!important}
  .leaflet-marker-icon:hover .ponto-marker--alert{background:#f87171!important;border-color:#991b1b!important}
  .ponto-popup{width:300px;max-width:72vw}
  .ponto-popup-photo{width:50%;max-width:50%;height:68px;max-height:68px;border-radius:10px;object-fit:cover;object-position:center;background:#f3f4f6;border:1px solid var(--border);display:block;margin:6px auto;box-sizing:border-box}
  .ponto-popup-photo-empty{width:50%;max-width:50%;height:68px;margin:6px auto;display:flex;align-items:center;justify-content:center;border-radius:10px;background:#f3f4f6;border:1px solid var(--border);color:var(--muted);font-weight:700;font-size:11px;box-sizing:border-box}
  .ponto-popup-meta{color:var(--muted);font-size:12px}
  .ponto-popup-address{display:block;margin-top:4px;color:#374151}
  .ponto-popup-history{border-top:1px solid var(--border);margin-top:10px;padding-top:8px}
  .ponto-popup-history-title{font-weight:800;margin-bottom:6px}
  .ponto-popup-call{display:block;padding:6px 0;border-top:1px solid #eef2f7;color:inherit;text-decoration:none}
  .ponto-popup-call:first-of-type{border-top:0}
  .ponto-popup-call strong{display:block;font-size:12px}
  .ponto-popup-call small{color:var(--muted)}
  .ponto-popup-actions{margin-top:8px}
  .map-tools{display:grid;grid-template-columns:1.1fr 1.4fr auto;gap:10px;align-items:end;margin:0 0 12px;padding:12px;border:1px solid var(--border);border-radius:14px;background:#f8fafc}
  .map-tools label{display:block;font-size:12px;font-weight:800;color:#374151;margin-bottom:6px}
  .map-tools .input{min-height:38px}
  .map-tools-toggle{display:flex;align-items:center;gap:8px;min-height:38px;font-weight:800;color:#374151;white-space:nowrap}
  .map-tools-status{grid-column:1/-1;color:var(--muted);font-size:12px;font-weight:700}
  @media (max-width: 900px){.map-tools{grid-template-columns:1fr}.map-tools-status{grid-column:auto}}
</style>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <div class="card">
    <div class="panel-head">
      <h4>Mapa de acompanhamento</h4>
      <span class="panel-sub"><?= count($pins) ?> de <?= count($pinsTodos) ?> ponto(s) com coordenadas</span>
    </div>
    <div class="panel-body">
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
        <a href="pontos_iluminacao_mapa.php?cliente_id=<?= (int) $scopeId ?>" class="btn btn-sm <?= $filtroMapa === '' ? 'btn-primary' : 'btn-secondary' ?>">Todos os pontos</a>
        <a href="pontos_iluminacao_mapa.php?cliente_id=<?= (int) $scopeId ?>&amp;filtro=chamados" class="btn btn-sm <?= $filtroMapa === 'chamados' ? 'btn-primary' : 'btn-secondary' ?>">Filtrar chamados (<?= (int) $totalComChamados ?>)</a>
        <a href="pontos_iluminacao_rotas.php?cliente_id=<?= (int) $scopeId ?>" class="btn btn-sm btn-secondary">Rotas</a>
      </div>
      <div class="map-tools" aria-label="Ferramentas do mapa">
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
        <label class="map-tools-toggle" for="map-toggle-cluster">
          <input type="checkbox" id="map-toggle-cluster" checked>
          Agrupar clusters
        </label>
        <div class="map-tools-status" id="map-visible-count"><?= count($pins) ?> ponto(s) visível(is)</div>
      </div>
      <div id="pontos-iluminacao-map" role="region" aria-label="Mapa de pontos de iluminação"></div>
    </div>
  </div>
</section>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js" crossorigin=""></script>
<script>
window.PONTOS_ILUMINACAO_MAP = <?= json_encode($pins, JSON_UNESCAPED_UNICODE) ?: '[]' ?>;
</script>
<script src="<?= $basePath ?>assets/js/ponto-marker-status.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/ponto-marker-status.js') ?>"></script>
<script src="<?= $basePath ?>assets/js/pontos-iluminacao-map.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/pontos-iluminacao-map.js') ?>"></script>

</main>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
