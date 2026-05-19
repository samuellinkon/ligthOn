<?php
/**
 * Página mínima só para crawlers de redes sociais (Open Graph).
 * Use esta URL no Facebook Debugger se /login retornar 403:
 * https://seudominio/social-preview.php
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/meta_social.php';

$brandFull = function_exists('app_brand_full') ? app_brand_full() : 'OnLight — Gestão em Iluminação';
$loginUrl  = app_public_root_url() . '/login.php';
$title     = 'Acessar o sistema · ' . $brandFull;

header('Content-Type: text/html; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow', true);
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
  <?php app_meta_social_render([
      'title' => $title,
      'url'   => $loginUrl,
  ]); ?>
</head>
<body>
  <p><?= htmlspecialchars($brandFull, ENT_QUOTES, 'UTF-8') ?></p>
  <p><a href="<?= htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') ?>">Acessar o sistema</a></p>
</body>
</html>
