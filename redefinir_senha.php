<?php
/**
 * Redefinir senha com token recebido por e-mail.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/repository.php';
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Nova senha';
$erro      = '';
$tokenIn   = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$ctx       = $tokenIn !== '' ? repo_password_reset_lookup($tokenIn) : null;

if (function_exists('current_user') && current_user()) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokenIn = trim((string) ($_POST['token'] ?? ''));
    $s1      = (string) ($_POST['senha'] ?? '');
    $s2      = (string) ($_POST['senha2'] ?? '');
    $r       = repo_password_reset_apply($tokenIn, $s1, $s2);
    if ($r['ok']) {
        header('Location: login.php?senha=redefinida');
        exit;
    }
    $erro = $r['err'];
    $ctx  = repo_password_reset_lookup($tokenIn);
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
  </style>
</head>
<body>

<div class="auth-wrap">
  <div class="auth-card">
    <div class="brand brand--login-logo">
      <img class="brand-logo-img brand-logo-img--login-full" src="<?= htmlspecialchars(defined('APP_BRAND_LOGO') ? APP_BRAND_LOGO : 'assets/img/lighton-logo.png') ?>" width="280" height="120" alt="">
    </div>
    <h2>Nova senha</h2>

    <?php if (!$ctx && $tokenIn === ''): ?>
      <p class="auth-sub">Abra o link enviado por e-mail ou peça um novo em «Esqueci minha senha».</p>
      <p style="margin-top:18px;text-align:center;"><a href="esqueci_senha.php" style="color:var(--primary);font-weight:600;">Pedir novo link</a></p>
    <?php elseif (!$ctx): ?>
      <div class="alert-error">Este link expirou ou já foi utilizado. Peça um novo e-mail.</div>
      <p style="margin-top:18px;text-align:center;"><a href="esqueci_senha.php" style="color:var(--primary);font-weight:600;">Pedir novo link</a></p>
    <?php else: ?>
      <p class="auth-sub">Conta: <strong><?= htmlspecialchars($ctx['email']) ?></strong></p>
      <?php if ($erro): ?>
        <div class="alert-error"><?= htmlspecialchars($erro) ?></div>
      <?php endif; ?>
      <form method="post" action="redefinir_senha.php" autocomplete="off">
        <input type="hidden" name="token" value="<?= htmlspecialchars($tokenIn) ?>">
        <div class="form-group" style="margin-bottom:14px;">
          <label for="senha">Nova senha</label>
          <input type="password" id="senha" name="senha" class="input" required minlength="6" autocomplete="new-password" placeholder="Mínimo 6 caracteres">
        </div>
        <div class="form-group" style="margin-bottom:18px;">
          <label for="senha2">Confirmar senha</label>
          <input type="password" id="senha2" name="senha2" class="input" required minlength="6" autocomplete="new-password" placeholder="Repita a senha">
        </div>
        <button type="submit" class="btn btn-primary btn-block btn-lg">Guardar nova senha</button>
      </form>
    <?php endif; ?>

    <p style="margin-top:18px;text-align:center;font-size:14px;">
      <a href="login.php" style="color:var(--primary);font-weight:600;">Voltar ao login</a>
    </p>
  </div>
</div>
</body>
</html>
