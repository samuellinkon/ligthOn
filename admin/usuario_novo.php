<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';

$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('usuarios');

$usuariosListaHref = (($me['perfil'] ?? '') === 'admin') ? 'usuarios.php' : 'index.php';

$escopoNu = gestao_scope_cliente_id($me);
$pageTitle   = 'Novo usuário';
$basePath    = '../';
$activePage  = 'usuarios';

if (!db_ok()) {
    flash_set('err', 'Banco indisponível.');
    header('Location: ' . $usuariosListaHref);
    exit;
}

/** Acessos do portal (cliente): na empresa do gestor ou todos (admin). */
$opcoesCliente = $escopoNu !== null
    ? repo_clientes_na_empresa($escopoNu)
    : repo_clientes();
$portalUmSoCliente = count($opcoesCliente) === 1;
$portalUnicoId = $portalUmSoCliente ? (int) ($opcoesCliente[0]['id'] ?? 0) : 0;
$portalUnicoNome = $portalUmSoCliente ? (string) ($opcoesCliente[0]['empresa'] ?? '') : '';
/** Cadastros raiz (empresa) para vincular operador/gestor — só admin escolhe na lista. */
$opcoesEmpresaRaiz = $escopoNu === null ? repo_clientes_empresas() : [];
$empresaUmSoRaiz = count($opcoesEmpresaRaiz) === 1;
$empresaUnicoId = $empresaUmSoRaiz ? (int) ($opcoesEmpresaRaiz[0]['id'] ?? 0) : 0;
$empresaUnicoNome = $empresaUmSoRaiz ? (string) ($opcoesEmpresaRaiz[0]['empresa'] ?? '') : '';
$preClienteId = (int) ($_GET['cliente_id'] ?? 0);
$prePerfil    = strtolower(trim((string) ($_GET['perfil'] ?? '')));
if (!in_array($prePerfil, ['cliente', 'operador', 'gestor'], true)) {
    $prePerfil = '';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome       = trim((string) ($_POST['nome'] ?? ''));
    $email      = trim((string) ($_POST['email'] ?? ''));
    $perfil       = (string) ($_POST['perfil'] ?? 'cliente');
    $clienteId    = (int) ($_POST['cliente_id'] ?? 0);
    $empresaIdPost = (int) ($_POST['empresa_id'] ?? 0);
    $senha        = (string) ($_POST['senha'] ?? '');
    $senha2     = (string) ($_POST['senha2'] ?? '');

    if ($nome === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('err', 'Informe nome e um e-mail válido.');
        header('Location: usuario_novo.php');
        exit;
    }
    if (!in_array($perfil, ['admin', 'gestor', 'cliente', 'operador'], true)) {
        flash_set('err', 'Perfil inválido.');
        header('Location: usuario_novo.php');
        exit;
    }
    $perfisGestorPermitidos = ['gestor', 'operador'];
    if ($escopoNu !== null && !in_array($perfil, $perfisGestorPermitidos, true)) {
        flash_set('err', 'Gestores da empresa de gestão só podem criar usuários gestor ou operador. Usuários do portal da prefeitura são criados pelo administrador.');
        header('Location: usuario_novo.php');
        exit;
    }
    if ($senha !== $senha2) {
        flash_set('err', 'As senhas não coincidem.');
        header('Location: usuario_novo.php');
        exit;
    }
    if (strlen($senha) < 6) {
        flash_set('err', 'A senha deve ter pelo menos 6 caracteres.');
        header('Location: usuario_novo.php');
        exit;
    }
    $empresaCriar = null;
    $clienteCriar = null;
    if ($perfil === 'admin') {
        // sem vínculo
    } elseif (in_array($perfil, ['operador', 'gestor'], true)) {
        $empresaCriar = $escopoNu !== null ? $escopoNu : $empresaIdPost;
        if (
            $empresaCriar <= 0
            && $escopoNu === null
            && $empresaUmSoRaiz
            && $empresaUnicoId > 0
        ) {
            $empresaCriar = $empresaUnicoId;
        }
        if ($empresaCriar <= 0) {
            flash_set('err', 'Selecione a empresa (cadastro raiz) para operador ou gestor.');
            header('Location: usuario_novo.php');
            exit;
        }
        if ($escopoNu === null) {
            $okE = false;
            foreach ($opcoesEmpresaRaiz as $er) {
                if ((int) ($er['id'] ?? 0) === $empresaCriar) {
                    $okE = true;
                    break;
                }
            }
            if (!$okE) {
                flash_set('err', 'Empresa inválida.');
                header('Location: usuario_novo.php');
                exit;
            }
        }
    } else {
        $clienteCriar = $clienteId;
        if ($clienteCriar <= 0) {
            flash_set('err', 'Selecione o cadastro da prefeitura (portal) para este usuário.');
            header('Location: usuario_novo.php');
            exit;
        }
        if ($escopoNu !== null && !repo_cliente_pertence_empresa($clienteCriar, $escopoNu)) {
            flash_set('err', 'Acesso cliente inválido para sua empresa.');
            header('Location: usuario_novo.php');
            exit;
        }
    }
    if (repo_email_existe($email)) {
        flash_set('err', 'Já existe um usuário com este e-mail.');
        header('Location: usuario_novo.php');
        exit;
    }

    if ($perfil === 'cliente') {
        $okCliente = false;
        foreach ($opcoesCliente as $c) {
            if ((int) $c['id'] === $clienteCriar) {
                $okCliente = true;
                break;
            }
        }
        if (!$okCliente) {
            flash_set('err', 'Cadastro do portal inválido.');
            header('Location: usuario_novo.php');
            exit;
        }
    }

    $newId = repo_create_usuario([
        'nome'          => $nome,
        'email'         => $email,
        'senha'         => $senha,
        'perfil'        => $perfil,
        'cliente_id'    => $clienteCriar,
        'empresa_id'    => $empresaCriar,
        'iniciais'      => repo_usuario_calcular_iniciais($nome),
        'modulo_perfil' => null,
    ]);

    if (!$newId) {
        flash_set('err', 'Não foi possível criar o usuário.');
        header('Location: usuario_novo.php');
        exit;
    }

    flash_set('ok', 'Usuário #' . $newId . ' criado com sucesso.');
    header('Location: usuario_editar.php?id=' . $newId);
    exit;
}

$topTitle    = 'Novo usuário';
$topSubtitle = 'Administrador, gestão, operador ou acesso ao portal da prefeitura';
$topSearch   = 'Buscar...';
$topAction   = ['label' => 'Voltar', 'href' => $usuariosListaHref, 'icon' => '←'];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <form class="card" method="post" action="usuario_novo.php" autocomplete="off">
    <div class="panel-head">
      <h4>Cadastro</h4>
      <span class="panel-sub">O e-mail será usado no login</span>
    </div>

    <div class="panel-body form form-grid form-grid--usuario">
      <div class="form-group full">
        <label for="nome">Nome completo</label>
        <input type="text" id="nome" name="nome" class="input" required maxlength="120" placeholder="Nome e sobrenome">
      </div>
      <div class="form-group full">
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" class="input" required maxlength="150" data-crm-mask="email" autocomplete="email" placeholder="nome@organização.gov.br">
      </div>
      <div class="form-group">
        <label for="perfil">Perfil</label>
        <select id="perfil" name="perfil" class="select" data-perfil-select>
          <?php if ($escopoNu !== null): ?>
          <option value="gestor" <?= $prePerfil === 'gestor' ? 'selected' : '' ?>>Gestor (empresa de gestão / painel interno)</option>
          <option value="operador" <?= $prePerfil !== 'gestor' ? 'selected' : '' ?>>Operador (campo / app)</option>
          <?php else: ?>
          <option value="cliente" <?= $prePerfil === 'operador' ? '' : 'selected' ?>>Portal da prefeitura</option>
          <option value="operador" <?= $prePerfil === 'operador' ? 'selected' : '' ?>>Operador (campo / app)</option>
          <option value="gestor" <?= $prePerfil === 'gestor' ? 'selected' : '' ?>>Gestor (empresa de gestão / painel interno)</option>
          <option value="admin">Administrador</option>
          <?php endif; ?>
        </select>
      </div>
      <?php if ($escopoNu !== null): ?>
      <input type="hidden" name="empresa_id" id="empresa_id_hidden" value="<?= (int) $escopoNu ?>">
      <?php elseif (!empty($opcoesEmpresaRaiz)): ?>
      <div class="form-group" id="wrap-empresa" style="display:none;">
        <label for="empresa_id">Empresa (raiz) — operador / gestor</label>
        <?php if ($empresaUmSoRaiz && $empresaUnicoId > 0): ?>
        <input type="hidden" name="empresa_id" id="empresa_id" value="<?= $empresaUnicoId ?>">
        <p class="muted" style="margin:0;padding:10px 12px;background:var(--bg,#f8f8fb);border-radius:10px;border:1px solid rgba(83,74,183,0.18);font-weight:500;">
          <?php if ($empresaUnicoNome !== ''): ?>
            <?= htmlspecialchars($empresaUnicoNome) ?> <span class="muted" style="font-weight:400;">(#<?= $empresaUnicoId ?>)</span>
          <?php else: ?>
            Cadastro raiz #<?= $empresaUnicoId ?>
          <?php endif; ?>
        </p>
        <small class="muted" style="display:block;margin-top:6px;">Única empresa cadastrada — vínculo aplicado automaticamente. Operadores atendem chamados de toda a empresa (matriz e unidades vinculadas).</small>
        <?php else: ?>
        <select id="empresa_id" name="empresa_id" class="select">
          <option value="0">— Selecione —</option>
          <?php foreach ($opcoesEmpresaRaiz as $er): ?>
          <option value="<?= (int) $er['id'] ?>">
            <?= htmlspecialchars($er['empresa'] ?? '') ?> (#<?= (int) $er['id'] ?>)
          </option>
          <?php endforeach; ?>
        </select>
        <small class="muted" style="display:block;margin-top:6px;">Operadores atendem chamados de toda a empresa (matriz e unidades vinculadas).</small>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="form-group" id="wrap-cliente">
        <label for="cliente_id">Acesso ao portal da prefeitura</label>
        <?php if ($portalUmSoCliente && $portalUnicoId > 0): ?>
        <input type="hidden" name="cliente_id" id="cliente_id" value="<?= $portalUnicoId ?>">
        <p class="muted" style="margin:0;padding:10px 12px;background:var(--bg,#f8f8fb);border-radius:10px;border:1px solid rgba(83,74,183,0.18);font-weight:500;">
          <?php if ($portalUnicoNome !== ''): ?>
            <?= htmlspecialchars($portalUnicoNome) ?> <span class="muted" style="font-weight:400;">(#<?= $portalUnicoId ?>)</span>
          <?php else: ?>
            Cadastro #<?= $portalUnicoId ?>
          <?php endif; ?>
        </p>
        <small class="muted" style="display:block;margin-top:6px;">Único cadastro disponível — vínculo aplicado automaticamente.</small>
        <?php else: ?>
        <select id="cliente_id" name="cliente_id" class="select">
          <option value="0">— Selecione —</option>
          <?php foreach ($opcoesCliente as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= ($preClienteId === (int) $c['id']) ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['empresa'] ?? '') ?> (#<?= (int) $c['id'] ?>)
          </option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
      </div>
      <div class="form-group">
        <label for="senha">Senha</label>
        <input type="password" id="senha" name="senha" class="input" required minlength="6" autocomplete="new-password" placeholder="Mínimo 6 caracteres">
      </div>
      <div class="form-group">
        <label for="senha2">Confirmar senha</label>
        <input type="password" id="senha2" name="senha2" class="input" required minlength="6" autocomplete="new-password" placeholder="Repita a senha">
      </div>
    </div>

    <div class="panel-body">
      <div class="form-actions" style="padding:0;border:0;background:transparent;">
        <a href="<?= htmlspecialchars($usuariosListaHref) ?>" class="btn btn-secondary">Cancelar</a>
        <button type="submit" class="btn btn-primary">Criar usuário</button>
      </div>
    </div>
  </form>
</section>

<script>
(function () {
  var sel = document.querySelector('[data-perfil-select]');
  var wrapC = document.getElementById('wrap-cliente');
  var wrapE = document.getElementById('wrap-empresa');
  var cli = document.getElementById('cliente_id');
  var emp = document.getElementById('empresa_id');
  if (!sel || !cli) return;
  var escopoGestor = <?= $escopoNu !== null ? 'true' : 'false' ?>;
  function sync() {
    var admin = sel.value === 'admin';
    var op = sel.value === 'operador';
    var gest = sel.value === 'gestor';
    var portal = sel.value === 'cliente';
    cli.disabled = !portal;
    if (wrapC) {
      wrapC.style.display = portal ? '' : 'none';
      wrapC.style.opacity = portal && !admin ? '1' : (portal ? '1' : '1');
    }
    if (wrapE && emp) {
      var showE = (op || gest) && !escopoGestor;
      wrapE.style.display = showE ? '' : 'none';
      emp.disabled = !showE;
    }
  }
  sel.addEventListener('change', sync);
  sel.addEventListener('input', sync);
  sync();
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
