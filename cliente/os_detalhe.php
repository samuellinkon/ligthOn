<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/medicao_helpers.php';

$user = require_auth('cliente');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_cliente('os');

$pageTitle  = 'OS';
$basePath   = '../';
$activePage = 'os';

$id         = (int) ($_GET['id'] ?? 0);
$clienteId  = (int) ($user['cliente_id'] ?? 0);

if ($id <= 0) {
    header('Location: os.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && db_ok()) {
    $osCh = repo_os_pedido($id);
    if (!$osCh || !repo_cliente_pertence_empresa((int) $osCh['cliente_id'], $clienteId)) {
        flash_set('err', 'OS não encontrada.');
        header('Location: os.php');
        exit;
    }
    $tabAfterPost = null;
    try {
        $acao = (string) ($_POST['acao'] ?? '');
        if ($acao === 'aprovar') {
            $r = repo_os_pedido_cliente_aprovar($id);
            flash_set($r['ok'] ? 'ok' : 'err', $r['ok'] ? 'OS aprovada. Obrigado.' : $r['err']);
        } elseif ($acao === 'rejeitar') {
            $motivoRej = trim((string) ($_POST['motivo'] ?? ''));
            $r         = repo_os_pedido_cliente_rejeitar($id, $motivoRej);
            if ($r['ok'] && $motivoRej !== '') {
                repo_os_pedido_resposta_criar(
                    $id,
                    (string) ($user['nome'] ?? 'Cliente'),
                    'cliente',
                    'Reprovação: ' . $motivoRej,
                    false
                );
                $tabAfterPost = 'historico';
            }
            flash_set(
                $r['ok'] ? 'ok' : 'err',
                $r['ok'] ? 'Reprovação registrada. O comentário consta no histórico.' : $r['err']
            );
        } elseif ($acao === 'responder') {
            $texto  = trim((string) ($_POST['resposta'] ?? ''));
            $temArq = !empty($_FILES['anexos']['name'][0]);
            if ($texto === '' && !$temArq) {
                flash_set('err', 'Escreva uma mensagem ou anexe um arquivo.');
            } else {
                $rid = null;
                if ($texto !== '') {
                    $rid = repo_os_pedido_resposta_criar($id, (string) ($user['nome'] ?? 'Cliente'), 'cliente', $texto, false);
                }
                if ($temArq) {
                    $dest = upload_dir_os_pedido($id);
                    $up   = upload_gravar_multiplos($_FILES['anexos'], $dest);
                    foreach ($up['salvos'] ?? [] as $arq) {
                        repo_os_pedido_anexo_criar([
                            'os_pedido_id'  => $id,
                            'resposta_id'   => $rid,
                            'nome_original' => $arq['nome_original'],
                            'nome_arquivo'  => $arq['nome_arquivo'],
                            'mime'          => $arq['mime'],
                            'tamanho'       => $arq['tamanho'],
                            'enviado_por'   => $user['nome'] ?? 'Cliente',
                            'enviado_tipo'  => 'cliente',
                        ]);
                    }
                    if (!empty($up['erros'])) {
                        flash_set('err', implode(' | ', $up['erros']));
                    } else {
                        flash_set('ok', 'Enviado.');
                    }
                } elseif ($texto !== '') {
                    flash_set('ok', 'Mensagem enviada.');
                }
            }
        }
    } catch (Throwable $e) {
        flash_set('err', $e->getMessage());
    }
    $redir = 'os_detalhe.php?id=' . $id;
    if ($tabAfterPost) {
        $redir .= '&tab=' . rawurlencode($tabAfterPost);
    }
    header('Location: ' . $redir);
    exit;
}

if (!db_ok()) {
    flash_set('err', 'Banco indisponível.');
    header('Location: os.php');
    exit;
}

$os = repo_os_pedido($id);
if (!$os || !repo_cliente_pertence_empresa((int) $os['cliente_id'], $clienteId)) {
    header('Location: os.php');
    exit;
}

$st        = (string) ($os['status'] ?? '');
$itens     = repo_os_pedido_itens_list($id);
$total     = repo_os_pedido_itens_valor_total($id);
$respostas = repo_os_pedido_respostas($id, true);
$anexos    = repo_os_pedido_anexos($id);
$anexosPorResposta = [];
foreach ($anexos as $a) {
    if (!empty($a['resposta_id'])) {
        $anexosPorResposta[(int) $a['resposta_id']][] = $a;
    }
}

$clienteOs = repo_cliente((int) ($os['cliente_id'] ?? 0));

$topTitle    = 'OS #' . $id;
$topSubtitle = (string) $os['titulo'];
$topAction   = ['label' => '← Minhas OS', 'href' => 'os.php', 'icon' => '←'];
$loadLeaflet = db_ok()
    && isset($os['latitude'], $os['longitude'])
    && $os['latitude'] !== null
    && $os['longitude'] !== null;

$tabInicial = (string) ($_GET['tab'] ?? '');
if (!in_array($tabInicial, ['geral', 'historico'], true)) {
    $tabInicial = 'geral';
}

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-cliente.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <style>
    .cliente-ticket-tabs{display:flex;gap:8px;flex-wrap:wrap;padding:10px}
    .cliente-ticket-tab{border:0;background:transparent;color:var(--muted);font-weight:700;padding:10px 14px;border-radius:14px;cursor:pointer}
    .cliente-ticket-tab.active{background:var(--primary);color:#fff}
    .cliente-ticket-panel{display:none}
    .cliente-ticket-panel.active{display:block}
    .os-map-mini{height:220px;border-radius:14px;overflow:hidden;border:1px solid var(--border)}
    .print-only{display:none}
    @media print{
      body{background:#fff!important}
      .sidebar,.sidebar-overlay,.topbar,.ticket-actions,.composer,.no-print{display:none!important}
      .main{margin:0!important;padding:0!important}
      .content{padding:0!important}
      .card,.ticket-header{box-shadow:none!important;border:1px solid #ddd!important;break-inside:avoid}
      .cliente-ticket-panel{display:block!important}
      .print-only{display:block!important}
      a{color:#111;text-decoration:none}
    }
  </style>

  <div class="print-only" style="margin-bottom:18px;">
    <h1 style="margin:0 0 6px;">OS #<?= (int) $id ?></h1>
    <p style="margin:0;color:#555;"><?= htmlspecialchars((string) ($os['titulo'] ?? '')) ?></p>
  </div>

  <div class="ticket-header">
    <div>
      <div class="ticket-id">OS #<?= (int) $id ?> · <?= htmlspecialchars(medicao_mes_label_pt((string) $os['ref_ym'])) ?></div>
      <h2><?= htmlspecialchars((string) $os['titulo']) ?></h2>
      <div class="ticket-meta">
        <span class="badge <?= status_class($st) ?>"><?= htmlspecialchars($st) ?></span>
        <span class="badge <?= status_class((string) ($os['prioridade'] ?? 'Normal')) ?>">Prioridade: <?= htmlspecialchars((string) ($os['prioridade'] ?? 'Normal')) ?></span>
        <?php if (!empty($os['aberto_em'])): ?>
          <span class="badge badge-plain">Aberta em <?= date('d/m/Y H:i', strtotime((string) $os['aberto_em'])) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="ticket-actions no-print" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:flex-end;">
      <?php if ($st === 'Enviada ao cliente'): ?>
        <form method="post" style="display:inline;margin:0;" data-confirm="Aprovar esta ordem de serviço?" id="form-os-aprovar">
          <input type="hidden" name="acao" value="aprovar">
          <button type="submit" class="btn btn-primary">Aprovar</button>
        </form>
        <button type="button" class="btn btn-danger" id="btn-os-reprovar" data-open-reprovar>Reprovar</button>
      <?php endif; ?>
      <button type="button" class="btn btn-secondary" onclick="window.print()">Imprimir OS</button>
      <a href="os.php" class="btn btn-secondary">Voltar</a>
    </div>
  </div>

  <nav class="admin-tabs card mb-24 cliente-ticket-tabs no-print" aria-label="Abas da OS">
    <button type="button" class="cliente-ticket-tab<?= $tabInicial === 'geral' ? ' active' : '' ?>" data-ticket-tab="geral">Geral</button>
    <button type="button" class="cliente-ticket-tab<?= $tabInicial === 'historico' ? ' active' : '' ?>" data-ticket-tab="historico">Histórico</button>
  </nav>

  <?php if ($st === 'Enviada ao cliente'): ?>
  <div class="app-modal-overlay" id="modal-os-reprovar" hidden aria-hidden="true">
    <div class="app-modal app-modal--form" style="max-width:480px;" role="dialog" aria-modal="true" aria-labelledby="modal-reprovar-title">
      <h3 class="app-modal-title" id="modal-reprovar-title">Reprovar OS</h3>
      <p class="app-modal-text" style="margin-bottom:12px;">Informe o comentário. Será salvo no histórico e enviado à equipe.</p>
      <form method="post" id="form-os-reprovar">
        <input type="hidden" name="acao" value="rejeitar">
        <div class="form-group" style="margin:0 0 16px;">
          <label for="motivo_reprovar">Comentário (obrigatório)</label>
          <textarea class="textarea" name="motivo" id="motivo_reprovar" rows="4" required placeholder="Descreva o motivo da reprovação."></textarea>
        </div>
        <div class="app-modal-actions" style="margin-top:0;">
          <button type="button" class="btn btn-secondary" data-close-reprovar>Cancelar</button>
          <button type="submit" class="btn btn-danger">Reprovar</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <div class="cliente-ticket-panel<?= $tabInicial === 'geral' ? ' active' : '' ?>" data-ticket-panel="geral">
    <div class="flex-col" style="gap:18px;">
    <div class="ticket-body" style="grid-template-columns:minmax(0,1fr) 260px;">
      <div class="flex-col" style="gap:18px;">
        <?php if ($st === 'Rejeitada' && !empty($os['rejeitada_motivo'])): ?>
          <div class="card">
            <div class="panel-head"><h4>Motivo da rejeição</h4></div>
            <div class="panel-body">
              <p class="muted" style="white-space:pre-wrap;margin:0;"><?= htmlspecialchars((string) $os['rejeitada_motivo']) ?></p>
            </div>
          </div>
        <?php endif; ?>

        <div class="card">
          <div class="panel-head"><h4>Descrição enviada</h4></div>
          <div class="panel-body">
            <p style="color:var(--muted);line-height:1.65;margin:0;"><?= nl2br(htmlspecialchars((string) ($os['descricao'] ?? ''))) ?></p>
          </div>
        </div>

        <?php if (!empty($os['endereco_completo']) || !empty($loadLeaflet)): ?>
        <div class="card">
          <div class="panel-head"><h4>Local da OS</h4></div>
          <div class="panel-body">
            <?php if (!empty($os['endereco_completo'])): ?>
              <p style="color:var(--muted);line-height:1.65;margin:0 0 12px;"><?= nl2br(htmlspecialchars((string) $os['endereco_completo'])) ?></p>
            <?php else: ?>
              <p class="muted" style="margin:0 0 12px;">Nenhum endereço informado nesta OS.</p>
            <?php endif; ?>
            <?php if (!empty($loadLeaflet)): ?>
              <div id="os-map-mini" class="os-map-mini" aria-label="Mapa"></div>
              <a class="btn btn-secondary" style="margin-top:10px;" target="_blank" rel="noopener"
                 href="https://www.openstreetmap.org/?mlat=<?= urlencode((string) $os['latitude']) ?>&amp;mlon=<?= urlencode((string) $os['longitude']) ?>&amp;zoom=16">Ver no mapa externo</a>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <aside class="flex-col" style="gap:18px;">
        <div class="card">
          <div class="panel-head"><h4>Detalhes</h4></div>
          <div class="panel-body flex-col" style="gap:12px;">
            <div class="info-row"><span>Status</span><strong><?= htmlspecialchars($st) ?></strong></div>
            <div class="info-row"><span>Prioridade</span><strong><?= htmlspecialchars((string) ($os['prioridade'] ?? 'Normal')) ?></strong></div>
            <div class="info-row"><span>Mês de referência</span><strong><?= htmlspecialchars(medicao_mes_label_pt((string) $os['ref_ym'])) ?></strong></div>
            <div class="info-row"><span>Prefeitura</span><strong><?= htmlspecialchars((string) ($clienteOs['empresa'] ?? $os['cliente_empresa'] ?? '—')) ?></strong></div>
            <?php if (!empty($os['aberto_em'])): ?>
              <div class="info-row"><span>Aberta em</span><strong><?= date('d/m/Y H:i', strtotime((string) $os['aberto_em'])) ?></strong></div>
            <?php endif; ?>
            <?php if (!empty($os['enviada_cliente_em'])): ?>
              <div class="info-row"><span>Enviada ao portal</span><strong><?= date('d/m/Y H:i', strtotime((string) $os['enviada_cliente_em'])) ?></strong></div>
            <?php endif; ?>
            <?php if (!empty($os['aprovada_cliente_em'])): ?>
              <div class="info-row"><span>Aprovada em</span><strong><?= date('d/m/Y H:i', strtotime((string) $os['aprovada_cliente_em'])) ?></strong></div>
            <?php endif; ?>
            <div class="info-row"><span>Valor</span><strong>R$ <?= number_format((float) $total, 2, ',', '.') ?></strong></div>
          </div>
        </div>
      </aside>
    </div>

    <div class="card">
      <div class="panel-head" style="flex-wrap:wrap;gap:10px;">
        <div>
          <h4>Itens e serviços</h4>
          <span class="panel-sub">Itens informados pela equipe para esta OS</span>
        </div>
        <strong>Valor: R$ <?= number_format((float) $total, 2, ',', '.') ?></strong>
      </div>
      <div class="panel-body">
        <?php if (empty($itens)): ?>
          <p class="muted" style="margin:0;">Nenhum item foi lançado nesta OS.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Movimento</th>
                  <th>Item</th>
                  <th class="text-right">Qtd</th>
                  <th class="text-right">V. unit.</th>
                  <th class="text-right">Subtotal</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($itens as $it): ?>
                <tr>
                  <td><span class="badge <?= ($it['movimento'] ?? '') === 'devolvido' ? 'info' : 'success' ?>"><?= ($it['movimento'] ?? '') === 'devolvido' ? 'Devolvido' : 'Usado' ?></span></td>
                  <td>
                    <?= htmlspecialchars((string) ($it['item_nome'] ?? '')) ?>
                    <?php if (!empty($it['observacao'])): ?><small class="muted" style="display:block;"><?= htmlspecialchars((string) $it['observacao']) ?></small><?php endif; ?>
                  </td>
                  <td class="text-right"><?= htmlspecialchars(rtrim(rtrim(sprintf('%.4f', (float) ($it['quantidade'] ?? 0)), '0'), '.')) ?></td>
                  <td class="text-right td-mute">R$ <?= number_format((float) ($it['valor_unitario'] ?? 0), 4, ',', '.') ?></td>
                  <td class="text-right">R$ <?= number_format((float) ($it['subtotal'] ?? 0), 2, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
    </div>
  </div>

  <div class="cliente-ticket-panel<?= $tabInicial === 'historico' ? ' active' : '' ?>" data-ticket-panel="historico">
    <div class="card">
      <div class="panel-head">
        <h4>Histórico da OS</h4>
        <span class="panel-sub"><?= count($respostas) ?> mensagem(s)</span>
      </div>
      <div class="thread">
        <?php if (empty($respostas)): ?>
          <div class="empty-state" style="padding:20px;">
            <div class="empty-icon">💬</div>
            <p>Aguardando mensagens.</p>
          </div>
        <?php else: foreach ($respostas as $r): ?>
          <div class="msg <?= ($r['tipo'] ?? '') === 'cliente' ? 'me' : '' ?>">
            <div class="avatar avatar-sm"><?= initials($r['autor']) ?></div>
            <div class="bubble">
              <div class="bubble-head">
                <strong><?= htmlspecialchars((string) $r['autor']) ?></strong>
                <span><?= date('d/m/Y H:i', strtotime((string) $r['data'])) ?></span>
              </div>
              <p><?= nl2br(htmlspecialchars((string) $r['texto'])) ?></p>
              <?php
                $anexosMsg = $anexosPorResposta[(int) ($r['id'] ?? 0)] ?? [];
                if ($anexosMsg):
              ?>
                <div class="bubble-files">
                  <?php foreach ($anexosMsg as $ax): ?>
                    <a class="file-chip" href="os_download.php?id=<?= (int) $ax['id'] ?>">
                      <span class="file-chip-ico"><?= upload_icone_por_ext((string) $ax['nome_original']) ?></span>
                      <span>
                        <strong><?= htmlspecialchars((string) $ax['nome_original']) ?></strong>
                        <small><?= upload_formatar_tamanho((int) ($ax['tamanho'] ?? 0)) ?></small>
                      </span>
                    </a>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <form class="composer no-print" method="post" enctype="multipart/form-data" id="composer-form">
        <input type="hidden" name="acao" value="responder">
        <textarea class="textarea" id="composer-text" name="resposta" placeholder="Adicione um comentário para a equipe..."></textarea>
        <div class="composer-files" id="composer-files" hidden>
          <strong>Arquivos selecionados:</strong>
          <ul id="composer-files-list"></ul>
        </div>
        <input type="file" id="composer-file-input" name="anexos[]" multiple hidden
               accept=".pdf,.doc,.docx,.odt,.rtf,.txt,.xls,.xlsx,.ods,.csv,.png,.jpg,.jpeg,.gif,.webp,.zip,.rar,.7z">
        <div class="composer-bar">
          <div class="composer-tools">
            <button type="button" class="tool" id="btn-anexo">+ Anexo</button>
          </div>
          <button type="submit" class="btn btn-primary" id="btn-enviar">Enviar</button>
        </div>
      </form>
    </div>
  </div>
</section>

<?php if ($st === 'Enviada ao cliente'): ?>
<script>
(function () {
  var ov = document.getElementById('modal-os-reprovar');
  var openBtn = document.getElementById('btn-os-reprovar');
  var ta = document.getElementById('motivo_reprovar');
  if (!ov) return;
  function openM() {
    ov.classList.add('is-open');
    ov.removeAttribute('hidden');
    ov.setAttribute('aria-hidden', 'false');
    if (ta) { setTimeout(function () { ta.focus(); }, 50); }
  }
  function closeM() {
    ov.classList.remove('is-open');
    ov.setAttribute('hidden', 'hidden');
    ov.setAttribute('aria-hidden', 'true');
  }
  if (openBtn) {
    openBtn.addEventListener('click', openM);
  }
  ov.addEventListener('click', function (e) {
    if (e.target === ov) closeM();
  });
  var closes = Array.prototype.slice.call(ov.querySelectorAll('[data-close-reprovar]'));
  closes.forEach(function (b) { b.addEventListener('click', closeM); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && ov && ov.classList.contains('is-open')) {
      e.preventDefault();
      closeM();
    }
  });
})();
</script>
<?php endif; ?>
<script>
(function () {
  var tabs = Array.prototype.slice.call(document.querySelectorAll('[data-ticket-tab]'));
  var panels = Array.prototype.slice.call(document.querySelectorAll('[data-ticket-panel]'));
  if (!tabs.length || !panels.length) return;

  function activate(key) {
    tabs.forEach(function (tab) {
      tab.classList.toggle('active', tab.getAttribute('data-ticket-tab') === key);
    });
    panels.forEach(function (panel) {
      panel.classList.toggle('active', panel.getAttribute('data-ticket-panel') === key);
    });
    if (window.osTicketMap && key === 'geral') {
      setTimeout(function () { window.osTicketMap.invalidateSize(); }, 60);
    }
  }

  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
      activate(tab.getAttribute('data-ticket-tab'));
    });
  });
})();
</script>

<?php if (!empty($loadLeaflet)): ?>
<script>
(function () {
  var lat = <?= json_encode((float) $os['latitude']) ?>;
  var lng = <?= json_encode((float) $os['longitude']) ?>;
  var map = L.map('os-map-mini', { scrollWheelZoom: false });
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OSM' }).addTo(map);
  L.marker([lat, lng]).addTo(map);
  map.setView([lat, lng], 15);
  window.osTicketMap = map;
})();
</script>
<?php endif; ?>
<?php
$loadComposer = db_ok();
include __DIR__ . '/../includes/footer.php';
