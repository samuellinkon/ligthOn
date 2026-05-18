<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/app_runtime.php';
require_once __DIR__ . '/includes/audit_log.php';

$pageTitle = 'Acessar o sistema';
$erro = '';
$msgSenhaOk = '';
$emailPreenchido = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $senha = $_POST['senha'] ?? '';
    $emailPreenchido = $email;

    $u = mock_login($email, $senha);
    if ($u) {
        $cidLog = 0;
        if (!empty($u['empresa_id'])) {
            $cidLog = (int) $u['empresa_id'];
        } elseif (!empty($u['cliente_id'])) {
            $cidLog = (int) $u['cliente_id'];
        }
        audit_log_registar('auth.login', 'sessao', null, $cidLog > 0 ? $cidLog : null, [
            'email' => function_exists('mb_substr') ? mb_substr(trim((string) $email), 0, 160, 'UTF-8') : substr(trim((string) $email), 0, 160),
        ]);
        $p = (string) ($u['perfil'] ?? '');
        if ($p === 'admin' || $p === 'gestor') {
            $destino = 'admin/index.php';
        } elseif ($p === 'operador') {
            $destino = 'operador/chamados.php';
        } else {
            $destino = 'cliente/index.php';
        }
        header('Location: ' . $destino);
        exit;
    }
    audit_log_registar('auth.login_falha', 'auth', null, null, [
        'email' => function_exists('mb_substr') ? mb_substr(trim((string) $email), 0, 160, 'UTF-8') : substr(trim((string) $email), 0, 160),
    ], null);
    require_once __DIR__ . '/includes/repository.php';
    if (db_ok() && repo_usuario_credenciais_corretas_mas_inativa($email, $senha)) {
        $erro = 'Esta conta está desativada. Peça ao administrador para reativá-la.';
    } else {
        $erro = 'E-mail ou senha inválidos.';
    }
}

if (isset($_GET['erro']) && $_GET['erro'] === 'perfil') {
    $erro = 'Você não tem permissão para acessar essa área.';
}

if (isset($_GET['senha']) && $_GET['senha'] === 'redefinida') {
    $msgSenhaOk = 'Senha alterada com sucesso. Já pode entrar com a nova senha.';
}

$cssBustLogin = static function (string $file): int {
    $path = __DIR__ . '/assets/css/' . basename($file);
    return is_file($path) ? (int) filemtime($path) : 0;
};
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= htmlspecialchars($pageTitle) ?> · <?= htmlspecialchars(function_exists('app_brand_full') ? app_brand_full() : 'OnLight — Gestão em Iluminação') ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="icon" type="image/png" href="<?= htmlspecialchars(defined('APP_BRAND_ICON') ? APP_BRAND_ICON : 'assets/img/lighton-icon.png') ?>">
  <link rel="apple-touch-icon" href="<?= htmlspecialchars(defined('APP_BRAND_ICON') ? APP_BRAND_ICON : 'assets/img/lighton-icon.png') ?>">
  <link rel="stylesheet" href="assets/css/style.css?v=<?= $cssBustLogin('style.css') ?>">
  <link rel="stylesheet" href="assets/css/forms.css?v=<?= $cssBustLogin('forms.css') ?>">
  <link rel="stylesheet" href="assets/css/responsive.css?v=<?= $cssBustLogin('responsive.css') ?>">
  <style>
    .alert-error {
      background: rgba(226,75,74,0.08);
      border: 1px solid rgba(226,75,74,0.25);
      color: var(--danger);
      padding: 10px 12px; border-radius: 10px;
      font-size: 13px; font-weight: 600;
      margin-bottom: 14px;
    }
    .alert-success {
      background: rgba(34,197,94,0.10);
      border: 1px solid rgba(34,197,94,0.28);
      color: #166534;
      padding: 10px 12px; border-radius: 10px;
      font-size: 13px; font-weight: 600;
      margin-bottom: 14px;
      line-height: 1.45;
    }
    .quick-login {
      display: grid;
      gap: 8px;
      margin-top: 14px;
    }
    .quick-login-title {
      color: var(--muted);
      font-size: 12px;
      font-weight: 700;
      letter-spacing: .04em;
      text-transform: uppercase;
    }
    .quick-login-btn {
      align-items: center;
      background: rgba(79, 70, 229, .06);
      border: 1px solid rgba(79, 70, 229, .16);
      border-radius: 12px;
      color: var(--text);
      cursor: pointer;
      display: flex;
      justify-content: space-between;
      padding: 10px 12px;
      text-align: left;
      width: 100%;
    }
    .quick-login-btn strong,
    .quick-login-btn small {
      display: block;
    }
    .quick-login-btn small {
      color: var(--muted);
      font-size: 12px;
      margin-top: 2px;
    }
    .quick-login-badge {
      color: var(--primary);
      font-size: 12px;
      font-weight: 800;
      margin-left: 12px;
    }
  </style>
</head>
<body<?= app_debug_mode() ? ' data-form-fill-dev="1"' : '' ?>>

<div class="auth-wrap">
  <div class="auth-card">
    <div class="brand brand--login-logo">
      <img class="brand-logo-img brand-logo-img--login-full" src="<?= htmlspecialchars(defined('APP_BRAND_LOGO') ? APP_BRAND_LOGO : 'assets/img/lighton-logo.png') ?>" width="280" height="120" alt="<?= htmlspecialchars(function_exists('app_brand_full') ? app_brand_full() : 'OnLight — Gestão em Iluminação') ?>">
      <h1 class="sr-only"><?= htmlspecialchars(function_exists('app_brand_full') ? app_brand_full() : 'OnLight') ?></h1>
    </div>

    <h2>Acessar sistema</h2>
    <p class="auth-sub">Entre com seu e-mail e senha para continuar.</p>

    <?php if ($msgSenhaOk): ?>
      <div class="alert-success"><?= htmlspecialchars($msgSenhaOk) ?></div>
    <?php endif; ?>
    <?php if ($erro): ?>
      <div class="alert-error"><?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>

    <form action="login.php" method="post" autocomplete="off" id="login-form">
      <div class="form-group" style="margin-bottom:14px;">
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" class="input"
               placeholder="voce@empresa.com"
               value="<?= htmlspecialchars($emailPreenchido) ?>" required>
      </div>
      <div class="form-group" style="margin-bottom:18px;">
        <label for="senha">Senha</label>
        <input type="password" id="senha" name="senha" class="input"
               placeholder="••••••••" value="" required autocomplete="current-password">
      </div>

      <div class="flex-between" style="margin-bottom:20px;">
        <label class="checkbox">
          <input type="checkbox" checked>
          <span>Manter conectado</span>
        </label>
        <a href="esqueci_senha.php" style="color:var(--primary); font-weight:600; font-size:13px;">Esqueci minha senha</a>
      </div>

      <button type="submit" class="btn btn-primary btn-block btn-lg">Entrar</button>

      <div class="quick-login" aria-label="Acessos rápidos de teste">
        <div class="quick-login-title">Acessos rápidos de teste</div>
        <button type="button" class="quick-login-btn" data-login-email="ouvidoria@ipojuca.pe.gov.br">
          <span>
            <strong>Portal da prefeitura</strong>
            <small>ouvidoria@ipojuca.pe.gov.br</small>
          </span>
          <span class="quick-login-badge">12345678</span>
        </button>
        <button type="button" class="quick-login-btn" data-login-email="operador@operador.com.br">
          <span>
            <strong>Operador</strong>
            <small>operador@operador.com.br</small>
          </span>
          <span class="quick-login-badge">12345678</span>
        </button>
        <button type="button" class="quick-login-btn" data-login-email="gestor@gestor.com.br">
          <span>
            <strong>Gestão e iluminação</strong>
            <small>gestor@gestor.com.br</small>
          </span>
          <span class="quick-login-badge">12345678</span>
        </button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var form = document.getElementById('login-form');
  var email = document.getElementById('email');
  var senha = document.getElementById('senha');
  if (!form || !email || !senha) return;
  document.querySelectorAll('[data-login-email]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      email.value = btn.getAttribute('data-login-email') || '';
      senha.value = '12345678';
      form.submit();
    });
  });
})();
</script>

<?php if (app_debug_mode()): ?>
<script src="assets/js/form-fill-dev.js"></script>
<?php endif; ?>

</body>
</html>
