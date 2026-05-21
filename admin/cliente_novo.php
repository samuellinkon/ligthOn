<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/upload.php';
$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('clientes');
$pageTitle  = 'Novo cadastro (prefeitura)';
$basePath   = '../';
$activePage = 'clientes';
$escopoGestor = gestao_scope_cliente_id($me);
$empresasGestoras = db_ok() ? ($escopoGestor !== null ? array_values(array_filter(repo_clientes_empresas(), fn ($eg) => (int) ($eg['id'] ?? 0) === $escopoGestor)) : repo_clientes_empresas()) : [];
$empresaPrefeituraId = 0;
if ($escopoGestor !== null) {
    $empresaPrefeituraId = $escopoGestor;
} else {
    foreach ($empresasGestoras as $eg) {
        $rotuloEmpresa = mb_strtolower((string) (($eg['empresa'] ?? '') . ' ' . ($eg['nome'] ?? '')), 'UTF-8');
        if (strpos($rotuloEmpresa, 'eip') !== false) {
            $empresaPrefeituraId = (int) ($eg['id'] ?? 0);
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!db_ok()) {
        flash_set('err', 'Banco indisponível. Execute install.php primeiro.');
        header('Location: cliente_novo.php'); exit;
    }
    try {
        $nome        = trim($_POST['nome']     ?? '');
        $empresa     = trim($_POST['empresa']  ?? '');
        $email       = trim($_POST['email']    ?? '');
        $telefone    = trim($_POST['telefone'] ?? '');
        $doc         = trim($_POST['doc']      ?? '');
        $status      = $_POST['status']        ?? 'Ativo';
        $obs         = trim($_POST['obs']      ?? '');
        $criarLogin  = !empty($_POST['criar_login']);
        $empresaGestoraId = (int) ($_POST['empresa_id'] ?? 0);
        if ($escopoGestor !== null) {
            $empresaGestoraId = $escopoGestor;
        }

        if ($empresaGestoraId > 0) {
            $empresaOk = false;
            foreach (repo_clientes_empresas() as $eg) {
                if ((int) ($eg['id'] ?? 0) === $empresaGestoraId) {
                    $empresaOk = true;
                    break;
                }
            }
            if (!$empresaOk) {
                flash_set('err', 'Empresa gestora inválida.');
                header('Location: cliente_novo.php'); exit;
            }
        }

        if ($criarLogin && $email && repo_email_existe($email)) {
            flash_set('err', 'Já existe um login para o e-mail "' . htmlspecialchars($email) . '".');
            header('Location: cliente_novo.php'); exit;
        }

        $id = repo_create_cliente([
            'nome'              => $nome,
            'empresa'           => $empresa,
            'email'             => $email,
            'telefone'          => $telefone,
            'doc'               => $doc,
            'status'            => $status,
            'desde'             => date('Y-m-d'),
            'obs'               => $obs,
            'empresa_id'         => $empresaGestoraId > 0 ? $empresaGestoraId : null,
        ]);

        $msg = 'Cadastro #' . $id . ' registrado com sucesso.';

        // ---- Anexos (contrato, documentos) ----
        if (!empty($_FILES['anexos']) && is_array($_FILES['anexos']['name'])) {
            $tipoAnexo = $_POST['tipo_anexo'] ?? 'Contrato';
            $descAnexo = trim($_POST['descricao_anexo'] ?? '');
            $resUp = upload_gravar_multiplos($_FILES['anexos'], $id);
            foreach ($resUp['salvos'] as $arq) {
                repo_create_cliente_anexo([
                    'cliente_id'    => $id,
                    'nome_original' => $arq['nome_original'],
                    'nome_arquivo'  => $arq['nome_arquivo'],
                    'mime'          => $arq['mime'],
                    'tamanho'       => $arq['tamanho'],
                    'tipo'          => $tipoAnexo,
                    'descricao'     => $descAnexo ?: null,
                    'enviado_por'   => $me['nome'] ?? 'Admin',
                ]);
            }
            if ($resUp['salvos']) {
                $msg .= ' ' . count($resUp['salvos']) . ' anexo(s) enviado(s).';
            }
            if ($resUp['erros']) {
                flash_set('err', 'Alguns arquivos não foram enviados: ' . implode(' | ', $resUp['erros']));
            }
        }

        if ($criarLogin && $email) {
            $senhaPlana = gerar_senha_temp(10);
            repo_create_usuario([
                'nome'       => $nome,
                'email'      => $email,
                'senha'      => $senhaPlana,
                'perfil'     => 'cliente',
                'cliente_id' => $id,
                'iniciais'   => mb_strtoupper(mb_substr($nome, 0, 1, 'UTF-8')
                              . mb_substr(explode(' ', $nome)[count(explode(' ', $nome)) - 1] ?? '', 0, 1, 'UTF-8'), 'UTF-8'),
            ]);

            $proto    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $base     = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
            $portalUrl = $proto . '://' . $host . dirname($base) . '/login.php';

            $mail = mail_welcome($email, $nome, $empresa, $email, $senhaPlana, $portalUrl);
            if ($mail['ok']) {
                $msg .= ' Login criado e e-mail enviado para ' . $email . '.';
            } else {
                $msg .= ' Login criado, mas o e-mail não pôde ser enviado. Senha temporária: ' . $senhaPlana;
            }
        }

        flash_set('ok', $msg);
        header('Location: clientes.php'); exit;
    } catch (Throwable $e) {
        flash_set('err', 'Falha ao salvar: ' . $e->getMessage());
        header('Location: cliente_novo.php'); exit;
    }
}

$topTitle    = 'Novo cadastro';
$topSubtitle = 'Cadastre os dados e, se quiser, crie acesso ao portal.';
$topSearch   = 'Buscar cadastro existente...';
$topAction   = ['label' => 'Voltar', 'href' => 'clientes.php', 'icon' => '←'];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <form class="card" action="cliente_novo.php" method="post" autocomplete="off" enctype="multipart/form-data">
    <div class="panel-head">
      <div>
        <h4>Dados do cliente</h4>
        <span class="panel-sub">Preencha as informações principais</span>
      </div>
      <button type="button" class="btn btn-secondary btn-sm" id="btn-preencher-prefeitura">
        Preencher com dados da Prefeitura
      </button>
    </div>

    <div class="form form-grid">
      <div class="form-group">
        <label for="nome">Nome do contato</label>
        <input type="text" id="nome" name="nome" class="input" placeholder="Ex: Mariana Costa" required
               value="Prefeitura do Ipojuca">
      </div>
      <div class="form-group">
        <label for="empresa">Empresa</label>
        <input type="text" id="empresa" name="empresa" class="input" placeholder="Ex: Clínica Visão" required
               value="Prefeitura Municipal do Ipojuca">
      </div>
      <div class="form-group">
        <label for="empresa_id">Empresa gestora (licença)</label>
        <select id="empresa_id" name="empresa_id" class="select">
          <option value="0">Nenhuma — este cadastro é uma empresa gestora</option>
          <?php foreach ($empresasGestoras as $eg): ?>
            <option value="<?= (int) $eg['id'] ?>" <?= $empresaPrefeituraId === (int) $eg['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars((string) ($eg['empresa'] ?? '')) ?> (#<?= (int) $eg['id'] ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <span class="hint">Use EIP para clientes atendidos pela empresa. Deixe vazio apenas para cadastrar uma nova empresa gestora.</span>
      </div>
      <div class="form-group">
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" class="input" placeholder="contato@empresa.com" required
               data-crm-mask="email" autocomplete="email"
               value="ouvidoria@ipojuca.pe.gov.br">
      </div>
      <div class="form-group">
        <label for="telefone">Telefone</label>
        <input type="tel" id="telefone" name="telefone" class="input" placeholder="(11) 98765-4321"
               data-crm-mask="telefone" inputmode="tel" maxlength="15" autocomplete="tel" value="(81) 3551-1156">
      </div>
      <div class="form-group">
        <label for="doc">CPF / CNPJ</label>
        <input type="text" id="doc" name="doc" class="input" placeholder="000.000.000-00 ou 00.000.000/0000-00"
               data-crm-mask="cpf-cnpj" inputmode="numeric" maxlength="18" autocomplete="off" value="11.294.386/0001-08">
      </div>
      <div class="form-group">
        <label for="status">Status</label>
        <select id="status" name="status" class="select">
          <option>Ativo</option>
          <option>Pendente</option>
          <option>Fechado</option>
        </select>
      </div>
      <div class="form-group full">
        <label for="obs">Observações</label>
        <textarea id="obs" name="obs" class="textarea" placeholder="Contrato, particularidades, histórico de negociação...">Site oficial: https://ipojuca.pe.gov.br/
Endereço: Rua Cel. João de Souza Leão, Centro, Ipojuca/PE - CEP 55590-090.</textarea>
      </div>

      <div class="form-group full">
        <label class="checkbox">
          <input type="checkbox" name="criar_login" checked>
          <span>Criar login para o cliente acessar o portal</span>
        </label>
        <span class="hint">Uma senha temporária é gerada automaticamente e enviada ao e-mail informado acima.</span>
      </div>
    </div>

    <div class="panel-head" style="margin-top:22px;">
      <h4>Anexos (contrato e documentos)</h4>
      <span class="panel-sub">Opcional — você pode adicionar mais depois em "Anexos" na listagem</span>
    </div>

    <div class="form form-grid">
      <div class="form-group">
        <label for="tipo_anexo">Tipo</label>
        <select id="tipo_anexo" name="tipo_anexo" class="select">
          <option>Contrato</option>
          <option>Documento</option>
          <option>Identidade</option>
          <option>Outro</option>
        </select>
      </div>
      <div class="form-group">
        <label for="descricao_anexo">Descrição</label>
        <input type="text" id="descricao_anexo" name="descricao_anexo" class="input" placeholder="Ex: Contrato assinado 2026">
      </div>
      <div class="form-group full">
        <label for="anexos">Arquivos</label>
        <input type="file" id="anexos" name="anexos[]" class="input" multiple
               accept=".pdf,.doc,.docx,.odt,.rtf,.txt,.xls,.xlsx,.ods,.csv,.png,.jpg,.jpeg,.gif,.webp,.zip,.rar,.7z">
        <span class="hint">PDF, Word, Excel, imagens ou ZIP. Máx. 15 MB por arquivo.</span>
      </div>
    </div>

    <div class="form-actions">
      <a href="clientes.php" class="btn btn-secondary">Cancelar</a>
      <button type="submit" class="btn btn-primary">Salvar cliente</button>
    </div>
  </form>
</section>

<script>
(function () {
  'use strict';
  var prefeituraBtn = document.getElementById('btn-preencher-prefeitura');
  var prefeituraDados = {
    nome: 'Prefeitura do Ipojuca',
    empresa: 'Prefeitura Municipal do Ipojuca',
    empresa_id: <?= json_encode((string) $empresaPrefeituraId) ?>,
    email: 'ouvidoria@ipojuca.pe.gov.br',
    telefone: '(81) 3551-1156',
    doc: '11.294.386/0001-08',
    status: 'Ativo',
    obs: 'Site oficial: https://ipojuca.pe.gov.br/\nEndereço: Rua Cel. João de Souza Leão, Centro, Ipojuca/PE - CEP 55590-090.'
  };

  function setField(id, value) {
    var field = document.getElementById(id);
    if (!field) return;
    field.value = value;
    field.dispatchEvent(new Event('input', { bubbles: true }));
    field.dispatchEvent(new Event('change', { bubbles: true }));
  }

  if (prefeituraBtn) {
    prefeituraBtn.addEventListener('click', function () {
      Object.keys(prefeituraDados).forEach(function (id) {
        setField(id, prefeituraDados[id]);
      });
    });
  }
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
