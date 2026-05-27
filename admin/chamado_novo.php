<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/chamado_os_fields.php';

$user = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('chamados');

$escopoCli = gestao_scope_cliente_id($user);
$pageTitle  = 'Novo chamado';
$basePath   = '../';
$activePage = 'chamados';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!db_ok()) {
        flash_set('err', 'Banco indisponível.');
        header('Location: chamado_novo.php'); exit;
    }
    try {
        $cidNovo = (int) ($_POST['cliente'] ?? 0);
        if ($escopoCli !== null) {
            if ($cidNovo <= 0 || !repo_cliente_pertence_empresa($cidNovo, $escopoCli)) {
                flash_set('err', 'Selecione um cliente da sua empresa.');
                header('Location: chamado_novo.php'); exit;
            }
        }
        if ($cidNovo <= 0) {
            flash_set('err', 'Selecione o cliente.');
            header('Location: chamado_novo.php'); exit;
        }
        $osErros = chamado_os_validar_obrigatorios($_POST);
        if ($osErros !== []) {
            flash_set('err', implode(' ', $osErros));
            header('Location: chamado_novo.php'); exit;
        }
        $os       = chamado_os_parse_post($_POST);
        $respAuto = trim((string) ($user['nome'] ?? ''));
        $id = repo_create_chamado(array_merge($os, [
            'cliente_id'          => $cidNovo,
            'criado_por_user_id'  => (int) ($user['id'] ?? 0),
            'ponto_iluminacao_id' => (int) ($_POST['ponto_iluminacao_id'] ?? 0),
            'titulo'              => chamado_os_titulo_from_post($_POST),
            'descricao'           => trim($_POST['descricao'] ?? ''),
            'latitude'            => $_POST['latitude'] ?? '',
            'longitude'           => $_POST['longitude'] ?? '',
            'prioridade'          => $_POST['prioridade'] ?? 'Normal',
            'responsavel'         => $respAuto !== '' ? $respAuto : null,
            'status'              => 'Aberto',
        ]));

        $msg       = 'Chamado #' . $id . ' aberto com sucesso.';
        $flashTipo = 'ok';
        $temArq    = !empty($_FILES['anexos']['name'][0]);
        if ($temArq) {
            $destino = upload_dir_chamado($id);
            $res     = upload_gravar_multiplos($_FILES['anexos'], $destino);
            $n       = 0;
            foreach ($res['salvos'] as $arq) {
                repo_create_chamado_anexo([
                    'chamado_id'    => $id,
                    'resposta_id'   => null,
                    'nome_original' => $arq['nome_original'],
                    'nome_arquivo'  => $arq['nome_arquivo'],
                    'mime'          => $arq['mime'],
                    'tamanho'       => $arq['tamanho'],
                    'enviado_por'   => $user['nome'] ?? 'Admin',
                    'enviado_tipo'  => 'admin',
                ]);
                $n++;
            }
            if ($n > 0) {
                $msg .= ' ' . $n . ' anexo(s) salvo(s).';
            }
            if (!empty($res['erros'])) {
                $msg .= ' Alguns arquivos não foram aceitos: ' . implode(' | ', $res['erros']);
                if ($n === 0) {
                    $flashTipo = 'err';
                }
            }
        }

        flash_set($flashTipo, $msg);
        header('Location: chamado_detalhe.php?id=' . $id); exit;
    } catch (Throwable $e) {
        flash_set('err', 'Falha ao abrir chamado: ' . $e->getMessage());
        header('Location: chamado_novo.php'); exit;
    }
}

$topTitle    = 'Abrir novo chamado';
$topSubtitle = 'Registre uma nova solicitação em nome de um cliente.';
$topSearch   = 'Buscar prefeitura ou chamado...';
$topAction   = ['label' => 'Voltar', 'href' => 'chamados.php', 'icon' => '←'];

$listaClientesChamado = [];
if (db_ok()) {
    if ($escopoCli !== null) {
        $listaClientesChamado = repo_clientes_na_empresa($escopoCli);
    } else {
        $listaClientesChamado = repo_clientes();
    }
} else {
    $listaClientesChamado = $MOCK_CLIENTES;
}

$chamadoNovoClienteId = 0;
foreach ($listaClientesChamado as $c) {
    $cid = (int) ($c['id'] ?? 0);
    if ($cid > 0) {
        $chamadoNovoClienteId = $cid;
        break;
    }
}

$pontosIluminacaoChamado = [];
if (db_ok()) {
    if ($escopoCli !== null && $escopoCli > 0) {
        $pontosIluminacaoChamado = repo_pontos_iluminacao_list($escopoCli, true, '', 'Ativo');
    } elseif ($chamadoNovoClienteId > 0) {
        $pontosIluminacaoChamado = repo_pontos_iluminacao_options($chamadoNovoClienteId);
    }
}

$loadLeaflet = true;
include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <form class="card" action="chamado_novo.php" method="post" enctype="multipart/form-data" autocomplete="off">
    <div class="panel-head">
      <h4>Nova ordem de serviço</h4>
      <span class="panel-sub">Mesma estrutura para abrir, consultar e editar no detalhe do chamado</span>
    </div>

    <?php if ($chamadoNovoClienteId > 0): ?>
      <input type="hidden" name="cliente" value="<?= $chamadoNovoClienteId ?>">
    <?php endif; ?>

    <?php
    $ch_os_vals = [];
    $pontoPre = (int) ($_GET['ponto_iluminacao_id'] ?? 0);
    if ($pontoPre > 0) {
        $ch_os_vals['ponto_iluminacao_id'] = $pontoPre;
    }
    $ch_os_descricao = '';
    $ch_os_mostrar_ponto = !empty($pontosIluminacaoChamado);
    $ch_os_pontos_opcoes = $pontosIluminacaoChamado ?: [];
    $ch_os_mostrar_preview_mapa = true;
    include __DIR__ . '/../includes/chamado_os_grid_markup.php';
    ?>

    <div class="form form-grid" style="padding-top: 0;">
      <div class="form-group full">
        <label>Prioridade</label>
        <div class="radio-group" data-prio-radio-group>
          <label class="radio-card radio-card--prio-baixa">
            <input type="radio" name="prioridade" value="Baixa">
            <strong>Baixa</strong>
          </label>
          <label class="radio-card radio-card--prio-normal active">
            <input type="radio" name="prioridade" value="Normal" checked>
            <strong>Normal</strong>
          </label>
          <label class="radio-card radio-card--prio-alta">
            <input type="radio" name="prioridade" value="Alta">
            <strong>Alta</strong>
          </label>
          <label class="radio-card radio-card--prio-urgente">
            <input type="radio" name="prioridade" value="Urgente">
            <strong>Urgente</strong>
          </label>
        </div>
      </div>

      <div class="form-group full">
        <label>Anexos</label>
        <div class="file-upload">
          <div class="file-icon">⇪</div>
          <strong>Clique ou arraste arquivos aqui</strong>
          <input type="file" name="anexos[]" multiple hidden>
        </div>
        <div class="file-list"></div>
      </div>
    </div>

    <div class="form-actions">
      <a href="chamados.php" class="btn btn-secondary">Cancelar</a>
      <button type="submit" class="btn btn-primary">Abrir chamado</button>
    </div>
  </form>
</section>

<script>
(function () {
  var group = document.querySelector('[data-prio-radio-group]');
  if (!group) return;
  var cards = group.querySelectorAll('.radio-card');
  function sync() {
    cards.forEach(function (lbl) {
      var inp = lbl.querySelector('input[type="radio"]');
      lbl.classList.toggle('active', !!(inp && inp.checked));
    });
  }
  cards.forEach(function (lbl) {
    lbl.addEventListener('click', function () {
      window.setTimeout(sync, 0);
    });
  });
  group.addEventListener('change', sync);
  sync();
})();
</script>
<script>
(function () {
  var input = document.querySelector('.file-upload input[type="file"][name="anexos[]"]');
  var list = document.querySelector('.file-list');
  if (!input || !list) return;
  input.addEventListener('change', function () {
    list.innerHTML = '';
    Array.prototype.forEach.call(input.files || [], function (file) {
      var row = document.createElement('div');
      row.className = 'file-preview-row';
      row.style.cssText = 'display:flex;align-items:center;gap:10px;margin-top:8px;padding:8px;border:1px solid var(--border,#e2e8f0);border-radius:10px;';
      if ((file.type || '').indexOf('image/') === 0) {
        var img = document.createElement('img');
        img.style.cssText = 'width:56px;height:56px;object-fit:cover;border-radius:8px;';
        img.src = URL.createObjectURL(file);
        img.onload = function () { URL.revokeObjectURL(img.src); };
        row.appendChild(img);
      }
      var meta = document.createElement('span');
      meta.textContent = file.name + ' (' + Math.round(file.size / 1024) + ' KB)';
      row.appendChild(meta);
      list.appendChild(row);
    });
  });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
