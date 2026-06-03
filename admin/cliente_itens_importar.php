<?php
$CRM_CATALOGO_IMPORT_PORTAL = !empty($CRM_CATALOGO_IMPORT_PORTAL);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';

if ($CRM_CATALOGO_IMPORT_PORTAL) {
    $CLIENTE = require_auth('cliente');
    require_once __DIR__ . '/../includes/modules.php';
    require_modulo_cliente('catalogo');
} else {
    $me = require_auth_gestao();
    require_once __DIR__ . '/../includes/modules.php';
    require_modulo_admin('catalogo');
}

$pageTitle  = 'Importar catálogo';
$basePath   = '../';
$activePage = 'catalogo';

if (!db_ok()) {
    flash_set('err', 'Banco indisponível.');
    header('Location: ' . ($CRM_CATALOGO_IMPORT_PORTAL ? ($basePath . 'cliente/index.php') : 'clientes.php'));
    exit;
}

if ($CRM_CATALOGO_IMPORT_PORTAL) {
    $clienteId = repo_cliente_catalogo_dono_id((int) ($CLIENTE['cliente_id'] ?? 0));
    $escopoEmpresa = null;
    $empresas = [];
} else {
    $escopoEmpresa = gestao_scope_cliente_id($me);
    $empresas = $escopoEmpresa !== null
        ? (($c0 = repo_cliente($escopoEmpresa)) ? [$c0] : [])
        : repo_clientes_empresas();

    $reqClienteId = (int) ($_GET['cliente_id'] ?? $_POST['cliente_id'] ?? 0);
    if ($escopoEmpresa !== null) {
        $clienteId = $escopoEmpresa;
    } elseif ($reqClienteId > 0) {
        $clienteId = repo_cliente_catalogo_dono_id($reqClienteId);
    } else {
        $clienteId = (int) ($empresas[0]['id'] ?? 0);
    }
}

if ($clienteId <= 0) {
    flash_set('err', 'Cadastre uma empresa raiz antes de importar produtos e serviços.');
    header('Location: ' . ($CRM_CATALOGO_IMPORT_PORTAL ? ($basePath . 'cliente/index.php') : 'clientes.php'));
    exit;
}

$cliente = repo_cliente($clienteId);
if (!$cliente) {
    flash_set('err', 'Empresa não encontrada.');
    header('Location: ' . ($CRM_CATALOGO_IMPORT_PORTAL ? ($basePath . 'cliente/index.php') : 'clientes.php'));
    exit;
}
if (!$CRM_CATALOGO_IMPORT_PORTAL) {
    gestor_assert_escopo_cliente($clienteId, 'catalogo.php');
}

$catalogoTemEstoque    = repo_cliente_itens_estoque_saldo_column_exists();
$catalogoTemCapacidade = repo_cliente_itens_estoque_capacidade_column_exists();

if (!empty($_GET['exportar_modelo'])) {
    require_once __DIR__ . '/../includes/catalogo_export_xlsx.php';
    catalogo_modelo_xlsx_send($clienteId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../includes/catalogo_import.php';

    if (empty($_FILES['planilha']['tmp_name']) || !is_uploaded_file($_FILES['planilha']['tmp_name'])) {
        flash_set('err', 'Selecione um arquivo CSV, TXT ou XLSX.');
    } else {
        $nomeArquivo = (string) ($_FILES['planilha']['name'] ?? '');
        $parse = catalogo_import_parse_upload((string) $_FILES['planilha']['tmp_name'], $nomeArquivo);
        if (!$parse['ok']) {
            flash_set('err', $parse['erro'] !== '' ? $parse['erro'] : 'Falha ao interpretar a planilha.');
        } else {
            $imp = repo_cliente_itens_importar_linhas($clienteId, $parse['linhas']);
            $msg = $imp['inseridos'] . ' linha(s) importada(s).';
            if ($imp['ignorados'] > 0) {
                $msg .= ' ' . $imp['ignorados'] . ' ignorada(s).';
            }
            $avisos = array_merge($parse['avisos'], $imp['erros']);
            if ($avisos) {
                $msg .= ' Avisos: ' . implode(' ', array_slice($avisos, 0, 5));
            }
            flash_set($imp['inseridos'] > 0 ? 'ok' : 'err', $msg);
        }
    }

    header('Location: ' . ($CRM_CATALOGO_IMPORT_PORTAL ? 'catalogo_importar.php' : ('cliente_itens_importar.php?cliente_id=' . $clienteId)));
    exit;
}

$topTitle    = 'Importar produtos e serviços';
$topSubtitle = 'Upload de planilha para ' . (string) ($cliente['empresa'] ?? '');
$topSearch   = '';
$topAction   = [
    'label' => 'Voltar à listagem',
    'href'  => $CRM_CATALOGO_IMPORT_PORTAL ? 'catalogo.php' : ('catalogo.php?cliente_id=' . $clienteId),
    'icon'  => '←',
];

$catalogoImportFormAction = $CRM_CATALOGO_IMPORT_PORTAL
    ? 'catalogo_importar.php'
    : ('cliente_itens_importar.php?cliente_id=' . (int) $clienteId);
$catalogoImportModeloHref = $CRM_CATALOGO_IMPORT_PORTAL
    ? 'catalogo_importar.php?exportar_modelo=1'
    : ('cliente_itens_importar.php?cliente_id=' . (int) $clienteId . '&exportar_modelo=1');
$catalogoImportCancelHref = $CRM_CATALOGO_IMPORT_PORTAL ? 'catalogo.php' : ('catalogo.php?cliente_id=' . (int) $clienteId);
$catalogoImportSidebar = $CRM_CATALOGO_IMPORT_PORTAL ? 'sidebar-cliente.php' : 'sidebar-admin.php';

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/' . $catalogoImportSidebar; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <div class="content-grid-2">
    <form class="card js-crm-import-form" method="post" action="<?= htmlspecialchars($catalogoImportFormAction) ?>" enctype="multipart/form-data" data-import-msg="Importando catálogo…">
      <div class="panel-head">
        <h4>Enviar planilha</h4>
        <span class="panel-sub"><?= htmlspecialchars((string) ($cliente['empresa'] ?? '')) ?> · modelo XLSX institucional ou CSV UTF-8</span>
      </div>
      <div class="panel-body form form-grid">
        <?php if (!$CRM_CATALOGO_IMPORT_PORTAL): ?>
        <div class="form-group full">
          <label for="cliente_id">Empresa</label>
          <select id="cliente_id" name="cliente_id" class="select" <?= $escopoEmpresa !== null ? 'disabled' : '' ?> onchange="location.href='cliente_itens_importar.php?cliente_id=' + this.value">
            <?php foreach ($empresas as $emp): ?>
            <option value="<?= (int) $emp['id'] ?>" <?= ((int) $emp['id'] === $clienteId) ? 'selected' : '' ?>>
              <?= htmlspecialchars((string) ($emp['empresa'] ?? '')) ?> (#<?= (int) $emp['id'] ?>)
            </option>
            <?php endforeach; ?>
          </select>
          <?php if ($escopoEmpresa !== null): ?><input type="hidden" name="cliente_id" value="<?= (int) $clienteId ?>"><?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="form-group full">
          <label for="planilha">Arquivo</label>
          <input type="file" id="planilha" name="planilha" class="input" accept=".xlsx,.csv,.txt,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
          <span class="hint">Recomendado: baixe o modelo XLSX abaixo. Também aceita CSV com separador <code>;</code> ou <code>,</code>. Extensão PHP <code>zip</code> obrigatória para .xlsx.</span>
        </div>
      </div>
      <div class="panel-body">
        <div class="form-actions" style="padding:0;border:0;background:transparent;">
          <a class="btn btn-secondary" href="<?= htmlspecialchars($catalogoImportCancelHref) ?>">Cancelar</a>
          <button class="btn btn-primary" type="submit">Enviar e importar</button>
        </div>
      </div>
    </form>

    <div class="card">
      <div class="panel-head">
        <div>
          <h4>Formato da planilha</h4>
          <span class="panel-sub">Colunas da tabela <code>cliente_itens</code></span>
        </div>
        <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($catalogoImportModeloHref) ?>">Baixar modelo XLSX</a>
      </div>
      <div class="panel-body">
        <p class="muted" style="line-height:1.6;margin-top:0;">
          A planilha importa produtos e serviços para o catálogo da empresa raiz. Unidades vinculadas compartilham o catálogo da matriz. Itens são gravados como <strong>ativos</strong>. Código duplicado na mesma empresa é rejeitado com aviso.
        </p>
        <div class="table-wrap" style="border:1px solid var(--border);border-radius:12px;">
          <table>
            <thead>
              <tr><th>Campo</th><th>Obrigatório</th><th>Exemplo</th></tr>
            </thead>
            <tbody>
              <tr><td><code>Tipo</code> / <code>tipo</code></td><td>Sim</td><td><code>produto</code> ou <code>servico</code> (aceita Produto / Serviço)</td></tr>
              <tr><td><code>Nome</code> / <code>nome</code></td><td>Sim</td><td>Filtro de água / Instalação</td></tr>
              <tr><td><code>Código</code> / <code>codigo</code></td><td>Não</td><td>SKU-001</td></tr>
              <tr><td><code>Unidade</code> / <code>unidade</code></td><td>Não</td><td>UN, M, KG, H (padrão UN)</td></tr>
              <tr><td><code>Valor unit. (R$)</code> / <code>valor_unitario</code></td><td>Não</td><td>19,90 ou 19.90</td></tr>
              <?php if ($catalogoTemEstoque && $catalogoTemCapacidade): ?>
              <tr><td><code>Estoque</code> / <code>estoque</code> / <code>estoque_capacidade</code></td><td>Não</td><td>Referência no cadastro (ex.: 100)</td></tr>
              <tr><td><code>Saldo</code> / <code>saldo</code> / <code>estoque_saldo</code></td><td>Não</td><td>Saldo inicial; vazio na linha = igual ao Estoque</td></tr>
              <?php elseif ($catalogoTemEstoque): ?>
              <tr><td><code>Estoque saldo</code> / <code>estoque_saldo</code></td><td>Não</td><td>25 ou 0 — execute <code>052_estoque_capacidade.sql</code> para colunas Estoque e Saldo</td></tr>
              <?php else: ?>
              <tr><td><code>estoque_saldo</code></td><td colspan="2" class="td-mute">Indisponível — execute a migração <code>database/migrations/045_cliente_itens_estoque_saldo.sql</code></td></tr>
              <?php endif; ?>
              <tr><td><code>Descrição</code> / <code>descricao</code></td><td>Não</td><td>Texto opcional (até 500 caracteres)</td></tr>
            </tbody>
          </table>
        </div>
        <pre style="white-space:pre-wrap;background:#0f172a;color:#e2e8f0;padding:14px;border-radius:12px;margin-top:14px;font-size:12px;">tipo;nome;codigo;unidade;valor_unitario;estoque;saldo;descricao
produto;Filtro de água;SKU-001;UN;59,90;100;25;Filtro refil
servico;Instalação padrão;SERV-001;UN;120,00;0;0;Serviço interno</pre>
        <p class="muted" style="line-height:1.6;margin-bottom:0;font-size:13px;">
          No modelo XLSX, a capa institucional fica acima do cabeçalho — não altere os títulos das colunas (<code>Tipo</code>, <code>Nome</code>, …). Preencha as linhas abaixo do cabeçalho e envie o arquivo.<?php if ($catalogoTemEstoque && $catalogoTemCapacidade): ?> Se a coluna <strong>Saldo</strong> estiver vazia na linha, o saldo inicial será igual ao <strong>Estoque</strong>.<?php endif; ?>
        </p>
      </div>
    </div>
  </div>
</section>

<script src="<?= $basePath ?>assets/js/crm-import-loading.js?v=<?= (int) @filemtime(dirname(__DIR__) . '/assets/js/crm-import-loading.js') ?>"></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
