<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('clientes');

$pageTitle  = 'Editar cliente';
$basePath   = '../';
$activePage = 'clientes';

$clienteId = (int) ($_GET['id'] ?? 0);
if ($clienteId <= 0) {
    flash_set('err', 'Cadastro não informado.');
    header('Location: clientes.php'); exit;
}

if (!db_ok()) {
    flash_set('err', 'Banco indisponível. Execute install.php primeiro.');
    header('Location: clientes.php'); exit;
}

$cliente = repo_cliente($clienteId);
if (!$cliente) {
    flash_set('err', 'Cadastro #' . $clienteId . ' não encontrado.');
    header('Location: clientes.php'); exit;
}
$escopoGestor = gestao_scope_cliente_id($me);
gestor_assert_escopo_cliente($clienteId, 'clientes.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $empresaGestoraId = 0;
        if (isset($cliente['empresa_id']) && $cliente['empresa_id'] !== null && $cliente['empresa_id'] !== '') {
            $empresaGestoraId = (int) $cliente['empresa_id'];
        }
        if ($empresaGestoraId === $clienteId) {
            $empresaGestoraId = 0;
        }
        if ($escopoGestor !== null) {
            $empresaGestoraId = $clienteId === $escopoGestor ? 0 : $escopoGestor;
        }
        $dados = [
            'nome'              => trim($_POST['nome']     ?? ''),
            'empresa'           => trim($_POST['empresa']  ?? ''),
            'email'             => trim($_POST['email']    ?? ''),
            'telefone'          => trim($_POST['telefone'] ?? ''),
            'doc'               => trim($_POST['doc']      ?? ''),
            'status'            => $_POST['status']        ?? 'Ativo',
            'obs'               => trim($_POST['obs']      ?? ''),
            'empresa_id'         => $empresaGestoraId > 0 ? $empresaGestoraId : null,
        ];
        repo_update_cliente($clienteId, $dados);
        flash_set('ok', 'Cadastro #' . $clienteId . ' atualizado.');
        header('Location: cliente_detalhe.php?id=' . $clienteId); exit;
    } catch (Throwable $e) {
        flash_set('err', 'Falha ao salvar: ' . $e->getMessage());
        header('Location: cliente_editar.php?id=' . $clienteId); exit;
    }
}

$topTitle    = 'Editar — ' . $cliente['empresa'];
$topSubtitle = 'Cadastro #' . $clienteId;
$topSearch   = 'Buscar cadastro existente...';
$topAction   = ['label' => 'Voltar', 'href' => 'cliente_detalhe.php?id=' . $clienteId, 'icon' => '←'];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <form class="card" action="cliente_editar.php?id=<?= $clienteId ?>" method="post" autocomplete="off">
    <div class="panel-head">
      <h4>Dados do cliente</h4>
      <span class="panel-sub">Atualize as informações conforme necessário</span>
    </div>

    <div class="form form-grid">
      <div class="form-group">
        <label for="nome">Nome do contato</label>
        <input type="text" id="nome" name="nome" class="input" required
               placeholder="Nome do contato"
               value="<?= htmlspecialchars($cliente['nome']) ?>">
      </div>
      <div class="form-group">
        <label for="empresa">Empresa</label>
        <input type="text" id="empresa" name="empresa" class="input" required
               placeholder="Razão social ou nome fantasia"
               value="<?= htmlspecialchars($cliente['empresa']) ?>">
      </div>
      <div class="form-group">
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" class="input"
               data-crm-mask="email" autocomplete="email"
               placeholder="contato@empresa.gov.br"
               value="<?= htmlspecialchars($cliente['email'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="telefone">Telefone</label>
        <input type="tel" id="telefone" name="telefone" class="input"
               data-crm-mask="telefone" inputmode="tel" autocomplete="tel"
               placeholder="(00) 00000-0000"
               value="<?= htmlspecialchars($cliente['telefone'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="doc">CPF / CNPJ</label>
        <input type="text" id="doc" name="doc" class="input"
               data-crm-mask="cpf-cnpj" inputmode="numeric" autocomplete="off"
               placeholder="CPF ou CNPJ"
               value="<?= htmlspecialchars($cliente['doc'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="status">Status</label>
        <select id="status" name="status" class="select">
          <?php foreach (['Ativo','Pendente','Fechado'] as $s): ?>
            <option <?= $cliente['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group full">
        <label for="obs">Observações</label>
        <textarea id="obs" name="obs" class="textarea" placeholder="Notas internas sobre o cadastro"><?= htmlspecialchars($cliente['obs'] ?? '') ?></textarea>
      </div>
    </div>

    <div class="form-actions">
      <a href="cliente_detalhe.php?id=<?= $clienteId ?>" class="btn btn-secondary">Cancelar</a>
      <button type="submit" class="btn btn-primary">Salvar alterações</button>
    </div>
  </form>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
