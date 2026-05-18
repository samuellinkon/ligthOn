<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';

$user = require_auth('cliente');
$pageTitle   = 'Minha conta';
$basePath    = '../';
$activePage  = 'perfil';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'dados') {
        $nome  = trim((string) ($_POST['nome'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        if (db_ok()) {
            $r = repo_update_usuario_perfil((int) ($user['id'] ?? 0), $nome, $email);
            if ($r['ok']) {
                $u = repo_user_by_id((int) ($user['id'] ?? 0));
                if ($u) {
                    $_SESSION['user'] = $u;
                }
            }
            flash_set($r['ok'] ? 'ok' : 'err', $r['ok'] ? 'Dados salvos com sucesso.' : $r['err']);
        } else {
            $_SESSION['user']['nome']     = $nome;
            $_SESSION['user']['email']    = $email;
            $_SESSION['user']['iniciais'] = repo_usuario_calcular_iniciais($nome);
            flash_set('ok', 'Dados atualizados nesta sessão (modo sem banco — não persistem após novo login).');
        }
        header('Location: perfil.php');
        exit;
    }

    if ($acao === 'senha') {
        if (!db_ok()) {
            flash_set('err', 'Alteração de senha exige banco de dados ativo.');
            header('Location: perfil.php');
            exit;
        }
        $r = repo_update_usuario_senha(
            (int) ($user['id'] ?? 0),
            (string) ($_POST['senha_atual'] ?? ''),
            (string) ($_POST['senha_nova'] ?? ''),
            (string) ($_POST['senha_nova2'] ?? '')
        );
        flash_set($r['ok'] ? 'ok' : 'err', $r['ok'] ? 'Senha alterada com sucesso.' : $r['err']);
        header('Location: perfil.php');
        exit;
    }

    header('Location: perfil.php');
    exit;
}

$user = $_SESSION['user'] ?? $user;
$topTitle    = 'Minha conta';
$topSubtitle = 'Seus dados de acesso ao portal';
$topSearch   = 'Buscar...';
$topAction   = ['label' => 'Voltar', 'href' => 'index.php', 'icon' => '←'];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-cliente.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content perfil-cliente">
  <div class="perfil-cliente__wrap">
    <header class="card perfil-cliente__hero" aria-labelledby="perfil-cliente-nome">
      <div class="perfil-cliente__avatar-wrap" aria-hidden="true">
        <div class="avatar perfil-cliente__avatar"><?= htmlspecialchars(function_exists('initials') ? initials((string) ($user['nome'] ?? '')) : '?') ?></div>
      </div>
      <div class="perfil-cliente__hero-text">
        <p class="perfil-cliente__kicker">Sua conta no portal</p>
        <h2 id="perfil-cliente-nome" class="perfil-cliente__nome"><?= htmlspecialchars((string) ($user['nome'] ?? '')) ?></h2>
        <p class="perfil-cliente__email"><?= htmlspecialchars((string) ($user['email'] ?? '')) ?></p>
      </div>
    </header>

    <div class="perfil-cliente__cards">
      <div class="card perfil-cliente__card">
        <div class="panel-head perfil-cliente__panel-head">
          <div>
            <h4>Dados do perfil</h4>
            <span class="panel-sub">Nome e e-mail usados nos chamados e notificações</span>
          </div>
        </div>
        <form class="panel-body form-grid form-grid--perfil" method="post" action="perfil.php" autocomplete="off">
          <input type="hidden" name="acao" value="dados">
          <div class="form-group full">
            <label for="nome">Nome completo</label>
            <input type="text" id="nome" name="nome" class="input" required maxlength="120"
                   placeholder="Nome e sobrenome"
                   value="<?= htmlspecialchars((string) ($user['nome'] ?? '')) ?>">
          </div>
          <div class="form-group full">
            <label for="email">E-mail</label>
            <input type="email" id="email" name="email" class="input" required maxlength="150"
                   placeholder="nome@prefeitura.gov.br"
                   value="<?= htmlspecialchars((string) ($user['email'] ?? '')) ?>">
          </div>
          <div class="form-actions full">
            <button type="submit" class="btn btn-primary">Salvar dados</button>
          </div>
        </form>
      </div>

      <div class="card perfil-cliente__card">
        <div class="panel-head perfil-cliente__panel-head">
          <div>
            <h4>Segurança</h4>
            <span class="panel-sub">Senha com no mínimo 6 caracteres</span>
          </div>
        </div>
        <form class="panel-body form-grid form-grid--perfil" method="post" action="perfil.php" autocomplete="off">
          <input type="hidden" name="acao" value="senha">
          <div class="form-group full">
            <label for="senha_atual">Senha atual</label>
            <input type="password" id="senha_atual" name="senha_atual" class="input" autocomplete="current-password"
                   placeholder="Senha em uso"
                   <?= db_ok() ? '' : 'disabled' ?>>
          </div>
          <div class="form-group full">
            <label for="senha_nova">Nova senha</label>
            <input type="password" id="senha_nova" name="senha_nova" class="input" autocomplete="new-password" minlength="6"
                   placeholder="Mínimo 6 caracteres"
                   <?= db_ok() ? '' : 'disabled' ?>>
          </div>
          <div class="form-group full">
            <label for="senha_nova2">Confirmar nova senha</label>
            <input type="password" id="senha_nova2" name="senha_nova2" class="input" autocomplete="new-password" minlength="6"
                   placeholder="Repita a nova senha"
                   <?= db_ok() ? '' : 'disabled' ?>>
          </div>
          <?php if (!db_ok()): ?>
          <p class="muted full perfil-cliente__hint">Conecte o MySQL para alterar a senha com segurança.</p>
          <?php endif; ?>
          <div class="form-actions full">
            <button type="submit" class="btn btn-primary" <?= db_ok() ? '' : 'disabled' ?>>Atualizar senha</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
