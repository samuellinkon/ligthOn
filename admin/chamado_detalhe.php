<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/upload.php';
$user = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('chamados');

$pageTitle  = 'Chamado';
$basePath   = '../';
$activePage = 'chamados';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: chamados.php'); exit; }

if (db_ok()) {
    $__chAuth = repo_chamado($id);
    if (!$__chAuth) {
        header('Location: chamados.php');
        exit;
    }
    gestor_assert_escopo_cliente((int) ($__chAuth['cliente_id'] ?? 0), 'chamados.php');
}

/* --------------------------------------------------------------
 * POST handlers
 * ------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!db_ok()) {
        flash_set('err', 'Banco indisponível.');
        header('Location: chamado_detalhe.php?id=' . $id); exit;
    }
    try {
        $acao = $_POST['acao'] ?? '';

        if ($acao === 'responder') {
            $texto    = trim($_POST['resposta'] ?? '');
            $interna  = !empty($_POST['interna']);
            $temArq   = !empty($_FILES['anexos']['name'][0]);
            $arquivosSalvos = 0;

            if ($texto === '' && !$temArq) {
                flash_set('err', 'Informe uma resposta ou anexe ao menos um arquivo.');
                header('Location: chamado_detalhe.php?id=' . $id); exit;
            }

            $respostaId = null;
            if ($texto !== '') {
                $respostaId = repo_create_chamado_resposta($id, $user['nome'], 'admin', $texto, $interna);
            }

            if ($temArq) {
                $destino = upload_dir_chamado($id);
                $res = upload_gravar_multiplos($_FILES['anexos'], $destino);
                foreach ($res['salvos'] as $arq) {
                    repo_create_chamado_anexo([
                        'chamado_id'    => $id,
                        'resposta_id'   => $respostaId,
                        'nome_original' => $arq['nome_original'],
                        'nome_arquivo'  => $arq['nome_arquivo'],
                        'mime'          => $arq['mime'],
                        'tamanho'       => $arq['tamanho'],
                        'enviado_por'   => $user['nome'] ?? 'Admin',
                        'enviado_tipo'  => 'admin',
                    ]);
                    $arquivosSalvos++;
                }
                if ($res['erros']) {
                    flash_set('err', 'Falhas em anexos: ' . implode(' | ', $res['erros']));
                }
            }

            if (!$interna) {
                repo_update_chamado_status($id, 'Em andamento');
            }

            $msg = $interna ? 'Nota interna registrada.' : 'Resposta enviada ao cliente.';
            if ($arquivosSalvos > 0) $msg .= ' ' . $arquivosSalvos . ' anexo(s) salvo(s).';
            flash_set('ok', $msg);

        } elseif ($acao === 'resolver') {
            repo_update_chamado_status($id, 'Resolvido');
            flash_set('ok', 'Chamado marcado como resolvido.');

        } elseif ($acao === 'status') {
            $novo = (string) ($_POST['status'] ?? 'Em andamento');
            $permitidosStatus = ['Aberto', 'Em andamento', 'Aguardando', 'Resolvido', 'Fechado', 'Cancelado'];
            if (!in_array($novo, $permitidosStatus, true)) {
                flash_set('err', 'Status inválido.');
            } elseif (repo_update_chamado_status($id, $novo)) {
                flash_set('ok', 'Status atualizado para "' . $novo . '".');
            } else {
                flash_set('err', 'Não foi possível atualizar o status.');
            }

        } elseif ($acao === 'responsavel') {
            $novo = trim($_POST['responsavel'] ?? '');
            if ($novo !== '') {
                repo_update_chamado_responsavel($id, $novo);
                flash_set('ok', 'Responsável atualizado para ' . $novo . '.');
            }

        } elseif ($acao === 'tecnico') {
            $chAtual = repo_chamado($id);
            $empresaChamado = $chAtual ? repo_cliente_catalogo_dono_id((int) ($chAtual['cliente_id'] ?? 0)) : 0;
            $tecnicoId = (int) ($_POST['tecnico_user_id'] ?? 0);
            $r = repo_chamado_atribuir_tecnico($id, $tecnicoId > 0 ? $tecnicoId : null, $empresaChamado);
            flash_set($r['ok'] ? 'ok' : 'err', $r['ok'] ? 'Técnico vinculado ao chamado.' : $r['err']);

        } elseif ($acao === 'aprovar_execucao') {
            $checklist = trim((string) ($_POST['checklist_realizado'] ?? ''));
            $r = repo_chamado_aprovar_gestor($id, (int) ($user['id'] ?? 0), (string) ($user['nome'] ?? 'Gestor'), $checklist);
            flash_set($r['ok'] ? 'ok' : 'err', $r['ok'] ? 'Execução aprovada e chamado resolvido.' : $r['err']);

        } elseif ($acao === 'localizacao') {
            $end = trim($_POST['endereco_completo'] ?? '');
            [$la, $lo] = _repo_parse_latlng_pair(
                (string) ($_POST['latitude'] ?? ''),
                (string) ($_POST['longitude'] ?? '')
            );
            if (repo_update_chamado_localizacao($id, $end !== '' ? $end : null, $la, $lo)) {
                flash_set('ok', 'Endereço e coordenadas atualizados.');
            } else {
                flash_set('err', 'Não foi possível salvar a localização.');
            }

        } elseif ($acao === 'os_dados') {
            require_once __DIR__ . '/../includes/chamado_os_fields.php';
            $os = chamado_os_parse_post($_POST);
            $os['titulo'] = chamado_os_titulo_from_post($_POST);
            $os['descricao'] = trim($_POST['descricao'] ?? '');
            $os['latitude'] = $_POST['latitude'] ?? '';
            $os['longitude'] = $_POST['longitude'] ?? '';
            $os['ponto_iluminacao_id'] = (int) ($_POST['ponto_iluminacao_id'] ?? 0);
            if (repo_update_chamado_os_dados($id, $os)) {
                flash_set('ok', 'Ficha da ordem de serviço atualizada.');
            } else {
                flash_set('err', 'Não foi possível salvar a ficha.');
            }

        } elseif ($acao === 'chamado_item_add') {
            $itemId = (int) ($_POST['item_id'] ?? 0);
            $qtd     = (float) str_replace(',', '.', (string) ($_POST['quantidade'] ?? '1'));
            $mov     = (string) ($_POST['movimento'] ?? 'utilizado');
            $obs     = trim((string) ($_POST['observacao'] ?? ''));
            $r       = repo_chamado_item_adicionar($id, $itemId, $qtd, $mov, $obs !== '' ? $obs : null);
            flash_set($r['ok'] ? 'ok' : 'err', $r['ok'] ? 'Item lançado no chamado.' : $r['err']);

        } elseif ($acao === 'chamado_item_qtd') {
            $lid = (int) ($_POST['linha_id'] ?? 0);
            $qtd = (float) str_replace(',', '.', (string) ($_POST['quantidade'] ?? '1'));
            if ($lid > 0 && $qtd > 0 && repo_chamado_item_atualizar_quantidade($lid, $id, $qtd)) {
                flash_set('ok', 'Quantidade atualizada.');
            } else {
                flash_set('err', 'Não foi possível atualizar a linha.');
            }

        } elseif ($acao === 'chamado_item_del') {
            $lid = (int) ($_POST['linha_id'] ?? 0);
            if ($lid > 0 && repo_chamado_item_remover($lid, $id)) {
                flash_set('ok', 'Linha removida.');
            } else {
                flash_set('err', 'Não foi possível remover.');
            }

        } elseif ($acao === 'excluir_anexo') {
            $anexoId = (int) ($_POST['anexo_id'] ?? 0);
            $anexo = repo_delete_chamado_anexo($anexoId);
            if ($anexo && (int) $anexo['chamado_id'] === $id) {
                $path = upload_dir_chamado($id) . DIRECTORY_SEPARATOR . $anexo['nome_arquivo'];
                if (is_file($path)) @unlink($path);
                flash_set('ok', 'Anexo removido.');
            } else {
                flash_set('err', 'Anexo não encontrado.');
            }
        }
    } catch (Throwable $e) {
        flash_set('err', 'Falha: ' . $e->getMessage());
    }
    header('Location: chamado_detalhe.php?id=' . $id); exit;
}

/* --------------------------------------------------------------
 * Exportação (GET): um chamado — CSV ou JSON
 * ------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $exportFmt = strtolower(trim((string) ($_GET['export'] ?? '')));
    if (in_array($exportFmt, ['csv', 'json', 'html', 'pdf'], true)) {
        if (db_ok()) {
            $chEx = repo_chamado($id);
            if (!$chEx) {
                header('Location: chamados.php');
                exit;
            }
            gestor_assert_escopo_cliente((int) ($chEx['cliente_id'] ?? 0), 'chamados.php');
            $respostasEx = repo_chamado_respostas_do_ticket($id, false);
            $materiaisEx = repo_chamado_itens_list($id);
            $anexosEx    = repo_chamado_anexos($id);
        } else {
            $chEx = find_by_id($MOCK_CHAMADOS, $id);
            if (!$chEx) {
                header('Location: chamados.php');
                exit;
            }
            $respostasEx = $MOCK_CHAMADO_RESPOSTAS[$id] ?? [];
            $materiaisEx = [];
            $anexosEx    = [];
        }

        $payload = [
            'exportado_em' => date('c'),
            'chamado_id'   => $id,
            'chamado'      => $chEx,
            'respostas'    => $respostasEx,
            'materiais'    => $materiaisEx,
            'anexos'       => $anexosEx,
        ];

        $stamp = date('Y-m-d_His');
        if (in_array($exportFmt, ['html', 'pdf'], true)) {
            require_once __DIR__ . '/../includes/chamado_export_document.php';
            $htmlOut = chamado_export_document_html($chEx, $respostasEx, $materiaisEx, $anexosEx, $exportFmt === 'pdf');
            if ($exportFmt === 'pdf') {
                header('Content-Type: text/html; charset=UTF-8');
                header('Content-Disposition: inline; filename="chamado_' . $id . '_impressao.html"');
                echo $htmlOut;
                exit;
            }
            header('Content-Type: text/html; charset=UTF-8');
            header('Content-Disposition: attachment; filename="chamado_' . $id . '_' . $stamp . '.html"');
            echo $htmlOut;
            exit;
        }

        if ($exportFmt === 'json') {
            header('Content-Type: application/json; charset=UTF-8');
            header('Content-Disposition: attachment; filename="chamado_' . $id . '_' . $stamp . '.json"');
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="chamado_' . $id . '_' . $stamp . '.csv"');
        $out = fopen('php://output', 'w');
        if ($out === false) {
            http_response_code(500);
            exit;
        }
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        $sep = ';';
        $flatVal = static function ($v): string {
            if ($v === null || $v === '') {
                return '';
            }
            if (is_bool($v)) {
                return $v ? '1' : '0';
            }
            if (is_scalar($v)) {
                return (string) $v;
            }

            return json_encode($v, JSON_UNESCAPED_UNICODE);
        };
        fputcsv($out, ['# Chamado — campo; valor'], $sep);
        fputcsv($out, ['campo', 'valor'], $sep);
        foreach ($chEx as $k => $v) {
            $cell = $flatVal($v);
            $cell = str_replace(["\r", "\n"], ' ', $cell);
            fputcsv($out, [(string) $k, $cell], $sep);
        }
        fputcsv($out, [], $sep);
        fputcsv($out, ['# Respostas'], $sep);
        fputcsv($out, ['id', 'data', 'autor', 'tipo', 'interna', 'texto'], $sep);
        foreach ($respostasEx as $r) {
            fputcsv($out, [
                (string) ($r['id'] ?? ''),
                (string) ($r['data'] ?? ''),
                (string) ($r['autor'] ?? ''),
                (string) ($r['tipo'] ?? ''),
                isset($r['interna']) ? (string) (int) $r['interna'] : '',
                str_replace(["\r", "\n"], ' ', (string) ($r['texto'] ?? '')),
            ], $sep);
        }
        fputcsv($out, [], $sep);
        fputcsv($out, ['# Materiais / itens'], $sep);
        fputcsv($out, ['id', 'item_nome', 'item_tipo', 'item_codigo', 'movimento', 'quantidade', 'valor_unitario', 'subtotal', 'observacao'], $sep);
        foreach ($materiaisEx as $m) {
            fputcsv($out, [
                (string) ($m['id'] ?? ''),
                (string) ($m['item_nome'] ?? ''),
                (string) ($m['item_tipo'] ?? ''),
                (string) ($m['item_codigo'] ?? ''),
                (string) ($m['movimento'] ?? ''),
                isset($m['quantidade']) ? (string) $m['quantidade'] : '',
                isset($m['valor_unitario']) ? (string) $m['valor_unitario'] : '',
                isset($m['subtotal']) ? (string) $m['subtotal'] : '',
                str_replace(["\r", "\n"], ' ', (string) ($m['observacao'] ?? '')),
            ], $sep);
        }
        fputcsv($out, [], $sep);
        fputcsv($out, ['# Anexos (metadados — ficheiros não incluídos)'], $sep);
        fputcsv($out, ['id', 'resposta_id', 'nome_original', 'nome_armazenado', 'mime', 'tamanho_bytes', 'enviado_por', 'enviado_tipo', 'enviado_em'], $sep);
        foreach ($anexosEx as $a) {
            fputcsv($out, [
                (string) ($a['id'] ?? ''),
                $a['resposta_id'] !== null && $a['resposta_id'] !== '' ? (string) $a['resposta_id'] : '',
                (string) ($a['nome_original'] ?? ''),
                (string) ($a['nome_arquivo'] ?? ''),
                (string) ($a['mime'] ?? ''),
                isset($a['tamanho']) ? (string) $a['tamanho'] : '',
                (string) ($a['enviado_por'] ?? ''),
                (string) ($a['enviado_tipo'] ?? ''),
                (string) ($a['enviado_em'] ?? ''),
            ], $sep);
        }
        fclose($out);
        exit;
    }
}

/* --------------------------------------------------------------
 * Carga
 * ------------------------------------------------------------- */
$chamadoMateriais     = [];
$totalMateriaisChamado = 0.0;
$catalogoItensChamado = [];
$tecnicosChamado       = [];
$empresaChamadoId      = 0;
$pontoChamado          = null;
$pontoChamadoImagens   = [];

if (db_ok()) {
    $chamado = repo_chamado($id);
    if (!$chamado) {
        header('Location: chamados.php');
        exit;
    }
    $respostas = repo_chamado_respostas_do_ticket($id, false);
    try {
        $empresaChamadoId      = repo_cliente_catalogo_dono_id((int) $chamado['cliente_id']);
        $tecnicosChamado       = $empresaChamadoId > 0 ? repo_operadores_empresa($empresaChamadoId) : [];
        $chamadoMateriais      = repo_chamado_itens_list($id);
        $totalMateriaisChamado = repo_chamado_itens_valor_total($id);
        $catalogoItensChamado  = repo_cliente_itens_list((int) $chamado['cliente_id'], true);
    } catch (Throwable $e) {
        $chamadoMateriais = [];
    }
    $pontosOsForm = repo_pontos_iluminacao_options((int) ($chamado['cliente_id'] ?? 0));

    $pidPonto = (int) ($chamado['ponto_iluminacao_id'] ?? 0);
    if ($pidPonto > 0) {
        $pontoChamado = repo_ponto_iluminacao($pidPonto);
        if ($pontoChamado && (int) ($pontoChamado['cliente_id'] ?? 0) !== (int) ($chamado['cliente_id'] ?? 0)) {
            $pontoChamado = null;
        }
        if ($pontoChamado) {
            $pontoChamadoImagens = repo_ponto_iluminacao_imagens_list((int) $pontoChamado['id']);
        }
    }
} else {
    $chamado = find_by_id($MOCK_CHAMADOS, $id);
    if (!$chamado) {
        header('Location: chamados.php');
        exit;
    }
    $respostas = $MOCK_CHAMADO_RESPOSTAS[$chamado['id']] ?? [];
    $pontosOsForm = [];
}

$cliente   = find_by_id($MOCK_CLIENTES, $chamado['cliente_id']);
$anexos    = db_ok() ? repo_chamado_anexos($chamado['id']) : [];

/* agrupa anexos por resposta para exibir junto da mensagem (e os "soltos" no painel) */
$anexosPorResposta = [];
foreach ($anexos as $a) {
    if (!empty($a['resposta_id'])) {
        $anexosPorResposta[(int) $a['resposta_id']][] = $a;
    }
}

$topTitle    = 'Chamado #' . $chamado['id'];
$topSubtitle = $chamado['titulo'];
$topSearch   = 'Buscar em chamados...';
$topAction   = ['label' => 'Voltar', 'href' => 'chamados.php', 'icon' => '←'];
$loadComposer = true;
$loadLeaflet  = db_ok()
    && isset($chamado['latitude'], $chamado['longitude'])
    && $chamado['latitude'] !== null
    && $chamado['longitude'] !== null;

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <style>
    .chamado-location-grid {
      display: grid;
      grid-template-columns: minmax(0, 1fr);
      gap: 14px;
    }

    .chamado-location-coords {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
    }

    .chamado-location-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      align-items: center;
    }

    .chamado-location-map {
      overflow: hidden;
      border: 1px solid var(--border-soft);
      border-radius: 12px;
      background: #fafafe;
    }

    .chamado-location-map .chamado-map-mini {
      height: 260px;
      margin: 0;
      border: 0;
      border-radius: 0;
    }

    @media (max-width: 560px) {
      .chamado-location-coords {
        grid-template-columns: 1fr;
      }

      .chamado-location-actions .btn {
        width: 100%;
        justify-content: center;
      }
    }

    .chamado-ponto-fotos {
      margin-top: 12px;
      border-top: 1px solid var(--border-soft);
      padding-top: 12px;
    }

    .chamado-ponto-fotos > summary {
      cursor: pointer;
      font-weight: 600;
      font-size: 13px;
      color: var(--primary);
      list-style: none;
      user-select: none;
    }

    .chamado-ponto-fotos > summary::-webkit-details-marker {
      display: none;
    }

    .chamado-ponto-fotos > summary::before {
      content: '▸ ';
      display: inline-block;
      transition: transform 0.15s ease;
    }

    .chamado-ponto-fotos[open] > summary::before {
      transform: rotate(90deg);
    }

    .chamado-ponto-fotos-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
      gap: 8px;
      margin-top: 10px;
    }

    .chamado-ponto-fotos-grid a {
      display: block;
      border-radius: 8px;
      overflow: hidden;
      border: 1px solid var(--border-soft);
      aspect-ratio: 1;
      background: #f0f0f7;
    }

    .chamado-ponto-fotos-grid img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .chamado-ponto-fotos-badge {
      position: absolute;
      top: 4px;
      left: 4px;
      font-size: 9px;
      padding: 2px 6px;
      border-radius: 4px;
      background: rgba(15, 23, 42, 0.75);
      color: #fff;
      font-weight: 700;
    }

    .chamado-ponto-fotos-cell {
      position: relative;
      border-radius: 8px;
    }
  </style>

  <div class="ticket-header">
    <div>
      <div class="ticket-id">CHAMADO #<?= $chamado['id'] ?></div>
      <h2><?= htmlspecialchars($chamado['titulo']) ?></h2>
      <div class="ticket-meta">
        <span class="badge <?= status_class($chamado['status']) ?>"><?= htmlspecialchars($chamado['status']) ?></span>
        <span class="badge <?= status_class($chamado['prioridade']) ?>">Prioridade: <?= htmlspecialchars($chamado['prioridade']) ?></span>
        <span class="badge badge-plain">Aberto em <?= date('d/m/Y H:i', strtotime($chamado['data'])) ?></span>
        <?php if (db_ok() && (int) ($chamado['ponto_iluminacao_id'] ?? 0) > 0): ?>
          <?php
            $lblPoste = $pontoChamado
                ? (string) ($pontoChamado['codigo_poste'] ?? '')
                : (string) ($chamado['ponto_codigo_poste'] ?? '');
            $lblPoste = $lblPoste !== '' ? $lblPoste : 'Poste #' . (int) $chamado['ponto_iluminacao_id'];
          ?>
          <span class="badge badge-plain" title="Ponto de iluminação vinculado">Poste: <?= htmlspecialchars($lblPoste) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="ticket-actions" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
      <?php if (!in_array($chamado['status'], ['Resolvido', 'Fechado', 'Cancelado'], true)): ?>
        <form method="post" style="display:inline;">
          <input type="hidden" name="acao" value="resolver">
          <button class="btn btn-primary" type="submit">Marcar como resolvido</button>
        </form>
      <?php endif; ?>
      <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
        <span class="muted" style="font-size:12px;">Exportar:</span>
        <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars('chamado_detalhe.php?' . http_build_query(['id' => $id, 'export' => 'html'])) ?>" title="Ficha formatada para arquivo ou impressão">HTML</a>
        <a class="btn btn-primary btn-sm" href="<?= htmlspecialchars('chamado_detalhe.php?' . http_build_query(['id' => $id, 'export' => 'pdf'])) ?>" title="Abre a ficha e sugere imprimir como PDF">PDF</a>
        <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars('chamado_detalhe.php?' . http_build_query(['id' => $id, 'export' => 'csv'])) ?>">CSV</a>
        <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars('chamado_detalhe.php?' . http_build_query(['id' => $id, 'export' => 'json'])) ?>">JSON</a>
      </div>
    </div>
  </div>

  <div class="ticket-body">
    <div class="flex-col" style="gap:18px;">

      <?php if (db_ok()): ?>
      <form method="post" class="card" style="overflow:hidden;">
        <input type="hidden" name="acao" value="os_dados">
        <div class="panel-head">
          <h4>Ordem de serviço</h4>
          <span class="panel-sub">Contribuinte, endereço e classificação no mesmo bloco; descrição abaixo — igual ao formulário de abertura</span>
        </div>
        <div class="form" style="padding: 0 24px 20px;">
          <?php
          $ch_os_vals = $chamado;
          $ch_os_descricao = (string) ($chamado['descricao'] ?? '');
          $ch_os_mostrar_ponto = !empty($pontosOsForm);
          $ch_os_pontos_opcoes = $pontosOsForm;
          include __DIR__ . '/../includes/chamado_os_grid_markup.php';
          ?>
        </div>
        <div class="form-actions" style="margin:0;border-top:1px solid var(--border-soft);">
          <button type="submit" class="btn btn-primary">Salvar ficha</button>
        </div>
      </form>
      <?php else: ?>
      <div class="card">
        <div class="panel-head"><h4>Descrição</h4></div>
        <div class="panel-body">
          <p style="color:var(--muted); line-height:1.65;"><?= nl2br(htmlspecialchars((string) ($chamado['descricao'] ?? ''))) ?></p>
        </div>
      </div>
      <?php endif; ?>

      <?php if (db_ok()): ?>
      <div class="card">
        <div class="panel-head" style="flex-wrap:wrap;gap:10px;">
          <div>
            <h4>Itens do atendimento</h4>
            <span class="panel-sub">Usados e devolvidos pelo técnico, a partir do catálogo da empresa</span>
          </div>
          <strong style="font-size:15px;">Valor do chamado: R$ <?= number_format($totalMateriaisChamado, 2, ',', '.') ?></strong>
        </div>
        <div class="panel-body">
          <?php if (empty($chamadoMateriais)): ?>
            <p class="muted" style="margin:0 0 12px;">Nenhum item lançado ainda.</p>
          <?php else: ?>
            <div class="table-wrap chamado-itens-table chamado-itens-table--admin" style="margin-bottom:16px;">
              <table>
                <thead>
                  <tr>
                    <th>Movimento</th>
                    <th>Item</th>
                    <th>Tipo</th>
                    <th class="text-right">Qtd</th>
                    <th class="text-right">V. unit.</th>
                    <th class="text-right">Subtotal</th>
                    <th>Observação</th>
                    <th class="text-right">Ações</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($chamadoMateriais as $lm): ?>
                  <tr>
                    <td><span class="badge <?= ($lm['movimento'] ?? '') === 'devolvido' ? 'info' : 'success' ?>"><?= ($lm['movimento'] ?? '') === 'devolvido' ? 'Devolvido' : 'Usado' ?></span></td>
                    <td><?= htmlspecialchars((string) ($lm['item_nome'] ?? '')) ?></td>
                    <td class="td-mute"><?= htmlspecialchars((string) ($lm['item_tipo'] ?? '')) ?></td>
                    <td class="text-right">
                      <form method="post" style="display:inline-flex;gap:6px;align-items:center;justify-content:flex-end;">
                        <input type="hidden" name="acao" value="chamado_item_qtd">
                        <input type="hidden" name="linha_id" value="<?= (int) ($lm['id'] ?? 0) ?>">
                        <input type="text" name="quantidade" class="input" style="width:88px;text-align:right;" value="<?= htmlspecialchars(rtrim(rtrim(sprintf('%.4f', (float) ($lm['quantidade'] ?? 0)), '0'), '.')) ?>">
                        <button type="submit" class="btn btn-secondary btn-sm">OK</button>
                      </form>
                    </td>
                    <td class="text-right td-mute">R$ <?= number_format((float) ($lm['valor_unitario'] ?? 0), 4, ',', '.') ?></td>
                    <td class="text-right"><strong>R$ <?= number_format((float) ($lm['subtotal'] ?? 0), 2, ',', '.') ?></strong></td>
                    <td class="td-mute"><?= htmlspecialchars((string) ($lm['observacao'] ?? '')) ?></td>
                    <td class="text-right">
                      <form method="post" style="display:inline;" data-confirm="Remover esta linha?" data-confirm-danger>
                        <input type="hidden" name="acao" value="chamado_item_del">
                        <input type="hidden" name="linha_id" value="<?= (int) ($lm['id'] ?? 0) ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                      </form>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

          <form method="post" class="form-grid form-grid--chamado-item-add">
            <input type="hidden" name="acao" value="chamado_item_add">
            <div class="chamado-item-add-toprow">
              <div class="form-group chamado-item-add-field--mov">
                <label for="movimento_mat">Movimento</label>
                <select id="movimento_mat" name="movimento" class="select">
                  <option value="utilizado">Usado no chamado</option>
                  <option value="devolvido">Devolvido/recolhido</option>
                </select>
              </div>
              <div class="form-group chamado-item-add-field--qtd">
                <label for="qtd_mat">Quantidade</label>
                <input type="text" id="qtd_mat" name="quantidade" class="input" value="1" inputmode="decimal" required>
              </div>
              <div class="chamado-item-add-submit">
                <button type="submit" class="btn btn-primary">Lançar</button>
              </div>
            </div>
            <div class="form-group chamado-item-add-catalog">
              <label for="filtro_catalogo_chamado_mat">Item do catálogo</label>
              <div class="chamado-catalog-combo">
                <input type="search" class="input chamado-catalog-search" id="filtro_catalogo_chamado_mat" autocomplete="off"
                       placeholder="Digite para buscar — a lista abaixo mostra só algumas linhas filtradas"
                       aria-describedby="hint_catalogo_chamado_mat">
                <select id="item_id_mat" name="item_id" class="select chamado-catalog-select" required size="5"
                        aria-label="Itens do catálogo (use setas ou clique)">
                  <option value="">— Selecione —</option>
                  <?php foreach ($catalogoItensChamado as $ci): ?>
                    <option value="<?= (int) $ci['id'] ?>">
                      <?= htmlspecialchars((string) ($ci['tipo'] ?? '')) ?> · <?= htmlspecialchars((string) ($ci['nome'] ?? '')) ?>
                      (R$ <?= number_format((float) ($ci['valor_unitario'] ?? 0), 2, ',', '.') ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <span class="hint" id="hint_catalogo_chamado_mat">Digite para filtrar · seta ↓ no campo de busca abre a lista · depois use «Lançar».</span>
            </div>
            <div class="form-group">
              <label for="obs_mat">Observação</label>
              <input type="text" id="obs_mat" name="observacao" class="input" placeholder="Ex.: lâmpada queimada recolhida">
            </div>
          </form>
          <?php if (empty($catalogoItensChamado)): ?>
            <p class="muted" style="margin-top:12px;margin-bottom:0;font-size:13px;">
              Sem itens ativos no catálogo desta empresa.
              <?php if ($empresaChamadoId > 0): ?>
                <a href="catalogo.php?cliente_id=<?= (int) $empresaChamadoId ?>">Abrir catálogo</a>
              <?php endif; ?>
            </p>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if (db_ok()): ?>
      <div class="card">
        <div class="panel-head">
          <h4>Aprovação do gestor</h4>
          <span class="panel-sub">Checklist do que foi realizado e aprovação final do chamado</span>
        </div>
        <div class="panel-body">
          <?php if (!empty($chamado['aprovado_gestor_em'])): ?>
            <div class="panel-note">
              <div class="panel-head"><h4>Atendimento aprovado</h4></div>
              <div class="panel-body" style="line-height:1.6;">
                <p class="muted" style="margin:0 0 8px;">
                  Aprovado por <?= htmlspecialchars((string) ($chamado['aprovado_gestor_nome'] ?? 'gestor')) ?>
                  em <?= date('d/m/Y H:i', strtotime((string) $chamado['aprovado_gestor_em'])) ?>.
                </p>
                <?php if (!empty($chamado['checklist_realizado'])): ?>
                  <strong>Checklist realizado</strong>
                  <p style="margin:6px 0 0;color:var(--muted);"><?= nl2br(htmlspecialchars((string) $chamado['checklist_realizado'])) ?></p>
                <?php endif; ?>
              </div>
            </div>
          <?php elseif (!empty($chamado['finalizado_operador_em'])): ?>
            <form method="post" class="form-stack" style="display:flex;flex-direction:column;gap:12px;">
              <input type="hidden" name="acao" value="aprovar_execucao">
              <p class="muted" style="margin:0;">
                Técnico finalizou em <?= date('d/m/Y H:i', strtotime((string) $chamado['finalizado_operador_em'])) ?>.
                Confira os itens usados/devolvidos e registre o checklist antes de aprovar.
              </p>
              <div class="form-group" style="margin:0;">
                <label for="checklist_realizado">Checklist do que foi realizado</label>
                <textarea id="checklist_realizado" name="checklist_realizado" class="textarea" rows="4"
                          placeholder="Ex.: troca de 2 lâmpadas, teste de funcionamento, recolhimento dos itens queimados..."><?= htmlspecialchars((string) ($chamado['checklist_realizado'] ?? '')) ?></textarea>
              </div>
              <div class="form-actions">
                <button type="submit" class="btn btn-primary">Aprovar execução e resolver chamado</button>
              </div>
            </form>
          <?php else: ?>
            <p class="muted" style="margin:0;">Aguardando o técnico finalizar o atendimento pelo PWA.</p>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- CONVERSA -->
      <div class="card">
        <div class="panel-head">
          <h4>Conversa</h4>
          <span class="panel-sub"><?= count($respostas) ?> mensagem(s)</span>
        </div>

        <div class="thread">
          <?php if (empty($respostas)): ?>
            <div class="empty-state" style="padding:20px;">
              <div class="empty-icon">💬</div>
              <p>Nenhuma resposta ainda.</p>
              <small>Use o campo abaixo para responder ao cliente ou registrar uma nota interna.</small>
            </div>
          <?php else: foreach ($respostas as $r): ?>
            <div class="msg <?= $r['tipo'] === 'admin' ? 'me' : '' ?> <?= !empty($r['interna']) ? 'msg-interna' : '' ?>">
              <div class="avatar avatar-sm"><?= initials($r['autor']) ?></div>
              <div class="bubble">
                <div class="bubble-head">
                  <strong><?= htmlspecialchars($r['autor']) ?></strong>
                  <?php if (!empty($r['interna'])): ?>
                    <span class="badge urgent" style="font-size:10px;">Nota interna</span>
                  <?php endif; ?>
                  <span><?= date('d/m/Y H:i', strtotime($r['data'])) ?></span>
                </div>
                <p><?= nl2br(htmlspecialchars($r['texto'])) ?></p>
                <?php
                  $anexosMsg = $anexosPorResposta[(int) ($r['id'] ?? 0)] ?? [];
                  if ($anexosMsg):
                ?>
                  <div class="bubble-files">
                    <?php foreach ($anexosMsg as $ax): ?>
                      <a class="file-chip" href="chamado_download.php?id=<?= $ax['id'] ?>">
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

        <!-- COMPOSER -->
        <form class="composer" method="post" enctype="multipart/form-data" id="composer-form">
          <input type="hidden" name="acao" value="responder">
          <input type="hidden" name="interna" id="composer-interna" value="">
          <textarea class="textarea" id="composer-text" name="resposta"
                    placeholder="Escreva uma resposta para o portal da prefeitura..."></textarea>

          <!-- Lista de arquivos selecionados (preview antes de enviar) -->
          <div class="composer-files" id="composer-files" hidden>
            <strong>Arquivos selecionados:</strong>
            <ul id="composer-files-list"></ul>
          </div>

          <input type="file" id="composer-file-input" name="anexos[]" multiple hidden
                 accept=".pdf,.doc,.docx,.odt,.rtf,.txt,.xls,.xlsx,.ods,.csv,.png,.jpg,.jpeg,.gif,.webp,.zip,.rar,.7z">

          <div class="composer-bar">
            <div class="composer-tools">
              <button type="button" class="tool" id="btn-anexo">+ Anexo</button>
              <button type="button" class="tool" id="btn-interna" title="Só admins verão esta mensagem">
                <span class="dot"></span> Interna
              </button>
            </div>
            <button type="submit" class="btn btn-primary" id="btn-enviar">Enviar resposta</button>
          </div>
        </form>
      </div>
    </div>

    <aside class="flex-col" style="gap:18px;">
      <!-- INFORMAÇÕES -->
      <div class="card">
        <div class="panel-head"><h4>Informações</h4></div>
        <div class="panel-body flex-col" style="gap:12px;">
          <div class="info-row"><span>Prefeitura / órgão</span>
            <?php if ($cliente): ?>
              <strong><a href="cliente_detalhe.php?id=<?= (int)$cliente['id'] ?>"><?= htmlspecialchars($chamado['cliente']) ?></a></strong>
            <?php else: ?>
              <strong><?= htmlspecialchars($chamado['cliente']) ?></strong>
            <?php endif; ?>
          </div>
          <?php if ($cliente): ?>
            <div class="info-row"><span>Contato</span><strong><?= htmlspecialchars($cliente['nome']) ?></strong></div>
            <div class="info-row"><span>E-mail</span><strong><?= htmlspecialchars($cliente['email']) ?></strong></div>
            <div class="info-row"><span>Telefone</span><strong><?= htmlspecialchars($cliente['telefone']) ?></strong></div>
            <?php if ($empresaChamadoId > 0): ?>
            <div class="info-row"><span>Catálogo</span>
              <strong><a href="catalogo.php?cliente_id=<?= (int) $empresaChamadoId ?>">Abrir</a></strong>
            </div>
            <?php endif; ?>
          <?php endif; ?>

          <form method="post" class="info-row">
            <input type="hidden" name="acao" value="tecnico">
            <span>Técnico</span>
            <span class="info-edit-value">
              <select name="tecnico_user_id" class="select">
                <option value="0">— Sem técnico —</option>
                <?php foreach ($tecnicosChamado as $tec): ?>
                  <option value="<?= (int) $tec['id'] ?>" <?= (int) ($chamado['tecnico_user_id'] ?? 0) === (int) $tec['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string) ($tec['nome'] ?? '')) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="action primary">OK</button>
            </span>
          </form>
          <?php if (empty($tecnicosChamado)): ?>
            <p class="muted" style="font-size:12px;margin:0;">Nenhum técnico vinculado a esta empresa.</p>
          <?php endif; ?>

          <div class="info-row"><span>Prioridade</span><strong><?= htmlspecialchars($chamado['prioridade']) ?></strong></div>
          <div class="info-row"><span>Valor do chamado</span><strong>R$ <?= number_format($totalMateriaisChamado, 2, ',', '.') ?></strong></div>
          <?php if (!empty($chamado['finalizado_operador_em'])): ?>
            <div class="info-row"><span>Finalizado pelo técnico</span><strong><?= date('d/m/Y H:i', strtotime((string) $chamado['finalizado_operador_em'])) ?></strong></div>
          <?php endif; ?>
          <?php if (!empty($chamado['aprovado_gestor_em'])): ?>
            <div class="info-row"><span>Aprovado</span><strong><?= date('d/m/Y H:i', strtotime((string) $chamado['aprovado_gestor_em'])) ?></strong></div>
          <?php endif; ?>

          <!-- Status editável -->
          <form method="post" class="info-row">
            <input type="hidden" name="acao" value="status">
            <span>Status</span>
            <span class="info-edit-value">
              <select name="status" class="select">
                <?php foreach (['Aberto','Em andamento','Aguardando','Resolvido','Fechado','Cancelado'] as $s): ?>
                  <option <?= $chamado['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="action primary">OK</button>
            </span>
          </form>
        </div>
      </div>

      <?php if (db_ok() && ((int) ($chamado['ponto_iluminacao_id'] ?? 0) > 0)): ?>
      <div class="card">
        <div class="panel-head">
          <h4>Poste / ponto de iluminação</h4>
          <span class="panel-sub">Dados do cadastro vinculado a este chamado</span>
        </div>
        <div class="panel-body flex-col" style="gap:10px;">
          <?php if ($pontoChamado): ?>
            <div class="info-row"><span>Código</span><strong><?= htmlspecialchars((string) ($pontoChamado['codigo_poste'] ?? '')) ?></strong></div>
            <?php if (!empty($pontoChamado['identificador_externo'])): ?>
            <div class="info-row"><span>Barramento / ID externo</span><strong><?= htmlspecialchars((string) $pontoChamado['identificador_externo']) ?></strong></div>
            <?php endif; ?>
            <?php if (!empty($pontoChamado['bairro'])): ?>
            <div class="info-row"><span>Bairro</span><strong><?= htmlspecialchars((string) $pontoChamado['bairro']) ?></strong></div>
            <?php endif; ?>
            <?php if (!empty($pontoChamado['referencia'])): ?>
            <div class="info-row"><span>Referência</span><strong><?= htmlspecialchars((string) $pontoChamado['referencia']) ?></strong></div>
            <?php endif; ?>
            <div class="info-row"><span>Status no cadastro</span>
              <strong><span class="badge <?= (($pontoChamado['status'] ?? '') === 'Ativo') ? 'success' : 'plain' ?>"><?= htmlspecialchars((string) ($pontoChamado['status'] ?? '—')) ?></span></strong>
            </div>
            <?php if (!empty($pontoChamado['endereco_completo'])): ?>
            <div class="info-row" style="align-items:flex-start;"><span>Endereço (cadastro)</span>
              <strong style="text-align:right;font-weight:500;line-height:1.45;"><?= nl2br(htmlspecialchars((string) $pontoChamado['endereco_completo'])) ?></strong>
            </div>
            <?php endif; ?>
            <?php if (!empty($pontoChamado['latitude']) && !empty($pontoChamado['longitude'])): ?>
            <div class="info-row"><span>Coordenadas (cadastro)</span>
              <strong class="td-mute"><?= htmlspecialchars((string) $pontoChamado['latitude']) ?>, <?= htmlspecialchars((string) $pontoChamado['longitude']) ?></strong>
            </div>
            <?php endif; ?>
            <?php if (!empty($pontoChamado['observacoes'])): ?>
            <div style="margin-top:4px;">
              <span class="muted" style="font-size:12px;display:block;margin-bottom:6px;">Observações do cadastro</span>
              <p style="margin:0;color:var(--muted);font-size:13px;line-height:1.55;"><?= nl2br(htmlspecialchars((string) $pontoChamado['observacoes'])) ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($pontoChamadoImagens)): ?>
            <details class="chamado-ponto-fotos">
              <summary>Fotos do poste (<?= count($pontoChamadoImagens) ?>) — clique para mostrar ou ocultar</summary>
              <div class="chamado-ponto-fotos-grid">
                <?php foreach ($pontoChamadoImagens as $pimg): ?>
                <div class="chamado-ponto-fotos-cell">
                  <?php if (!empty($pimg['principal'])): ?>
                    <span class="chamado-ponto-fotos-badge">Principal</span>
                  <?php endif; ?>
                  <a href="ponto_iluminacao_imagem.php?id=<?= (int) ($pimg['id'] ?? 0) ?>" target="_blank" rel="noopener" title="<?= htmlspecialchars((string) ($pimg['nome_original'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    <img src="ponto_iluminacao_imagem.php?id=<?= (int) ($pimg['id'] ?? 0) ?>" alt="" loading="lazy" width="120" height="120">
                  </a>
                </div>
                <?php endforeach; ?>
              </div>
            </details>
            <?php else: ?>
            <p class="muted" style="margin:12px 0 0;font-size:12px;">Sem imagens cadastradas para este poste.</p>
            <?php endif; ?>
          <?php else: ?>
            <p class="muted" style="margin:0;font-size:13px;line-height:1.5;">
              O chamado referencia o poste #<?= (int) ($chamado['ponto_iluminacao_id'] ?? 0) ?>,
              mas o cadastro não foi encontrado ou não pertence a este cliente.
              <?php if (!empty($chamado['ponto_codigo_poste'])): ?>
                <br><strong>Código gravado no chamado:</strong> <?= htmlspecialchars((string) $chamado['ponto_codigo_poste']) ?>
              <?php endif; ?>
            </p>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if (db_ok()): ?>
      <div class="card">
        <div class="panel-head">
          <h4>Endereço e mapa</h4>
          <span class="panel-sub">Aparece no mapa do dashboard quando latitude e longitude estão preenchidas</span>
        </div>
        <div class="panel-body">
          <div class="chamado-location-grid">
            <div class="form-group">
              <label for="endereco_completo">Endereço completo</label>
              <textarea id="endereco_completo" class="textarea" rows="3" readonly><?= htmlspecialchars((string) ($chamado['endereco_completo'] ?? '')) ?></textarea>
            </div>
            <div class="chamado-location-coords">
              <div class="form-group">
                <label for="latitude">Latitude</label>
                <input type="text" id="latitude" class="input" readonly
                       value="<?= isset($chamado['latitude']) && $chamado['latitude'] !== null ? htmlspecialchars((string) $chamado['latitude']) : '' ?>">
              </div>
              <div class="form-group">
                <label for="longitude">Longitude</label>
                <input type="text" id="longitude" class="input" readonly
                       value="<?= isset($chamado['longitude']) && $chamado['longitude'] !== null ? htmlspecialchars((string) $chamado['longitude']) : '' ?>">
              </div>
            </div>
            <div class="chamado-location-actions">
              <?php if (!empty($loadLeaflet)): ?>
                <a class="btn btn-ghost" target="_blank" rel="noopener"
                   href="https://www.openstreetmap.org/?mlat=<?= urlencode((string) $chamado['latitude']) ?>&amp;mlon=<?= urlencode((string) $chamado['longitude']) ?>&amp;zoom=16">Abrir no OSM</a>
              <?php endif; ?>
            </div>
          </div>
          <?php if (!empty($loadLeaflet)): ?>
          <div class="chamado-location-map" style="margin-top:14px;">
            <div id="chamado-map-mini" class="chamado-map-mini" aria-label="Mapa do chamado"></div>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- ANEXOS (gerais do chamado) -->
      <div class="card">
        <div class="panel-head">
          <h4>Anexos</h4>
          <span class="panel-sub"><?= count($anexos) ?> arquivo(s)</span>
        </div>
        <div class="panel-body flex-col" style="gap:10px;">
          <?php if (empty($anexos)): ?>
            <div class="empty-state" style="padding:16px 0;">
              <div class="empty-icon">📎</div>
              <p style="font-size:14px;">Nenhum anexo neste chamado.</p>
              <small>Use "+ Anexo" no formulário de resposta.</small>
            </div>
          <?php else: foreach ($anexos as $a): ?>
            <div class="file-item">
              <span class="file-item__meta">
                <?= upload_icone_por_ext($a['nome_original']) ?>
                <strong><?= htmlspecialchars($a['nome_original']) ?></strong><br>
                <small class="muted">
                  <?= upload_formatar_tamanho($a['tamanho']) ?>
                  · <?= htmlspecialchars($a['enviado_por'] ?? '—') ?>
                  <?= $a['enviado_tipo'] === 'cliente' ? '(cliente)' : '' ?>
                  · <?= htmlspecialchars($a['enviado_em']) ?>
                </small>
              </span>
              <div class="file-item-actions">
                <a class="btn btn-primary btn-sm" href="chamado_download.php?id=<?= (int) $a['id'] ?>">Baixar</a>
                <form method="post" action="chamado_detalhe.php?id=<?= (int) $id ?>">
                  <input type="hidden" name="acao" value="excluir_anexo">
                  <input type="hidden" name="anexo_id" value="<?= (int) $a['id'] ?>">
                  <button type="submit" class="btn btn-danger btn-sm"
                          data-confirm="Excluir o anexo &quot;<?= htmlspecialchars($a['nome_original'], ENT_QUOTES) ?>&quot;?"
                          data-confirm-danger>Excluir</button>
                </form>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

    </aside>
  </div>
</section>

<?php if (db_ok()): ?>
<script>
(function () {
  var ponto = document.getElementById('ponto_iluminacao_id');
  if (!ponto) return;
  function fillFromPonto() {
    var opt = ponto.options[ponto.selectedIndex];
    if (!opt || !opt.value || opt.value === '0') return;
    var end = opt.getAttribute('data-endereco') || '';
    var lat = opt.getAttribute('data-lat') || '';
    var lng = opt.getAttribute('data-lng') || '';
    var log = document.getElementById('os_logradouro');
    if (end && log) log.value = end;
    if (lat) {
      var la = document.getElementById('chamado_latitude');
      if (la) la.value = lat;
    }
    if (lng) {
      var lo = document.getElementById('chamado_longitude');
      if (lo) lo.value = lng;
    }
  }
  ponto.addEventListener('change', fillFromPonto);
})();
</script>
<?php endif; ?>

<?php if (!empty($loadLeaflet)): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script>
(function () {
  var lat = <?= json_encode((float) $chamado['latitude']) ?>;
  var lng = <?= json_encode((float) $chamado['longitude']) ?>;
  var map = L.map('chamado-map-mini', { scrollWheelZoom: false, zoomControl: true });
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OSM' }).addTo(map);
  L.marker([lat, lng]).addTo(map);
  map.setView([lat, lng], 15);
})();
</script>
<?php endif; ?>
<script>
(function () {
  var sel = document.getElementById('item_id_mat');
  var filtro = document.getElementById('filtro_catalogo_chamado_mat');
  if (!sel || !filtro) return;
  var opts = Array.from(sel.options);

  function firstVisibleWithValue() {
    for (var i = 0; i < sel.options.length; i++) {
      var o = sel.options[i];
      if (!o.hidden && o.value !== '') return i;
    }
    return 0;
  }

  function applyFilter() {
    var q = filtro.value.trim().toLowerCase();
    opts.forEach(function (o) {
      if (o.value === '') {
        o.hidden = false;
        return;
      }
      if (!q) {
        o.hidden = false;
        return;
      }
      o.hidden = o.textContent.toLowerCase().indexOf(q) === -1;
    });
    var cur = sel.selectedOptions[0];
    if (cur && cur.hidden) {
      sel.selectedIndex = 0;
    }
  }

  filtro.addEventListener('input', applyFilter);

  filtro.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      sel.focus();
      sel.selectedIndex = firstVisibleWithValue();
      return;
    }
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      sel.focus();
      if (sel.selectedIndex <= 0 || (sel.selectedOptions[0] && sel.selectedOptions[0].hidden)) {
        sel.selectedIndex = firstVisibleWithValue();
      }
    }
  });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
