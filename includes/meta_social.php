<?php
/**
 * Meta tags Open Graph / Twitter para compartilhamento em redes sociais.
 * Imagem padrão: APP_OG_IMAGE (assets/img/lighton-logo-login.png).
 */

require_once __DIR__ . '/config.php';

if (!function_exists('app_public_root_url')) {
    /**
     * URL pública da raiz do CRM (sem /admin, /cliente, /operador).
     */
    function app_public_root_url(): string
    {
        require_once __DIR__ . '/app_runtime.php';
        $base = app_public_base_url();
        foreach (['/admin', '/cliente', '/operador'] as $suffix) {
            if (str_ends_with($base, $suffix)) {
                return substr($base, 0, -strlen($suffix));
            }
        }

        return $base;
    }
}

if (!function_exists('app_og_image_url')) {
    function app_og_image_url(): string
    {
        $path = defined('APP_OG_IMAGE') ? (string) APP_OG_IMAGE : 'assets/img/lighton-logo-login.png';
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $url  = app_public_root_url() . '/' . $path;
        $full = dirname(__DIR__) . '/' . $path;
        if (is_file($full)) {
            $url .= '?v=' . (int) filemtime($full);
        }

        return $url;
    }
}

if (!function_exists('app_request_url')) {
    function app_request_url(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        $scheme = $https ? 'https' : 'http';
        $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $uri    = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        if ($uri === '' || $uri[0] !== '/') {
            $uri = '/' . ltrim($uri, '/');
        }

        return rtrim($scheme . '://' . $host, '/') . $uri;
    }
}

if (!function_exists('app_meta_social_render')) {
    /**
     * @param array{title?:string,description?:string,url?:string,image?:string,type?:string} $opts
     */
    function app_meta_social_render(array $opts = []): void
    {
        $brandName = defined('APP_BRAND_NAME') ? APP_BRAND_NAME : 'OnLight';
        $brandFull = function_exists('app_brand_full') ? app_brand_full() : $brandName;
        $tagline   = defined('APP_BRAND_TAGLINE') ? APP_BRAND_TAGLINE : 'Gestão em Iluminação';

        $pageTitle = $GLOBALS['pageTitle'] ?? null;
        $title = (string) ($opts['title'] ?? ($pageTitle !== null && $pageTitle !== ''
            ? $pageTitle . ' · ' . $brandFull
            : $brandFull));
        $description = (string) ($opts['description'] ?? $tagline . ' — Acesse o sistema ' . $brandName);
        $url         = (string) ($opts['url'] ?? app_request_url());
        $image       = (string) ($opts['image'] ?? app_og_image_url());
        $type        = (string) ($opts['type'] ?? 'website');

        $imgPath = defined('APP_OG_IMAGE') ? (string) APP_OG_IMAGE : 'assets/img/lighton-logo-login.png';
        $imgPath = ltrim(str_replace('\\', '/', $imgPath), '/');
        $imgW = 500;
        $imgH = 500;
        $full = dirname(__DIR__) . '/' . $imgPath;
        if (is_file($full) && function_exists('getimagesize')) {
            $info = @getimagesize($full);
            if (is_array($info) && !empty($info[0]) && !empty($info[1])) {
                $imgW = (int) $info[0];
                $imgH = (int) $info[1];
            }
        }

        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        ?>
  <meta name="description" content="<?= $esc($description) ?>" />
  <link rel="canonical" href="<?= $esc($url) ?>" />
  <meta property="og:type" content="<?= $esc($type) ?>" />
  <meta property="og:site_name" content="<?= $esc($brandName) ?>" />
  <meta property="og:title" content="<?= $esc($title) ?>" />
  <meta property="og:description" content="<?= $esc($description) ?>" />
  <meta property="og:url" content="<?= $esc($url) ?>" />
  <meta property="og:image" content="<?= $esc($image) ?>" />
  <meta property="og:image:secure_url" content="<?= $esc($image) ?>" />
  <meta property="og:image:type" content="image/png" />
  <meta property="og:image:width" content="<?= (int) $imgW ?>" />
  <meta property="og:image:height" content="<?= (int) $imgH ?>" />
  <meta property="og:image:alt" content="<?= $esc($brandFull) ?>" />
  <meta property="og:locale" content="pt_BR" />
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:title" content="<?= $esc($title) ?>" />
  <meta name="twitter:description" content="<?= $esc($description) ?>" />
  <meta name="twitter:image" content="<?= $esc($image) ?>" />
        <?php
    }
}

if (!function_exists('app_is_social_crawler')) {
    function app_is_social_crawler(): bool
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

        return (bool) preg_match(
            '/facebookexternalhit|Facebot|WhatsApp|Twitterbot|LinkedInBot|Slackbot|TelegramBot|Discordbot|Pinterestbot/i',
            $ua
        );
    }
}
