<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/upload.php';

$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('pontos_iluminacao');

$pageTitle  = 'Ponto de iluminação';
$basePath   = '../';
$activePage = 'pontos_iluminacao';
$id         = (int) ($_GET['id'] ?? 0);
$clienteId = (int) ($_GET['cliente_id'] ?? 0);

if (!db_ok()) {
    flash_set('err', 'Banco indisponível.');
    header('Location: pontos_iluminacao.php');
    exit;
}

$escopoEmpresa = gestao_scope_cliente_id($me);
$ponto = [
    'id' => 0,
    'cliente_id' => $clienteId,
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
    if (!$pontoDb) {
        flash_set('err', 'Ponto não encontrado.');
        header('Location: pontos_iluminacao.php');
        exit;
    }
    gestor_assert_escopo_cliente((int) ($pontoDb['cliente_id'] ?? 0), 'pontos_iluminacao.php');
    $ponto = array_merge($ponto, $pontoDb);
    $clienteId = (int) ($ponto['cliente_id'] ?? 0);
} elseif ($clienteId > 0) {
    gestor_assert_escopo_cliente($clienteId, 'pontos_iluminacao.php');
}

$empresaRaizId = $escopoEmpresa;
if ($empresaRaizId === null && $clienteId > 0) {
    $empresaRaizId = repo_cliente_matriz_raiz_id($clienteId);
}
if ($empresaRaizId === null || $empresaRaizId <= 0) {
    $empresas = repo_clientes_empresas();
    $empresaRaizId = (int) ($empresas[0]['id'] ?? 0);
}
$clientesOptions = $empresaRaizId > 0 ? repo_clientes_na_empresa((int) $empresaRaizId) : repo_clientes();
if ($clienteId <= 0 && !empty($clientesOptions[0]['id'])) {
    $clienteId = (int) $clientesOptions[0]['id'];
    $ponto['cliente_id'] = $clienteId;
}

$pontoImagens = ($id > 0) ? repo_ponto_iluminacao_imagens_list($id) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string) ($_POST['acao'] ?? '');

    if ($acao === 'excluir_imagem') {
        $imgId = (int) ($_POST['imagem_id'] ?? 0);
        $pontoIdPost = (int) ($_POST['ponto_id'] ?? 0);
        $pDel = ($pontoIdPost > 0) ? repo_ponto_iluminacao($pontoIdPost) : null;
        if ($pDel && $imgId > 0) {
            gestor_assert_escopo_cliente((int) ($pDel['cliente_id'] ?? 0), 'pontos_iluminacao.php');
            if (repo_ponto_iluminacao_imagem_excluir($imgId, $pontoIdPost)) {
                flash_set('ok', 'Imagem removida.');
            } else {
                flash_set('err', 'Não foi possível remover a imagem.');
            }
            header('Location: ponto_iluminacao_novo.php?id=' . $pontoIdPost . '&cliente_id=' . (int) ($pDel['cliente_id'] ?? 0));
            exit;
        }
        flash_set('err', 'Requisição inválida.');
        header('Location: pontos_iluminacao.php?cliente_id=' . (int) $clienteId);
        exit;
    }

    if ($acao === 'definir_principal') {
        $imgId = (int) ($_POST['imagem_id'] ?? 0);
        $pontoIdPost = (int) ($_POST['ponto_id'] ?? 0);
        $pDel = ($pontoIdPost > 0) ? repo_ponto_iluminacao($pontoIdPost) : null;
        if ($pDel && $imgId > 0) {
            gestor_assert_escopo_cliente((int) ($pDel['cliente_id'] ?? 0), 'pontos_iluminacao.php');
            if (repo_ponto_iluminacao_imagem_definir_principal($imgId, $pontoIdPost)) {
                flash_set('ok', 'Imagem principal atualizada.');
            } else {
                flash_set('err', 'Não foi possível definir a imagem principal.');
            }
            header('Location: ponto_iluminacao_novo.php?id=' . $pontoIdPost . '&cliente_id=' . (int) ($pDel['cliente_id'] ?? 0));
            exit;
        }
        flash_set('err', 'Requisição inválida.');
        header('Location: pontos_iluminacao.php?cliente_id=' . (int) $clienteId);
        exit;
    }

    $postClienteId = (int) ($_POST['cliente_id'] ?? 0);
    gestor_assert_escopo_cliente($postClienteId, 'pontos_iluminacao.php');
    $save = repo_ponto_iluminacao_salvar([
        'id' => (int) ($_POST['id'] ?? 0),
        'cliente_id' => $postClienteId,
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
        $redir = 'ponto_iluminacao_novo.php?cliente_id=' . $postClienteId;
        if (!empty($_POST['id'])) {
            $redir .= '&id=' . (int) $_POST['id'];
        }
        header('Location: ' . $redir);
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

    header('Location: pontos_iluminacao.php?cliente_id=' . $postClienteId);
    exit;
}

$topTitle    = $id > 0 ? 'Editar poste' : 'Adicionar poste';
$topSubtitle = 'Cadastre o ponto de iluminação para vincular aos chamados e ao mapa.';
$topSearch   = '';
$topAction   = ['label' => 'Voltar', 'href' => 'pontos_iluminacao.php?cliente_id=' . (int) $clienteId, 'icon' => '←'];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <form class="card" method="post" enctype="multipart/form-data"
        action="ponto_iluminacao_novo.php<?= $id > 0 ? '?id=' . (int) $id . '&cliente_id=' . (int) $clienteId : '?cliente_id=' . (int) $clienteId ?>" autocomplete="off">
    <input type="hidden" name="id" value="<?= (int) ($ponto['id'] ?? 0) ?>">
    <div class="panel-head">
      <h4>Dados do poste</h4>
      <span class="panel-sub">Use o cliente correto para o poste aparecer na seleção do chamado.</span>
    </div>

    <div class="form form-grid">
      <div class="form-group full">
        <label for="cliente_id">Prefeitura / cadastro</label>
        <select id="cliente_id" name="cliente_id" class="select" required>
          <?php foreach ($clientesOptions as $cli): ?>
          <option value="<?= (int) $cli['id'] ?>" <?= (int) ($ponto['cliente_id'] ?? $clienteId) === (int) $cli['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars((string) ($cli['empresa'] ?? $cli['nome'] ?? 'Cadastro')) ?> #<?= (int) $cli['id'] ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

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
                <form method="post" style="display:inline;margin:0;" action="ponto_iluminacao_novo.php?id=<?= (int) $id ?>&amp;cliente_id=<?= (int) $clienteId ?>">
                  <input type="hidden" name="acao" value="definir_principal">
                  <input type="hidden" name="ponto_id" value="<?= (int) $id ?>">
                  <input type="hidden" name="imagem_id" value="<?= (int) $im['id'] ?>">
                  <button type="submit" class="action primary" style="font-size:11px;padding:4px 8px;">Usar como principal</button>
                </form>
              <?php endif; ?>
              <form method="post" style="display:inline;margin:4px 0 0;" action="ponto_iluminacao_novo.php?id=<?= (int) $id ?>&amp;cliente_id=<?= (int) $clienteId ?>" data-confirm="Remover esta imagem?" data-confirm-danger>
                <input type="hidden" name="acao" value="excluir_imagem">
                <input type="hidden" name="ponto_id" value="<?= (int) $id ?>">
                <input type="hidden" name="imagem_id" value="<?= (int) $im['id'] ?>">
                <button type="submit" class="action danger" style="font-size:11px;padding:4px 8px;">Excluir</button>
              </form>
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
      <a href="pontos_iluminacao.php?cliente_id=<?= (int) $clienteId ?>" class="btn btn-secondary">Cancelar</a>
      <button type="submit" class="btn btn-primary">Salvar poste</button>
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
