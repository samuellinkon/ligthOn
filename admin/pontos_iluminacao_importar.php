<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/pontos_iluminacao_import.php';

$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('pontos_iluminacao');

$pageTitle  = 'Importar pontos de iluminação';
$basePath   = '../';
$activePage = 'pontos_iluminacao';

if (!db_ok()) {
    flash_set('err', 'Banco indisponível.');
    header('Location: clientes.php');
    exit;
}

$escopoEmpresa = gestao_scope_cliente_id($me);
$reqClienteId = (int) ($_GET['cliente_id'] ?? $_POST['cliente_id'] ?? 0);

if ($escopoEmpresa !== null) {
    $empresaRaizId = $escopoEmpresa;
} elseif ($reqClienteId > 0) {
    $empresaRaizId = repo_cliente_matriz_raiz_id($reqClienteId);
} else {
    $empresaRaizId = (int) (repo_catalogo_cliente_id_padrao_admin() ?? 0);
    if ($empresaRaizId > 0) {
        $empresaRaizId = repo_cliente_matriz_raiz_id($empresaRaizId);
    }
}
if ($empresaRaizId <= 0) {
    $empresas = repo_clientes_empresas();
    $empresaRaizId = (int) ($empresas[0]['id'] ?? 0);
}
if ($empresaRaizId <= 0) {
    flash_set('err', 'Cadastre uma empresa antes de importar pontos.');
    header('Location: clientes.php');
    exit;
}
gestor_assert_escopo_cliente($empresaRaizId, 'pontos_iluminacao.php');

$clientesOptions = repo_clientes_na_empresa($empresaRaizId);
if (!$clientesOptions) {
    $empresa = repo_cliente($empresaRaizId);
    $clientesOptions = $empresa ? [$empresa] : [];
}

$clienteId = $reqClienteId > 0 ? $reqClienteId : (int) ($clientesOptions[0]['id'] ?? 0);
if ($clienteId <= 0) {
    flash_set('err', 'Nenhum cliente disponível para importação.');
    header('Location: pontos_iluminacao.php?cliente_id=' . (int) $empresaRaizId);
    exit;
}
gestor_assert_escopo_cliente($clienteId, 'pontos_iluminacao.php');

$cliente = repo_cliente($clienteId);
if (!$cliente) {
    flash_set('err', 'Prefeitura / cadastro não encontrado.');
    header('Location: pontos_iluminacao.php?cliente_id=' . (int) $empresaRaizId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postClienteId = (int) ($_POST['cliente_id'] ?? 0);
    gestor_assert_escopo_cliente($postClienteId, 'pontos_iluminacao.php');

    if (empty($_FILES['planilha']['tmp_name']) || !is_uploaded_file($_FILES['planilha']['tmp_name'])) {
        flash_set('err', 'Selecione a planilha de pontos de iluminação.');
        header('Location: pontos_iluminacao_importar.php?cliente_id=' . (int) $postClienteId);
        exit;
    }

    $nomeArquivo = (string) ($_FILES['planilha']['name'] ?? '');
    $parse = pontos_iluminacao_import_parse_upload((string) $_FILES['planilha']['tmp_name'], $nomeArquivo);
    if (!$parse['ok']) {
        flash_set('err', $parse['erro'] !== '' ? $parse['erro'] : 'Falha ao interpretar a planilha.');
        header('Location: pontos_iluminacao_importar.php?cliente_id=' . (int) $postClienteId);
        exit;
    }

    $grav = repo_pontos_iluminacao_importar_linhas($postClienteId, $parse['linhas']);
    if (!$grav['ok']) {
        $msg = $grav['err'] !== '' ? $grav['err'] : 'Nenhum ponto foi importado.';
        if (!empty($grav['erros'])) {
            $msg .= ' Avisos: ' . implode(' ', array_slice($grav['erros'], 0, 5));
        }
        flash_set('err', $msg);
        header('Location: pontos_iluminacao_importar.php?cliente_id=' . (int) $postClienteId);
        exit;
    }

    $msg = $grav['processados'] . ' ponto(s) importado(s)/atualizado(s).';
    $avisos = array_merge($parse['avisos'], $grav['erros']);
    if ($avisos) {
        $msg .= ' Avisos: ' . implode(' ', array_slice($avisos, 0, 5));
        if (count($avisos) > 5) {
            $msg .= ' +' . (count($avisos) - 5) . ' aviso(s).';
        }
    }
    flash_set('ok', $msg);
    header('Location: pontos_iluminacao.php?cliente_id=' . (int) $postClienteId);
    exit;
}

$topTitle    = 'Importar parque de iluminação';
$topSubtitle = 'Upload de planilha no modelo do cadastro georreferenciado de Ipojuca.';
$topSearch   = '';
$topAction   = ['label' => 'Voltar', 'href' => 'pontos_iluminacao.php?cliente_id=' . (int) $clienteId, 'icon' => '←'];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <div class="content-grid-2">
    <form class="card js-crm-import-form" method="post" action="pontos_iluminacao_importar.php?cliente_id=<?= (int) $clienteId ?>" enctype="multipart/form-data" data-import-msg="Importando parque de iluminação…">
      <div class="panel-head">
        <h4>Enviar planilha</h4>
        <span class="panel-sub">Use o cadastro georreferenciado de Ipojuca (.xlsx). A chave é o <strong>Código</strong> do poste — os chamados já ligados não se soltam.</span>
      </div>
      <div class="panel-body form form-grid">
        <div class="form-group full">
          <label for="cliente_id">Prefeitura / cadastro dos postes</label>
          <select id="cliente_id" name="cliente_id" class="select" onchange="location.href='pontos_iluminacao_importar.php?cliente_id=' + this.value">
            <?php foreach ($clientesOptions as $cli): ?>
              <option value="<?= (int) $cli['id'] ?>" <?= ((int) $cli['id'] === $clienteId) ? 'selected' : '' ?>>
                <?= htmlspecialchars((string) ($cli['empresa'] ?? '')) ?> (#<?= (int) $cli['id'] ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group full">
          <label for="planilha">Arquivo</label>
          <input type="file" id="planilha" name="planilha" class="input" accept=".xlsx,.csv,.txt,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
          <span class="hint">Aceita .xlsx, .csv ou .txt. Atualiza postes existentes pelo <strong>Código</strong> (não pelo barramento), cria os novos e grava características extras nas observações. Endereço já cadastrado só muda se a planilha trouxer logradouro/bairro.</span>
        </div>
      </div>
      <div class="panel-body">
        <div class="form-actions" style="padding:0;border:0;background:transparent;">
          <a class="btn btn-secondary" href="pontos_iluminacao.php?cliente_id=<?= (int) $clienteId ?>">Cancelar</a>
          <a class="btn btn-secondary js-crm-export-link" href="pontos_iluminacao_export_xlsx.php?cliente_id=<?= (int) $clienteId ?>">Exportar parque</a>
          <button class="btn btn-primary" type="submit">Importar dados</button>
        </div>
      </div>
    </form>

    <div class="card">
      <div class="panel-head">
        <h4>Modelo esperado</h4>
        <span class="panel-sub">Campos lidos da planilha</span>
      </div>
      <div class="panel-body">
        <p class="muted" style="line-height:1.6;margin-top:0;">
          A importação usa o layout do parque de Ipojuca. O cabeçalho pode estar nas primeiras linhas. O <strong>Código</strong> identifica o poste (os chamados ficam no mesmo ponto). Linhas com o mesmo Código (várias luminárias) entram no mesmo cadastro.
        </p>
        <div class="table-wrap" style="border:1px solid var(--border);border-radius:12px;">
          <table>
            <thead>
              <tr><th>Campo da planilha</th><th>Destino no sistema</th></tr>
            </thead>
            <tbody>
              <tr><td><code>Código</code></td><td>ID/código do poste (chave da atualização)</td></tr>
              <tr><td><code>Barramento</code></td><td>Identificador externo</td></tr>
              <tr><td><code>Latitude</code> / <code>Longitude</code></td><td>Coordenadas do mapa</td></tr>
              <tr><td><code>Tipo/Nome do logradouro</code>, <code>Bairro</code>, <code>Localidade</code></td><td>Endereço (só grava se a planilha trouxer)</td></tr>
              <tr><td><code>Qtd luminárias</code>, <code>Tecnologia</code>, <code>Potência</code></td><td>Observações</td></tr>
              <tr><td><code>Comprimento do braço</code>, <code>Tipo/Material do poste</code>, <code>Altura útil</code></td><td>Observações</td></tr>
              <tr><td><code>Foto1</code>, <code>Foto2</code>, consumo e propriedade</td><td>Observações (se existirem na planilha)</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</section>

</main>
</div>
<script src="<?= $basePath ?>assets/js/crm-import-loading.js?v=<?= (int) @filemtime(dirname(__DIR__) . '/assets/js/crm-import-loading.js') ?>"></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
