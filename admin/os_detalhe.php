<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/medicao_helpers.php';

$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('os');

$pageTitle  = 'OS';
$basePath   = '../';
$activePage = 'os';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: os.php');
    exit;
}

if (db_ok()) {
    $osAuth = repo_os_pedido($id);
    if (!$osAuth) {
        header('Location: os.php');
        exit;
    }
    gestor_assert_escopo_cliente((int) ($osAuth['cliente_id'] ?? 0), 'os.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && db_ok()) {
    try {
        $acao = $_POST['acao'] ?? '';
        $os0  = repo_os_pedido($id);
        if (!$os0) {
            flash_set('err', 'OS não encontrada.');
            header('Location: os.php');
            exit;
        }
        gestor_assert_escopo_cliente((int) $os0['cliente_id'], 'os.php');

        if ($acao === 'responder') {
            $texto   = trim((string) ($_POST['resposta'] ?? ''));
            $interna = isset($_POST['interna']) && (string) $_POST['interna'] === '1';
            $temArq  = !empty($_FILES['anexos']['name'][0]);
            if ($texto === '' && !$temArq) {
                flash_set('err', 'Mensagem ou anexo necessário.');
            } else {
                $rid   = null;
                $nAnex = 0;
                $erros = [];
                if ($texto !== '') {
                    $rid = repo_os_pedido_resposta_criar($id, (string) ($me['nome'] ?? 'Admin'), 'admin', $texto, $interna);
                }
                if ($temArq) {
                    $dest = upload_dir_os_pedido($id);
                    $up   = upload_gravar_multiplos($_FILES['anexos'], $dest);
                    $erros = $up['erros'] ?? [];
                    foreach ($up['salvos'] ?? [] as $arq) {
                        repo_os_pedido_anexo_criar([
                            'os_pedido_id'  => $id,
                            'resposta_id'   => $rid,
                            'nome_original' => $arq['nome_original'],
                            'nome_arquivo'  => $arq['nome_arquivo'],
                            'mime'          => $arq['mime'],
                            'tamanho'       => $arq['tamanho'],
                            'enviado_por'   => $me['nome'] ?? 'Admin',
                            'enviado_tipo'  => 'admin',
                        ]);
                        $nAnex++;
                    }
                }
                if ($erros !== []) {
                    flash_set('err', 'Anexos: ' . implode(' | ', $erros));
                } elseif ($texto !== '' && $nAnex > 0) {
                    flash_set('ok', 'Mensagem e anexos enviados.');
                } elseif ($nAnex > 0) {
                    flash_set('ok', 'Anexo(s) enviado(s).');
                } elseif ($texto !== '') {
                    flash_set('ok', $interna ? 'Nota interna registrada.' : 'Mensagem registrada.');
                }
            }
        } elseif ($acao === 'salvar_rascunho') {
            $r = repo_os_pedido_atualizar_rascunho($id, [
                'ref_ym'            => (string) ($_POST['ref_ym'] ?? ''),
                'titulo'            => (string) ($_POST['titulo'] ?? ''),
                'descricao'         => (string) ($_POST['descricao'] ?? ''),
                'endereco_completo' => (string) ($_POST['endereco_completo'] ?? ''),
                'latitude'          => $_POST['latitude']  ?? '',
                'longitude'         => $_POST['longitude'] ?? '',
                'prioridade'        => (string) ($_POST['prioridade'] ?? 'Normal'),
                'responsavel'       => (string) ($_POST['responsavel'] ?? ''),
                'servico_id'        => (int) ($_POST['servico_id'] ?? 0),
            ]);
            flash_set($r['ok'] ? 'ok' : 'err', $r['ok'] ? 'Dados salvos.' : $r['err']);
        } elseif ($acao === 'enviar_cliente') {
            $r = repo_os_pedido_enviar_cliente($id);
            flash_set($r['ok'] ? 'ok' : 'err', $r['ok'] ? 'OS enviada ao cliente para aprovação.' : $r['err']);
        } elseif ($acao === 'concluir') {
            $r = repo_os_pedido_marcar_concluida($id);
            flash_set($r['ok'] ? 'ok' : 'err', $r['ok'] ? 'OS concluída.' : $r['err']);
        } elseif ($acao === 'cancelar') {
            $r = repo_os_pedido_cancelar($id);
            flash_set($r['ok'] ? 'ok' : 'err', $r['ok'] ? 'OS cancelada.' : $r['err']);
        } elseif ($acao === 'excluir') {
            if (repo_os_pedido_excluir($id)) {
                flash_set('ok', 'OS excluída.');
                header('Location: os.php');
                exit;
            }
            flash_set('err', 'Só é possível excluir em Rascunho.');
        } elseif ($acao === 'excluir_anexo') {
            $anexoId = (int) ($_POST['anexo_id'] ?? 0);
            $a       = $anexoId > 0 ? repo_os_pedido_anexo_excluir($anexoId, $id) : null;
            if ($a) {
                $p = __DIR__ . '/../uploads/os_pedidos/' . $id . '/' . $a['nome_arquivo'];
                if (is_file($p)) {
                    @unlink($p);
                }
                flash_set('ok', 'Anexo removido.');
            } else {
                flash_set('err', 'Não foi possível remover o anexo.');
            }
        } elseif ($acao === 'os_item_add') {
            $r = repo_os_pedido_item_adicionar(
                $id,
                (int) ($_POST['item_id'] ?? 0),
                (float) str_replace(',', '.', (string) ($_POST['quantidade'] ?? '1')),
                (string) ($_POST['movimento'] ?? 'utilizado'),
                trim((string) ($_POST['observacao'] ?? '')) !== '' ? trim((string) $_POST['observacao']) : null
            );
            flash_set($r['ok'] ? 'ok' : 'err', $r['ok'] ? 'Item adicionado.' : $r['err']);
        } elseif ($acao === 'os_item_qtd') {
            $lid = (int) ($_POST['linha_id'] ?? 0);
            $q   = (float) str_replace(',', '.', (string) ($_POST['quantidade'] ?? '1'));
            if ($lid > 0 && $q > 0 && repo_os_pedido_item_atualizar_quantidade($lid, $id, $q)) {
                flash_set('ok', 'Quantidade atualizada.');
            } else {
                flash_set('err', 'Não foi possível atualizar.');
            }
        } elseif ($acao === 'os_item_del') {
            $lid = (int) ($_POST['linha_id'] ?? 0);
            if ($lid > 0 && repo_os_pedido_item_remover($lid, $id)) {
                flash_set('ok', 'Linha removida.');
            } else {
                flash_set('err', 'Não foi possível remover.');
            }
        }
    } catch (Throwable $e) {
        flash_set('err', 'Falha: ' . $e->getMessage());
    }
    header('Location: os_detalhe.php?id=' . $id);
    exit;
}

$itens = [];
$total = 0.0;
$cat   = [];
$respostas = [];
$anexos    = [];
$empresaId = 0;
$st        = '';

if (db_ok()) {
    $os = repo_os_pedido($id);
    if (!$os) {
        header('Location: os.php');
        exit;
    }
    $st   = (string) ($os['status'] ?? '');
    $itens = repo_os_pedido_itens_list($id);
    $total = repo_os_pedido_itens_valor_total($id);
    $empresaId = repo_cliente_catalogo_dono_id((int) $os['cliente_id']);
    if ($empresaId > 0) {
        $cat = repo_cliente_itens_list((int) $os['cliente_id'], true);
    }
    $respostas = repo_os_pedido_respostas($id, false);
    $anexos    = repo_os_pedido_anexos($id);
} else {
    header('Location: os.php');
    exit;
}

$anexosPorResposta = [];
foreach ($anexos as $a) {
    if (!empty($a['resposta_id'])) {
        $anexosPorResposta[(int) $a['resposta_id']][] = $a;
    }
}
$cliente = repo_cliente((int) $os['cliente_id']);
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

$topTitle    = 'OS #' . $id;
$topSubtitle = (string) $os['titulo'];
$topAction   = ['label' => 'Voltar', 'href' => 'os_mes.php?mes=' . rawurlencode((string) $os['ref_ym']), 'icon' => '←'];
$loadLeaflet = db_ok() && $os['latitude'] !== null && $os['latitude'] !== '' && $os['longitude'] !== null && $os['longitude'] !== '';

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
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
    <h1 style="margin:0 0 6px;">OS #<?= (int) $os['id'] ?></h1>
    <p style="margin:0;color:#555;"><?= htmlspecialchars((string) $os['titulo']) ?></p>
  </div>

  <div class="ticket-header">
    <div>
      <div class="ticket-id">OS #<?= (int) $os['id'] ?> · <?= htmlspecialchars(medicao_mes_label_pt((string) $os['ref_ym'])) ?></div>
      <h2><?= htmlspecialchars((string) $os['titulo']) ?></h2>
      <div class="ticket-meta">
        <span class="badge <?= status_class($st) ?>"><?= htmlspecialchars($st) ?></span>
        <span class="badge <?= status_class((string) ($os['prioridade'] ?? 'Normal')) ?>">Prioridade: <?= htmlspecialchars((string) ($os['prioridade'] ?? 'Normal')) ?></span>
        <span class="badge badge-plain">Criada em <?= date('d/m/Y H:i', strtotime((string) $os['aberto_em'])) ?></span>
        <?php if (!empty($os['enviada_cliente_em'])): ?>
          <span class="badge info">Enviada <?= date('d/m/Y H:i', strtotime((string) $os['enviada_cliente_em'])) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="ticket-actions no-print" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
      <button type="button" class="btn btn-secondary" onclick="window.print()">Imprimir OS</button>
      <?php if (in_array($st, ['Rascunho', 'Rejeitada'], true)): ?>
        <form method="post" style="display:inline;" data-confirm="Enviar ao cliente para aprovação?">
          <input type="hidden" name="acao" value="enviar_cliente">
          <button class="btn btn-primary" type="submit">Enviar ao cliente</button>
        </form>
      <?php endif; ?>
      <?php if ($st === 'Aprovada'): ?>
        <form method="post" style="display:inline;" data-confirm="Marcar como concluída?">
          <input type="hidden" name="acao" value="concluir">
          <button class="btn btn-primary" type="submit">Concluir OS</button>
        </form>
      <?php endif; ?>
      <?php if (!in_array($st, ['Concluida', 'Cancelada'], true)): ?>
        <form method="post" style="display:inline;" data-confirm="Cancelar esta OS?" data-confirm-danger>
          <input type="hidden" name="acao" value="cancelar">
          <button class="btn btn-secondary" type="submit">Cancelar</button>
        </form>
      <?php endif; ?>
      <?php if ($st === 'Rascunho'): ?>
        <form method="post" style="display:inline;" data-confirm="Excluir definitivamente esta OS?" data-confirm-danger>
          <input type="hidden" name="acao" value="excluir">
          <button class="btn btn-danger" type="submit">Excluir rascunho</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <nav class="admin-tabs card mb-24 cliente-ticket-tabs no-print" aria-label="Abas da OS">
    <button type="button" class="cliente-ticket-tab active" data-ticket-tab="geral">Geral</button>
    <button type="button" class="cliente-ticket-tab" data-ticket-tab="historico">Histórico</button>
    <button type="button" class="cliente-ticket-tab" data-ticket-tab="itens">Itens</button>
    <button type="button" class="cliente-ticket-tab" data-ticket-tab="arquivos">Arquivos e imagens</button>
  </nav>

  <div class="cliente-ticket-panel active" data-ticket-panel="geral">
    <div class="ticket-body" style="grid-template-columns:minmax(0,1fr) 360px;">
      <div class="flex-col" style="gap:18px;">
        <?php if ($st === 'Rejeitada' && !empty($os['rejeitada_motivo'])): ?>
          <div class="card" style="border-color: var(--danger, #e24b4a);">
            <div class="panel-head"><h4>Rejeitada pelo cliente</h4></div>
            <div class="panel-body"><p class="muted" style="margin:0;white-space:pre-wrap;"><?= htmlspecialchars((string) $os['rejeitada_motivo']) ?></p></div>
          </div>
        <?php endif; ?>

        <?php if (in_array($st, ['Rascunho', 'Rejeitada'], true)): ?>
          <form class="card" method="post" action="os_detalhe.php?id=<?= (int) $id ?>">
            <input type="hidden" name="acao" value="salvar_rascunho">
            <div class="panel-head"><h4>Dados da OS</h4><span class="panel-sub">Edite antes de enviar ao cliente</span></div>
            <div class="panel-body form form-grid">
              <div class="form-group">
                <label for="ref_ym2">Mês de referência</label>
                <input type="month" class="input" id="ref_ym2" name="ref_ym" value="<?= htmlspecialchars((string) $os['ref_ym']) ?>" required>
              </div>
              <div class="form-group">
                <label>Prioridade</label>
                <select class="select" name="prioridade">
                  <?php foreach (['Baixa', 'Normal', 'Alta', 'Urgente'] as $pr): ?>
                    <option value="<?= $pr ?>" <?= ($os['prioridade'] ?? '') === $pr ? 'selected' : '' ?>><?= $pr ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group full">
                <label for="titulo2">Título</label>
                <input class="input" id="titulo2" name="titulo" value="<?= htmlspecialchars((string) $os['titulo']) ?>" required placeholder="Resumo da ordem de serviço">
              </div>
              <div class="form-group full">
                <label for="desc2">Descrição</label>
                <textarea class="textarea" id="desc2" name="descricao" rows="4" placeholder="Detalhes do serviço, materiais, observações"><?= htmlspecialchars((string) ($os['descricao'] ?? '')) ?></textarea>
              </div>
              <div class="form-group full">
                <label for="end2">Endereço / local</label>
                <textarea class="textarea" id="end2" name="endereco_completo" rows="2" placeholder="Endereço ou local do serviço"><?= htmlspecialchars((string) ($os['endereco_completo'] ?? '')) ?></textarea>
              </div>
              <div class="form-group">
                <label for="resp2">Responsável interno</label>
                <input class="input" id="resp2" name="responsavel" value="<?= htmlspecialchars((string) ($os['responsavel'] ?? '')) ?>" placeholder="Nome do responsável interno">
              </div>
              <div class="form-group">
                <label>Latitude</label>
                <input class="input" name="latitude" value="<?= $os['latitude'] !== null && $os['latitude'] !== '' ? htmlspecialchars((string) $os['latitude']) : '' ?>" placeholder="-8.123456">
              </div>
              <div class="form-group">
                <label>Longitude</label>
                <input class="input" name="longitude" value="<?= $os['longitude'] !== null && $os['longitude'] !== '' ? htmlspecialchars((string) $os['longitude']) : '' ?>" placeholder="-35.123456">
              </div>
              <div class="form-group full">
                <label for="serv2">Item principal do catálogo (opcional)</label>
                <select class="select" name="servico_id" id="serv2">
                  <option value="0">— Nenhum —</option>
                  <?php foreach ($cat as $ci): ?>
                    <option value="<?= (int) $ci['id'] ?>" <?= (int) ($os['servico_id'] ?? 0) === (int) $ci['id'] ? 'selected' : '' ?>>
                      <?= htmlspecialchars((string) ($ci['nome'] ?? '')) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group full form-actions" style="margin:0;padding-top:8px;">
                <button type="submit" class="btn btn-primary">Salvar alterações</button>
              </div>
            </div>
          </form>
        <?php else: ?>
          <div class="card">
            <div class="panel-head"><h4>Descrição</h4></div>
            <div class="panel-body"><p style="color:var(--muted);line-height:1.65;margin:0;"><?= nl2br(htmlspecialchars((string) ($os['descricao'] ?? '—'))) ?></p></div>
          </div>
          <?php if (!empty($os['endereco_completo']) || !empty($loadLeaflet)): ?>
          <div class="card">
            <div class="panel-head"><h4>Local da OS</h4></div>
            <div class="panel-body">
              <?php if (!empty($os['endereco_completo'])): ?>
                <p style="color:var(--muted);line-height:1.65;margin:0 0 12px;"><?= nl2br(htmlspecialchars((string) $os['endereco_completo'])) ?></p>
              <?php endif; ?>
              <?php if ($loadLeaflet): ?>
                <div id="os-map-mini" class="os-map-mini" aria-label="Mapa"></div>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <aside class="flex-col" style="gap:18px;">
        <div class="card">
          <div class="panel-head"><h4>Informações</h4></div>
          <div class="panel-body flex-col" style="gap:12px;">
            <div class="info-row"><span>Status</span><strong><?= htmlspecialchars($st) ?></strong></div>
            <div class="info-row"><span>Prefeitura</span><strong><a href="cliente_detalhe.php?id=<?= (int) $os['cliente_id'] ?>"><?= htmlspecialchars((string) ($os['cliente_empresa'] ?? '')) ?></a></strong></div>
            <?php if ($cliente): ?>
              <div class="info-row"><span>Contato</span><strong><?= htmlspecialchars((string) ($cliente['nome'] ?? '')) ?></strong></div>
              <div class="info-row"><span>E-mail</span><strong><?= htmlspecialchars((string) ($cliente['email'] ?? '')) ?></strong></div>
              <div class="info-row"><span>Telefone</span><strong><?= htmlspecialchars((string) ($cliente['telefone'] ?? '')) ?></strong></div>
            <?php endif; ?>
            <div class="info-row"><span>Mês</span><strong><?= htmlspecialchars(medicao_mes_label_pt((string) $os['ref_ym'])) ?></strong></div>
            <div class="info-row"><span>Responsável</span><strong><?= htmlspecialchars((string) ($os['responsavel'] ?? '—')) ?></strong></div>
            <div class="info-row"><span>Valor</span><strong>R$ <?= number_format((float) $total, 2, ',', '.') ?></strong></div>
            <?php if (!empty($os['aprovada_cliente_em'])): ?>
              <div class="info-row"><span>Aprovada em</span><strong><?= date('d/m/Y H:i', strtotime((string) $os['aprovada_cliente_em'])) ?></strong></div>
            <?php endif; ?>
          </div>
        </div>

        <div class="card">
          <div class="panel-head"><h4>Anexos gerais</h4></div>
          <div class="panel-body">
            <?php $anexosGerais = array_values(array_filter($anexos, fn($x) => empty($x['resposta_id']))); ?>
            <?php if (empty($anexosGerais)): ?>
              <p class="muted" style="margin:0;">Nenhum anexo geral.</p>
            <?php else: ?>
              <div class="flex-col" style="gap:8px;">
                <?php foreach ($anexosGerais as $a): ?>
                  <div class="file-item">
                    <span class="file-item__meta">
                      <?= upload_icone_por_ext((string) $a['nome_original']) ?>
                      <strong><?= htmlspecialchars((string) $a['nome_original']) ?></strong><br>
                      <small class="muted"><?= upload_formatar_tamanho((int) ($a['tamanho'] ?? 0)) ?></small>
                    </span>
                    <div class="file-item-actions">
                      <a class="btn btn-primary btn-sm" href="os_download.php?id=<?= (int) $a['id'] ?>">Baixar</a>
                      <form method="post" data-confirm="Excluir este anexo?" data-confirm-danger style="display:inline;">
                        <input type="hidden" name="acao" value="excluir_anexo">
                        <input type="hidden" name="anexo_id" value="<?= (int) $a['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                      </form>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </aside>
    </div>
  </div>

  <div class="cliente-ticket-panel" data-ticket-panel="historico">
    <div class="card">
      <div class="panel-head">
        <h4>Histórico da OS</h4>
        <span class="panel-sub">O portal da prefeitura vê as mensagens não internas e anexos</span>
      </div>
      <div class="thread">
        <?php if (empty($respostas)): ?>
          <div class="empty-state" style="padding:20px;"><div class="empty-icon">💬</div><p>Sem mensagens.</p></div>
        <?php else: foreach ($respostas as $r): ?>
          <div class="msg <?= $r['tipo'] === 'admin' ? 'me' : '' ?> <?= !empty($r['interna']) ? 'msg-interna' : '' ?>">
            <div class="avatar avatar-sm"><?= initials($r['autor']) ?></div>
            <div class="bubble">
              <div class="bubble-head">
                <strong><?= htmlspecialchars((string) $r['autor']) ?></strong>
                <?php if (!empty($r['interna'])): ?><span class="badge urgent" style="font-size:10px;">Interna</span><?php endif; ?>
                <span><?= date('d/m/Y H:i', strtotime((string) $r['data'])) ?></span>
              </div>
              <p><?= nl2br(htmlspecialchars((string) $r['texto'])) ?></p>
              <?php $am = $anexosPorResposta[(int) ($r['id'] ?? 0)] ?? []; ?>
              <?php if ($am): ?>
                <div class="bubble-files">
                  <?php foreach ($am as $ax): ?>
                    <a class="file-chip" href="os_download.php?id=<?= (int) $ax['id'] ?>">
                      <span class="file-chip-ico"><?= upload_icone_por_ext((string) $ax['nome_original']) ?></span>
                      <span><strong><?= htmlspecialchars((string) $ax['nome_original']) ?></strong><small><?= upload_formatar_tamanho((int) ($ax['tamanho'] ?? 0)) ?></small></span>
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
        <input type="hidden" name="interna" id="composer-interna" value="">
        <textarea class="textarea" id="composer-text" name="resposta" placeholder="Mensagem ao portal da prefeitura..."></textarea>
        <div class="composer-files" id="composer-files" hidden>
          <strong>Arquivos selecionados:</strong>
          <ul id="composer-files-list"></ul>
        </div>
        <input type="file" id="composer-file-input" name="anexos[]" multiple hidden
               accept=".pdf,.doc,.docx,.odt,.rtf,.txt,.xls,.xlsx,.ods,.csv,.png,.jpg,.jpeg,.gif,.webp,.zip,.rar,.7z">
        <div class="composer-bar">
          <div class="composer-tools">
            <button type="button" class="tool" id="btn-anexo">+ Anexo</button>
            <button type="button" class="tool" id="btn-interna" title="Só equipe interna vê"><span class="dot"></span> Interna</button>
          </div>
          <button type="submit" class="btn btn-primary" id="btn-enviar">Enviar</button>
        </div>
      </form>
    </div>
  </div>

  <div class="cliente-ticket-panel" data-ticket-panel="itens">
    <div class="card">
      <div class="panel-head" style="flex-wrap:wrap;gap:10px;">
        <div>
          <h4>Itens e serviços</h4>
          <span class="panel-sub">Catálogo do cliente — ajuste enquanto a OS não estiver aprovada/encerrada</span>
        </div>
        <strong>Valor: R$ <?= number_format((float) $total, 2, ',', '.') ?></strong>
      </div>
      <div class="panel-body">
        <?php if (empty($itens)): ?>
          <p class="muted" style="margin:0 0 12px;">Nenhum item.</p>
        <?php else: ?>
          <div class="table-wrap" style="margin-bottom:16px;">
            <table>
              <thead>
                <tr>
                  <th>Movimento</th>
                  <th>Item</th>
                  <th class="text-right">Qtd</th>
                  <th class="text-right">V. unit.</th>
                  <th class="text-right">Subtotal</th>
                  <th class="text-right">Ações</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($itens as $lm): $podeEd = !in_array($st, ['Aprovada', 'Concluida', 'Cancelada'], true); ?>
                <tr>
                  <td><span class="badge <?= ($lm['movimento'] ?? '') === 'devolvido' ? 'info' : 'success' ?>"><?= ($lm['movimento'] ?? '') === 'devolvido' ? 'Devolvido' : 'Utilizado' ?></span></td>
                  <td><?= htmlspecialchars((string) ($lm['item_nome'] ?? '')) ?></td>
                  <td class="text-right">
                    <?php if ($podeEd): ?>
                      <form method="post" style="display:inline-flex;gap:4px;align-items:center;justify-content:flex-end;">
                        <input type="hidden" name="acao" value="os_item_qtd">
                        <input type="hidden" name="linha_id" value="<?= (int) $lm['id'] ?>">
                        <input type="text" name="quantidade" class="input" style="width:88px;" placeholder="Qtd."
                               value="<?= htmlspecialchars(rtrim(rtrim(sprintf('%.4f', (float) ($lm['quantidade'] ?? 0)), '0'), '.')) ?>">
                        <button type="submit" class="btn btn-secondary btn-sm">OK</button>
                      </form>
                    <?php else: ?>
                      <?= htmlspecialchars(rtrim(rtrim(sprintf('%.4f', (float) ($lm['quantidade'] ?? 0)), '0'), '.')) ?>
                    <?php endif; ?>
                  </td>
                  <td class="text-right td-mute">R$ <?= number_format((float) ($lm['valor_unitario'] ?? 0), 4, ',', '.') ?></td>
                  <td class="text-right"><strong>R$ <?= number_format((float) ($lm['subtotal'] ?? 0), 2, ',', '.') ?></strong></td>
                  <td class="text-right">
                    <?php if ($podeEd): ?>
                      <form method="post" style="display:inline;" data-confirm="Remover esta linha do catálogo?" data-confirm-danger>
                        <input type="hidden" name="acao" value="os_item_del">
                        <input type="hidden" name="linha_id" value="<?= (int) $lm['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <?php $podeIt = !in_array($st, ['Aprovada', 'Concluida', 'Cancelada'], true); ?>
        <?php if ($podeIt): ?>
          <datalist id="os-detalhe-catalogo-list">
            <?php foreach ($cat as $ci): ?>
              <option value="<?= htmlspecialchars((string) ($ci['nome'] ?? '')) ?> (R$ <?= number_format((float) ($ci['valor_unitario'] ?? 0), 2, ',', '.') ?>)" data-id="<?= (int) $ci['id'] ?>"></option>
            <?php endforeach; ?>
          </datalist>
          <form method="post" class="form" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
            <input type="hidden" name="acao" value="os_item_add">
            <div class="form-group" style="margin:0;width:130px;">
              <label class="small">Movimento</label>
              <select name="movimento" class="select">
                <option value="utilizado">Utilizado</option>
                <option value="devolvido">Devolvido</option>
              </select>
            </div>
            <div class="form-group" style="margin:0;flex:1;min-width:220px;">
              <label class="small">Catálogo</label>
              <input type="hidden" name="item_id" id="os-detalhe-item-id">
              <input type="text" class="input" id="os-detalhe-item-search" list="os-detalhe-catalogo-list" placeholder="Digite para buscar no catálogo" autocomplete="off" required>
            </div>
            <div class="form-group" style="margin:0;width:100px;">
              <label class="small">Qtd</label>
              <input name="quantidade" class="input" value="1" inputmode="decimal" placeholder="Ex.: 1">
            </div>
            <div class="form-group" style="margin:0;min-width:160px;flex:1;">
              <label class="small">Obs.</label>
              <input name="observacao" class="input" placeholder="opcional">
            </div>
            <button type="submit" class="btn btn-primary">Adicionar</button>
          </form>
          <?php if (empty($cat)): ?>
            <p class="muted" style="margin-top:12px;font-size:13px;">Sem itens no catálogo. <?php if ($empresaId > 0): ?><a href="catalogo.php?cliente_id=<?= (int) $empresaId ?>">Abrir catálogo</a><?php endif; ?></p>
          <?php endif; ?>
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
          <div class="empty-state" style="padding:16px 0;"><div class="empty-icon">📎</div><p style="font-size:14px;">Nenhum anexo ainda.</p></div>
        <?php else: ?>
          <?php if (!empty($imagensAnexos)): ?>
            <div>
              <h4 style="margin:0 0 12px;">Galeria de imagens</h4>
              <div class="cliente-gallery">
                <?php foreach ($imagensAnexos as $img): ?>
                  <a href="os_download.php?id=<?= (int) $img['id'] ?>" target="_blank" rel="noopener">
                    <img src="os_download.php?id=<?= (int) $img['id'] ?>" alt="<?= htmlspecialchars((string) $img['nome_original']) ?>">
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
                    <?= upload_icone_por_ext((string) $a['nome_original']) ?>
                    <strong><?= htmlspecialchars((string) $a['nome_original']) ?></strong><br>
                    <small class="muted"><?= upload_formatar_tamanho((int) ($a['tamanho'] ?? 0)) ?> · <?= htmlspecialchars((string) $a['enviado_em']) ?></small>
                  </span>
                  <div class="file-item-actions">
                    <a class="btn btn-primary btn-sm" href="os_download.php?id=<?= (int) $a['id'] ?>">Baixar</a>
                    <form method="post" data-confirm="Excluir este anexo?" data-confirm-danger style="display:inline;">
                      <input type="hidden" name="acao" value="excluir_anexo">
                      <input type="hidden" name="anexo_id" value="<?= (int) $a['id'] ?>">
                      <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                    </form>
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
    if (window.osAdminMap && key === 'geral') {
      setTimeout(function () { window.osAdminMap.invalidateSize(); }, 60);
    }
  }

  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
      activate(tab.getAttribute('data-ticket-tab'));
    });
  });

  var list = document.getElementById('os-detalhe-catalogo-list');
  var input = document.getElementById('os-detalhe-item-search');
  var hidden = document.getElementById('os-detalhe-item-id');
  if (list && input && hidden) {
    function itemIdFromValue(value) {
      var opts = list.querySelectorAll('option');
      for (var i = 0; i < opts.length; i++) {
        if (opts[i].value === value) {
          return opts[i].getAttribute('data-id') || '';
        }
      }
      return '';
    }
    input.addEventListener('input', function () {
      hidden.value = itemIdFromValue(input.value);
      input.setCustomValidity('');
    });
    var itemForm = input.closest('form');
    if (itemForm) {
      itemForm.addEventListener('submit', function (ev) {
        if (hidden.value === '') {
          input.setCustomValidity('Selecione um item válido da lista.');
          input.reportValidity();
          ev.preventDefault();
        }
      });
    }
  }
})();
</script>

<?php if (!empty($loadLeaflet)): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<?php include __DIR__ . '/../includes/partials/leaflet_basemap_script.php'; ?>
<script>
(function () {
  var lat = <?= json_encode((float) $os['latitude']) ?>;
  var lng = <?= json_encode((float) $os['longitude']) ?>;
  var map = L.map('os-map-mini', { scrollWheelZoom: false });
  if (window.CrmLeafletBasemap) {
    window.CrmLeafletBasemap.addTo(map, { maxZoom: 19 });
  }
  L.marker([lat, lng]).addTo(map);
  map.setView([lat, lng], 16);
  window.osAdminMap = map;
})();
</script>
<?php endif; ?>
<?php
$loadComposer = true;
include __DIR__ . '/../includes/footer.php';