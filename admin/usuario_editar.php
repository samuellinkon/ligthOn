<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';

$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('usuarios');

$usuariosListaHref = (($me['perfil'] ?? '') === 'admin') ? 'usuarios.php' : 'index.php';

$escopoUe = gestao_scope_cliente_id($me);
$pageTitle   = 'Editar usuário';
$basePath    = '../';
$activePage  = 'usuarios';

$userId = (int) ($_GET['id'] ?? 0);
if ($userId <= 0) {
    flash_set('err', 'Usuário não informado.');
    header('Location: ' . $usuariosListaHref);
    exit;
}

if (!db_ok()) {
    flash_set('err', 'Banco indisponível.');
    header('Location: ' . $usuariosListaHref);
    exit;
}

$usuario = repo_user_by_id($userId);
if (!$usuario) {
    flash_set('err', 'Usuário #' . $userId . ' não encontrado.');
    header('Location: ' . $usuariosListaHref);
    exit;
}

if ($escopoUe !== null) {
    $meId   = (int) ($me['id'] ?? 0);
    $uCid   = isset($usuario['cliente_id']) && $usuario['cliente_id'] !== null ? (int) $usuario['cliente_id'] : 0;
    $uEid   = isset($usuario['empresa_id']) && $usuario['empresa_id'] !== null ? (int) $usuario['empresa_id'] : 0;
    $uPer   = (string) ($usuario['perfil'] ?? '');
    $podeUe = ($meId === $userId && $uPer === 'gestor')
        || ($uPer === 'operador' && $uEid === $escopoUe)
        || ($uPer === 'gestor' && $uEid === $escopoUe)
        || ($uPer === 'cliente' && repo_cliente_pertence_empresa($uCid, $escopoUe));
    if (!$podeUe) {
        flash_set('err', 'Sem permissão para editar este usuário.');
        header('Location: ' . $usuariosListaHref);
        exit;
    }
}

$opcoesCliente = $escopoUe !== null ? repo_clientes_na_empresa($escopoUe) : repo_clientes();
$opcoesEmpresaRaiz = $escopoUe === null ? repo_clientes_empresas() : [];
$empresaIdUsuario = isset($usuario['empresa_id']) && $usuario['empresa_id'] !== null ? (int) $usuario['empresa_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['acao'] ?? '') === 'excluir_usuario') {
    $returnPosted = trim((string) ($_POST['return_url'] ?? ''));
    $validReturn  = preg_match('#^cliente_detalhe\\.php\\?id=\\d+$#', $returnPosted) ? $returnPosted : '';
    if ($escopoUe !== null && (int) ($me['id'] ?? 0) !== $userId) {
        $rDel = repo_usuario_delete_by_gestor($userId, (int) ($me['id'] ?? 0), $escopoUe);
        if ($rDel['ok']) {
            flash_set('ok', 'Utilizador excluído.');
            if ($validReturn !== '') {
                header('Location: ' . $validReturn);
            } else {
                header('Location: ' . $usuariosListaHref);
            }
            exit;
        }
        flash_set('err', $rDel['err'] !== '' ? $rDel['err'] : 'Não foi possível excluir.');
    } else {
        flash_set('err', 'Sem permissão para excluir este utilizador.');
    }
    header('Location: usuario_editar.php?id=' . $userId . ($validReturn !== '' ? '&embed=1&return=' . rawurlencode($validReturn) : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $returnPosted = trim((string) ($_POST['return_url'] ?? ''));
    $validReturn  = preg_match('#^cliente_detalhe\\.php\\?id=\\d+$#', $returnPosted) ? $returnPosted : '';
    $errSuffix    = $validReturn !== '' ? '&embed=1&return=' . rawurlencode($validReturn) : '';

    $nome        = trim((string) ($_POST['nome'] ?? ''));
    $email       = trim((string) ($_POST['email'] ?? ''));
    $perfil      = (string) ($_POST['perfil'] ?? 'cliente');
    $clienteId   = (int) ($_POST['cliente_id'] ?? 0);
    $empresaIdPost = (int) ($_POST['empresa_id'] ?? 0);
    $senhaNova   = (string) ($_POST['senha_nova'] ?? '');
    $senhaNova2  = (string) ($_POST['senha_nova2'] ?? '');

    $ativoOpt = null;
    if (function_exists('repo_usuarios_ativo_column_exists') && repo_usuarios_ativo_column_exists()) {
        if (($me['perfil'] ?? '') === 'admin' || $escopoUe !== null) {
            $ativoOpt = isset($_POST['conta_ativa']) ? 1 : 0;
        }
    }

    $r = repo_update_usuario_admin(
        $userId,
        $nome,
        $email,
        $perfil,
        $clienteId > 0 ? $clienteId : null,
        null,
        $escopoUe,
        (int) ($me['id'] ?? 0),
        $empresaIdPost > 0 ? $empresaIdPost : null,
        $ativoOpt
    );
    if (!$r['ok']) {
        flash_set('err', $r['err']);
        header('Location: usuario_editar.php?id=' . $userId . $errSuffix);
        exit;
    }

    if ($senhaNova !== '' || $senhaNova2 !== '') {
        $rp = repo_admin_definir_senha_usuario($userId, $senhaNova, $senhaNova2);
        if (!$rp['ok']) {
            flash_set('err', 'Dados salvos, mas a senha não foi alterada: ' . $rp['err']);
            header('Location: usuario_editar.php?id=' . $userId . $errSuffix);
            exit;
        }
    }

    if ((int) ($me['id'] ?? 0) === $userId) {
        $fresh = repo_user_by_id($userId);
        if ($fresh) {
            $_SESSION['user'] = $fresh;
        }
    }

    flash_set('ok', 'Usuário atualizado com sucesso.');
    if ($validReturn !== '') {
        header('Location: ' . $validReturn);
    } else {
        header('Location: usuario_editar.php?id=' . $userId);
    }
    exit;
}

$embed = isset($_GET['embed']) && (string) $_GET['embed'] === '1';
$returnClient = '';
if ($embed && preg_match('#^cliente_detalhe\\.php\\?id=\\d+$#', (string) ($_GET['return'] ?? ''))) {
    $returnClient = (string) $_GET['return'];
}

if ($embed) {
    require_once __DIR__ . '/../includes/config.php';
    $cssBust = static function (string $file): int {
        $path = dirname(__DIR__) . '/assets/css/' . basename($file);
        return is_file($path) ? (int) filemtime($path) : 0;
    };
    header('X-Frame-Options: SAMEORIGIN');
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Editar usuário · <?= htmlspecialchars(function_exists('app_brand_full') ? app_brand_full() : 'OnLight') ?></title>
  <link rel="stylesheet" href="<?= htmlspecialchars($basePath) ?>assets/css/style.css?v=<?= $cssBust('style.css') ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars($basePath) ?>assets/css/forms.css?v=<?= $cssBust('forms.css') ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars($basePath) ?>assets/css/tables.css?v=<?= $cssBust('tables.css') ?>">
</head>
<body style="margin:0;padding:12px;background:var(--bg,#f4f4f8);">
<?php
    $embedForm = true;
    $returnUrlHidden = $returnClient;
    $cancelHref = $returnClient !== '' ? $returnClient : $usuariosListaHref;
    include __DIR__ . '/../includes/partials/usuario_editar_form.php';
?>
<script>
(function () {
  var sel = document.querySelector('[data-perfil-select]');
  if (!sel) return;
  var wrapC = document.getElementById('wrap-cliente');
  var wrapE = document.getElementById('wrap-empresa');
  var cli = document.getElementById('cliente_id');
  var emp = document.getElementById('empresa_id');
  if (!cli) return;
  var escopoGestor = <?= $escopoUe !== null ? 'true' : 'false' ?>;
  function sync() {
    var admin = sel.value === 'admin';
    var op = sel.value === 'operador';
    var gest = sel.value === 'gestor';
    var portal = sel.value === 'cliente';
    cli.disabled = admin || !portal;
    if (wrapC) {
      wrapC.style.display = portal ? '' : 'none';
      wrapC.style.opacity = admin ? '0.45' : '1';
    }
    if (wrapE && emp) {
      var showE = (op || gest) && !escopoGestor;
      wrapE.style.display = showE ? '' : 'none';
      emp.disabled = !showE;
    }
  }
  sel.addEventListener('change', sync);
  sync();
})();
</script>
</body>
</html>
<?php
    exit;
}

$topTitle    = 'Editar usuário';
$topSubtitle = htmlspecialchars((string) ($usuario['nome'] ?? '')) . ' · #' . $userId;
$topSearch   = 'Buscar...';
$topAction   = ['label' => 'Lista de usuários', 'href' => $usuariosListaHref, 'icon' => '←'];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
<?php
$embedForm = false;
$returnUrlHidden = '';
$cancelHref = $usuariosListaHref;
include __DIR__ . '/../includes/partials/usuario_editar_form.php';
?>
</section>

<script>
(function () {
  var sel = document.querySelector('[data-perfil-select]');
  if (!sel) return;
  var wrapC = document.getElementById('wrap-cliente');
  var wrapE = document.getElementById('wrap-empresa');
  var cli = document.getElementById('cliente_id');
  var emp = document.getElementById('empresa_id');
  if (!cli) return;
  var escopoGestor = <?= $escopoUe !== null ? 'true' : 'false' ?>;
  function sync() {
    var admin = sel.value === 'admin';
    var op = sel.value === 'operador';
    var gest = sel.value === 'gestor';
    var portal = sel.value === 'cliente';
    cli.disabled = admin || !portal;
    if (wrapC) {
      wrapC.style.display = portal ? '' : 'none';
      wrapC.style.opacity = admin ? '0.45' : '1';
    }
    if (wrapE && emp) {
      var showE = (op || gest) && !escopoGestor;
      wrapE.style.display = showE ? '' : 'none';
      emp.disabled = !showE;
    }
  }
  sel.addEventListener('change', sync);
  sync();
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
