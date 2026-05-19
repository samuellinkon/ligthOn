<?php
/**
 * Raiz do site: redireciona para login.
 * Crawlers de redes sociais recebem meta OG antes do redirect (preview no WhatsApp etc.).
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/meta_social.php';

if (app_is_social_crawler()) {
    $brandFull = function_exists('app_brand_full') ? app_brand_full() : 'OnLight — Gestão em Iluminação';
    $loginUrl  = app_public_root_url() . '/login.php';
    ?><!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Acessar o sistema · <?= htmlspecialchars($brandFull, ENT_QUOTES, 'UTF-8') ?></title>
  <?php app_meta_social_render([
      'title' => 'Acessar o sistema · ' . $brandFull,
      'url'   => $loginUrl,
  ]); ?>
  <meta http-equiv="refresh" content="0;url=<?= htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') ?>" />
</head>
<body>
  <p><a href="<?= htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') ?>">Acessar o sistema</a></p>
</body>
</html>
    <?php
    exit;
}

header('Location: login.php', true, 302);
exit;
