<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/chamado_os_fields.php';

$user = require_auth('cliente');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_cliente('chamados');
$pageTitle  = 'Novo chamado';
$basePath   = '../';
$activePage = 'chamados';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!db_ok()) {
        flash_set('err', 'Banco indisponível.');
        header('Location: chamado_novo.php'); exit;
    }
    try {
        $os = chamado_os_parse_post($_POST);
        $id = repo_create_chamado(array_merge($os, [
            'cliente_id'          => (int) ($user['cliente_id'] ?? 0),
            'ponto_iluminacao_id' => (int) ($_POST['ponto_iluminacao_id'] ?? 0),
            'titulo'              => chamado_os_titulo_from_post($_POST),
            'descricao'           => trim($_POST['descricao'] ?? ''),
            'latitude'            => $_POST['latitude'] ?? '',
            'longitude'           => $_POST['longitude'] ?? '',
            'prioridade'          => $_POST['prioridade'] ?? 'Normal',
            'responsavel'         => 'Suporte N1',
            'status'              => 'Aberto',
        ]));

        $msg      = 'Chamado #' . $id . ' enviado. Em breve entraremos em contato.';
        $flashTipo = 'ok';
        $temArq   = !empty($_FILES['anexos']['name'][0]);
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
                    'enviado_por'   => $user['nome'] ?? 'Cliente',
                    'enviado_tipo'  => 'cliente',
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
        flash_set('err', 'Falha ao enviar chamado: ' . $e->getMessage());
        header('Location: chamado_novo.php'); exit;
    }
}

$pontosIluminacao = db_ok() && !empty($user['cliente_id'])
    ? repo_pontos_iluminacao_options((int) $user['cliente_id'])
    : [];

$topTitle    = 'Abrir novo chamado';
$topSubtitle = 'Conte o que está acontecendo. Respondemos o mais rápido possível em horário comercial.';
$topSearch   = 'Buscar em chamados...';
$topAction   = ['label' => 'Voltar', 'href' => 'chamados.php', 'icon' => '←'];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-cliente.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <form class="card" action="chamado_novo.php" method="post" enctype="multipart/form-data" autocomplete="off">
    <div class="panel-head">
      <h4>Nova ordem de serviço</h4>
      <span class="panel-sub">Quanto mais detalhes, mais rápida a resposta</span>
    </div>

    <?php
    $ch_os_vals = [];
    $ch_os_descricao = '';
    $ch_os_mostrar_ponto = !empty($pontosIluminacao);
    $ch_os_pontos_opcoes = $pontosIluminacao ?: [];
    include __DIR__ . '/../includes/chamado_os_grid_markup.php';
    ?>

    <div class="form form-grid" style="padding-top: 0;">
      <div class="form-group full">
        <label>Qual a prioridade?</label>
        <div class="radio-group" data-prio-radio-group>
          <label class="radio-card radio-card--prio-baixa"><input type="radio" name="prioridade" value="Baixa"><strong>Baixa</strong><span>Não urgente</span></label>
          <label class="radio-card radio-card--prio-normal active"><input type="radio" name="prioridade" value="Normal" checked><strong>Normal</strong><span>Padrão</span></label>
          <label class="radio-card radio-card--prio-alta"><input type="radio" name="prioridade" value="Alta"><strong>Alta</strong><span>Impacta rotina</span></label>
          <label class="radio-card radio-card--prio-urgente"><input type="radio" name="prioridade" value="Urgente"><strong>Urgente</strong><span>Sistema parado</span></label>
        </div>
      </div>

      <div class="form-group full">
        <label>Anexos (opcional)</label>
        <div class="file-upload">
          <div class="file-icon">⇪</div>
          <strong>Clique ou arraste arquivos aqui</strong>
          <span>Prints, PDF ou documentos</span>
          <input type="file" name="anexos[]" multiple hidden>
        </div>
        <div class="file-list"></div>
      </div>
    </div>

    <div class="form-actions">
      <a href="chamados.php" class="btn btn-secondary">Cancelar</a>
      <button type="submit" class="btn btn-primary">Enviar chamado</button>
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

<?php include __DIR__ . '/../includes/footer.php'; ?>
