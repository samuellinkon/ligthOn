<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/upload.php';
$user = require_auth('operador');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_operador('chamados');

$pageTitle   = 'Chamado';
$basePath    = '../';
$activePage  = 'chamados';
$operadorPwa = true;

$empresaId = operador_empresa_id($user);
$id        = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: chamados.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && db_ok() && $empresaId > 0) {
    $acao = (string) ($_POST['acao'] ?? '');
    $ch   = repo_chamado($id);
    if (!$ch || !repo_chamado_acessivel_operador_atribuido($id, $empresaId, (int) ($user['id'] ?? 0))) {
        flash_set('err', 'Chamado não encontrado.');
        header('Location: chamados.php');
        exit;
    }

    if ($acao === 'enviar_os') {
        $msgs = [];
        $falhas = [];

        $end = trim((string) ($_POST['endereco_completo'] ?? ''));
        if ($end !== '' && isset($ch['latitude'], $ch['longitude']) && $ch['latitude'] !== null && $ch['longitude'] !== null) {
            if (repo_update_chamado_localizacao($id, $end, (float) $ch['latitude'], (float) $ch['longitude'])) {
                $msgs[] = 'Endereço/referência salvo.';
            }
        }

        $itemId = (int) ($_POST['item_id'] ?? 0);
        if ($itemId > 0) {
            $qtd = (float) str_replace(',', '.', (string) ($_POST['quantidade'] ?? '1'));
            $mov = (string) ($_POST['movimento'] ?? 'utilizado');
            $obs = trim((string) ($_POST['observacao'] ?? ''));
            $rItem = repo_chamado_item_adicionar($id, $itemId, $qtd, $mov, $obs !== '' ? $obs : null);
            if ($rItem['ok']) {
                $msgs[] = 'Item lançado.';
            } else {
                $falhas[] = $rItem['err'];
            }
        }

        $temArq = !empty($_FILES['imagens']['name'][0]);
        if ($temArq) {
            $destino = upload_dir_chamado($id);
            $res = upload_gravar_multiplos($_FILES['imagens'], $destino);
            $salvos = 0;
            foreach ($res['salvos'] as $arq) {
                repo_create_chamado_anexo([
                    'chamado_id'    => $id,
                    'resposta_id'   => null,
                    'nome_original' => $arq['nome_original'],
                    'nome_arquivo'  => $arq['nome_arquivo'],
                    'mime'          => $arq['mime'],
                    'tamanho'       => $arq['tamanho'],
                    'enviado_por'   => $user['nome'] ?? 'Operador',
                    'enviado_tipo'  => 'operador',
                ]);
                $salvos++;
            }
            if ($salvos > 0) {
                $msgs[] = $salvos . ' imagem(ns) enviada(s).';
            }
            if (!empty($res['erros'])) {
                $falhas[] = 'Algumas imagens não foram aceitas: ' . implode(' | ', $res['erros']);
            }
        }

        if (empty($falhas)) {
            $nome = (string) ($user['nome'] ?? 'Operador');
            $r = repo_operador_chamado_finalizar($id, (int) ($user['id'] ?? 0), $empresaId, $nome);
            if ($r['ok']) {
                $msgs[] = 'OS enviada para aprovação.';
                flash_set('ok', implode(' ', $msgs));
            } else {
                flash_set('err', $r['err']);
            }
        } else {
            flash_set('err', implode(' | ', array_filter($falhas)));
        }

    } elseif ($acao === 'geo') {
        $latRaw = trim((string) ($_POST['latitude'] ?? ''));
        $lngRaw = trim((string) ($_POST['longitude'] ?? ''));
        $lat    = $latRaw !== '' ? (float) str_replace(',', '.', $latRaw) : null;
        $lng    = $lngRaw !== '' ? (float) str_replace(',', '.', $lngRaw) : null;
        $end    = trim((string) ($_POST['endereco_completo'] ?? ''));
        $endTxt = $end !== '' ? $end : null;
        if ($latRaw === '' && $lngRaw === '') {
            if (repo_update_chamado_localizacao($id, $endTxt, null, null)) {
                flash_set('ok', $endTxt !== null ? 'Endereço atualizado (coordenadas removidas).' : 'Coordenadas removidas.');
            } else {
                flash_set('err', 'Não foi possível salvar.');
            }
        } elseif ($lat === null || $lng === null) {
            flash_set('err', 'Informe latitude e longitude juntos, ou deixe ambos vazios para limpar.');
        } elseif (abs($lat) > 90 || abs($lng) > 180) {
            flash_set('err', 'Coordenadas fora do intervalo válido.');
        } else {
            if (repo_update_chamado_localizacao($id, $endTxt, $lat, $lng)) {
                flash_set('ok', 'Localização atualizada.');
            } else {
                flash_set('err', 'Não foi possível salvar a localização.');
            }
        }
    } elseif ($acao === 'servico') {
        $sid = (int) ($_POST['servico_id'] ?? 0);
        $sid = $sid > 0 ? $sid : null;
        if (repo_operador_chamado_set_servico($id, $empresaId, $sid)) {
            flash_set('ok', $sid ? 'Serviço vinculado ao chamado.' : 'Serviço removido do chamado.');
        } else {
            flash_set('err', 'Não foi possível atualizar o serviço (verifique se está ativo no cadastro do cliente).');
        }
    } elseif ($acao === 'finalizar') {
        $nome = (string) ($user['nome'] ?? 'Operador');
        $r    = repo_operador_chamado_finalizar($id, (int) ($user['id'] ?? 0), $empresaId, $nome);
        flash_set($r['ok'] ? 'ok' : 'err', $r['ok'] ? 'Chamado marcado como finalizado.' : $r['err']);
    } elseif ($acao === 'chamado_item_add') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $qtd    = (float) str_replace(',', '.', (string) ($_POST['quantidade'] ?? '1'));
        $mov    = (string) ($_POST['movimento'] ?? 'utilizado');
        $obs    = trim((string) ($_POST['observacao'] ?? ''));
        $r      = repo_chamado_item_adicionar($id, $itemId, $qtd, $mov, $obs !== '' ? $obs : null);
        flash_set($r['ok'] ? 'ok' : 'err', $r['ok'] ? 'Item lançado.' : $r['err']);
    } elseif ($acao === 'chamado_item_qtd') {
        $lid = (int) ($_POST['linha_id'] ?? 0);
        $qtd = (float) str_replace(',', '.', (string) ($_POST['quantidade'] ?? '1'));
        if ($lid > 0 && $qtd > 0 && repo_chamado_item_atualizar_quantidade($lid, $id, $qtd)) {
            flash_set('ok', 'Quantidade atualizada.');
        } else {
            flash_set('err', 'Não foi possível atualizar.');
        }
    } elseif ($acao === 'chamado_item_del') {
        $lid = (int) ($_POST['linha_id'] ?? 0);
        if ($lid > 0 && repo_chamado_item_remover($lid, $id)) {
            flash_set('ok', 'Linha removida.');
        } else {
            flash_set('err', 'Não foi possível remover.');
        }
    } elseif ($acao === 'fotos') {
        $temArq = !empty($_FILES['imagens']['name'][0]);
        if (!$temArq) {
            flash_set('err', 'Selecione ou tire ao menos uma imagem.');
        } else {
            $destino = upload_dir_chamado($id);
            $res     = upload_gravar_multiplos($_FILES['imagens'], $destino);
            $salvos  = 0;
            foreach ($res['salvos'] as $arq) {
                repo_create_chamado_anexo([
                    'chamado_id'    => $id,
                    'resposta_id'   => null,
                    'nome_original' => $arq['nome_original'],
                    'nome_arquivo'  => $arq['nome_arquivo'],
                    'mime'          => $arq['mime'],
                    'tamanho'       => $arq['tamanho'],
                    'enviado_por'   => $user['nome'] ?? 'Operador',
                    'enviado_tipo'  => 'operador',
                ]);
                $salvos++;
            }
            flash_set($salvos > 0 ? 'ok' : 'err', $salvos > 0 ? $salvos . ' imagem(ns) enviada(s).' : 'Nenhuma imagem aceita.');
            if (!empty($res['erros'])) {
                flash_set('err', 'Algumas imagens não foram aceitas: ' . implode(' | ', $res['erros']));
            }
        }
    }

    header('Location: chamado_detalhe.php?id=' . $id);
    exit;
}

if (!db_ok() || $empresaId <= 0) {
    flash_set('err', 'Banco indisponível ou conta sem empresa vinculada.');
    header('Location: chamados.php');
    exit;
}

$chamado = repo_chamado($id);
if (!$chamado || !repo_chamado_acessivel_operador_atribuido($id, $empresaId, (int) ($user['id'] ?? 0))) {
    header('Location: chamados.php');
    exit;
}

$chamadoClienteId   = (int) ($chamado['cliente_id'] ?? 0);
$respostas          = repo_chamado_respostas_do_ticket($id, true);
$servicos           = $chamadoClienteId > 0 ? repo_cliente_itens_list($chamadoClienteId, true) : [];
$chamadoMateriaisOp = [];
$totalMatOp         = 0.0;
try {
    $chamadoMateriaisOp = repo_chamado_itens_list($id);
    $totalMatOp         = repo_chamado_itens_valor_total($id);
} catch (Throwable $e) {
    $chamadoMateriaisOp = [];
}
$anexos            = repo_chamado_anexos($chamado['id']);
$itensUsadosOp     = array_values(array_filter($chamadoMateriaisOp, fn ($i) => ($i['movimento'] ?? 'utilizado') !== 'devolvido'));
$itensDevolvidosOp = array_values(array_filter($chamadoMateriaisOp, fn ($i) => ($i['movimento'] ?? '') === 'devolvido'));
$imagensOp         = [];
foreach ($anexos as $ax) {
    $mime = strtolower((string) ($ax['mime'] ?? ''));
    $nome = strtolower((string) ($ax['nome_original'] ?? ''));
    if (strpos($mime, 'image/') === 0 || preg_match('/\.(png|jpe?g|gif|webp|bmp)$/i', $nome)) {
        $imagensOp[] = $ax;
    }
}
$anexosPorResposta = [];
foreach ($anexos as $a) {
    if (!empty($a['resposta_id'])) {
        $anexosPorResposta[(int) $a['resposta_id']][] = $a;
    }
}

$opCatalogoItensJs = [];
foreach ($servicos as $ci) {
    $iid   = (int) ($ci['id'] ?? 0);
    $tipo  = (string) ($ci['tipo'] ?? '');
    $nome  = (string) ($ci['nome'] ?? '');
    $cod   = (string) ($ci['codigo'] ?? '');
    $label = '#' . $iid . ' - ' . $tipo . ' · ' . $nome;
    $hay   = mb_strtolower($tipo . ' ' . $nome . ' ' . $cod . ' #' . $iid, 'UTF-8');
    $opCatalogoItensJs[] = ['id' => $iid, 'label' => $label, 'hay' => $hay];
}

$topTitle    = 'Chamado #' . $chamado['id'];
$topSubtitle = (string) ($chamado['titulo'] ?? '');
$topSearch   = '';
$topAction   = ['label' => 'Voltar', 'href' => 'chamados.php', 'icon' => '←'];

$loadLeaflet = isset($chamado['latitude'], $chamado['longitude'])
    && $chamado['latitude'] !== null
    && $chamado['longitude'] !== null;

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-operador.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <style>
    .op-mobile-shell{display:flex;flex-direction:column;gap:12px;max-width:760px;margin:0 auto}
    .op-card{background:#fff;border:1px solid var(--border);border-radius:18px;box-shadow:var(--shadow-sm);overflow:hidden}
    .op-card-head{padding:14px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:10px}
    .op-card-head h4{margin:0;font-size:15px}
    .op-card-body{padding:14px 16px}
    .op-map{height:260px;border-radius:14px;overflow:hidden;background:#f1f5f9}
    .op-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px}
    .op-actions .btn{width:100%;justify-content:center}
    .op-item-tabs{display:flex;gap:8px;flex-wrap:wrap}
    .op-item-tab{border:1px solid var(--border);background:#fff;border-radius:999px;padding:8px 12px;font-size:13px;font-weight:600;cursor:pointer}
    .op-item-tab.is-active{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8}
    .op-item-pane{display:none;flex-direction:column;gap:12px}
    .op-item-pane.is-active{display:flex}
    .op-item-list{display:flex;flex-direction:column;gap:8px}
    .op-item-row{border:1px solid var(--border);border-radius:14px;padding:10px;background:#f8fafc}
    .op-item-top{display:flex;justify-content:space-between;gap:8px;align-items:flex-start}
    .op-item-top strong{font-size:14px}
    .op-item-meta{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px}
    .op-item-combo{position:relative;width:100%}
    .op-item-combo__dd{position:absolute;left:0;right:0;top:calc(100% + 4px);max-height:min(280px,50vh);overflow-y:auto;background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:0 12px 36px rgba(15,23,42,.12);z-index:60;display:none}
    .op-item-combo.is-open .op-item-combo__dd{display:block}
    .op-item-combo__opt{display:block;width:100%;padding:10px 12px;margin:0;border:0;border-bottom:1px solid var(--border);background:transparent;text-align:left;font-size:14px;line-height:1.35;cursor:pointer;color:inherit;font-family:inherit}
    .op-item-combo__opt:last-child{border-bottom:0}
    .op-item-combo__opt:hover,.op-item-combo__opt.is-active{background:#eff6ff}
    .op-item-combo__empty{padding:12px;font-size:13px;color:var(--muted)}
    .op-photo-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:8px}
    .op-photo-grid img{width:100%;height:90px;object-fit:cover;border-radius:12px;border:1px solid var(--border);background:#f8fafc}
    .op-photo-preview{display:none;grid-template-columns:repeat(2,1fr);gap:8px}
    .op-photo-preview.active{display:grid}
    .op-photo-preview figure{margin:0;border:1px solid var(--border);border-radius:12px;overflow:hidden;background:#f8fafc}
    .op-photo-preview img{width:100%;height:110px;object-fit:cover;display:block}
    .op-photo-preview figcaption{padding:6px 8px;font-size:11px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    @media(max-width:720px){
      .content{padding:12px}
      .ticket-header{padding:14px 16px;border-radius:18px}
      .ticket-header h2{font-size:18px}
      .ticket-meta{gap:6px}
      .op-actions{grid-template-columns:1fr}
      .op-map{height:230px}
      .main{padding-bottom:80px}
    }
  </style>

  <div class="ticket-header">
    <div>
      <div class="ticket-id">CHAMADO #<?= (int) $chamado['id'] ?></div>
      <h2><?= htmlspecialchars((string) ($chamado['titulo'] ?? '')) ?></h2>
      <div class="ticket-meta">
        <span class="badge <?= status_class($chamado['status'] ?? '') ?>"><?= htmlspecialchars((string) ($chamado['status'] ?? '')) ?></span>
        <span class="badge <?= status_class($chamado['prioridade'] ?? '') ?>">Prioridade: <?= htmlspecialchars((string) ($chamado['prioridade'] ?? '')) ?></span>
        <?php if (!empty($chamado['data'])): ?>
          <span class="badge badge-plain">Aberto em <?= date('d/m/Y H:i', strtotime((string) $chamado['data'])) ?></span>
        <?php endif; ?>
        <?php if (!empty($chamado['servico_nome'])): ?>
          <span class="badge badge-plain">Serviço: <?= htmlspecialchars((string) $chamado['servico_nome']) ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="op-mobile-shell">
      <form id="op-os-form" method="post" enctype="multipart/form-data" data-confirm="Enviar esta OS para aprovação do gestor?">
        <input type="hidden" name="acao" value="enviar_os">
      </form>

      <div class="op-card">
        <div class="op-card-head">
          <h4>Endereço do atendimento</h4>
        </div>
        <div class="op-card-body">
          <?php if (!empty($chamado['endereco_completo'])): ?>
            <p style="color:var(--muted); line-height:1.55; margin:0 0 12px;"><?= nl2br(htmlspecialchars((string) $chamado['endereco_completo'])) ?></p>
          <?php else: ?>
            <p class="muted" style="margin:0 0 12px;">Sem endereço informado. Capture o GPS e adicione uma referência.</p>
          <?php endif; ?>
          <?php if ($loadLeaflet): ?>
            <p class="muted" style="font-size:12px;margin:0 0 10px;line-height:1.5;">
              Coordenadas: <strong><?= htmlspecialchars(number_format((float) $chamado['latitude'], 6, '.', '')) ?>, <?= htmlspecialchars(number_format((float) $chamado['longitude'], 6, '.', '')) ?></strong>
            </p>
            <div class="op-actions" style="margin-bottom:12px;">
              <?php
                $latUrl = number_format((float) $chamado['latitude'], 7, '.', '');
                $lngUrl = number_format((float) $chamado['longitude'], 7, '.', '');
                $coordUrl = rawurlencode($latUrl . ',' . $lngUrl);
              ?>
              <a class="btn btn-primary" target="_blank" rel="noopener"
                 href="https://www.google.com/maps/search/?api=1&amp;query=<?= $coordUrl ?>">Abrir no Google Maps</a>
              <a class="btn btn-secondary" target="_blank" rel="noopener"
                 href="https://waze.com/ul?ll=<?= $coordUrl ?>&amp;navigate=yes">Abrir no Waze</a>
            </div>
            <div id="chamado-map-mini" class="op-map" aria-label="Mapa do local do chamado"></div>
          <?php else: ?>
            <p class="muted" style="margin:0;font-size:13px;">Sem coordenadas no cadastro. Use o formulário abaixo para capturar o GPS e salvar.</p>
          <?php endif; ?>
        </div>
      </div>

      <div class="op-card">
        <div class="op-card-head"><h4>Ações rápidas</h4></div>
        <div class="op-card-body flex-col" style="gap:12px;">

          <div class="flex-col" style="gap:10px;">
            <div class="form-group" style="margin:0;">
              <label for="endereco_completo">Endereço / referência (opcional)</label>
              <textarea class="textarea" id="endereco_completo" name="endereco_completo" form="op-os-form" rows="2" placeholder="Ex.: Rua X, próximo ao mercado"><?= htmlspecialchars((string) ($chamado['endereco_completo'] ?? '')) ?></textarea>
            </div>
          </div>

          <?php
            $jaFechado = in_array((string) ($chamado['status'] ?? ''), ['Resolvido', 'Fechado'], true) || !empty($chamado['finalizado_operador_em']);
          ?>
          <button type="submit" form="op-os-form" class="btn btn-primary" style="width:100%;" <?= $jaFechado ? 'disabled' : '' ?>>
            <?= $jaFechado ? 'OS já enviada' : 'Enviar OS' ?>
          </button>
          <small class="muted" style="display:block;margin-top:8px;">As imagens e o endereço/referência serão salvos junto com o envio.</small>
        </div>
      </div>

      <div class="op-card">
        <div class="op-card-head">
          <h4>Fotos do atendimento</h4>
          <span class="panel-sub"><?= count($imagensOp) ?> foto(s)</span>
        </div>
        <div class="op-card-body flex-col" style="gap:12px;">
          <div class="flex-col" style="gap:8px;">
            <label class="btn btn-secondary" style="width:100%;justify-content:center;">
              Abrir câmera
              <input type="file" name="imagens[]" form="op-os-form" accept="image/*" capture="environment" multiple hidden data-op-photo-input>
            </label>
            <label class="btn btn-secondary" style="width:100%;justify-content:center;">
              Escolher da galeria
              <input type="file" name="imagens[]" form="op-os-form" accept="image/*" multiple hidden data-op-photo-input>
            </label>
            <div class="op-photo-preview" id="op-photo-preview" aria-live="polite"></div>
          </div>
          <?php if (!empty($imagensOp)): ?>
            <div class="op-photo-grid">
              <?php foreach (array_slice($imagensOp, 0, 6) as $img): ?>
                <a href="chamado_download.php?id=<?= (int) $img['id'] ?>" target="_blank" rel="noopener">
                  <img src="chamado_download.php?id=<?= (int) $img['id'] ?>" alt="<?= htmlspecialchars((string) $img['nome_original']) ?>">
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="op-card">
        <div class="op-card-head">
          <h4 style="margin:0;">Itens do atendimento</h4>
          <span class="panel-sub">Valor usado R$ <?= number_format($totalMatOp, 2, ',', '.') ?></span>
        </div>
        <div class="op-card-body flex-col" style="gap:12px;">
          <div class="op-item-tabs" role="tablist" aria-label="Tipo de item do atendimento">
            <button type="button" class="op-item-tab is-active" data-op-tab-trigger="utilizado" role="tab" aria-selected="true">Usei no atendimento</button>
            <button type="button" class="op-item-tab" data-op-tab-trigger="devolvido" role="tab" aria-selected="false">Recolhi/devolvi</button>
          </div>

          <div class="op-item-pane is-active" data-op-tab-pane="utilizado" role="tabpanel">
            <form method="post" class="flex-col" style="gap:8px;border:1px solid var(--border);border-radius:14px;padding:10px;background:#f8fafc;">
              <input type="hidden" name="acao" value="chamado_item_add">
              <input type="hidden" name="movimento" value="utilizado">
              <input type="hidden" name="item_id" data-op-item-id value="">
              <div class="op-item-combo" data-op-item-combo data-filter-empty="Nenhum item corresponde à busca.">
                <input type="text" data-op-item-search class="input" role="combobox" aria-expanded="false"
                       aria-autocomplete="list" autocomplete="off"
                       placeholder="Toque para listar ou digite para filtrar"
                       <?= empty($servicos) ? 'disabled' : '' ?>>
                <div class="op-item-combo__dd" data-op-item-dd role="listbox" hidden></div>
              </div>
              <?php if (empty($servicos)): ?>
                <small class="muted">Nenhum produto ou serviço ativo no catálogo desta empresa.</small>
              <?php endif; ?>
              <div style="display:grid;grid-template-columns:90px 1fr;gap:8px;">
                <input type="text" name="quantidade" class="input" value="1" inputmode="decimal" placeholder="Qtd">
                <input type="text" name="observacao" class="input" placeholder="Obs. opcional">
              </div>
              <button type="submit" class="btn btn-secondary" <?= empty($servicos) ? 'disabled' : '' ?>>Adicionar item usado</button>
            </form>

            <?php if (!empty($itensUsadosOp)): ?>
              <div class="op-item-list">
                <?php foreach ($itensUsadosOp as $lm): ?>
                  <div class="op-item-row">
                    <div class="op-item-top">
                      <strong><?= htmlspecialchars((string) ($lm['item_nome'] ?? '')) ?></strong>
                      <form method="post" data-confirm="Remover esta linha?" data-confirm-danger>
                        <input type="hidden" name="acao" value="chamado_item_del">
                        <input type="hidden" name="linha_id" value="<?= (int) ($lm['id'] ?? 0) ?>">
                        <button type="submit" class="action danger" style="background:none;border:0;padding:0;">Remover</button>
                      </form>
                    </div>
                    <div class="op-item-meta">
                      <span class="badge success">Usado</span>
                      <span class="muted">Qtd <?= htmlspecialchars(rtrim(rtrim(sprintf('%.4f', (float) ($lm['quantidade'] ?? 0)), '0'), '.')) ?></span>
                      <strong style="margin-left:auto;">R$ <?= number_format((float) ($lm['subtotal'] ?? 0), 2, ',', '.') ?></strong>
                    </div>
                    <?php if (!empty($lm['observacao'])): ?><small class="muted"><?= htmlspecialchars((string) $lm['observacao']) ?></small><?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <p class="muted" style="margin:0;font-size:14px;">Nenhum item usado lançado ainda.</p>
            <?php endif; ?>
          </div>

          <div class="op-item-pane" data-op-tab-pane="devolvido" role="tabpanel">
            <form method="post" class="flex-col" style="gap:8px;border:1px solid var(--border);border-radius:14px;padding:10px;background:#f8fafc;">
              <input type="hidden" name="acao" value="chamado_item_add">
              <input type="hidden" name="movimento" value="devolvido">
              <input type="hidden" name="item_id" data-op-item-id value="">
              <div class="op-item-combo" data-op-item-combo data-filter-empty="Nenhum item corresponde à busca.">
                <input type="text" data-op-item-search class="input" role="combobox" aria-expanded="false"
                       aria-autocomplete="list" autocomplete="off"
                       placeholder="Toque para listar ou digite para filtrar"
                       <?= empty($servicos) ? 'disabled' : '' ?>>
                <div class="op-item-combo__dd" data-op-item-dd role="listbox" hidden></div>
              </div>
              <?php if (empty($servicos)): ?>
                <small class="muted">Nenhum produto ou serviço ativo no catálogo desta empresa.</small>
              <?php endif; ?>
              <div style="display:grid;grid-template-columns:90px 1fr;gap:8px;">
                <input type="text" name="quantidade" class="input" value="1" inputmode="decimal" placeholder="Qtd">
                <input type="text" name="observacao" class="input" placeholder="Obs. opcional">
              </div>
              <button type="submit" class="btn btn-secondary" <?= empty($servicos) ? 'disabled' : '' ?>>Adicionar item recolhido</button>
            </form>

            <?php if (!empty($itensDevolvidosOp)): ?>
              <div class="op-item-list">
                <?php foreach ($itensDevolvidosOp as $lm): ?>
                  <div class="op-item-row" style="background:#eff6ff;border-color:#bfdbfe;">
                    <div class="op-item-top">
                      <strong><?= htmlspecialchars((string) ($lm['item_nome'] ?? '')) ?></strong>
                      <form method="post" data-confirm="Remover esta linha?" data-confirm-danger>
                        <input type="hidden" name="acao" value="chamado_item_del">
                        <input type="hidden" name="linha_id" value="<?= (int) ($lm['id'] ?? 0) ?>">
                        <button type="submit" class="action danger" style="background:none;border:0;padding:0;">Remover</button>
                      </form>
                    </div>
                    <div class="op-item-meta">
                      <span class="badge info">Devolvido</span>
                      <span class="muted">Qtd <?= htmlspecialchars(rtrim(rtrim(sprintf('%.4f', (float) ($lm['quantidade'] ?? 0)), '0'), '.')) ?></span>
                    </div>
                    <?php if (!empty($lm['observacao'])): ?><small class="muted"><?= htmlspecialchars((string) $lm['observacao']) ?></small><?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <p class="muted" style="margin:0;font-size:14px;">Nenhum item recolhido/devolvido lançado ainda.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="op-card">
        <div class="op-card-head"><h4>Descrição</h4></div>
        <div class="op-card-body">
          <p style="color:var(--muted); line-height:1.65;margin:0;"><?= nl2br(htmlspecialchars((string) ($chamado['descricao'] ?? ''))) ?></p>
        </div>
      </div>

      <div class="op-card">
        <div class="op-card-head">
          <h4>Conversa</h4>
          <span class="panel-sub"><?= count($respostas) ?> mensagem(ns)</span>
        </div>
        <div class="thread" style="padding:10px;">
          <?php if (empty($respostas)): ?>
            <div class="empty-state" style="padding:20px;">
              <p class="muted">Sem mensagens públicas ainda.</p>
            </div>
          <?php else: foreach ($respostas as $r): ?>
            <?php
              $tipo = (string) ($r['tipo'] ?? '');
              $cls  = $tipo === 'operador' ? 'me' : ($tipo === 'cliente' ? 'me' : '');
            ?>
            <div class="msg <?= $cls ?>">
              <div class="avatar avatar-sm"><?= initials($r['autor']) ?></div>
              <div class="bubble">
                <div class="bubble-head">
                  <strong><?= htmlspecialchars((string) ($r['autor'] ?? '')) ?></strong>
                  <span><?= !empty($r['data']) ? date('d/m/Y H:i', strtotime((string) $r['data'])) : '' ?></span>
                  <?php if ($tipo !== ''): ?>
                    <span class="muted" style="font-size:11px;"> · <?= htmlspecialchars($tipo) ?></span>
                  <?php endif; ?>
                </div>
                <p><?= nl2br(htmlspecialchars((string) ($r['texto'] ?? ''))) ?></p>
                <?php
                  $anexosMsg = $anexosPorResposta[(int) ($r['id'] ?? 0)] ?? [];
                  if ($anexosMsg):
                ?>
                  <div class="bubble-files">
                    <?php foreach ($anexosMsg as $ax): ?>
                      <a class="file-chip" href="chamado_download.php?id=<?= (int) $ax['id'] ?>">
                        <span class="file-chip-ico"><?= upload_icone_por_ext($ax['nome_original']) ?></span>
                        <span>
                          <strong><?= htmlspecialchars((string) ($ax['nome_original'] ?? '')) ?></strong>
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
      </div>

      <div class="op-card">
        <div class="op-card-head"><h4>Resumo</h4></div>
        <div class="op-card-body flex-col" style="gap:12px;">
          <div class="info-row"><span>Status</span><strong><?= htmlspecialchars((string) ($chamado['status'] ?? '')) ?></strong></div>
          <div class="info-row"><span>Aberto em</span><strong><?= !empty($chamado['data']) ? date('d/m/Y H:i', strtotime((string) $chamado['data'])) : '—' ?></strong></div>
          <?php if (!empty($chamado['finalizado_operador_em'])): ?>
            <div class="info-row"><span>Finalizado</span><strong><?= htmlspecialchars((string) $chamado['finalizado_operador_em']) ?></strong></div>
          <?php endif; ?>
        </div>
      </div>
  </div>
</section>

<script>
(function () {
  var CATALOGO = <?= json_encode($opCatalogoItensJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;

  function norm(s) {
    return (s || '').trim().toLowerCase();
  }

  function filterRows(q) {
    var n = norm(q);
    if (!n) return CATALOGO.slice();
    return CATALOGO.filter(function (row) {
      return row.hay.indexOf(n) !== -1;
    });
  }

  function initItemPicker(form) {
    var itemSearch = form.querySelector('[data-op-item-search]');
    var itemId = form.querySelector('[data-op-item-id]');
    var combo = form.querySelector('[data-op-item-combo]');
    var dd = form.querySelector('[data-op-item-dd]');
    var selectedLabel = '';
    var activeIndex = 0;
    var visibleRows = [];

    function renderList(rows, activeIdx) {
      if (!dd) return;
      dd.innerHTML = '';
      if (!rows.length) {
        var empty = document.createElement('div');
        empty.className = 'op-item-combo__empty';
        empty.textContent = combo
          ? (combo.getAttribute('data-filter-empty') || 'Nada encontrado.')
          : 'Nada encontrado.';
        dd.appendChild(empty);
        return;
      }
      rows.forEach(function (row, idx) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'op-item-combo__opt' + (idx === activeIdx ? ' is-active' : '');
        btn.setAttribute('role', 'option');
        btn.setAttribute('data-id', String(row.id));
        btn.setAttribute('data-label', row.label);
        btn.textContent = row.label;
        btn.addEventListener('mousedown', function (e) {
          e.preventDefault();
          pick(row.id, row.label);
        });
        dd.appendChild(btn);
      });
    }

    function setOpen(open) {
      if (!combo || !itemSearch) return;
      combo.classList.toggle('is-open', open);
      itemSearch.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (dd) dd.hidden = !open;
    }

    function openDropdown() {
      if (!itemSearch || itemSearch.disabled) return;
      visibleRows = filterRows(itemSearch.value);
      activeIndex = 0;
      renderList(visibleRows, activeIndex);
      setOpen(true);
    }

    function closeDropdown() {
      setOpen(false);
    }

    function pick(id, label) {
      if (!itemSearch || !itemId) return;
      itemId.value = String(id);
      selectedLabel = label;
      itemSearch.value = label;
      closeDropdown();
    }

    function syncIdFromInput() {
      if (!itemSearch || !itemId) return;
      var v = itemSearch.value.trim();
      if (v === '') {
        itemId.value = '';
        selectedLabel = '';
        return;
      }
      if (v === selectedLabel) return;
      var m = v.match(/^#(\d+)\s+-\s+/);
      itemId.value = m ? m[1] : '';
    }

    function scrollActiveIntoView() {
      var el = dd.querySelector('.op-item-combo__opt.is-active');
      if (el && typeof el.scrollIntoView === 'function') {
        el.scrollIntoView({ block: 'nearest' });
      }
    }

    if (itemSearch && itemId && combo && dd && CATALOGO.length) {
      itemSearch.addEventListener('focus', openDropdown);
      itemSearch.addEventListener('click', openDropdown);
      itemSearch.addEventListener('input', function () {
        syncIdFromInput();
        openDropdown();
      });
      itemSearch.addEventListener('keydown', function (e) {
        if (!combo.classList.contains('is-open')) {
          if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            openDropdown();
          }
          return;
        }
        if (e.key === 'Escape') {
          e.preventDefault();
          closeDropdown();
          return;
        }
        if (e.key === 'ArrowDown') {
          e.preventDefault();
          if (visibleRows.length) activeIndex = (activeIndex + 1) % visibleRows.length;
          renderList(visibleRows, activeIndex);
          scrollActiveIntoView();
          return;
        }
        if (e.key === 'ArrowUp') {
          e.preventDefault();
          if (visibleRows.length) activeIndex = (activeIndex - 1 + visibleRows.length) % visibleRows.length;
          renderList(visibleRows, activeIndex);
          scrollActiveIntoView();
          return;
        }
        if (e.key === 'Enter' && visibleRows.length && document.activeElement === itemSearch) {
          var row = visibleRows[activeIndex];
          if (row) {
            e.preventDefault();
            pick(row.id, row.label);
          }
        }
      });
      itemSearch.addEventListener('blur', function () {
        window.setTimeout(function () {
          if (document.activeElement && combo.contains(document.activeElement)) return;
          closeDropdown();
        }, 180);
      });
      document.addEventListener('click', function (e) {
        if (!combo.contains(e.target)) closeDropdown();
      });
      form.addEventListener('submit', function (ev) {
        syncIdFromInput();
        if (itemSearch.value.trim() !== '' && !itemId.value) {
          ev.preventDefault();
          openDropdown();
          itemSearch.focus();
          if (typeof window.appAlert === 'function') {
            window.appAlert('Selecione um produto ou serviço da lista.', 'Itens da OS');
          }
        }
      });
    }
  }

  var itemForms = Array.prototype.slice.call(document.querySelectorAll('[data-op-tab-pane] form'));
  itemForms.forEach(initItemPicker);

  var tabButtons = Array.prototype.slice.call(document.querySelectorAll('[data-op-tab-trigger]'));
  var tabPanes = Array.prototype.slice.call(document.querySelectorAll('[data-op-tab-pane]'));
  function setTab(tab) {
    tabButtons.forEach(function (btn) {
      var active = btn.getAttribute('data-op-tab-trigger') === tab;
      btn.classList.toggle('is-active', active);
      btn.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    tabPanes.forEach(function (pane) {
      var active = pane.getAttribute('data-op-tab-pane') === tab;
      pane.classList.toggle('is-active', active);
    });
  }
  tabButtons.forEach(function (btn) {
    btn.addEventListener('click', function () {
      setTab(btn.getAttribute('data-op-tab-trigger') || 'utilizado');
    });
  });
  if (tabButtons.length) setTab('utilizado');

  var inputs = Array.prototype.slice.call(document.querySelectorAll('[data-op-photo-input]'));
  var preview = document.getElementById('op-photo-preview');
  if (!inputs.length || !preview) return;

  inputs.forEach(function (input) {
    input.addEventListener('change', function () {
      preview.innerHTML = '';
      var files = Array.prototype.slice.call(input.files || []).filter(function (file) {
        return file.type && file.type.indexOf('image/') === 0;
      });

      preview.classList.toggle('active', files.length > 0);
      if (!files.length) return;

      files.forEach(function (file) {
        var url = URL.createObjectURL(file);
        var fig = document.createElement('figure');
        var img = document.createElement('img');
        var cap = document.createElement('figcaption');

        img.src = url;
        img.alt = file.name;
        img.onload = function () { URL.revokeObjectURL(url); };
        cap.textContent = file.name;

        fig.appendChild(img);
        fig.appendChild(cap);
        preview.appendChild(fig);
      });
    });
  });
})();
</script>

<?php if (!empty($loadLeaflet)): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script>
(function () {
  var el = document.getElementById('chamado-map-mini');
  if (!el || typeof L === 'undefined') return;
  var lat = <?= json_encode((float) $chamado['latitude']) ?>;
  var lng = <?= json_encode((float) $chamado['longitude']) ?>;
  var map = L.map('chamado-map-mini', { scrollWheelZoom: false });
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OSM' }).addTo(map);
  L.marker([lat, lng]).addTo(map);
  map.setView([lat, lng], 15);
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
