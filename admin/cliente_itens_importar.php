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

if (!empty($_GET['exportar_modelo'])) {
    $fn = 'modelo_importacao_catalogo.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    if ($out !== false) {
        fputcsv($out, ['tipo', 'nome', 'codigo', 'unidade', 'valor_unitario', 'descricao'], ';');
        fputcsv($out, ['produto', 'Exemplo produto', 'SKU-001', 'UN', '59,90', 'Substitua pelos seus dados'], ';');
        fputcsv($out, ['servico', 'Exemplo serviço', 'SERV-001', 'UN', '120,00', ''], ';');
        fclose($out);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_FILES['planilha']['tmp_name']) || !is_uploaded_file($_FILES['planilha']['tmp_name'])) {
        flash_set('err', 'Selecione um arquivo CSV ou TXT.');
    } else {
        $raw = file_get_contents($_FILES['planilha']['tmp_name']);
        if ($raw === false) {
            flash_set('err', 'Não foi possível ler o arquivo.');
        } else {
            $imp = repo_cliente_itens_importar_csv($clienteId, $raw);
            $msg = $imp['inseridos'] . ' linha(s) importada(s).';
            if ($imp['ignorados'] > 0) {
                $msg .= ' ' . $imp['ignorados'] . ' ignorada(s).';
            }
            if ($imp['erros']) {
                $msg .= ' Avisos: ' . implode(' ', array_slice($imp['erros'], 0, 5));
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
    <form class="card" method="post" action="<?= htmlspecialchars($catalogoImportFormAction) ?>" enctype="multipart/form-data">
      <div class="panel-head">
        <h4>Enviar planilha</h4>
        <span class="panel-sub">CSV ou TXT com separador vírgula ou ponto e vírgula</span>
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
          <input type="file" id="planilha" name="planilha" class="input" accept=".csv,.txt,text/csv" required>
          <span class="hint">Use UTF-8 quando possível. A primeira linha pode ser cabeçalho.</span>
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
          <span class="panel-sub">Campos aceitos</span>
        </div>
        <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($catalogoImportModeloHref) ?>">Baixar CSV modelo</a>
      </div>
      <div class="panel-body">
        <p class="muted" style="line-height:1.6;margin-top:0;">
          A planilha importa produtos e serviços para o catálogo da empresa. Unidades vinculadas usam o catálogo da matriz.
        </p>
        <div class="table-wrap" style="border:1px solid var(--border);border-radius:12px;">
          <table>
            <thead>
              <tr><th>Campo</th><th>Exemplo</th></tr>
            </thead>
            <tbody>
              <tr><td><code>tipo</code></td><td><code>produto</code> ou <code>servico</code></td></tr>
              <tr><td><code>nome</code></td><td>Filtro de água / Instalação</td></tr>
              <tr><td><code>codigo</code></td><td>SKU-001</td></tr>
              <tr><td><code>unidade</code></td><td>UN, M, KG, H</td></tr>
              <tr><td><code>valor_unitario</code></td><td>19,90 ou 19.90</td></tr>
              <tr><td><code>descricao</code></td><td>Texto opcional</td></tr>
            </tbody>
          </table>
        </div>
        <pre style="white-space:pre-wrap;background:#0f172a;color:#e2e8f0;padding:14px;border-radius:12px;margin-top:14px;font-size:12px;">tipo;nome;codigo;unidade;valor_unitario;descricao
produto;Filtro de água;SKU-001;UN;59,90;Filtro refil
servico;Instalação padrão;SERV-001;UN;120,00;Serviço interno</pre>
      </div>
    </div>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
