<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/upload.php';

$user = require_auth('cliente');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_cliente('pontos_iluminacao');

$pageTitle  = 'Ponto de iluminação';
$basePath   = '../';
$activePage = 'pontos_iluminacao';
$userClienteId = (int) ($user['cliente_id'] ?? 0);
$id             = (int) ($_GET['id'] ?? 0);
$scopeRaiz      = $userClienteId > 0 ? repo_cliente_matriz_raiz_id($userClienteId) : 0;

if (!db_ok() || $userClienteId <= 0 || $scopeRaiz <= 0) {
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
    if (!$pontoDb || !repo_ponto_iluminacao_pertence_empresa($id, $scopeRaiz)) {
        flash_set('err', 'Ponto não encontrado.');
        header('Location: pontos_iluminacao.php');
        exit;
    }
    $ponto = array_merge($ponto, $pontoDb);
}

$pontoImagens = ($id > 0) ? repo_ponto_iluminacao_imagens_list($id) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string) ($_POST['acao'] ?? '');

    if ($acao === 'excluir_imagem' || $acao === 'definir_principal') {
        $imgId = (int) ($_POST['imagem_id'] ?? 0);
        $pontoIdPost = (int) ($_POST['ponto_id'] ?? 0);
        $pDel = ($pontoIdPost > 0) ? repo_ponto_iluminacao($pontoIdPost) : null;
        if ($pDel && $imgId > 0 && repo_ponto_iluminacao_pertence_empresa($pontoIdPost, $scopeRaiz)) {
            $ok = $acao === 'excluir_imagem'
                ? repo_ponto_iluminacao_imagem_excluir($imgId, $pontoIdPost)
                : repo_ponto_iluminacao_imagem_definir_principal($imgId, $pontoIdPost);
            flash_set($ok ? 'ok' : 'err', $ok
                ? ($acao === 'excluir_imagem' ? 'Imagem removida.' : 'Imagem principal atualizada.')
                : ($acao === 'excluir_imagem' ? 'Não foi possível remover a imagem.' : 'Não foi possível definir a imagem principal.'));
            header('Location: ponto_iluminacao_novo.php?id=' . $pontoIdPost);
            exit;
        }
        flash_set('err', 'Requisição inválida.');
        header('Location: pontos_iluminacao.php');
        exit;
    }

    $clienteSalvar = (int) ($_POST['cliente_id'] ?? 0);
    if ($clienteSalvar <= 0) {
        $clienteSalvar = $userClienteId;
    }
    if (!repo_cliente_pertence_empresa($clienteSalvar, $scopeRaiz)) {
        flash_set('err', 'Unidade inválida para o seu acesso.');
        header('Location: pontos_iluminacao.php');
        exit;
    }
    $save = repo_ponto_iluminacao_salvar([
        'id' => (int) ($_POST['id'] ?? 0),
        'cliente_id' => $clienteSalvar,
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
    if (!$save['ok']) {
        flash_set('err', $save['err']);
        header('Location: ponto_iluminacao_novo.php' . ($id > 0 ? '?id=' . $id : ''));
        exit;
    }

    $pontoSalvoId = (int) $save['id'];
    $uploadMsgs = [];
    $uploadErrs = [];
    $dir = upload_dir_ponto_iluminacao($pontoSalvoId);

    if (!empty($_FILES['imagem_principal']['name']) && (int) ($_FILES['imagem_principal']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $f = $_FILES['imagem_principal'];
        if (!upload_extensao_imagem((string) ($f['name'] ?? ''))) {
            $uploadErrs[] = 'Imagem principal: use PNG, JPG, JPEG, GIF ou WEBP.';
        } else {
            $r = upload_gravar_arquivo($f, $dir);
            if ($r['ok']) {
                $ins = repo_ponto_iluminacao_imagem_inserir(
                    $pontoSalvoId,
                    (string) $r['nome_original'],
                    (string) $r['nome_arquivo'],
                    $r['mime'] ?? null,
                    (int) ($r['tamanho'] ?? 0),
                    true
                );
                if ($ins['ok']) {
                    $uploadMsgs[] = 'Imagem principal enviada.';
                } else {
                    @unlink($dir . DIRECTORY_SEPARATOR . $r['nome_arquivo']);
                    $uploadErrs[] = 'Imagem principal: ' . $ins['err'];
                }
            } else {
                $uploadErrs[] = 'Imagem principal: ' . ($r['msg'] ?? 'falha no upload.');
            }
        }
    }

    $secOk = 0;
    if (!empty($_FILES['imagens_secundarias']['name']) && is_array($_FILES['imagens_secundarias']['name'])) {
        $sec = $_FILES['imagens_secundarias'];
        $n = count($sec['name']);
        for ($i = 0; $i < $n; $i++) {
            if (($sec['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $single = [
                'name'     => $sec['name'][$i],
                'type'     => $sec['type'][$i] ?? '',
                'tmp_name' => $sec['tmp_name'][$i],
                'error'    => $sec['error'][$i],
                'size'     => $sec['size'][$i],
            ];
            if (!upload_extensao_imagem((string) ($single['name'] ?? ''))) {
                $uploadErrs[] = (string) ($single['name'] ?? 'arquivo') . ': só imagens (PNG, JPG, GIF, WEBP).';
                continue;
            }
            $r = upload_gravar_arquivo($single, $dir);
            if ($r['ok']) {
                $ins = repo_ponto_iluminacao_imagem_inserir(
                    $pontoSalvoId,
                    (string) $r['nome_original'],
                    (string) $r['nome_arquivo'],
                    $r['mime'] ?? null,
                    (int) ($r['tamanho'] ?? 0),
                    false
                );
                if ($ins['ok']) {
                    $secOk++;
                } else {
                    @unlink($dir . DIRECTORY_SEPARATOR . $r['nome_arquivo']);
                    $uploadErrs[] = (string) $r['nome_original'] . ': ' . $ins['err'];
                }
            } else {
                $uploadErrs[] = (string) ($single['name'] ?? '') . ': ' . ($r['msg'] ?? 'erro');
            }
        }
        if ($secOk > 0) {
            $uploadMsgs[] = $secOk . ' imagem(ns) secundária(s) enviada(s).';
        }
    }

    if ($uploadErrs !== []) {
        flash_set('err', 'Poste salvo, mas houve problema(s) no envio de fotos: ' . implode(' ', $uploadErrs));
    } elseif ($uploadMsgs !== []) {
        flash_set('ok', 'Ponto salvo. ' . implode(' ', $uploadMsgs));
    } else {
        flash_set('ok', 'Ponto de iluminação salvo.');
    }
    header('Location: ponto_iluminacao_novo.php?id=' . $pontoSalvoId);
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
  <form id="form-poste" class="card" method="post" enctype="multipart/form-data" action="ponto_iluminacao_novo.php<?= $id > 0 ? '?id=' . (int) $id : '' ?>" autocomplete="off">
    <input type="hidden" name="id" value="<?= (int) ($ponto['id'] ?? 0) ?>">
    <input type="hidden" name="cliente_id" value="<?= (int) ($ponto['cliente_id'] ?? $userClienteId) ?>">
    <div class="panel-head">
      <h4>Dados do poste</h4>
      <span class="panel-sub">Esses dados ajudam a abrir chamados no ponto correto.</span>
    </div>

    <div class="form form-grid">
      <div class="form-group">
        <label for="codigo_poste">ID / código do poste</label>
        <input type="text" id="codigo_poste" name="codigo_poste" class="input" required maxlength="80" placeholder="ID ou código no cadastro"
               value="<?= htmlspecialchars((string) ($ponto['codigo_poste'] ?? '')) ?>">
      </div>
      <div class="form-group">
        <label for="identificador_externo">Identificador externo</label>
        <input type="text" id="identificador_externo" name="identificador_externo" class="input" maxlength="120" placeholder="Barramento, luminaire…"
               value="<?= htmlspecialchars((string) ($ponto['identificador_externo'] ?? '')) ?>">
      </div>
      <div class="form-group">
        <label for="bairro">Bairro</label>
        <input type="text" id="bairro" name="bairro" class="input" maxlength="120" placeholder="Bairro"
               value="<?= htmlspecialchars((string) ($ponto['bairro'] ?? '')) ?>">
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
        <textarea id="endereco_completo" name="endereco_completo" class="textarea" rows="3" placeholder="Logradouro, número, complemento, CEP"><?= htmlspecialchars((string) ($ponto['endereco_completo'] ?? '')) ?></textarea>
      </div>
      <div class="form-group full">
        <label for="referencia">Referência</label>
        <input type="text" id="referencia" name="referencia" class="input" maxlength="255" placeholder="Ponto de referência no local"
               value="<?= htmlspecialchars((string) ($ponto['referencia'] ?? '')) ?>">
      </div>
      <div class="form-group">
        <label for="latitude">Latitude</label>
        <input type="text" id="latitude" name="latitude" class="input" inputmode="decimal" placeholder="-8.123456"
               value="<?= htmlspecialchars((string) ($ponto['latitude'] ?? '')) ?>">
      </div>
      <div class="form-group">
        <label for="longitude">Longitude</label>
        <input type="text" id="longitude" name="longitude" class="input" inputmode="decimal" placeholder="-35.123456"
               value="<?= htmlspecialchars((string) ($ponto['longitude'] ?? '')) ?>">
      </div>
      <div class="form-group full" style="margin-top:-8px;">
        <button type="button" class="btn btn-secondary" id="btn-ponto-geo">Usar minha localização</button>
        <small class="muted" style="display:block;margin-top:8px;">Opcional: use o GPS do dispositivo quando estiver perto do poste.</small>
      </div>
      <div class="form-group full">
        <label for="observacoes">Observações</label>
        <textarea id="observacoes" name="observacoes" class="textarea" rows="3" placeholder="Observações do ponto (opcional)"><?= htmlspecialchars((string) ($ponto['observacoes'] ?? '')) ?></textarea>
      </div>

      <?php if ($id > 0): ?>
      <div class="form-group full" style="border-top:1px solid var(--border-soft);padding-top:16px;margin-top:4px;">
        <h4 style="margin:0 0 8px;font-size:16px;">Fotos do poste</h4>
        <p class="muted" style="margin:0 0 14px;font-size:13px;">Uma imagem <strong>principal</strong> (destaque) e quantas <strong>secundárias</strong> precisar. Formatos: PNG, JPG, GIF ou WEBP (máx. <?= htmlspecialchars(upload_formatar_tamanho(UPLOAD_MAX_BYTES)) ?> por arquivo).</p>

        <?php if (!empty($pontoImagens)): ?>
        <div class="ponto-img-galeria" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin-bottom:16px;">
          <?php foreach ($pontoImagens as $im): ?>
          <div class="ponto-img-card" style="border:1px solid var(--border-soft);border-radius:10px;overflow:hidden;background:#fafafe;">
            <a href="ponto_iluminacao_imagem.php?id=<?= (int) $im['id'] ?>" target="_blank" rel="noopener" style="display:block;aspect-ratio:4/3;background:#eef;">
              <img src="ponto_iluminacao_imagem.php?id=<?= (int) $im['id'] ?>" alt="" style="width:100%;height:100%;object-fit:cover;display:block;">
            </a>
            <div style="padding:8px;font-size:11px;">
              <?php if (!empty($im['principal'])): ?>
                <span class="badge success" style="font-size:10px;">Principal</span>
              <?php else: ?>
                <button type="submit" form="form-img-principal-<?= (int) $im['id'] ?>" class="action primary" style="font-size:11px;padding:4px 8px;">Usar como principal</button>
              <?php endif; ?>
              <button type="submit" form="form-img-excluir-<?= (int) $im['id'] ?>" class="action danger" style="font-size:11px;padding:4px 8px;margin:4px 0 0;display:inline-block;">Excluir</button>
              <?php
                $nomO = (string) ($im['nome_original'] ?? '');
                $nomC = strlen($nomO) > 30 ? substr($nomO, 0, 27) . '…' : $nomO;
              ?>
              <div class="muted" style="margin-top:6px;word-break:break-all;" title="<?= htmlspecialchars($nomO) ?>"><?= htmlspecialchars($nomC) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="form-group full">
          <label for="imagem_principal">Nova imagem principal</label>
          <input type="file" id="imagem_principal" name="imagem_principal" class="input" accept="image/png,image/jpeg,image/jpg,image/gif,image/webp,.png,.jpg,.jpeg,.gif,.webp">
          <small class="muted" style="display:block;margin-top:6px;">Se já existir uma principal, ela passa a ser secundária ao enviar outra.</small>
        </div>
        <div class="form-group full">
          <label for="imagens_secundarias">Novas imagens secundárias</label>
          <input type="file" id="imagens_secundarias" name="imagens_secundarias[]" class="input" accept="image/png,image/jpeg,image/jpg,image/gif,image/webp,.png,.jpg,.jpeg,.gif,.webp" multiple>
        </div>
      </div>
      <?php else: ?>
      <div class="form-group full">
        <p class="muted" style="margin:0;font-size:13px;">Após salvar o poste pela primeira vez, edite-o de novo para enviar a foto principal e as secundárias.</p>
      </div>
      <?php endif; ?>
    </div>

    <div class="form-actions">
      <a href="pontos_iluminacao.php" class="btn btn-secondary">Cancelar</a>
      <button type="submit" form="form-poste" class="btn btn-primary">Salvar ponto</button>
    </div>
  </form>
  <?php if ($id > 0 && !empty($pontoImagens)): ?>
  <?php
    $imgFormAction = 'ponto_iluminacao_novo.php?id=' . (int) $id;
    foreach ($pontoImagens as $im):
      $imgId = (int) $im['id'];
  ?>
  <?php if (empty($im['principal'])): ?>
  <form id="form-img-principal-<?= $imgId ?>" method="post" action="<?= htmlspecialchars($imgFormAction) ?>" style="display:none;">
    <input type="hidden" name="acao" value="definir_principal">
    <input type="hidden" name="ponto_id" value="<?= (int) $id ?>">
    <input type="hidden" name="imagem_id" value="<?= $imgId ?>">
  </form>
  <?php endif; ?>
  <form id="form-img-excluir-<?= $imgId ?>" method="post" action="<?= htmlspecialchars($imgFormAction) ?>" style="display:none;" data-confirm="Remover esta imagem?" data-confirm-danger>
    <input type="hidden" name="acao" value="excluir_imagem">
    <input type="hidden" name="ponto_id" value="<?= (int) $id ?>">
    <input type="hidden" name="imagem_id" value="<?= $imgId ?>">
  </form>
  <?php endforeach; ?>
  <?php endif; ?>
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

<?php include __DIR__ . '/../includes/footer.php'; ?>
