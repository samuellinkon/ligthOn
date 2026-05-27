<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';

$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('pontos_iluminacao');

$pageTitle  = 'Rotas de chamados';
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

// Prefeitura Municipal do Ipojuca, ponto inicial da rota.
$origem = [
    'label' => 'Prefeitura Municipal do Ipojuca',
    'lat' => -8.398075,
    'lng' => -35.063889,
];
$limiteRota = 25;

$pinsTodos = repo_pontos_iluminacao_mapa($scopeId, true);
$pinsChamados = array_values(array_filter($pinsTodos, function (array $p): bool {
    return ((int) ($p['chamados_abertos'] ?? 0)) > 0
        && isset($p['lat'], $p['lng'])
        && is_numeric($p['lat'])
        && is_numeric($p['lng']);
}));

usort($pinsChamados, function (array $a, array $b) use ($origem): int {
    $da = rota_distancia_aproximada_km((float) $origem['lat'], (float) $origem['lng'], (float) $a['lat'], (float) $a['lng']);
    $db = rota_distancia_aproximada_km((float) $origem['lat'], (float) $origem['lng'], (float) $b['lat'], (float) $b['lng']);
    return $da <=> $db;
});

$totalChamadosComGeo = count($pinsChamados);
$pinsRota = rota_ordenar_vizinho_mais_proximo($origem, array_slice($pinsChamados, 0, $limiteRota));

$topTitle    = 'Rotas de chamados';
$topSubtitle = 'Rota saindo da Prefeitura do Ipojuca até os postes com chamados abertos.';
$topSearch   = '';
$topAction   = ['label' => 'Mapa', 'href' => 'pontos_iluminacao_mapa.php?cliente_id=' . (int) $scopeId . '&filtro=chamados', 'icon' => '←'];

function rota_distancia_aproximada_km(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earth = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) * sin($dLat / 2)
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
        * sin($dLng / 2) * sin($dLng / 2);

    return $earth * (2 * atan2(sqrt($a), sqrt(1 - $a)));
}

/**
 * @param array{lat:float,lng:float} $origem
 * @param list<array<string,mixed>> $pins
 * @return list<array<string,mixed>>
 */
function rota_ordenar_vizinho_mais_proximo(array $origem, array $pins): array
{
    $atualLat = (float) $origem['lat'];
    $atualLng = (float) $origem['lng'];
    $restantes = array_values($pins);
    $ordenados = [];

    while ($restantes) {
        $melhorIdx = 0;
        $melhorDist = null;
        foreach ($restantes as $idx => $p) {
            $dist = rota_distancia_aproximada_km($atualLat, $atualLng, (float) $p['lat'], (float) $p['lng']);
            if ($melhorDist === null || $dist < $melhorDist) {
                $melhorDist = $dist;
                $melhorIdx = (int) $idx;
            }
        }

        $proximo = $restantes[$melhorIdx];
        $ordenados[] = $proximo;
        $atualLat = (float) $proximo['lat'];
        $atualLng = (float) $proximo['lng'];
        array_splice($restantes, $melhorIdx, 1);
    }

    return $ordenados;
}

include __DIR__ . '/../includes/head.php';
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
<style>
  #pontos-iluminacao-route-map{height:620px;border-radius:18px;border:1px solid var(--border);overflow:hidden;background:#eef1f7}
  .route-empty{display:flex;align-items:center;justify-content:center;height:100%;color:var(--muted);font-weight:700;text-align:center;padding:24px}
  .route-origin-marker{display:flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:999px;background:#2563eb;color:#fff;border:2px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.28);font-size:13px;font-weight:900}
  .route-stop-marker{display:flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:999px;background:#dc2626;color:#fff;border:2px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.28);font-size:11px;font-weight:900}
  .route-summary{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px}
  .route-summary .chip{font-weight:800}
  .route-list{margin-top:16px}
  .route-list ol{margin:0;padding-left:20px}
  .route-list li{padding:6px 0;border-bottom:1px solid var(--border)}
  .route-list small{display:block;color:var(--muted)}
</style>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <div class="card">
    <div class="panel-head">
      <h4>Rota de atendimento</h4>
      <span class="panel-sub">
        <?= count($pinsRota) ?> de <?= (int) $totalChamadosComGeo ?> poste(s) com chamados e coordenadas
        <?php if ($totalChamadosComGeo > $limiteRota): ?>
          · exibindo os <?= (int) $limiteRota ?> mais próximos da Prefeitura
        <?php endif; ?>
      </span>
    </div>
    <div class="panel-body">
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
        <a href="pontos_iluminacao_mapa.php?cliente_id=<?= (int) $scopeId ?>" class="btn btn-sm btn-secondary">Todos os pontos</a>
        <a href="pontos_iluminacao_mapa.php?cliente_id=<?= (int) $scopeId ?>&amp;filtro=chamados" class="btn btn-sm btn-secondary">Filtrar chamados</a>
        <a href="pontos_iluminacao_rotas.php?cliente_id=<?= (int) $scopeId ?>" class="btn btn-sm btn-primary">Rotas</a>
      </div>

      <div class="route-summary">
        <span class="chip">Origem: <?= htmlspecialchars($origem['label']) ?></span>
        <span class="chip">Paradas: <?= count($pinsRota) ?></span>
        <span class="chip" id="route-distance-chip">Distância: calculando...</span>
      </div>

      <div id="pontos-iluminacao-route-map" role="region" aria-label="Mapa de rota dos chamados"></div>

      <div class="route-list">
        <h4 style="margin:0 0 8px;">Ordem sugerida</h4>
        <?php if (!$pinsRota): ?>
          <p class="muted">Nenhum poste com chamado aberto e coordenadas para montar rota.</p>
        <?php else: ?>
          <ol>
            <?php foreach ($pinsRota as $p): ?>
              <li>
                <strong>Poste <?= htmlspecialchars((string) ($p['codigo_poste'] ?? '')) ?></strong>
                <small><?= htmlspecialchars((string) ($p['bairro'] ?? '')) ?><?= !empty($p['endereco_completo']) ? ' · ' . htmlspecialchars((string) $p['endereco_completo']) : '' ?></small>
              </li>
            <?php endforeach; ?>
          </ol>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<?php include __DIR__ . '/../includes/partials/leaflet_basemap_script.php'; ?>
<script>
window.PONTOS_ILUMINACAO_ROUTE = <?= json_encode([
    'origem' => $origem,
    'pins' => $pinsRota,
], JSON_UNESCAPED_UNICODE) ?: '{"origem":null,"pins":[]}' ?>;
</script>
<script src="<?= $basePath ?>assets/js/pontos-iluminacao-rotas.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/pontos-iluminacao-rotas.js') ?>"></script>

</main>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
