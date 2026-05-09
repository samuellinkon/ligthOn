<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';

$user = require_auth('cliente');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_cliente('pontos_iluminacao');

$pageTitle  = 'Ponto de iluminação';
$basePath   = '../';
$activePage = 'pontos_iluminacao';
$clienteId  = (int) ($user['cliente_id'] ?? 0);
$id         = (int) ($_GET['id'] ?? 0);

if (!db_ok() || $clienteId <= 0) {
    flash_set('err', 'Banco indisponível ou cliente inválido.');
    header('Location: pontos_iluminacao.php');
    exit;
}

$ponto = [
    'id' => 0,
    'codigo_poste' => '',
    'identificador_externo' => '',
    'endereco_completo' => '',
    'bairro' => '',
    'referencia' => '',
    'latitude' => '',
    'longitude' => '',
    'status' => 'Ativo',
    'observacoes' => '',
];
if ($id > 0) {
    $pontoDb = repo_ponto_iluminacao($id);
    if (!$pontoDb || (int) ($pontoDb['cliente_id'] ?? 0) !== $clienteId) {
        flash_set('err', 'Ponto não encontrado.');
        header('Location: pontos_iluminacao.php');
        exit;
    }
    $ponto = array_merge($ponto, $pontoDb);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $save = repo_ponto_iluminacao_salvar([
        'id' => (int) ($_POST['id'] ?? 0),
        'cliente_id' => $clienteId,
        'codigo_poste' => $_POST['codigo_poste'] ?? '',
        'identificador_externo' => $_POST['identificador_externo'] ?? '',
        'endereco_completo' => $_POST['endereco_completo'] ?? '',
        'bairro' => $_POST['bairro'] ?? '',
        'referencia' => $_POST['referencia'] ?? '',
        'latitude' => $_POST['latitude'] ?? '',
        'longitude' => $_POST['longitude'] ?? '',
        'status' => $_POST['status'] ?? 'Ativo',
        'observacoes' => $_POST['observacoes'] ?? '',
    ]);
    if ($save['ok']) {
        flash_set('ok', 'Ponto de iluminação salvo.');
        header('Location: pontos_iluminacao.php');
        exit;
    }
    flash_set('err', $save['err']);
    header('Location: ponto_iluminacao_novo.php' . ($id > 0 ? '?id=' . $id : ''));
    exit;
}

$topTitle    = $id > 0 ? 'Editar ponto de iluminação' : 'Novo ponto de iluminação';
$topSubtitle = 'Informe o ID do poste, endereço e coordenadas.';
$topSearch   = '';
$topAction   = ['label' => 'Voltar', 'href' => 'pontos_iluminacao.php', 'icon' => '←'];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-cliente.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <form class="card" method="post" action="ponto_iluminacao_novo.php<?= $id > 0 ? '?id=' . (int) $id : '' ?>" autocomplete="off">
    <input type="hidden" name="id" value="<?= (int) ($ponto['id'] ?? 0) ?>">
    <div class="panel-head">
      <h4>Dados do poste</h4>
      <span class="panel-sub">Esses dados ajudam a abrir chamados no ponto correto.</span>
    </div>

    <div class="form form-grid">
      <div class="form-group">
        <label for="codigo_poste">ID / código do poste</label>
        <input type="text" id="codigo_poste" name="codigo_poste" class="input" required maxlength="80" value="<?= htmlspecialchars((string) ($ponto['codigo_poste'] ?? '')) ?>">
      </div>
      <div class="form-group">
        <label for="identificador_externo">Identificador externo</label>
        <input type="text" id="identificador_externo" name="identificador_externo" class="input" maxlength="120" value="<?= htmlspecialchars((string) ($ponto['identificador_externo'] ?? '')) ?>">
      </div>
      <div class="form-group">
        <label for="bairro">Bairro</label>
        <input type="text" id="bairro" name="bairro" class="input" maxlength="120" value="<?= htmlspecialchars((string) ($ponto['bairro'] ?? '')) ?>">
      </div>
      <div class="form-group">
        <label for="status">Status</label>
        <select id="status" name="status" class="select">
          <option value="Ativo" <?= ($ponto['status'] ?? '') === 'Ativo' ? 'selected' : '' ?>>Ativo</option>
          <option value="Inativo" <?= ($ponto['status'] ?? '') === 'Inativo' ? 'selected' : '' ?>>Inativo</option>
        </select>
      </div>
      <div class="form-group full">
        <label for="endereco_completo">Endereço completo</label>
        <textarea id="endereco_completo" name="endereco_completo" class="textarea" rows="3"><?= htmlspecialchars((string) ($ponto['endereco_completo'] ?? '')) ?></textarea>
      </div>
      <div class="form-group full">
        <label for="referencia">Referência</label>
        <input type="text" id="referencia" name="referencia" class="input" maxlength="255" value="<?= htmlspecialchars((string) ($ponto['referencia'] ?? '')) ?>">
      </div>
      <div class="form-group">
        <label for="latitude">Latitude</label>
        <input type="text" id="latitude" name="latitude" class="input" inputmode="decimal" value="<?= htmlspecialchars((string) ($ponto['latitude'] ?? '')) ?>">
      </div>
      <div class="form-group">
        <label for="longitude">Longitude</label>
        <input type="text" id="longitude" name="longitude" class="input" inputmode="decimal" value="<?= htmlspecialchars((string) ($ponto['longitude'] ?? '')) ?>">
      </div>
      <div class="form-group full" style="margin-top:-8px;">
        <button type="button" class="btn btn-secondary" id="btn-ponto-geo">Usar minha localização</button>
        <small class="muted" style="display:block;margin-top:8px;">Opcional: use o GPS do dispositivo quando estiver perto do poste.</small>
      </div>
      <div class="form-group full">
        <label for="observacoes">Observações</label>
        <textarea id="observacoes" name="observacoes" class="textarea" rows="3"><?= htmlspecialchars((string) ($ponto['observacoes'] ?? '')) ?></textarea>
      </div>
    </div>

    <div class="form-actions">
      <a href="pontos_iluminacao.php" class="btn btn-secondary">Cancelar</a>
      <button type="submit" class="btn btn-primary">Salvar ponto</button>
    </div>
  </form>
</section>

<script>
(function () {
  var btn = document.getElementById('btn-ponto-geo');
  if (!btn) return;
  btn.addEventListener('click', function () {
    if (!navigator.geolocation) {
      if (typeof window.appAlert === 'function') window.appAlert('Seu navegador não suporta geolocalização.', 'Localização');
      return;
    }
    navigator.geolocation.getCurrentPosition(function (pos) {
      document.getElementById('latitude').value = pos.coords.latitude.toFixed(7);
      document.getElementById('longitude').value = pos.coords.longitude.toFixed(7);
    }, function () {
      if (typeof window.appAlert === 'function') window.appAlert('Não foi possível obter a localização.', 'Localização');
    }, { enableHighAccuracy: true, timeout: 15000 });
  });
})();
</script>

</main>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
