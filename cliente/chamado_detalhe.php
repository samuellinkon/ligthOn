<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/upload.php';
$user = require_auth('cliente');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_cliente('chamados');

$pageTitle  = 'Chamado';
$basePath   = '../';
$activePage = 'chamados';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: chamados.php');
    exit;
}

$clienteId = (int) ($user['cliente_id'] ?? 0);

/* --------------------------------------------------------------
 * POST handlers
 * ------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!db_ok()) {
        flash_set('err', 'Banco indisponível.');
        header('Location: chamado_detalhe.php?id=' . $id);
        exit;
    }
    try {
        $texto  = trim($_POST['resposta'] ?? '');
        $temArq = !empty($_FILES['anexos']['name'][0]);

        if ($texto === '' && !$temArq) {
            flash_set('err', 'Escreva uma mensagem ou selecione ao menos um anexo.');
        } else {
            $chamadoCheck = repo_chamado($id);
            if (!$chamadoCheck || !repo_cliente_pertence_empresa((int) $chamadoCheck['cliente_id'], $clienteId)) {
                flash_set('err', 'Chamado não encontrado.');
                header('Location: chamados.php');
                exit;
            }

            $respostaId = null;
            if ($texto !== '') {
                $respostaId = repo_create_chamado_resposta($id, $user['nome'], 'cliente', $texto, false);
            }
            if ($temArq) {
                $destino = upload_dir_chamado($id);
                $res     = upload_gravar_multiplos($_FILES['anexos'], $destino);
                foreach ($res['salvos'] as $arq) {
                    repo_create_chamado_anexo([
                        'chamado_id'    => $id,
                        'resposta_id'   => $respostaId,
                        'nome_original' => $arq['nome_original'],
                        'nome_arquivo'  => $arq['nome_arquivo'],
                        'mime'          => $arq['mime'],
                        'tamanho'       => $arq['tamanho'],
                        'enviado_por'   => $user['nome'] ?? 'Cliente',
                        'enviado_tipo'  => 'cliente',
                    ]);
                }
                if ($res['erros']) {
                    flash_set('err', 'Falhas em anexos: ' . implode(' | ', $res['erros']));
                }
            }
            if (($chamadoCheck['status'] ?? '') === 'Aguardando') {
                repo_update_chamado_status($id, 'Em andamento');
            }
            flash_set('ok', 'Mensagem enviada ao suporte.');
        }
    } catch (Throwable $e) {
        flash_set('err', 'Falha: ' . $e->getMessage());
    }
    header('Location: chamado_detalhe.php?id=' . $id);
    exit;
}

/* --------------------------------------------------------------
 * Carga
 * ------------------------------------------------------------- */
if (db_ok()) {
    $chamado = repo_chamado($id);
    if (!$chamado || !repo_cliente_pertence_empresa((int) $chamado['cliente_id'], $clienteId)) {
        header('Location: chamados.php');
        exit;
    }
    $respostas = repo_chamado_respostas_do_ticket($id, true);
    $chamadoItens = repo_chamado_itens_list($id);
    $valorChamado = repo_chamado_itens_valor_total($id);
} else {
    $chamado = find_by_id($MOCK_CHAMADOS, $id);
    if (!$chamado || (int) $chamado['cliente_id'] !== $clienteId) {
        header('Location: chamados.php');
        exit;
    }
    $respostas = array_values(array_filter(
        $MOCK_CHAMADO_RESPOSTAS[$chamado['id']] ?? [],
        fn ($r) => empty($r['interna'])
    ));
    $chamadoItens = [];
    $valorChamado = 0.0;
}

$anexos = db_ok() ? repo_chamado_anexos($chamado['id']) : [];

$anexosPorResposta = [];
foreach ($anexos as $a) {
    if (!empty($a['resposta_id'])) {
        $anexosPorResposta[(int) $a['resposta_id']][] = $a;
    }
}

$imagensAnexos = [];
$arquivosAnexos = [];
foreach ($anexos as $a) {
    $mime = strtolower((string) ($a['mime'] ?? ''));
    $nome = strtolower((string) ($a['nome_original'] ?? ''));
    $ehImagem = strpos($mime, 'image/') === 0
        || (bool) preg_match('/\.(png|jpe?g|gif|webp|bmp)$/i', $nome);
    if ($ehImagem) {
        $imagensAnexos[] = $a;
    } else {
        $arquivosAnexos[] = $a;
    }
}

$topTitle    = 'Chamado #' . $chamado['id'];
$topSubtitle = $chamado['titulo'];
$topSearch   = 'Buscar em meus chamados...';
$topAction   = ['label' => 'Voltar', 'href' => 'chamados.php', 'icon' => '←'];
$loadLeaflet = db_ok()
    && isset($chamado['latitude'], $chamado['longitude'])
    && $chamado['latitude'] !== null
    && $chamado['longitude'] !== null;

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
    .cliente-gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px}
    .cliente-gallery a{display:block;border:1px solid var(--border);border-radius:18px;overflow:hidden;background:#fff;text-decoration:none;color:inherit}
    .cliente-gallery img{width:100%;height:140px;object-fit:cover;display:block;background:#f8fafc}
    .cliente-gallery span{display:block;padding:10px;font-size:12px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .print-only{display:none}
    @media print{
      body{background:#fff!important}
      .sidebar,.sidebar-overlay,.topbar,.admin-tabs,.ticket-actions,.composer,.no-print{display:none!important}
      .main{margin:0!important;padding:0!important}
      .content{padding:0!important}
      .card,.ticket-header{box-shadow:none!important;border:1px solid #ddd!important;break-inside:avoid}
      .cliente-ticket-panel{display:block!important}
      .print-only{display:block!important}
      a{color:#111;text-decoration:none}
    }
  </style>

  <div class="print-only" style="margin-bottom:18px;">
    <h1 style="margin:0 0 6px;">OS / Chamado #<?= (int) $chamado['id'] ?></h1>
    <p style="margin:0;color:#555;"><?= htmlspecialchars((string) ($chamado['titulo'] ?? '')) ?></p>
  </div>

  <div class="ticket-header">
    <div>
      <div class="ticket-id">CHAMADO #<?= (int) $chamado['id'] ?></div>
      <h2><?= htmlspecialchars($chamado['titulo']) ?></h2>
      <div class="ticket-meta">
        <span class="badge <?= status_class($chamado['status']) ?>"><?= htmlspecialchars($chamado['status']) ?></span>
        <span class="badge <?= status_class($chamado['prioridade']) ?>">Prioridade: <?= htmlspecialchars($chamado['prioridade']) ?></span>
        <span class="badge badge-plain">Aberto em <?= date('d/m/Y H:i', strtotime($chamado['data'])) ?></span>
      </div>
    </div>
    <div class="ticket-actions no-print">
      <button type="button" class="btn btn-secondary" onclick="window.print()">Imprimir OS/Chamado</button>
      <a href="chamados.php" class="btn btn-primary">Voltar</a>
    </div>
  </div>

  <nav class="admin-tabs card mb-24 cliente-ticket-tabs no-print" aria-label="Abas do chamado">
    <button type="button" class="cliente-ticket-tab active" data-ticket-tab="geral">Geral</button>
    <button type="button" class="cliente-ticket-tab" data-ticket-tab="historico">Histórico</button>
    <button type="button" class="cliente-ticket-tab" data-ticket-tab="itens">Itens</button>
    <button type="button" class="cliente-ticket-tab" data-ticket-tab="arquivos">Arquivos e imagens</button>
  </nav>

  <div class="cliente-ticket-panel active" data-ticket-panel="geral">
    <div class="ticket-body" style="grid-template-columns:minmax(0,1fr) 340px;">
      <div class="flex-col" style="gap:18px;">
      <div class="card">
        <div class="panel-head"><h4>Descrição enviada</h4></div>
        <div class="panel-body">
          <p style="color:var(--muted); line-height:1.65;"><?= nl2br(htmlspecialchars((string) ($chamado['descricao'] ?? ''))) ?></p>
        </div>
      </div>

      <?php if (!empty($chamado['endereco_completo']) || !empty($loadLeaflet)): ?>
      <div class="card">
        <div class="panel-head"><h4>Local do chamado</h4></div>
        <div class="panel-body">
          <?php if (!empty($chamado['endereco_completo'])): ?>
            <p style="color:var(--muted); line-height:1.65; margin:0 0 12px;"><?= nl2br(htmlspecialchars((string) $chamado['endereco_completo'])) ?></p>
          <?php else: ?>
            <p class="muted" style="margin:0 0 12px;">Nenhum endereço informado neste chamado.</p>
          <?php endif; ?>
          <?php if (!empty($loadLeaflet)): ?>
            <div id="chamado-map-mini" class="chamado-map-mini" aria-label="Mapa"></div>
            <a class="btn btn-secondary" style="margin-top:10px;" target="_blank" rel="noopener"
               href="https://www.openstreetmap.org/?mlat=<?= urlencode((string) $chamado['latitude']) ?>&amp;mlon=<?= urlencode((string) $chamado['longitude']) ?>&amp;zoom=16">Ver no mapa externo</a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
      </div>

      <aside class="flex-col" style="gap:18px;">
      <div class="card">
        <div class="panel-head"><h4>Detalhes</h4></div>
        <div class="panel-body flex-col" style="gap:12px;">
          <div class="info-row"><span>Status</span><strong><?= htmlspecialchars((string) ($chamado['status'] ?? '')) ?></strong></div>
          <div class="info-row"><span>Prioridade</span><strong><?= htmlspecialchars((string) ($chamado['prioridade'] ?? '')) ?></strong></div>
          <div class="info-row"><span>Responsável</span><strong><?= htmlspecialchars((string) ($chamado['responsavel'] ?? '—')) ?></strong></div>
          <div class="info-row"><span>Aberto em</span><strong><?= date('d/m/Y H:i', strtotime($chamado['data'])) ?></strong></div>
          <div class="info-row"><span>Valor</span><strong>R$ <?= number_format((float) $valorChamado, 2, ',', '.') ?></strong></div>
        </div>
      </div>
      </aside>
    </div>
  </div>

  <div class="cliente-ticket-panel" data-ticket-panel="historico">
    <div class="card">
      <div class="panel-head">
        <h4>Histórico do chamado</h4>
        <span class="panel-sub"><?= count($respostas) ?> mensagem(s)</span>
      </div>

      <div class="thread">
        <?php if (empty($respostas)): ?>
          <div class="empty-state" style="padding:20px;">
            <div class="empty-icon">💬</div>
            <p>Aguardando resposta do suporte.</p>
          </div>
        <?php else: foreach ($respostas as $r): ?>
          <div class="msg <?= ($r['tipo'] ?? '') === 'cliente' ? 'me' : '' ?>">
            <div class="avatar avatar-sm"><?= initials($r['autor']) ?></div>
            <div class="bubble">
              <div class="bubble-head">
                <strong><?= htmlspecialchars($r['autor']) ?></strong>
                <span><?= date('d/m/Y H:i', strtotime($r['data'])) ?></span>
              </div>
              <p><?= nl2br(htmlspecialchars($r['texto'])) ?></p>
              <?php
                $anexosMsg = $anexosPorResposta[(int) ($r['id'] ?? 0)] ?? [];
                if ($anexosMsg):
              ?>
                <div class="bubble-files">
                  <?php foreach ($anexosMsg as $ax): ?>
                    <a class="file-chip" href="chamado_download.php?id=<?= (int) $ax['id'] ?>">
                      <span class="file-chip-ico"><?= upload_icone_por_ext($ax['nome_original']) ?></span>
                      <span>
                        <strong><?= htmlspecialchars($ax['nome_original']) ?></strong>
                        <small><?= upload_formatar_tamanho($ax['tamanho']) ?></small>
                      </span>
                    </a>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <?php if (db_ok()): ?>
      <form class="composer no-print" method="post" enctype="multipart/form-data" id="composer-form">
        <textarea class="textarea" id="composer-text" name="resposta"
                  placeholder="Adicione um comentário para o suporte..."></textarea>

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
      <?php else: ?>
      <p class="muted" style="padding:16px;">Para responder neste chamado é necessário o banco de dados ativo.</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="cliente-ticket-panel" data-ticket-panel="itens">
    <div class="card">
      <div class="panel-head" style="flex-wrap:wrap;gap:10px;">
        <div>
          <h4>Itens do chamado</h4>
          <span class="panel-sub">Produtos/serviços usados e devolvidos no atendimento</span>
        </div>
        <strong>Valor: R$ <?= number_format((float) $valorChamado, 2, ',', '.') ?></strong>
      </div>
      <div class="panel-body">
        <?php if (!empty($chamado['checklist_realizado'])): ?>
          <div class="panel-note" style="margin-bottom:16px;">
            <div class="panel-head"><h4>Checklist realizado</h4></div>
            <div class="panel-body">
              <p style="margin:0;color:var(--muted);line-height:1.6;"><?= nl2br(htmlspecialchars((string) $chamado['checklist_realizado'])) ?></p>
            </div>
          </div>
        <?php endif; ?>
        <?php if (empty($chamadoItens)): ?>
          <p class="muted" style="margin:0;">Nenhum item foi lançado neste chamado.</p>
        <?php else: ?>
          <div class="table-wrap chamado-itens-table chamado-itens-table--cliente">
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
                <?php foreach ($chamadoItens as $it): ?>
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

  <div class="cliente-ticket-panel" data-ticket-panel="arquivos">
      <div class="card">
        <div class="panel-head">
          <h4>Arquivos e imagens</h4>
          <span class="panel-sub"><?= count($imagensAnexos) ?> imagem(ns) · <?= count($arquivosAnexos) ?> arquivo(s)</span>
        </div>
        <div class="panel-body flex-col" style="gap:18px;">
          <?php if (empty($anexos)): ?>
            <div class="empty-state" style="padding:16px 0;">
              <div class="empty-icon">📎</div>
              <p style="font-size:14px;">Nenhum anexo ainda.</p>
            </div>
          <?php else: ?>
            <?php if (!empty($imagensAnexos)): ?>
              <div>
                <h4 style="margin:0 0 12px;">Galeria de imagens</h4>
                <div class="cliente-gallery">
                  <?php foreach ($imagensAnexos as $img): ?>
                    <a href="chamado_download.php?id=<?= (int) $img['id'] ?>" target="_blank" rel="noopener">
                      <img src="chamado_download.php?id=<?= (int) $img['id'] ?>" alt="<?= htmlspecialchars((string) $img['nome_original']) ?>">
                      <span><?= htmlspecialchars((string) $img['nome_original']) ?></span>
                    </a>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>
            <?php if (!empty($arquivosAnexos)): ?>
              <div>
                <h4 style="margin:0 0 12px;">Outros arquivos</h4>
                <?php foreach ($arquivosAnexos as $a): ?>
                  <div class="file-item">
                    <span class="file-item__meta">
                      <?= upload_icone_por_ext($a['nome_original']) ?>
                      <strong><?= htmlspecialchars($a['nome_original']) ?></strong><br>
                      <small class="muted">
                        <?= upload_formatar_tamanho($a['tamanho']) ?>
                        · <?= htmlspecialchars($a['enviado_em']) ?>
                      </small>
                    </span>
                    <div class="file-item-actions">
                      <a class="btn btn-primary btn-sm" href="chamado_download.php?id=<?= (int) $a['id'] ?>">Baixar</a>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
  </div>
</section>

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
    if (window.ticketMap && key === 'geral') {
      setTimeout(function () { window.ticketMap.invalidateSize(); }, 60);
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
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script>
(function () {
  var lat = <?= json_encode((float) $chamado['latitude']) ?>;
  var lng = <?= json_encode((float) $chamado['longitude']) ?>;
  var map = L.map('chamado-map-mini', { scrollWheelZoom: false });
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OSM' }).addTo(map);
  L.marker([lat, lng]).addTo(map);
  map.setView([lat, lng], 15);
  window.ticketMap = map;
})();
</script>
<?php endif; ?>
<?php
$loadComposer = db_ok();
include __DIR__ . '/../includes/footer.php';
?>
