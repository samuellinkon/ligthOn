<?php
/**
 * Pedido de recuperação de senha (e-mail com link).
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/repository.php';
require_once __DIR__ . '/includes/app_runtime.php';
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Esqueci minha senha';
$erro      = '';
$info      = '';

if (function_exists('current_user') && current_user()) {
    header('Location: login.php');
    exit;
}

if (isset($_GET['enviado']) && (string) $_GET['enviado'] === '1') {
    $info = 'Se existir uma conta com o e-mail indicado, enviámos instruções para redefinir a senha. Verifique também o spam.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $base  = app_public_base_url();
    $r     = repo_password_reset_request($email, $base);
    if (!$r['ok']) {
        $erro = $r['err'] !== '' ? $r['err'] : 'Não foi possível enviar o pedido.';
    } else {
        header('Location: esqueci_senha.php?enviado=1');
        exit;
    }
}

$cssBust = static function (string $file): int {
    $path = __DIR__ . '/assets/css/' . basename($file);

    return is_file($path) ? (int) filemtime($path) : 0;
};
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= htmlspecialchars($pageTitle) ?> · <?= htmlspecialchars(function_exists('app_brand_full') ? app_brand_full() : 'OnLight') ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="icon" type="image/png" href="<?= htmlspecialchars(defined('APP_BRAND_ICON') ? APP_BRAND_ICON : 'assets/img/lighton-icon.png') ?>">
  <link rel="stylesheet" href="assets/css/style.css?v=<?= $cssBust('style.css') ?>">
  <link rel="stylesheet" href="assets/css/forms.css?v=<?= $cssBust('forms.css') ?>">
  <link rel="stylesheet" href="assets/css/responsive.css?v=<?= $cssBust('responsive.css') ?>">
  <style>
    .alert-error { background: rgba(226,75,74,0.08); border: 1px solid rgba(226,75,74,0.25); color: var(--danger); padding: 10px 12px; border-radius: 10px; font-size: 13px; font-weight: 600; margin-bottom: 14px; }
    .alert-info { background: rgba(59,130,246,0.08); border: 1px solid rgba(59,130,246,0.22); color: #1e40af; padding: 10px 12px; border-radius: 10px; font-size: 13px; font-weight: 600; margin-bottom: 14px; line-height: 1.45; }
  </style>
</head>
<body>

<div class="auth-wrap">
  <div class="auth-card">
    <div class="brand brand--login-logo">
      <img class="brand-logo-img brand-logo-img--login-full" src="<?= htmlspecialchars(defined('APP_BRAND_LOGO') ? APP_BRAND_LOGO : 'assets/img/lighton-logo.png') ?>" width="280" height="120" alt="">
    </div>
    <h2>Recuperar senha</h2>
    <p class="auth-sub">Indique o e-mail da sua conta. Se existir registo, receberá um link válido por 1 hora.</p>

    <?php if ($erro): ?>
      <div class="alert-error"><?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>
    <?php if ($info): ?>
      <div class="alert-info"><?= htmlspecialchars($info) ?></div>
    <?php endif; ?>

    <form method="post" action="esqueci_senha.php" autocomplete="off">
      <div class="form-group" style="margin-bottom:18px;">
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" class="input" required placeholder="voce@empresa.com"
               value="<?= htmlspecialchars((string) ($_POST['email'] ?? '')) ?>">
      </div>
      <button type="submit" class="btn btn-primary btn-block btn-lg">Enviar link</button>
      <p style="margin-top:18px;text-align:center;font-size:14px;">
        <a href="login.php" style="color:var(--primary);font-weight:600;">Voltar ao login</a>
      </p>
    </form>
  </div>
</div>
</body>
</html>
