<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';

$user = require_auth_gestao();
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
            flash_set('err', 'Alteração de senha exige banco de dados ativo (usuários reais).');
            header('Location: perfil.php');
            exit;
        }
        $r = repo_update_usuario_senha(
            (int) ($user['id'] ?? 0),
            (string) ($_POST['senha_atual'] ?? ''),
            (string) ($_POST['senha_nova'] ?? ''),
            (string) ($_POST['senha_nova2'] ?? '')
        );
        flash_set($r['ok'] ? 'ok' : 'err', $r['ok'] ? 'Senha alterada. Use a nova senha no próximo login.' : $r['err']);
        header('Location: perfil.php');
        exit;
    }

    header('Location: perfil.php');
    exit;
}

$user = $_SESSION['user'] ?? $user;
$topTitle    = 'Minha conta';
$topSubtitle = 'Nome, e-mail e senha de acesso';
$topSearch   = 'Buscar...';
$topAction   = ['label' => 'Voltar ao painel', 'href' => 'index.php', 'icon' => '←'];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <div class="card mb-24">
    <div class="panel-head">
      <h4>Dados pessoais</h4>
      <span class="panel-sub">Nome e e-mail usados no sistema e nos envios</span>
    </div>
    <form class="panel-body form form-grid" method="post" action="perfil.php" autocomplete="off" >
      <input type="hidden" name="acao" value="dados">
      <div class="form-group full">
        <label for="nome">Nome completo</label>
        <input type="text" id="nome" name="nome" class="input" required maxlength="120"
               placeholder="Nome e sobrenome"
               value="<?= htmlspecialchars((string) ($user['nome'] ?? '')) ?>">
      </div>
      <div class="form-group full">
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" class="input" required maxlength="150" data-crm-mask="email" autocomplete="email"
               placeholder="nome@organização.gov.br"
               value="<?= htmlspecialchars((string) ($user['email'] ?? '')) ?>">
      </div>
      <div class="form-actions full" style="grid-column:1/-1;padding:0;border:0;background:transparent;">
        <button type="submit" class="btn btn-primary">Salvar dados</button>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="panel-head">
      <h4>Alterar senha</h4>
      <span class="panel-sub">Informe a senha atual e escolha uma nova</span>
    </div>
    <form class="panel-body form form-grid" method="post" action="perfil.php" autocomplete="off">
      <input type="hidden" name="acao" value="senha">
      <div class="form-group full">
        <label for="senha_atual">Senha atual</label>
        <input type="password" id="senha_atual" name="senha_atual" class="input" autocomplete="current-password"
               placeholder="Senha em uso"
               <?= db_ok() ? '' : 'disabled' ?>>
      </div>
      <div class="form-group">
        <label for="senha_nova">Nova senha</label>
        <input type="password" id="senha_nova" name="senha_nova" class="input" autocomplete="new-password" minlength="6"
               placeholder="Mínimo 6 caracteres"
               <?= db_ok() ? '' : 'disabled' ?>>
      </div>
      <div class="form-group">
        <label for="senha_nova2">Confirmar nova senha</label>
        <input type="password" id="senha_nova2" name="senha_nova2" class="input" autocomplete="new-password" minlength="6"
               placeholder="Repita a nova senha"
               <?= db_ok() ? '' : 'disabled' ?>>
      </div>
      <?php if (!db_ok()): ?>
      <p class="muted full" style="grid-column:1/-1;font-size:13px;">Com o MySQL desligado, a troca de senha fica desativada. Ative o banco para alterar a senha do seu usuário.</p>
      <?php endif; ?>
      <div class="form-actions full" style="grid-column:1/-1;padding:0;border:0;background:transparent;">
        <button type="submit" class="btn btn-primary" <?= db_ok() ? '' : 'disabled' ?>>Alterar senha</button>
      </div>
    </form>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
