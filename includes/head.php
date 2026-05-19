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
  <?php if (!empty($operadorPwa)): ?>
  <link rel="manifest" href="<?= htmlspecialchars($basePath) ?>operador/manifest.webmanifest" />
  <meta name="theme-color" content="#1e3a8a" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <?php endif; ?>
</head>
<body<?= app_debug_mode() ? ' data-form-fill-dev="1"' : '' ?>>
<div class="sidebar-overlay"></div>
