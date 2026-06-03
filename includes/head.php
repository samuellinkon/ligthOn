<?php
/**
 * Head comum a todas as páginas.
 * Espera: $pageTitle (string) e $basePath (string) opcionais.
 */

if (!isset($basePath)) {
    $basePath = '../';
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app_runtime.php';
require_once __DIR__ . '/tabela_sort_ui.php';
require_once __DIR__ . '/chamado_geo.php';

if (!isset($pageTitle)) {
    $pageTitle = defined('APP_BRAND_NAME') ? APP_BRAND_NAME : 'Painel';
}

/** Evita cache antigo de CSS após deploy (usa data de modificação do arquivo). */
$cssBust = static function (string $file): int {
  $path = dirname(__DIR__) . '/assets/css/' . basename($file);
  return is_file($path) ? (int) filemtime($path) : 0;
};
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= htmlspecialchars($pageTitle) ?> · <?= htmlspecialchars(function_exists('app_brand_full') ? app_brand_full() : 'OnLight') ?></title>
  <?php require_once __DIR__ . '/meta_social.php'; app_meta_social_render(); ?>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <link rel="icon" type="image/png" href="<?= htmlspecialchars($basePath . (defined('APP_BRAND_ICON') ? APP_BRAND_ICON : 'assets/img/lighton-icon.png')) ?>">
  <link rel="apple-touch-icon" href="<?= htmlspecialchars($basePath . (defined('APP_BRAND_ICON') ? APP_BRAND_ICON : 'assets/img/lighton-icon.png')) ?>">

  <link rel="stylesheet" href="<?= $basePath ?>assets/css/style.css?v=<?= $cssBust('style.css') ?>">
  <link rel="stylesheet" href="<?= $basePath ?>assets/css/sidebar.css?v=<?= $cssBust('sidebar.css') ?>">
  <link rel="stylesheet" href="<?= $basePath ?>assets/css/tables.css?v=<?= $cssBust('tables.css') ?>">
  <link rel="stylesheet" href="<?= $basePath ?>assets/css/forms.css?v=<?= $cssBust('forms.css') ?>">
  <link rel="stylesheet" href="<?= $basePath ?>assets/css/dashboard.css?v=<?= $cssBust('dashboard.css') ?>">
  <link rel="stylesheet" href="<?= $basePath ?>assets/css/responsive.css?v=<?= $cssBust('responsive.css') ?>">
  <link rel="stylesheet" href="<?= $basePath ?>assets/css/modal.css?v=<?= $cssBust('modal.css') ?>">
  <?php
  /** Sino na topbar: admin, gestor, operador e cliente (ver includes/topbar.php). */
  $includeNotificacoesCss = false;
  if (function_exists('current_user')) {
      $uHead = current_user();
      if ($uHead) {
          $pHead = (string) ($uHead['perfil'] ?? '');
          $includeNotificacoesCss = in_array($pHead, ['admin', 'gestor', 'operador', 'cliente'], true);
      }
  }
  ?>
  <?php if ($includeNotificacoesCss): ?>
  <link rel="stylesheet" href="<?= $basePath ?>assets/css/notificacoes.css?v=<?= $cssBust('notificacoes.css') ?>">
  <?php endif; ?>
  <?php if (!empty($loadLeaflet)): ?>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="" />
  <?php endif; ?>
  <?php if (!empty($loadLeafletMarkerCluster)): ?>
  <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" crossorigin="" />
  <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" crossorigin="" />
  <?php endif; ?>
  <?php
  $dashMapsAtivosHead = !empty($dashMapsAtivos)
      || !empty($loadPontosMap)
      || !empty($loadMapaCombinado)
      || !empty($loadLeafletChamados)
      || !empty($loadGoogleMapsJs);
  $loadPontosMapCss = $dashMapsAtivosHead || !empty($loadPontosMapGoogle);
  ?>
  <?php if (!empty($loadLeafletMarkerCluster) || $loadPontosMapCss): ?>
  <link rel="stylesheet" href="<?= $basePath ?>assets/css/map-popup.css?v=<?= $cssBust('map-popup.css') ?>">
  <?php endif; ?>
  <?php if (!empty($loadLeafletChamados) || !empty($loadMapaCombinado) || !empty($loadGoogleMapsJs)): ?>
  <link rel="stylesheet" href="<?= $basePath ?>assets/css/call-popup.css?v=<?= $cssBust('call-popup.css') ?>">
  <?php endif; ?>
  <?php if (!empty($loadMedicaoCustos)): ?>
  <link rel="stylesheet" href="<?= $basePath ?>assets/css/medicao-custos.css?v=<?= $cssBust('medicao-custos.css') ?>">
  <?php endif; ?>
  <?php if (!empty($loadMedicaoMeses)): ?>
  <link rel="stylesheet" href="<?= $basePath ?>assets/css/medicao-meses.css?v=<?= $cssBust('medicao-meses.css') ?>">
  <?php endif; ?>
  <?php if (!empty($operadorPwa)): ?>
  <link rel="manifest" href="<?= htmlspecialchars($basePath) ?>operador/manifest.webmanifest" />
  <meta name="theme-color" content="#1e3a8a" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <?php endif; ?>
  <?php
  $crmGoogleMapsApiKeyHead = crm_google_maps_api_key();
  $crmGoogleMapsMapIdHead  = crm_google_maps_map_id();
  if ($crmGoogleMapsApiKeyHead !== ''):
  ?>
  <script>window.CRM_GOOGLE_MAPS_API_KEY = <?= json_encode($crmGoogleMapsApiKeyHead, JSON_UNESCAPED_UNICODE) ?>;</script>
  <?php endif; ?>
  <?php if ($crmGoogleMapsMapIdHead !== ''): ?>
  <script>window.CRM_GOOGLE_MAPS_MAP_ID = <?= json_encode($crmGoogleMapsMapIdHead, JSON_UNESCAPED_UNICODE) ?>;</script>
  <?php endif; ?>
  <?php if (!empty($loadPontosIluminacaoPageLoader)): ?>
  <style>
    .pontos-page-loading{position:fixed;inset:0;z-index:10070;display:flex;align-items:center;justify-content:center;padding:24px;background:rgba(15,23,42,.52);backdrop-filter:blur(4px);opacity:0;visibility:hidden;transition:opacity .22s ease,visibility .22s ease}
    .pontos-page-loading.is-visible{opacity:1;visibility:visible}
    body.pontos-page-loading-active{overflow:hidden}
    .pontos-page-loading__panel{display:flex;flex-direction:column;align-items:center;gap:12px;max-width:320px;padding:28px 32px;border-radius:16px;background:#fff;box-shadow:0 20px 50px rgba(15,23,42,.22);text-align:center}
    .pontos-page-loading__spinner{width:40px;height:40px;border:3px solid #e2e8f0;border-top-color:#2563eb;border-radius:50%;animation:pontos-page-loading-spin .75s linear infinite}
    .pontos-page-loading__title{margin:0;font-size:1rem;font-weight:800;color:#0f172a}
    .pontos-page-loading__msg{margin:0;font-size:.875rem;font-weight:600;color:#64748b;line-height:1.45}
    @keyframes pontos-page-loading-spin{to{transform:rotate(360deg)}}
  </style>
  <?php
  $pontosPageLoaderJs = dirname(__DIR__) . '/assets/js/pontos-iluminacao-page-loader.js';
  ?>
  <script src="<?= $basePath ?>assets/js/pontos-iluminacao-page-loader.js?v=<?= (int) @filemtime($pontosPageLoaderJs) ?>" defer></script>
  <?php endif; ?>
</head>
<body<?= app_debug_mode() ? ' data-form-fill-dev="1"' : '' ?><?= !empty($loadPontosIluminacaoPageLoader) ? ' class="pontos-page-loading-active"' : '' ?><?= !empty($pageBodyAttrs) ? (string) $pageBodyAttrs : '' ?><?= $crmGoogleMapsApiKeyHead !== '' ? ' data-google-maps-key="' . htmlspecialchars($crmGoogleMapsApiKeyHead, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
<div class="sidebar-overlay"></div>
<?php if (!empty($loadPontosIluminacaoPageLoader)): ?>
<div id="pontos-page-loader" class="pontos-page-loading is-visible" role="alertdialog" aria-modal="true" aria-busy="true" aria-live="polite" aria-label="Carregando pontos de iluminação">
  <div class="pontos-page-loading__panel">
    <div class="pontos-page-loading__spinner" aria-hidden="true"></div>
    <p class="pontos-page-loading__title">Carregando</p>
    <p class="pontos-page-loading__msg"><?= htmlspecialchars((string) ($pontosPageLoaderMsg ?? 'Preparando mapa e listagem…'), ENT_QUOTES, 'UTF-8') ?></p>
  </div>
</div>
<?php endif; ?>
