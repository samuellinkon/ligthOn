<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/upload.php';

$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('os');

$escopoCli = gestao_scope_cliente_id($me);
$pageTitle = 'Nova OS';
$basePath = '../';
$activePage = 'os';
$modoUnicoInstancia = db_ok();

$refDefault = date('Y-m');
$getYm = trim((string) ($_GET['ref_ym'] ?? ''));
if (preg_match('/^\d{4}-\d{2}$/', $getYm)) {
    $refDefault = $getYm;
}

$listaClientes = [];
if (db_ok()) {
    $listaClientes = ($escopoCli !== null) ? repo_clientes_na_empresa($escopoCli) : repo_clientes();
} else {
    $listaClientes = $MOCK_CLIENTES;
}

$clienteForcadoId = 0;
if (db_ok()) {
    if ($escopoCli !== null && count($listaClientes) === 1) {
        $clienteForcadoId = (int) ($listaClientes[0]['id'] ?? 0);
    } elseif ($modoUnicoInstancia) {
        $clienteForcadoId = (int) (repo_catalogo_cliente_id_padrao_admin() ?? 0);
        if ($clienteForcadoId <= 0 && !empty($listaClientes)) {
            $clienteForcadoId = (int) ($listaClientes[0]['id'] ?? 0);
        }
    }
}

$clienteSelecionadoId = $clienteForcadoId;
if ($clienteSelecionadoId <= 0 && !empty($listaClientes)) {
    $clienteSelecionadoId = (int) ($listaClientes[0]['id'] ?? 0);
}
$catalogoItens = (db_ok() && $clienteSelecionadoId > 0) ? repo_cliente_itens_list($clienteSelecionadoId, true) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!db_ok()) {
        flash_set('err', 'Banco indisponível.');
        header('Location: os_novo.php');
        exit;
    }
    try {
        $cidNovo = $clienteSelecionadoId;
        if ($escopoCli !== null && ($cidNovo <= 0 || !repo_cliente_pertence_empresa($cidNovo, $escopoCli))) {
            flash_set('err', 'Selecione um cliente da sua empresa.');
            header('Location: os_novo.php');
            exit;
        }
        if ($cidNovo <= 0) {
            flash_set('err', 'Selecione o cliente.');
            header('Location: os_novo.php');
            exit;
        }

        $refYm = trim((string) ($_POST['ref_ym'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}$/', $refYm)) {
            $refYm = date('Y-m');
        }

        $id = repo_os_pedido_criar([
            'cliente_id' => $cidNovo,
            'ref_ym' => $refYm,
            'titulo' => trim((string) ($_POST['titulo'] ?? '')),
            'descricao' => trim((string) ($_POST['descricao'] ?? '')),
            'endereco_completo' => '',
            'latitude' => '',
            'longitude' => '',
            'prioridade' => (string) ($_POST['prioridade'] ?? 'Normal'),
            'responsavel' => null,
            'criado_por_user_id' => (int) ($me['id'] ?? 0) ?: null,
        ]);
        if (!$id) {
            flash_set('err', 'Não foi possível criar a OS.');
            header('Location: os_novo.php');
            exit;
        }

        $msg = 'OS #' . $id . ' criada em rascunho.';

        $itemIds = is_array($_POST['item_id'] ?? null) ? $_POST['item_id'] : [];
        $movs = is_array($_POST['movimento'] ?? null) ? $_POST['movimento'] : [];
        $qtds = is_array($_POST['quantidade'] ?? null) ? $_POST['quantidade'] : [];
        $obss = is_array($_POST['observacao'] ?? null) ? $_POST['observacao'] : [];
        $nLancados = 0;

        $nRows = max(count($itemIds), count($movs), count($qtds), count($obss));
        for ($i = 0; $i < $nRows; $i++) {
            $itemId = (int) ($itemIds[$i] ?? 0);
            $qtd = (float) str_replace(',', '.', (string) ($qtds[$i] ?? '0'));
            if ($itemId <= 0 || $qtd <= 0) {
                continue;
            }
            $mov = (string) ($movs[$i] ?? 'utilizado');
            $obs = trim((string) ($obss[$i] ?? ''));
            $r = repo_os_pedido_item_adicionar($id, $itemId, $qtd, $mov, $obs !== '' ? $obs : null);
            if ($r['ok']) {
                $nLancados++;
            }
        }
        if ($nLancados > 0) {
            $msg .= ' ' . $nLancados . ' item(ns) lançados.';
        }

        flash_set('ok', $msg);
        header('Location: os_detalhe.php?id=' . $id);
        exit;
    } catch (Throwable $e) {
        flash_set('err', 'Falha: ' . $e->getMessage());
        header('Location: os_novo.php');
        exit;
    }
}

$topTitle = 'Nova OS';
$topSubtitle = 'Layout padrão de chamado, sem operador, com aprovação do cliente.';
$topAction = ['label' => 'Voltar', 'href' => 'os.php', 'icon' => '←'];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <style>
    .os-item-grid .input,
    .os-item-grid .select {
      height: 42px;
    }

    .os-item-grid {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    #os-itens-rows {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .os-item-grid-head,
    .os-item-row {
      display: grid;
      grid-template-columns: minmax(320px, 1fr) 110px minmax(180px, .75fr) 92px;
      gap: 10px;
      align-items: start;
    }

    .os-item-grid-head {
      color: var(--muted);
      font-size: 11px;
      font-weight: 700;
      letter-spacing: .06em;
      text-transform: uppercase;
      padding: 0 4px;
    }

    .os-item-row {
      padding: 12px;
      border: 1px solid var(--border-soft);
      border-radius: var(--radius-sm);
      background: #fafafe;
    }

    .os-item-picker {
      min-width: 0;
    }

    .os-item-picker .input {
      padding-right: 14px;
    }

    .os-item-picker .hint {
      margin-top: 4px;
      font-size: 12px;
      color: var(--muted);
    }

    @media (max-width: 760px) {
      .os-item-grid-head {
        display: none;
      }

      .os-item-row {
        grid-template-columns: 1fr;
      }

      .os-item-row .text-right {
        text-align: left;
      }
    }
  </style>
  <form class="card" method="post" enctype="multipart/form-data" autocomplete="off" action="os_novo.php">
    <div class="panel-head">
      <h4>Nova ordem de serviço</h4>
      <span class="panel-sub">Será criada em <strong>Rascunho</strong> e depois enviada ao cliente para aprovação.</span>
    </div>

    <div class="form form-grid">
      <input type="hidden" name="cliente" value="<?= (int) $clienteSelecionadoId ?>">

      <div class="form-group">
        <label for="ref_ym">Mês de referência</label>
        <input type="month" class="input" id="ref_ym" name="ref_ym" value="<?= htmlspecialchars($refDefault) ?>" required>
      </div>
      <div class="form-group">
        <label for="titulo">Título</label>
        <input type="text" id="titulo" name="titulo" class="input" required placeholder="Resumo da OS">
      </div>

      <div class="form-group full">
        <label for="descricao">Descrição</label>
        <textarea id="descricao" name="descricao" class="textarea" rows="3" placeholder="Detalhes do serviço ou materiais..."></textarea>
      </div>

    </div>

    <div class="card" style="margin:16px;">
      <div class="panel-head" style="flex-wrap:wrap;gap:10px;">
        <h4>Itens e serviços</h4>
        <span class="panel-sub">Mesmo padrão do chamado: adicione uma ou mais linhas</span>
      </div>
      <div class="panel-body">
        <?php if (empty($catalogoItens)): ?>
          <p class="muted" style="margin:0;">Sem catálogo disponível para o cliente selecionado.</p>
        <?php else: ?>
        <datalist id="os-catalogo-list">
          <?php foreach ($catalogoItens as $ci): ?>
            <option
              value="<?= htmlspecialchars((string) ($ci['nome'] ?? '')) ?> (R$ <?= number_format((float) ($ci['valor_unitario'] ?? 0), 2, ',', '.') ?>)"
              data-id="<?= (int) $ci['id'] ?>"
            ></option>
          <?php endforeach; ?>
        </datalist>
        <div class="os-item-grid">
          <div class="os-item-grid-head" aria-hidden="true">
            <div>Item/serviço</div>
            <div class="text-right">Qtd.</div>
            <div>Observação</div>
            <div class="text-right">Ações</div>
          </div>
          <div id="os-itens-rows"></div>
        </div>
        <div class="form-actions" style="padding:12px 0 0;">
          <button type="button" class="btn btn-secondary" id="os-item-add">+ Adicionar linha</button>
        </div>
        <template id="os-item-template">
          <div class="os-item-row">
            <div class="os-item-picker">
              <input type="hidden" name="item_id[]" class="os-item-id">
              <input type="hidden" name="movimento[]" value="utilizado">
              <input type="text" class="input os-item-search" list="os-catalogo-list" placeholder="Digite para buscar no catálogo" autocomplete="off">
              <div class="hint">Digite parte do nome e selecione uma opção.</div>
            </div>
            <div>
              <input type="text" name="quantidade[]" class="input text-right" value="1" inputmode="decimal">
            </div>
            <div>
              <input type="text" name="observacao[]" class="input" placeholder="Opcional">
            </div>
            <div class="text-right">
              <button type="button" class="btn btn-danger btn-sm os-item-remove">Excluir</button>
            </div>
          </div>
        </template>
        <?php endif; ?>
      </div>
    </div>

    <div class="form-actions">
      <a href="os.php" class="btn btn-secondary">Cancelar</a>
      <button type="submit" class="btn btn-primary">Criar OS (rascunho)</button>
    </div>
  </form>
</section>

<script>
(function () {
  var tbody = document.getElementById('os-itens-rows');
  var tpl = document.getElementById('os-item-template');
  var addBtn = document.getElementById('os-item-add');
  if (!tbody || !tpl || !addBtn) return;
  var catalogList = document.getElementById('os-catalogo-list');

  function addRow() {
    var node = tpl.content.firstElementChild.cloneNode(true);
    tbody.appendChild(node);
  }

  function catalogIdFromValue(value) {
    if (!catalogList) return '';
    var opts = catalogList.querySelectorAll('option');
    for (var i = 0; i < opts.length; i++) {
      if (opts[i].value === value) {
        return opts[i].getAttribute('data-id') || '';
      }
    }
    return '';
  }

  addBtn.addEventListener('click', addRow);
  tbody.addEventListener('input', function (ev) {
    if (!ev.target.classList.contains('os-item-search')) return;
    var row = ev.target.closest('.os-item-row');
    var hidden = row ? row.querySelector('.os-item-id') : null;
    if (hidden) hidden.value = catalogIdFromValue(ev.target.value);
  });
  tbody.addEventListener('click', function (ev) {
    var btn = ev.target.closest('.os-item-remove');
    if (!btn) return;
    var row = btn.closest('.os-item-row');
    if (row) row.remove();
  });

  var form = tbody.closest('form');
  if (form) {
    form.addEventListener('submit', function (ev) {
      var searches = tbody.querySelectorAll('.os-item-search');
      for (var i = 0; i < searches.length; i++) {
        var input = searches[i];
        var row = input.closest('.os-item-row');
        var hidden = row ? row.querySelector('.os-item-id') : null;
        if (input.value.trim() !== '' && hidden && hidden.value === '') {
          input.setCustomValidity('Selecione um item válido da lista.');
          input.reportValidity();
          ev.preventDefault();
          return;
        }
        input.setCustomValidity('');
      }
    });
  }

  addRow();
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
