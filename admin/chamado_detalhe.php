<?php
$CRM_CHAMADO_PORTAL = !empty($CRM_CHAMADO_PORTAL);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/upload.php';
if ($CRM_CHAMADO_PORTAL) {
    $user = require_auth('cliente');
    require_once __DIR__ . '/../includes/modules.php';
    require_modulo_cliente('chamados');
} else {
    $user = require_auth_gestao();
    require_once __DIR__ . '/../includes/modules.php';
    require_modulo_admin('chamados');
}
require_once __DIR__ . '/../includes/audit_log.php';

$chamadoPortalMatrizId = $CRM_CHAMADO_PORTAL ? repo_cliente_matriz_raiz_id((int) ($user['cliente_id'] ?? 0)) : 0;

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
    if ($CRM_CHAMADO_PORTAL) {
        if ($chamadoPortalMatrizId <= 0
            || !repo_cliente_pertence_empresa((int) ($__chAuth['cliente_id'] ?? 0), $chamadoPortalMatrizId)) {
            header('Location: chamados.php');
            exit;
        }
    } else {
        gestor_assert_escopo_cliente((int) ($__chAuth['cliente_id'] ?? 0), 'chamados.php');
    }
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
        $acao             = (string) ($_POST['acao'] ?? '');
        $silentAutosave   = isset($_POST['silent_autosave']) && (string) $_POST['silent_autosave'] === '1';

        if ($CRM_CHAMADO_PORTAL) {
            $trLeg = trim((string) ($_POST['resposta'] ?? ''));
            $temLeg = !empty($_FILES['anexos']['name'][0]);
            if ($acao === '' && ($trLeg !== '' || $temLeg)) {
                $acao = 'responder';
            }
            if ($acao !== 'responder') {
                flash_set('err', 'Esta ação não está disponível no portal do cliente.');
                header('Location: chamado_detalhe.php?id=' . $id);
                exit;
            }
            $__chPost = repo_chamado($id);
            if (!$__chPost || $chamadoPortalMatrizId <= 0
                || !repo_cliente_pertence_empresa((int) ($__chPost['cliente_id'] ?? 0), $chamadoPortalMatrizId)) {
                flash_set('err', 'Chamado não encontrado.');
                header('Location: chamados.php');
                exit;
            }
        }

        if ($acao === 'responder') {
            $texto    = trim((string) ($_POST['resposta'] ?? ''));
            $interna  = !empty($_POST['interna']);
            if ($CRM_CHAMADO_PORTAL) {
                $interna = false;
            }
            $temArq   = !empty($_FILES['anexos']['name'][0]);
            $arquivosSalvos = 0;
            $tipoResposta = $CRM_CHAMADO_PORTAL ? 'cliente' : 'admin';
            $tipoAnexo    = $CRM_CHAMADO_PORTAL ? 'cliente' : 'admin';
            $nomeAnexoPor = $CRM_CHAMADO_PORTAL ? ($user['nome'] ?? 'Cliente') : ($user['nome'] ?? 'Admin');

            if ($texto === '' && !$temArq) {
                flash_set('err', 'Informe uma resposta ou anexe ao menos um arquivo.');
                header('Location: chamado_detalhe.php?id=' . $id); exit;
            }

            $respostaId = null;
            if ($texto !== '') {
                $respostaId = repo_create_chamado_resposta(
                    $id,
                    $user['nome'],
                    $tipoResposta,
                    $texto,
                    $interna,
                    (int) ($user['id'] ?? 0)
                );
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
                        'enviado_por'   => $nomeAnexoPor,
                        'enviado_tipo'  => $tipoAnexo,
                    ]);
                    $arquivosSalvos++;
                }
                if ($res['erros']) {
                    flash_set('err', 'Falhas em anexos: ' . implode(' | ', $res['erros']));
                }
            }

            if ($CRM_CHAMADO_PORTAL) {
                $chSt = repo_chamado($id);
                if ($chSt && (($chSt['status'] ?? '') === 'Aguardando')) {
                    repo_update_chamado_status($id, 'Em andamento');
                }
            } elseif (!$interna) {
                repo_update_chamado_status($id, 'Em andamento');
            }

            if ($CRM_CHAMADO_PORTAL) {
                $msg = 'Mensagem enviada ao suporte.';
            } else {
                $msg = $interna ? 'Nota interna registrada.' : 'Resposta enviada ao cliente.';
            }
            if ($arquivosSalvos > 0) {
                $msg .= ' ' . $arquivosSalvos . ' anexo(s) salvo(s).';
            }
            flash_set('ok', $msg);

        } elseif ($acao === 'anexos_painel') {
            $temArq = !empty($_FILES['anexos']['name'][0]);
            if (!$temArq) {
                flash_set('err', 'Selecione ao menos um arquivo para enviar.');
            } else {
                $destino = upload_dir_chamado($id);
                $res = upload_gravar_multiplos($_FILES['anexos'], $destino);
                $arquivosSalvos = 0;
                foreach ($res['salvos'] as $arq) {
                    repo_create_chamado_anexo([
                        'chamado_id'      => $id,
                        'resposta_id'     => null,
                        'nome_original'   => $arq['nome_original'],
                        'nome_arquivo'    => $arq['nome_arquivo'],
                        'mime'            => $arq['mime'],
                        'tamanho'         => $arq['tamanho'],
                        'enviado_por'     => $user['nome'] ?? 'Admin',
                        'enviado_tipo'    => 'admin',
                    ]);
                    $arquivosSalvos++;
                }
                $msgParts = [];
                if ($arquivosSalvos > 0) {
                    $msgParts[] = $arquivosSalvos . ' anexo(s) adicionado(s).';
                }
                if (!empty($res['erros'])) {
                    $msgParts[] = 'Falhas: ' . implode(' | ', $res['erros']);
                }
                if ($arquivosSalvos > 0) {
                    flash_set('ok', implode(' ', $msgParts));
                } else {
                    flash_set('err', $msgParts ? implode(' ', $msgParts) : 'Não foi possível salvar os anexos.');
                }
            }

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
            $tecnicoIdsPost = $_POST['tecnico_user_ids'] ?? [];
            if (!is_array($tecnicoIdsPost)) {
                $tecnicoIdsPost = [];
            }
            if (empty($tecnicoIdsPost) && isset($_POST['tecnico_user_id'])) {
                $tecnicoIdsPost = [(int) ($_POST['tecnico_user_id'] ?? 0)];
            }
            $tecnicoIds = [];
            foreach ($tecnicoIdsPost as $tid) {
                $tid = (int) $tid;
                if ($tid > 0 && !in_array($tid, $tecnicoIds, true)) {
                    $tecnicoIds[] = $tid;
                }
            }
            $r = repo_chamado_atribuir_tecnicos($id, $tecnicoIds, $empresaChamado);
            if ($r['ok']) {
                if (!$silentAutosave) {
                    flash_set('ok', 'Técnicos vinculados ao chamado.');
                }
            } else {
                flash_set('err', $r['err']);
            }

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
                if (!$silentAutosave) {
                    flash_set('ok', 'Ficha da ordem de serviço atualizada.');
                }
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

        } elseif ($acao === 'chamado_item_edit') {
            $lid = (int) ($_POST['linha_id'] ?? 0);
            $qtd = (float) str_replace(',', '.', (string) ($_POST['quantidade'] ?? '1'));
            $obs = trim((string) ($_POST['observacao'] ?? ''));
            if ($lid > 0 && $qtd > 0 && repo_chamado_item_atualizar_linha($lid, $id, $qtd, $obs !== '' ? $obs : null)) {
                flash_set('ok', 'Item do atendimento atualizado.');
            } else {
                flash_set('err', 'Não foi possível atualizar o lançamento.');
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
 * Exportação (GET): um chamado — XLSX ou PDF (HTML para impressão / guardar como PDF)
 * ------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $exportFmt = strtolower(trim((string) ($_GET['export'] ?? '')));
    if (in_array($exportFmt, ['xlsx', 'pdf'], true)) {
        $pontoFotosEx = [];
        if (db_ok()) {
            $chEx = repo_chamado($id);
            if (!$chEx) {
                header('Location: chamados.php');
                exit;
            }
            if ($CRM_CHAMADO_PORTAL) {
                if ($chamadoPortalMatrizId <= 0
                    || !repo_cliente_pertence_empresa((int) ($chEx['cliente_id'] ?? 0), $chamadoPortalMatrizId)) {
                    header('Location: chamados.php');
                    exit;
                }
            } else {
                gestor_assert_escopo_cliente((int) ($chEx['cliente_id'] ?? 0), 'chamados.php');
            }
            $respostasEx = repo_chamado_respostas_do_ticket($id, $CRM_CHAMADO_PORTAL);
            $materiaisEx = repo_chamado_itens_list($id);
            $anexosEx    = repo_chamado_anexos($id);
            $pidEx       = (int) ($chEx['ponto_iluminacao_id'] ?? 0);
            if ($pidEx > 0) {
                $pontoEx = repo_ponto_iluminacao($pidEx);
                if ($pontoEx && (int) ($pontoEx['cliente_id'] ?? 0) === (int) ($chEx['cliente_id'] ?? 0)) {
                    $pontoFotosEx = repo_ponto_iluminacao_imagens_list($pidEx);
                }
            }
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

        if ($CRM_CHAMADO_PORTAL) {
            $respostasEx = array_values(array_filter(
                $respostasEx,
                static fn ($r) => empty($r['interna'])
            ));
            if (!db_ok()) {
                if ((int) ($chEx['cliente_id'] ?? 0) !== (int) ($user['cliente_id'] ?? 0)) {
                    header('Location: chamados.php');
                    exit;
                }
            }
        }

        $stamp = date('Y-m-d_His');
        if ($exportFmt === 'pdf') {
            require_once __DIR__ . '/../includes/chamado_export_document.php';
            $cidEx = (int) ($chEx['cliente_id'] ?? 0);
            audit_log_registar('chamado.exportar', 'chamado', $id, $cidEx > 0 ? $cidEx : null, [
                'formato' => 'pdf_html',
            ]);
            $htmlOut = chamado_export_document_html($chEx, $respostasEx, $materiaisEx, $anexosEx, true, $pontoFotosEx, false);
            header('Content-Type: text/html; charset=UTF-8');
            header('Content-Disposition: inline; filename="chamado_' . $id . '_impressao.html"');
            echo $htmlOut;
            exit;
        }

        require_once __DIR__ . '/../includes/chamado_export_xlsx.php';
        $totalEx = db_ok() ? repo_chamado_itens_valor_total($id) : 0.0;
        $cidEx = (int) ($chEx['cliente_id'] ?? 0);
        audit_log_registar('chamado.exportar', 'chamado', $id, $cidEx > 0 ? $cidEx : null, [
            'formato' => 'xlsx',
        ]);
        chamado_export_xlsx_send($chEx, $respostasEx, $materiaisEx, $anexosEx, $pontoFotosEx, $id, $user, $totalEx);
        exit;
    }
}

/* --------------------------------------------------------------
 * Carga
 * ------------------------------------------------------------- */
$chamadoMateriais     = [];
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
    if (function_exists('repo_notificacoes_marcar_lidas_chamado') && repo_notificacoes_table_exists() && !$CRM_CHAMADO_PORTAL) {
        repo_notificacoes_marcar_lidas_chamado((int) ($user['id'] ?? 0), $id);
    }
    if ($CRM_CHAMADO_PORTAL) {
        if ($chamadoPortalMatrizId <= 0
            || !repo_cliente_pertence_empresa((int) ($chamado['cliente_id'] ?? 0), $chamadoPortalMatrizId)) {
            header('Location: chamados.php');
            exit;
        }
    }
    $respostas = repo_chamado_respostas_do_ticket($id, $CRM_CHAMADO_PORTAL);
    try {
        $empresaChamadoId      = repo_cliente_catalogo_dono_id((int) $chamado['cliente_id']);
        $tecnicosChamado       = $empresaChamadoId > 0 ? repo_operadores_empresa($empresaChamadoId) : [];
        $chamadoMateriais      = repo_chamado_itens_list($id);
        $catalogoItensChamado  = repo_cliente_itens_list((int) $chamado['cliente_id'], true);
    } catch (Throwable $e) {
        $chamadoMateriais = [];
    }
    $chamadoMateriaisUtil = array_values(array_filter(
        $chamadoMateriais,
        static fn (array $m): bool => (($m['movimento'] ?? '') !== 'devolvido')
    ));
    $chamadoMateriaisDev = array_values(array_filter(
        $chamadoMateriais,
        static fn (array $m): bool => (($m['movimento'] ?? '') === 'devolvido')
    ));
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
    if ($CRM_CHAMADO_PORTAL && (int) ($chamado['cliente_id'] ?? 0) !== (int) ($user['cliente_id'] ?? 0)) {
        header('Location: chamados.php');
        exit;
    }
    $respostas = $MOCK_CHAMADO_RESPOSTAS[$chamado['id']] ?? [];
    if ($CRM_CHAMADO_PORTAL) {
        $respostas = array_values(array_filter(
            $respostas,
            static fn ($r) => empty($r['interna'])
        ));
    }
    $pontosOsForm = [];
    $chamadoMateriaisUtil = [];
    $chamadoMateriaisDev  = [];
}

$cliente   = find_by_id($MOCK_CLIENTES, $chamado['cliente_id']);
$anexos    = db_ok() ? repo_chamado_anexos($chamado['id']) : [];
$tecnicoIdsSelecionados = [];
foreach (($chamado['tecnico_ids'] ?? []) as $tid) {
    $tid = (int) $tid;
    if ($tid > 0) {
        $tecnicoIdsSelecionados[$tid] = true;
    }
}
if (empty($tecnicoIdsSelecionados) && !empty($chamado['tecnico_user_id'])) {
    $tecnicoIdsSelecionados[(int) $chamado['tecnico_user_id']] = true;
}
$tecnicoNomesSelecionados = $chamado['tecnico_nomes'] ?? [];
if (empty($tecnicoNomesSelecionados) && !empty($chamado['tecnico_nome'])) {
    $tecnicoNomesSelecionados = [(string) $chamado['tecnico_nome']];
}

/* agrupa anexos por resposta para exibir junto da mensagem (e os "soltos" no painel) */
$anexosPorResposta = [];
foreach ($anexos as $a) {
    if (!empty($a['resposta_id'])) {
        $anexosPorResposta[(int) $a['resposta_id']][] = $a;
    }
}

/* Cabeçalho: segmentos do título (tipo · problema · origem) */
$tituloRaw = trim((string) ($chamado['titulo'] ?? ''));
$tituloPartes = $tituloRaw !== ''
    ? preg_split('/\s*·\s*/u', $tituloRaw, -1, PREG_SPLIT_NO_EMPTY)
    : [];
$tituloPartes = array_values(array_filter(array_map('trim', $tituloPartes), static fn ($p) => $p !== ''));
$chamadoTituloPrincipal = $tituloPartes[0] ?? ($tituloRaw !== '' ? $tituloRaw : 'Chamado');

$lblPosteHeader = '';
if (db_ok() && (int) ($chamado['ponto_iluminacao_id'] ?? 0) > 0) {
    $lblPosteHeader = $pontoChamado
        ? (string) ($pontoChamado['codigo_poste'] ?? '')
        : (string) ($chamado['ponto_codigo_poste'] ?? '');
    $lblPosteHeader = $lblPosteHeader !== '' ? $lblPosteHeader : '#' . (int) $chamado['ponto_iluminacao_id'];
}

$tsAberto = strtotime((string) ($chamado['data'] ?? ''));
$chamadoAbertoStr = ($tsAberto !== false)
    ? 'Aberto em ' . date('d/m/Y', $tsAberto) . ' às ' . date('H:i', $tsAberto)
    : 'Aberto em —';
$chamadoAbertoCurto = ($tsAberto !== false)
    ? 'Aberto ' . date('d/m/y', $tsAberto) . ' · ' . date('H:i', $tsAberto)
    : '—';

$lblCanalHeader = trim((string) ($chamado['origem_os'] ?? ''));

$topTitle    = 'Chamado #' . $chamado['id'];
$topSubtitle = $chamado['titulo'];
$topSearch   = $CRM_CHAMADO_PORTAL ? 'Buscar em meus chamados...' : 'Buscar em chamados...';
$topAction   = ['label' => 'Voltar', 'href' => 'chamados.php', 'icon' => '←'];
$loadComposer = true;
$loadLeaflet  = db_ok()
    && isset($chamado['latitude'], $chamado['longitude'])
    && $chamado['latitude'] !== null
    && $chamado['longitude'] !== null;

$valorChamadoItens = db_ok() ? repo_chamado_itens_valor_total($id) : 0.0;

$chDetalheSidebar = $CRM_CHAMADO_PORTAL ? 'sidebar-cliente.php' : 'sidebar-admin.php';

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/' . $chDetalheSidebar; ?>
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

    .chamado-tecnicos-selected {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      justify-content: flex-end;
    }

    .chamado-ponto-panel .chamado-ponto-endereco {
      margin-top: 2px;
      padding: 12px 14px;
      border-radius: 12px;
      border: 1px solid rgba(83, 74, 183, 0.14);
      background: var(--surface-raised, #fafafa);
    }

    .chamado-ponto-endereco__label {
      display: block;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: var(--muted);
      margin-bottom: 6px;
    }

    .chamado-ponto-endereco__text {
      font-size: 14px;
      font-weight: 500;
      line-height: 1.5;
      color: var(--text);
    }

    /* Anexos: miniatura para imagens (URL = chamado_download.php, como operador/cliente) */
    .file-item.file-item--thumb {
      align-items: center;
    }

    .file-item__thumb {
      flex-shrink: 0;
      display: block;
      width: 72px;
      height: 72px;
      border-radius: 10px;
      overflow: hidden;
      border: 1px solid var(--border-soft);
      background: var(--surface-raised, #f0f0f7);
    }

    .file-item__thumb img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
      pointer-events: none;
    }

    button.file-item__thumb {
      cursor: zoom-in;
      padding: 0;
      margin: 0;
      border: none;
      font: inherit;
      color: inherit;
      text-align: left;
    }

    /* Preview anexo (sem nova aba) */
    .chamado-anexo-preview {
      position: fixed;
      inset: 0;
      z-index: 10050;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }

    .chamado-anexo-preview[hidden] {
      display: none !important;
    }

    .chamado-anexo-preview__scrim {
      position: absolute;
      inset: 0;
      margin: 0;
      border: 0;
      padding: 0;
      background: rgba(15, 23, 42, 0.82);
      cursor: pointer;
    }

    .chamado-anexo-preview__box {
      position: relative;
      z-index: 1;
      max-width: min(96vw, 1100px);
      max-height: 90vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 12px;
    }

    .chamado-anexo-preview__box img {
      max-width: 100%;
      max-height: calc(90vh - 100px);
      width: auto;
      height: auto;
      object-fit: contain;
      border-radius: 14px;
      box-shadow: 0 20px 50px rgba(0, 0, 0, 0.45);
      background: #0f172a;
    }

    .chamado-anexo-preview__close {
      position: absolute;
      top: -6px;
      right: -6px;
      z-index: 2;
      border-radius: 999px;
      min-width: 40px;
      min-height: 40px;
      padding: 0 12px;
      font-weight: 700;
      box-shadow: 0 4px 14px rgba(0, 0, 0, 0.25);
    }

    .chamado-anexo-preview__name {
      margin: 0;
      font-size: 13px;
      color: #e2e8f0;
      text-align: center;
      max-width: 100%;
      word-break: break-word;
      line-height: 1.4;
    }

  </style>

  <div class="ticket-header chamado-header-toolbar">
    <div class="chamado-header-toolbar__body">
      <div class="chamado-header-toolbar__identity">
        <div class="chamado-header-toolbar__eyebrow">CHAMADO #<?= (int) $chamado['id'] ?></div>
        <h2 class="chamado-header-toolbar__title"><?= htmlspecialchars($chamadoTituloPrincipal) ?></h2>
        <div class="chamado-header-toolbar__badges" aria-label="Status e metadados">
          <span class="chamado-header-toolbar__chip chamado-header-toolbar__chip--status badge <?= status_class($chamado['status']) ?>"><?= htmlspecialchars($chamado['status']) ?></span>
          <span class="chamado-header-toolbar__chip chamado-header-toolbar__chip--prio badge <?= status_class($chamado['prioridade']) ?>" title="Prioridade"><?= htmlspecialchars($chamado['prioridade']) ?></span>
          <?php if ($lblPosteHeader !== ''): ?>
            <span class="chamado-header-toolbar__chip chamado-header-toolbar__chip--muted" title="Poste / ponto"><?= htmlspecialchars($lblPosteHeader) ?></span>
          <?php endif; ?>
          <?php if ($lblCanalHeader !== ''): ?>
            <span class="chamado-header-toolbar__chip chamado-header-toolbar__chip--muted" title="Canal / origem"><?= htmlspecialchars($lblCanalHeader) ?></span>
          <?php endif; ?>
          <span class="chamado-header-toolbar__chip chamado-header-toolbar__chip--muted" title="<?= htmlspecialchars($chamadoAbertoStr) ?>"><?= htmlspecialchars($chamadoAbertoCurto) ?></span>
        </div>
      </div>
      <div class="chamado-header-toolbar__actions" aria-label="Ações rápidas">
        <?php if (db_ok() && !$CRM_CHAMADO_PORTAL): ?>
          <button type="submit" form="chamado-form-os-dados"
                  class="btn btn-secondary btn-sm chamado-header-toolbar__btn-save js-chamado-os-salvar"
                  id="chamado_os_salvar_manual"
                  disabled
                  title="Altere a ficha da OS antes de salvar">Salvar</button>
        <?php endif; ?>
        <?php if (!$CRM_CHAMADO_PORTAL && !in_array($chamado['status'], ['Resolvido', 'Fechado', 'Cancelado'], true)): ?>
          <form method="post" class="chamado-header-toolbar__form-inline">
            <input type="hidden" name="acao" value="resolver">
            <button class="btn btn-primary btn-sm" type="submit">Resolver</button>
          </form>
        <?php endif; ?>
        <a class="btn btn-ghost btn-sm chamado-header-toolbar__ghost"
           href="<?= htmlspecialchars('chamado_detalhe.php?' . http_build_query(['id' => $id, 'export' => 'pdf'])) ?>"
           title="Resumo do chamado com anexos e fotos do poste — use Imprimir para gerar PDF">PDF</a>
        <a class="btn btn-ghost btn-sm chamado-header-toolbar__ghost"
           href="<?= htmlspecialchars('chamado_detalhe.php?' . http_build_query(['id' => $id, 'export' => 'xlsx'])) ?>"
           title="Relatório Excel com várias abas: resumo, conversa, itens e anexos">Excel</a>
      </div>
    </div>
  </div>

  <div class="ticket-body">
    <div class="flex-col" style="gap:18px;">

      <?php if (db_ok()): ?>
      <?php if ($CRM_CHAMADO_PORTAL): ?>
      <div class="card" style="overflow:hidden;">
        <div class="panel-head">
          <h4>Ordem de serviço</h4>
          <span class="panel-sub">Contribuinte, endereço e classificação no mesmo bloco; descrição abaixo — igual ao formulário de abertura (somente leitura no portal)</span>
        </div>
        <fieldset disabled class="chamado-os-portal-readonly" style="border:0;margin:0;padding:0;min-width:0;">
        <div class="form" style="padding: 0 24px 20px;">
          <?php
          $ch_os_vals = $chamado;
          $ch_os_descricao = (string) ($chamado['descricao'] ?? '');
          $ch_os_mostrar_ponto = !empty($pontosOsForm);
          $ch_os_pontos_opcoes = $pontosOsForm;
          include __DIR__ . '/../includes/chamado_os_grid_markup.php';
          ?>
        </div>
        </fieldset>
      </div>
      <?php else: ?>
      <form method="post" id="chamado-form-os-dados" class="card" style="overflow:hidden;">
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
        <div class="chamado-os-form__actions" style="padding: 0 24px 22px; display:flex; justify-content:flex-end; gap:12px; border-top:1px solid var(--border); padding-top:16px;">
          <button type="submit" class="btn btn-secondary btn-sm js-chamado-os-salvar" disabled title="Altere a ficha da OS antes de salvar">Salvar</button>
        </div>
      </form>
      <?php endif; ?>
      <?php endif; ?>

      <?php if (db_ok()): ?>
      <?php
        $nMatU = count($chamadoMateriaisUtil);
        $nMatD = count($chamadoMateriaisDev);
        $nCatalogoProduto = 0;
        $nCatalogoServico = 0;
        $catalogoJsonForJs = [];
        foreach ($catalogoItensChamado as $_ci) {
            $catalogoJsonForJs[] = [
                'id' => (int) ($_ci['id'] ?? 0),
                'tipo' => (string) ($_ci['tipo'] ?? ''),
                'nome' => (string) ($_ci['nome'] ?? ''),
                'codigo' => (string) ($_ci['codigo'] ?? ''),
                'unidade' => (string) ($_ci['unidade'] ?? ''),
            ];
            $tt = strtolower(trim((string) ($_ci['tipo'] ?? '')));
            if ($tt === 'produto') {
                $nCatalogoProduto++;
            } elseif ($tt === 'servico') {
                $nCatalogoServico++;
            }
        }
      ?>
      <div class="card chamado-materiais-card">
        <div class="panel-head chamado-materiais-card__head">
          <div class="chamado-materiais-card__intro">
            <h4>Itens do atendimento</h4>
            <span class="panel-sub">Controle de materiais utilizados e recolhidos neste chamado</span>
          </div>
          <div class="chamado-materiais-card__stats" aria-label="Resumo rápido">
            <div class="chamado-materiais-card__pill">
              <span>Utilizados</span>
              <strong><?= (int) $nMatU ?></strong>
            </div>
            <div class="chamado-materiais-card__pill">
              <span>Recolhidos</span>
              <strong><?= (int) $nMatD ?></strong>
            </div>
            <div class="chamado-materiais-card__pill" style="min-width:118px;">
              <span>Valor (itens)</span>
              <strong>R$ <?= number_format((float) $valorChamadoItens, 2, ',', '.') ?></strong>
            </div>
          </div>
        </div>
        <div class="panel-body chamado-materiais-card__body">
          <?php if ($CRM_CHAMADO_PORTAL && !empty($chamado['checklist_realizado'])): ?>
            <div class="panel-note" style="margin-bottom:16px;">
              <div class="panel-head"><h4>Checklist realizado</h4></div>
              <div class="panel-body">
                <p style="margin:0;color:var(--muted);line-height:1.6;"><?= nl2br(htmlspecialchars((string) $chamado['checklist_realizado'])) ?></p>
              </div>
            </div>
          <?php endif; ?>
          <section class="chamado-materiais-block chamado-materiais-block--uso" aria-labelledby="ch-mat-uso-title">
            <div class="chamado-materiais-block__bar">
              <h5 id="ch-mat-uso-title" class="chamado-materiais-block__title">Itens utilizados</h5>
              <?php if (!$CRM_CHAMADO_PORTAL): ?>
              <div class="chamado-materiais-block__actions">
                <button type="button" class="btn btn-primary btn-sm js-cham-mat-open-pick" data-pick-mov="utilizado" data-catalog-tipo="produto"
                  <?= $nCatalogoProduto < 1 ? 'disabled title="Sem produtos no catálogo da empresa"' : '' ?>>Novo item</button>
                <button type="button" class="btn btn-secondary btn-sm js-cham-mat-open-pick" data-pick-mov="utilizado" data-catalog-tipo="servico"
                  <?= $nCatalogoServico < 1 ? 'disabled title="Sem serviços no catálogo da empresa"' : '' ?>>Novo serviço</button>
              </div>
              <?php endif; ?>
            </div>
            <?php if (empty($chamadoMateriaisUtil)): ?>
              <div class="chamado-materiais-empty">
                <p>Nenhum item utilizado lançado ainda.</p>
                <small><?= $CRM_CHAMADO_PORTAL ? 'Os itens aplicados pelo atendimento aparecem aqui.' : 'Adicione produtos ou serviços aplicados neste atendimento.' ?></small>
              </div>
            <?php else: ?>
              <div class="table-wrap chamado-itens-table chamado-itens-table--admin">
                <table class="chamado-materiais-table chamado-materiais-table--uso">
                  <thead>
                    <tr>
                      <th>Item</th>
                      <th>Tipo</th>
                      <th class="text-right">Qtd</th>
                      <?php if (!$CRM_CHAMADO_PORTAL): ?>
                      <th class="text-right td-actions">Ações</th>
                      <?php endif; ?>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($chamadoMateriaisUtil as $lm): ?>
                    <?php
                      $qDisp = htmlspecialchars(rtrim(rtrim(sprintf('%.4f', (float) ($lm['quantidade'] ?? 0)), '0'), '.'));
                      $lblItem = (string) ($lm['item_nome'] ?? '');
                      $codLm = trim((string) ($lm['item_codigo'] ?? ''));
                    ?>
                    <tr>
                      <td>
                        <strong><?= htmlspecialchars($lblItem) ?></strong>
                        <?php if ($codLm !== ''): ?>
                          <div class="td-mute" style="font-size:12px;">Cód. <?= htmlspecialchars($codLm) ?></div>
                        <?php endif; ?>
                      </td>
                      <td class="td-mute"><?= htmlspecialchars((string) ($lm['item_tipo'] ?? '')) ?></td>
                      <td class="text-right"><?= $qDisp ?></td>
                      <?php if (!$CRM_CHAMADO_PORTAL): ?>
                      <td class="text-right td-actions">
                        <button type="button" class="btn btn-secondary btn-sm js-cham-mat-open-edit"
                          data-linha-id="<?= (int) ($lm['id'] ?? 0) ?>"
                          data-qty="<?= $qDisp ?>"
                          data-obs="<?= htmlspecialchars((string) ($lm['observacao'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                          data-item-label="<?= htmlspecialchars($lblItem, ENT_QUOTES, 'UTF-8') ?>"
                          data-movimento="utilizado">Editar</button>
                        <form method="post" style="display:inline;" data-confirm="Remover esta linha?" data-confirm-danger>
                          <input type="hidden" name="acao" value="chamado_item_del">
                          <input type="hidden" name="linha_id" value="<?= (int) ($lm['id'] ?? 0) ?>">
                          <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                        </form>
                      </td>
                      <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </section>

          <section class="chamado-materiais-block chamado-materiais-block--dev" aria-labelledby="ch-mat-dev-title">
            <div class="chamado-materiais-block__bar">
              <h5 id="ch-mat-dev-title" class="chamado-materiais-block__title">Devolvidos / recolhidos</h5>
              <?php if (!$CRM_CHAMADO_PORTAL): ?>
              <button type="button" class="btn btn-secondary btn-sm js-cham-mat-open-pick" data-pick-mov="devolvido" data-catalog-tipo="produto"
                <?= $nCatalogoProduto < 1 ? 'disabled title="Sem produtos no catálogo para recolhimento"' : '' ?>>Recolher produto</button>
              <?php endif; ?>
            </div>
            <?php if (empty($chamadoMateriaisDev)): ?>
              <div class="chamado-materiais-empty chamado-materiais-empty--muted">
                <p>Nenhum item recolhido lançado ainda.</p>
                <small><?= $CRM_CHAMADO_PORTAL ? 'Recolhimentos feitos pela equipe aparecem aqui.' : 'Registre materiais retirados, devolvidos ou recolhidos no atendimento.' ?></small>
              </div>
            <?php else: ?>
              <div class="table-wrap chamado-itens-table chamado-itens-table--admin">
                <table class="chamado-materiais-table chamado-materiais-table--dev">
                  <thead>
                    <tr>
                      <th>Item</th>
                      <th>Tipo</th>
                      <th class="text-right">Qtd</th>
                      <?php if (!$CRM_CHAMADO_PORTAL): ?>
                      <th class="text-right td-actions">Ações</th>
                      <?php endif; ?>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($chamadoMateriaisDev as $lm): ?>
                    <?php
                      $qDispD = htmlspecialchars(rtrim(rtrim(sprintf('%.4f', (float) ($lm['quantidade'] ?? 0)), '0'), '.'));
                      $lblItemD = (string) ($lm['item_nome'] ?? '');
                      $codLmD = trim((string) ($lm['item_codigo'] ?? ''));
                    ?>
                    <tr>
                      <td>
                        <strong><?= htmlspecialchars($lblItemD) ?></strong>
                        <?php if ($codLmD !== ''): ?>
                          <div class="td-mute" style="font-size:12px;">Cód. <?= htmlspecialchars($codLmD) ?></div>
                        <?php endif; ?>
                      </td>
                      <td class="td-mute"><?= htmlspecialchars((string) ($lm['item_tipo'] ?? '')) ?></td>
                      <td class="text-right"><?= $qDispD ?></td>
                      <?php if (!$CRM_CHAMADO_PORTAL): ?>
                      <td class="text-right td-actions">
                        <button type="button" class="btn btn-secondary btn-sm js-cham-mat-open-edit"
                          data-linha-id="<?= (int) ($lm['id'] ?? 0) ?>"
                          data-qty="<?= $qDispD ?>"
                          data-obs="<?= htmlspecialchars((string) ($lm['observacao'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                          data-item-label="<?= htmlspecialchars($lblItemD, ENT_QUOTES, 'UTF-8') ?>"
                          data-movimento="devolvido">Editar</button>
                        <form method="post" style="display:inline;" data-confirm="Remover esta linha?" data-confirm-danger>
                          <input type="hidden" name="acao" value="chamado_item_del">
                          <input type="hidden" name="linha_id" value="<?= (int) ($lm['id'] ?? 0) ?>">
                          <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                        </form>
                      </td>
                      <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </section>

          <?php if (!$CRM_CHAMADO_PORTAL && empty($catalogoItensChamado)): ?>
            <p class="muted chamado-materiais-catalog-empty">Sem itens ativos no catálogo desta empresa.</p>
          <?php endif; ?>
        </div>
      </div>

      <?php if (!$CRM_CHAMADO_PORTAL): ?>
      <div id="chamado-mat-modal-pick" class="chamado-mat-modal" hidden aria-hidden="true">
        <button type="button" class="chamado-mat-modal__scrim" data-cham-mat-pick-close tabindex="-1" aria-label="Fechar"></button>
        <div class="chamado-mat-modal__box chamado-mat-modal__box--pick" role="dialog" aria-modal="true" aria-labelledby="chamado-mat-pick-title">
          <header class="chamado-mat-modal__head">
            <h3 id="chamado-mat-pick-title">Catálogo</h3>
            <button type="button" class="btn btn-ghost btn-sm" data-cham-mat-pick-close aria-label="Fechar">✕</button>
          </header>
          <div class="chamado-mat-modal__body chamado-mat-modal__body--pick">
            <form id="chamado-mat-form-pick" method="post" class="chamado-mat-form">
              <input type="hidden" name="acao" value="chamado_item_add">
              <input type="hidden" name="movimento" id="chamado-mat-pick-mov" value="utilizado">
              <input type="hidden" name="item_id" id="chamado-mat-pick-item-id" value="">
              <div class="form-group">
                <label for="chamado-mat-pick-busca">Buscar no catálogo</label>
                <input type="search" id="chamado-mat-pick-busca" class="input" autocomplete="off" placeholder="Nome ou código">
              </div>
              <div id="chamado-mat-pick-results" class="chamado-mat-results chamado-mat-results--modal" role="listbox" aria-label="Itens filtrados"></div>
              <div id="chamado-mat-pick-preview" class="chamado-mat-preview" hidden>
                <strong id="chamado-mat-pick-preview-nome"></strong>
                <div class="chamado-mat-preview__meta" id="chamado-mat-pick-preview-meta"></div>
              </div>
              <div class="form-group">
                <label for="chamado-mat-pick-qty">Quantidade</label>
                <input type="text" name="quantidade" id="chamado-mat-pick-qty" class="input" value="1" inputmode="decimal" required placeholder="Ex.: 1 ou 1,5">
              </div>
            </form>
          </div>
          <footer class="chamado-mat-modal__foot">
            <button type="button" class="btn btn-secondary" data-cham-mat-pick-close>Cancelar</button>
            <button type="submit" form="chamado-mat-form-pick" class="btn btn-primary" id="chamado-mat-pick-submit">Adicionar ao chamado</button>
          </footer>
        </div>
      </div>

      <div id="chamado-mat-drawer" class="chamado-mat-drawer" hidden aria-hidden="true">
        <div class="chamado-mat-drawer__backdrop" data-cham-mat-close tabindex="-1"></div>
        <aside class="chamado-mat-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="chamado-mat-drawer-title">
          <header class="chamado-mat-drawer__header">
            <h3 id="chamado-mat-drawer-title">Adicionar item</h3>
            <button type="button" class="btn btn-ghost btn-sm" data-cham-mat-close aria-label="Fechar">✕</button>
          </header>
          <div class="chamado-mat-drawer__body">
            <form id="chamado-mat-form-edit" method="post" class="chamado-mat-form" hidden>
              <input type="hidden" name="acao" value="chamado_item_edit">
              <input type="hidden" name="linha_id" id="chamado-mat-edit-linha-id" value="">
              <p class="chamado-mat-edit-itemline" id="chamado-mat-edit-itemlabel"></p>
              <div class="form-group">
                <label for="chamado-mat-qty-edit">Quantidade</label>
                <input type="text" name="quantidade" id="chamado-mat-qty-edit" class="input" inputmode="decimal" required>
              </div>
              <input type="hidden" name="observacao" id="chamado-mat-obs-edit-preserve" value="">
              <div class="chamado-mat-drawer__actions">
                <button type="button" class="btn btn-secondary" data-cham-mat-close>Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar alterações</button>
              </div>
            </form>
          </div>
        </aside>
      </div>

      <script type="application/json" id="chamado-catalogo-json"><?= json_encode($catalogoJsonForJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS) ?></script>
      <?php endif; ?>
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
              <small><?= $CRM_CHAMADO_PORTAL ? 'Use o campo abaixo para enviar uma mensagem ao suporte.' : 'Use o campo abaixo para responder ao cliente ou registrar uma nota interna.' ?></small>
            </div>
          <?php else: foreach ($respostas as $r): ?>
            <div class="msg <?= ($CRM_CHAMADO_PORTAL ? (($r['tipo'] ?? '') === 'cliente') : (($r['tipo'] ?? '') === 'admin')) ? 'me' : '' ?> <?= !empty($r['interna']) ? 'msg-interna' : '' ?>">
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
                    placeholder="<?= $CRM_CHAMADO_PORTAL ? 'Escreva uma mensagem para o suporte…' : 'Escreva uma resposta para o portal da prefeitura…' ?>"></textarea>

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
              <?php if (!$CRM_CHAMADO_PORTAL): ?>
              <button type="button" class="tool" id="btn-interna" title="Só admins verão esta mensagem">
                <span class="dot"></span> Interna
              </button>
              <?php endif; ?>
            </div>
            <button type="submit" class="btn btn-primary" id="btn-enviar"><?= $CRM_CHAMADO_PORTAL ? 'Enviar' : 'Enviar resposta' ?></button>
          </div>
        </form>
      </div>
    </div>

    <aside class="flex-col" style="gap:18px;">
      <?php if (db_ok() && !$CRM_CHAMADO_PORTAL): ?>
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

      <!-- INFORMAÇÕES -->
      <div class="card">
        <div class="panel-head"><h4>Informações</h4></div>
        <div class="panel-body flex-col" style="gap:12px;">
          <div class="info-row"><span>Prefeitura / órgão</span>
            <?php if ($cliente && !$CRM_CHAMADO_PORTAL): ?>
              <strong><a href="cliente_detalhe.php?id=<?= (int)$cliente['id'] ?>"><?= htmlspecialchars($chamado['cliente']) ?></a></strong>
            <?php else: ?>
              <strong><?= htmlspecialchars($chamado['cliente']) ?></strong>
            <?php endif; ?>
          </div>
          <?php if ($cliente && !$CRM_CHAMADO_PORTAL): ?>
            <div class="info-row"><span>Contato</span><strong><?= htmlspecialchars($cliente['nome']) ?></strong></div>
            <div class="info-row"><span>E-mail</span><strong><?= htmlspecialchars($cliente['email']) ?></strong></div>
            <div class="info-row"><span>Telefone</span><strong><?= htmlspecialchars($cliente['telefone']) ?></strong></div>
          <?php endif; ?>

          <div class="info-row" style="align-items:flex-start;">
            <span>Equipe</span>
            <span class="info-edit-value" style="text-align:right;">
              <?php if (!empty($tecnicoNomesSelecionados)): ?>
                <span class="chamado-tecnicos-selected" aria-label="Equipe vinculada ao chamado">
                  <?php foreach ($tecnicoNomesSelecionados as $tecNome): ?>
                    <span class="badge badge-plain"><?= htmlspecialchars((string) $tecNome) ?></span>
                  <?php endforeach; ?>
                </span>
              <?php else: ?>
                <strong class="muted" style="font-size:13px;">Ninguém na equipe ainda</strong>
              <?php endif; ?>
              <?php if (!$CRM_CHAMADO_PORTAL): ?>
              <span class="muted" style="font-size:11px;display:block;margin-top:6px;">Monte a dupla ou trio em «Equipe no chamado», acima do mapa.</span>
              <?php endif; ?>
            </span>
          </div>

          <div class="info-row"><span>Prioridade</span><strong><?= htmlspecialchars($chamado['prioridade']) ?></strong></div>
          <?php if (!empty($chamado['finalizado_operador_em'])): ?>
            <div class="info-row"><span>Finalizado pelo técnico</span><strong><?= date('d/m/Y H:i', strtotime((string) $chamado['finalizado_operador_em'])) ?></strong></div>
          <?php endif; ?>
          <?php if (!empty($chamado['aprovado_gestor_em'])): ?>
            <div class="info-row"><span>Aprovado</span><strong><?= date('d/m/Y H:i', strtotime((string) $chamado['aprovado_gestor_em'])) ?></strong></div>
          <?php endif; ?>

          <?php if ($CRM_CHAMADO_PORTAL): ?>
          <div class="info-row"><span>Status</span><strong><?= htmlspecialchars((string) ($chamado['status'] ?? '')) ?></strong></div>
          <?php else: ?>
          <!-- Status editável -->
          <form method="post" class="info-row">
            <input type="hidden" name="acao" value="status">
            <span>Status</span>
            <span class="info-edit-value">
              <select name="status" class="select" onchange="this.form.submit()">
                <?php foreach (['Aberto','Em andamento','Aguardando','Resolvido','Fechado','Cancelado'] as $s): ?>
                  <option <?= $chamado['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
              </select>
            </span>
          </form>
          <?php endif; ?>
        </div>
      </div>

      <?php if (db_ok() && ((int) ($chamado['ponto_iluminacao_id'] ?? 0) > 0)): ?>
      <?php
        $pontoEnderecoBloco = '';
        $pontoSvLat = null;
        $pontoSvLng = null;
        if ($pontoChamado) {
            $pontoEnderecoBloco = trim((string) ($pontoChamado['endereco_completo'] ?? ''));
            if ($pontoEnderecoBloco === '') {
                $pontoEnderecoBloco = trim((string) ($chamado['endereco_completo'] ?? ''));
            }
            $pla = $pontoChamado['latitude'] ?? null;
            $plo = $pontoChamado['longitude'] ?? null;
            if ($pla !== null && $plo !== null && $pla !== '' && $plo !== '' && is_numeric($pla) && is_numeric($plo)) {
                $pontoSvLat = (float) $pla;
                $pontoSvLng = (float) $plo;
            }
        }
        if ($pontoEnderecoBloco === '') {
            $pontoEnderecoBloco = trim((string) ($chamado['endereco_completo'] ?? ''));
        }
        if ($pontoSvLat === null || $pontoSvLng === null) {
            $cla = $chamado['latitude'] ?? null;
            $clo = $chamado['longitude'] ?? null;
            if ($cla !== null && $clo !== null && $cla !== '' && $clo !== '' && is_numeric($cla) && is_numeric($clo)) {
                $pontoSvLat = (float) $cla;
                $pontoSvLng = (float) $clo;
            }
        }
      ?>
      <div class="card">
        <div class="panel-head">
          <h4>Ponto de iluminação</h4>
          <span class="panel-sub">Cadastro vinculado a este chamado</span>
        </div>
        <div class="panel-body flex-col chamado-ponto-panel" style="gap:10px;">
          <?php if ($pontoChamado): ?>
            <div class="info-row"><span>Código</span><strong><?= htmlspecialchars((string) ($pontoChamado['codigo_poste'] ?? '')) ?></strong></div>
            <?php if ($pontoEnderecoBloco !== ''): ?>
            <div class="chamado-ponto-endereco">
              <span class="chamado-ponto-endereco__label">Endereço</span>
              <div class="chamado-ponto-endereco__text"><?= nl2br(htmlspecialchars($pontoEnderecoBloco)) ?></div>
            </div>
            <?php endif; ?>
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
            <?php if (!empty($pontoChamado['latitude']) && !empty($pontoChamado['longitude'])): ?>
            <div class="info-row"><span>Coordenadas (cadastro)</span>
              <strong class="td-mute"><?= htmlspecialchars((string) $pontoChamado['latitude']) ?>, <?= htmlspecialchars((string) $pontoChamado['longitude']) ?></strong>
            </div>
            <?php elseif ($pontoSvLat !== null && $pontoSvLng !== null): ?>
            <div class="info-row"><span>Coordenadas (chamado)</span>
              <strong class="td-mute"><?= htmlspecialchars((string) $pontoSvLat) ?>, <?= htmlspecialchars((string) $pontoSvLng) ?></strong>
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
              <summary>Fotos do ponto (<?= count($pontoChamadoImagens) ?>) — clique para mostrar ou ocultar</summary>
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
            <p class="muted" style="margin:12px 0 0;font-size:12px;">Sem imagens cadastradas para este ponto.</p>
            <?php endif; ?>
          <?php else: ?>
            <p class="muted" style="margin:0;font-size:13px;line-height:1.5;">
              O chamado referencia o ponto #<?= (int) ($chamado['ponto_iluminacao_id'] ?? 0) ?>,
              mas o cadastro não foi encontrado ou não pertence a este cliente.
              <?php if (!empty($chamado['ponto_codigo_poste'])): ?>
                <br><strong>Código gravado no chamado:</strong> <?= htmlspecialchars((string) $chamado['ponto_codigo_poste']) ?>
              <?php endif; ?>
            </p>
            <?php if ($pontoEnderecoBloco !== ''): ?>
            <div class="chamado-ponto-endereco">
              <span class="chamado-ponto-endereco__label">Endereço (chamado)</span>
              <div class="chamado-ponto-endereco__text"><?= nl2br(htmlspecialchars($pontoEnderecoBloco)) ?></div>
            </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if (db_ok() && !$CRM_CHAMADO_PORTAL): ?>
      <?php
        $equipeN = count($tecnicoIdsSelecionados);
        $equipeCountLabel = $equipeN === 0
            ? 'Ninguém selecionado'
            : ($equipeN === 1 ? '1 na equipe' : $equipeN . ' na equipe');
        $equipeCountClass = $equipeN === 0 ? 'equipe-picker-count equipe-picker-count--muted' : 'equipe-picker-count equipe-picker-count--ok';
      ?>
      <div class="card equipe-picker-card">
        <div class="panel-head">
          <h4>Equipe no chamado</h4>
          <span class="panel-sub">Quem vai ao campo neste atendimento — em geral dupla ou trio. Toque no nome para incluir ou tirar; use <strong>Salvar equipe</strong> para gravar.</span>
        </div>
        <div class="panel-body">
          <?php if (empty($tecnicosChamado)): ?>
            <p class="muted" style="margin:0;font-size:13px;line-height:1.5;">Nenhum operador cadastrado para a empresa deste catálogo. Cadastre operadores na empresa antes de montar a equipe.</p>
          <?php else: ?>
            <form method="post" class="equipe-picker-form" id="equipe_picker_form" autocomplete="off">
              <input type="hidden" name="acao" value="tecnico">
              <input type="hidden" name="silent_autosave" id="chamado_equipe_silent_autosave" value="">
              <div class="equipe-picker-top">
                <span id="equipe_picker_count" class="<?= htmlspecialchars($equipeCountClass) ?>" aria-live="polite"><?= htmlspecialchars($equipeCountLabel) ?></span>
                <label class="sr-only" for="filtro_equipe_chamado">Buscar operador</label>
                <input type="search" class="input equipe-picker-search" id="filtro_equipe_chamado" name="q_equipe_dummy" autocomplete="off"
                       placeholder="Buscar operador…" inputmode="search">
              </div>
              <div class="equipe-picker" id="equipe_picker_grid" role="group" aria-label="Operadores da empresa">
                <?php foreach ($tecnicosChamado as $tec): ?>
                  <?php
                    $tecId = (int) $tec['id'];
                    $tecNome = trim((string) ($tec['nome'] ?? ''));
                    $tecPartes = $tecNome !== '' ? preg_split('/\s+/u', $tecNome, 2) : [];
                    $tecPrenome = (string) ($tecPartes[0] ?? '');
                    $tecSobrenome = (string) ($tecPartes[1] ?? '');
                  ?>
                  <label class="equipe-picker__opt" data-equipe-pick
                         data-equipe-nome="<?= htmlspecialchars(mb_strtolower($tecNome, 'UTF-8'), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="checkbox" name="tecnico_user_ids[]" value="<?= $tecId ?>"
                           <?= isset($tecnicoIdsSelecionados[$tecId]) ? 'checked' : '' ?>>
                    <span class="equipe-picker__face">
                      <span class="equipe-picker__line">
                        <?php if ($tecPrenome !== ''): ?>
                          <span class="equipe-picker__gn"><?= htmlspecialchars($tecPrenome) ?></span>
                          <?php if ($tecSobrenome !== ''): ?>
                            <span class="equipe-picker__sn"><?= htmlspecialchars($tecSobrenome) ?></span>
                          <?php endif; ?>
                        <?php else: ?>
                          <span class="equipe-picker__gn">—</span>
                        <?php endif; ?>
                      </span>
                    </span>
                  </label>
                <?php endforeach; ?>
              </div>
              <p class="equipe-picker-many" id="equipe_picker_many" <?= $equipeN > 3 ? '' : 'hidden' ?>>Mais de três pessoas é incomum — confira se todos entram neste chamado.</p>
              <div class="equipe-picker-foot">
                <p class="equipe-picker-hint">Selecionados continuam visíveis na busca. Limpe o campo para ver a lista inteira.</p>
                <button type="submit" class="btn btn-primary btn-sm">Salvar equipe</button>
              </div>
            </form>
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
                   href="https://www.google.com/maps?q=<?= urlencode((string) $chamado['latitude'] . ',' . (string) $chamado['longitude']) ?>">Abrir no Google Maps</a>
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
          <?php if (db_ok() && !$CRM_CHAMADO_PORTAL): ?>
          <form method="post" action="chamado_detalhe.php?id=<?= (int) $id ?>" enctype="multipart/form-data" class="chamado-anexos-painel-upload" style="padding-bottom:10px;border-bottom:1px solid var(--border-soft);margin-bottom:6px;">
            <input type="hidden" name="acao" value="anexos_painel">
            <div class="form-group full" style="margin:0;">
              <label for="chamado_anexos_painel_input">Adicionar arquivos</label>
              <div class="file-upload">
                <div class="file-icon">⇪</div>
                <strong>Clique ou arraste aqui</strong>
                <span>Vários arquivos de uma vez — imagens, PDF e documentos (até 10MB cada)</span>
                <input type="file" id="chamado_anexos_painel_input" name="anexos[]" multiple hidden
                       accept=".pdf,.doc,.docx,.odt,.rtf,.txt,.xls,.xlsx,.ods,.csv,.png,.jpg,.jpeg,.gif,.webp,.zip,.rar,.7z">
              </div>
              <div class="file-list"></div>
            </div>
            <div class="form-actions" style="margin-top:10px;">
              <button type="submit" class="btn btn-primary btn-sm">Enviar anexos</button>
            </div>
          </form>
          <?php endif; ?>
          <?php if (empty($anexos)): ?>
            <div class="empty-state" style="padding:12px 0 8px;">
              <div class="empty-icon">📎</div>
              <p style="font-size:14px;margin-bottom:4px;">Nenhum anexo neste chamado ainda.</p>
              <small class="muted"><?= db_ok() ? ($CRM_CHAMADO_PORTAL ? 'Use «+ Anexo» na conversa para enviar ficheiros.' : 'Use o envio acima ou «+ Anexo» na conversa.') : 'Anexos disponíveis quando o banco estiver ativo.' ?></small>
            </div>
          <?php else: foreach ($anexos as $a): ?>
            <?php
              $nomeAnexo = (string) ($a['nome_original'] ?? '');
              $mimeAnexo = strtolower((string) ($a['mime'] ?? ''));
              $anexoEhImagem = upload_extensao_imagem($nomeAnexo)
                  || (strncmp($mimeAnexo, 'image/', 8) === 0 && strpos($mimeAnexo, 'svg') === false);
              $urlAnexoDl = 'chamado_download.php?id=' . (int) $a['id'];
            ?>
            <div class="file-item<?= $anexoEhImagem ? ' file-item--thumb' : '' ?>">
              <?php if ($anexoEhImagem): ?>
                <button type="button" class="file-item__thumb js-chamado-anexo-preview"
                        data-preview-src="<?= htmlspecialchars($urlAnexoDl, ENT_QUOTES, 'UTF-8') ?>"
                        data-preview-title="<?= htmlspecialchars($nomeAnexo, ENT_QUOTES, 'UTF-8') ?>"
                        aria-label="Ver imagem em tamanho maior">
                  <img src="<?= htmlspecialchars($urlAnexoDl) ?>" alt="" loading="lazy" width="72" height="72">
                </button>
              <?php endif; ?>
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
                <a class="btn btn-primary btn-sm" href="<?= htmlspecialchars($urlAnexoDl) ?>">Baixar</a>
                <?php if (!$CRM_CHAMADO_PORTAL): ?>
                <form method="post" action="chamado_detalhe.php?id=<?= (int) $id ?>">
                  <input type="hidden" name="acao" value="excluir_anexo">
                  <input type="hidden" name="anexo_id" value="<?= (int) $a['id'] ?>">
                  <button type="submit" class="btn btn-danger btn-sm"
                          data-confirm="Excluir o anexo &quot;<?= htmlspecialchars($a['nome_original'], ENT_QUOTES) ?>&quot;?"
                          data-confirm-danger>Excluir</button>
                </form>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

    </aside>
  </div>

  <div id="chamado-anexo-preview" class="chamado-anexo-preview" hidden role="dialog" aria-modal="true"
       aria-labelledby="chamado-anexo-preview-title">
    <button type="button" class="chamado-anexo-preview__scrim" data-chamado-anexo-preview-close aria-label="Fechar pré-visualização"></button>
    <div class="chamado-anexo-preview__box">
      <button type="button" class="chamado-anexo-preview__close btn btn-secondary" data-chamado-anexo-preview-close>Fechar</button>
      <img id="chamado-anexo-preview-img" src="" alt="" decoding="async">
      <p id="chamado-anexo-preview-title" class="chamado-anexo-preview__name"></p>
    </div>
  </div>
</section>

<script>
(function () {
  var root = document.getElementById('chamado-anexo-preview');
  if (!root) return;
  var img = document.getElementById('chamado-anexo-preview-img');
  var titleEl = document.getElementById('chamado-anexo-preview-title');
  var lastFocus = null;

  function onKey(e) {
    if (e.key === 'Escape') close();
  }

  function close() {
    root.hidden = true;
    root.setAttribute('aria-hidden', 'true');
    if (img) {
      img.removeAttribute('src');
      img.alt = '';
    }
    if (titleEl) titleEl.textContent = '';
    document.body.style.overflow = '';
    document.removeEventListener('keydown', onKey);
    if (lastFocus && typeof lastFocus.focus === 'function') {
      try { lastFocus.focus(); } catch (err) {}
    }
    lastFocus = null;
  }

  function open(src, title) {
    if (!img || !src) return;
    lastFocus = document.activeElement;
    img.src = src;
    img.alt = title || '';
    if (titleEl) titleEl.textContent = title || '';
    root.hidden = false;
    root.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', onKey);
    var btnFechar = root.querySelector('.chamado-anexo-preview__close');
    if (btnFechar) setTimeout(function () { try { btnFechar.focus(); } catch (e2) {} }, 0);
  }

  document.addEventListener('click', function (e) {
    var thumb = e.target.closest('.js-chamado-anexo-preview');
    if (thumb) {
      e.preventDefault();
      open(thumb.getAttribute('data-preview-src'), thumb.getAttribute('data-preview-title') || '');
      return;
    }
    if (e.target.closest('[data-chamado-anexo-preview-close]')) {
      e.preventDefault();
      close();
    }
  });
})();
</script>

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
  var jsonEl = document.getElementById('chamado-catalogo-json');
  var drawer = document.getElementById('chamado-mat-drawer');
  var formEdit = document.getElementById('chamado-mat-form-edit');
  var titleEl = document.getElementById('chamado-mat-drawer-title');
  var pickModal = document.getElementById('chamado-mat-modal-pick');
  var pickBusca = document.getElementById('chamado-mat-pick-busca');
  var pickResults = document.getElementById('chamado-mat-pick-results');
  var pickPreview = document.getElementById('chamado-mat-pick-preview');
  var pickPreviewNome = document.getElementById('chamado-mat-pick-preview-nome');
  var pickPreviewMeta = document.getElementById('chamado-mat-pick-preview-meta');
  var pickInpItemId = document.getElementById('chamado-mat-pick-item-id');
  var pickQty = document.getElementById('chamado-mat-pick-qty');
  var pickTitle = document.getElementById('chamado-mat-pick-title');
  var pickMovInput = document.getElementById('chamado-mat-pick-mov');
  var pickSubmitBtn = document.getElementById('chamado-mat-pick-submit');
  var formPick = document.getElementById('chamado-mat-form-pick');
  var pickTipoFilter = 'produto';
  var pickContextMov = 'utilizado';
  if (!drawer || !formEdit) return;

  var catalog = [];
  if (jsonEl) {
    try {
      catalog = JSON.parse(jsonEl.textContent || '[]');
    } catch (e) {
      catalog = [];
    }
  }

  function escHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function normTipo(t) {
    return String(t == null ? '' : t).trim().toLowerCase();
  }

  function closeDrawer() {
    drawer.hidden = true;
    drawer.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  function openDrawer() {
    drawer.hidden = false;
    drawer.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }

  function clearPickSelection() {
    if (!pickInpItemId || !pickPreview || !pickPreviewNome || !pickPreviewMeta) return;
    pickInpItemId.value = '';
    pickPreview.hidden = true;
    pickPreviewNome.textContent = '';
    pickPreviewMeta.textContent = '';
  }

  function selectPickItem(it) {
    if (!pickInpItemId || !pickPreview || !pickPreviewNome || !pickPreviewMeta) return;
    pickInpItemId.value = String(it.id);
    pickPreview.hidden = false;
    pickPreviewNome.textContent = it.nome || '';
    var parts = [];
    parts.push('Tipo: ' + (it.tipo || '—'));
    if (it.codigo) parts.push('Cód.: ' + String(it.codigo));
    if (it.unidade) parts.push('Un.: ' + it.unidade);
    pickPreviewMeta.textContent = parts.join(' · ');
  }

  function renderPickResults(q) {
    if (!pickResults) return;
    q = (q || '').trim().toLowerCase();
    pickResults.innerHTML = '';
    var base = catalog.filter(function (it) {
      return normTipo(it.tipo) === pickTipoFilter;
    });
    var list = base.filter(function (it) {
      if (!q) return true;
      var blob = [it.nome, it.tipo, it.codigo || ''].join(' ').toLowerCase();
      return blob.indexOf(q) !== -1;
    });
    if (!list.length) {
      var emptyMsg = pickContextMov === 'devolvido'
        ? 'Nenhum produto disponível no catálogo para recolhimento.'
        : 'Nenhum item encontrado neste filtro.';
      pickResults.innerHTML = '<p class="chamado-mat-results-empty muted">' + escHtml(emptyMsg) + '</p>';
      return;
    }
    list.slice(0, 120).forEach(function (it) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'chamado-mat-results__opt';
      b.setAttribute('role', 'option');
      b.innerHTML = '<span class="chamado-mat-results__nome">' + escHtml(it.nome || '') + '</span>' +
        '<span class="chamado-mat-results__sub muted">' + escHtml(it.codigo ? String(it.codigo) : '—') +
        (it.unidade ? ' · Un.: ' + escHtml(String(it.unidade)) : '') + '</span>';
      b.addEventListener('click', function () {
        pickResults.querySelectorAll('.chamado-mat-results__opt.is-active').forEach(function (x) {
          x.classList.remove('is-active');
        });
        b.classList.add('is-active');
        selectPickItem(it);
      });
      pickResults.appendChild(b);
    });
  }

  function closePickModal() {
    if (!pickModal) return;
    pickModal.hidden = true;
    pickModal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  function openPickModal(opts) {
    opts = opts || {};
    var mov = opts.mov === 'devolvido' ? 'devolvido' : 'utilizado';
    var catTipo = opts.catalogTipo === 'servico' ? 'servico' : 'produto';
    pickContextMov = mov;
    if (pickMovInput) pickMovInput.value = mov;
    if (mov === 'devolvido') {
      pickTipoFilter = 'produto';
      if (pickTitle) pickTitle.textContent = 'Recolher produto';
      if (pickSubmitBtn) pickSubmitBtn.textContent = 'Registrar recolhimento';
    } else {
      pickTipoFilter = catTipo === 'servico' ? 'servico' : 'produto';
      if (pickTitle) pickTitle.textContent = catTipo === 'servico' ? 'Novo serviço' : 'Novo item';
      if (pickSubmitBtn) pickSubmitBtn.textContent = 'Adicionar ao chamado';
    }
    if (!pickModal) return;
    clearPickSelection();
    if (pickBusca) pickBusca.value = '';
    renderPickResults('');
    if (pickQty) pickQty.value = '1';
    pickModal.hidden = false;
    pickModal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    setTimeout(function () { if (pickBusca) pickBusca.focus(); }, 50);
  }

  document.querySelectorAll('.js-cham-mat-open-edit').forEach(function (btn) {
    btn.addEventListener('click', function () {
      formEdit.hidden = false;
      var lid = document.getElementById('chamado-mat-edit-linha-id');
      var qe = document.getElementById('chamado-mat-qty-edit');
      var oe = document.getElementById('chamado-mat-obs-edit-preserve');
      var lbl = document.getElementById('chamado-mat-edit-itemlabel');
      if (lid) lid.value = btn.getAttribute('data-linha-id') || '';
      if (qe) qe.value = btn.getAttribute('data-qty') || '1';
      if (oe) oe.value = btn.getAttribute('data-obs') || '';
      if (lbl) lbl.textContent = btn.getAttribute('data-item-label') || '';
      if (titleEl) titleEl.textContent = 'Editar lançamento';
      openDrawer();
      setTimeout(function () { if (qe) qe.focus(); }, 50);
    });
  });

  drawer.querySelectorAll('[data-cham-mat-close]').forEach(function (el) {
    el.addEventListener('click', closeDrawer);
  });

  document.querySelectorAll('.js-cham-mat-open-pick').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (btn.disabled) return;
      var mov = btn.getAttribute('data-pick-mov') || 'utilizado';
      var catalogTipo = btn.getAttribute('data-catalog-tipo') || 'produto';
      openPickModal({ mov: mov, catalogTipo: catalogTipo });
    });
  });

  if (pickModal) {
    pickModal.querySelectorAll('[data-cham-mat-pick-close]').forEach(function (el) {
      el.addEventListener('click', closePickModal);
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (pickModal && !pickModal.hidden) {
      closePickModal();
      return;
    }
    if (!drawer.hidden) closeDrawer();
  });

  if (pickBusca && pickResults) {
    pickBusca.addEventListener('input', function () {
      renderPickResults(pickBusca.value);
      clearPickSelection();
      pickResults.querySelectorAll('.chamado-mat-results__opt.is-active').forEach(function (x) {
        x.classList.remove('is-active');
      });
    });
  }

  if (formPick) {
    formPick.addEventListener('submit', function (e) {
      if (!pickInpItemId || !pickInpItemId.value) {
        e.preventDefault();
        if (typeof window.appAlert === 'function') {
          window.appAlert('Selecione um item do catálogo na lista.');
        } else {
          alert('Selecione um item do catálogo na lista.');
        }
      }
    });
  }
})();

(function () {
  var root = document.getElementById('equipe_picker_grid');
  var filtro = document.getElementById('filtro_equipe_chamado');
  var countEl = document.getElementById('equipe_picker_count');
  var manyEl = document.getElementById('equipe_picker_many');
  if (!root || !filtro) return;
  var labels = root.querySelectorAll('[data-equipe-pick]');

  function updateCount() {
    var n = root.querySelectorAll('input[type="checkbox"]:checked').length;
    if (!countEl) return;
    if (n === 0) {
      countEl.textContent = 'Ninguém selecionado';
      countEl.className = 'equipe-picker-count equipe-picker-count--muted';
    } else if (n === 1) {
      countEl.textContent = '1 na equipe';
      countEl.className = 'equipe-picker-count equipe-picker-count--ok';
    } else {
      countEl.textContent = n + ' na equipe';
      countEl.className = 'equipe-picker-count equipe-picker-count--ok';
    }
    if (manyEl) {
      if (n > 3) manyEl.removeAttribute('hidden');
      else manyEl.setAttribute('hidden', '');
    }
  }

  function applyFilter() {
    var q = filtro.value.trim().toLowerCase();
    labels.forEach(function (lab) {
      var cb = lab.querySelector('input[type="checkbox"]');
      var raw = lab.getAttribute('data-equipe-nome') || '';
      var match = !q || raw.indexOf(q) !== -1;
      var show = match || (cb && cb.checked);
      lab.classList.toggle('equipe-picker__opt--hidden', !show);
    });
  }

  filtro.addEventListener('input', applyFilter);
  root.addEventListener('change', function () {
    applyFilter();
    updateCount();
  });
  applyFilter();
  updateCount();
})();

/** Ficha OS: botões «Salvar» (header e rodapé) só habilitam com alterações; gravação manual ao submeter. */
(function () {
  var osForm = document.getElementById('chamado-form-os-dados');
  var osSalvarBtns = document.querySelectorAll('.js-chamado-os-salvar');

  function osFormSnapshot() {
    if (!osForm) return '';
    try {
      var fd = new FormData(osForm);
      var parts = [];
      fd.forEach(function (v, k) {
        parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(String(v)));
      });
      parts.sort();
      return parts.join('&');
    } catch (e) {
      return '';
    }
  }

  var osInitialSnap = '';
  function osUpdateSalvarButton() {
    if (!osForm || !osSalvarBtns.length) return;
    var dirty = osFormSnapshot() !== osInitialSnap;
    osSalvarBtns.forEach(function (btn) {
      btn.disabled = !dirty;
      btn.title = dirty ? 'Gravar alterações da ordem de serviço' : 'Altere a ficha da OS antes de salvar';
      btn.classList.toggle('is-dirty', dirty);
      btn.classList.toggle('btn-primary', dirty);
      btn.classList.toggle('btn-secondary', !dirty);
    });
  }

  if (osForm) {
    osInitialSnap = osFormSnapshot();
    osUpdateSalvarButton();
    osForm.addEventListener('change', function (e) {
      var t = e.target;
      if (!t || !t.name || t.name === 'acao') return;
      osUpdateSalvarButton();
    });
    osForm.addEventListener('input', function (e) {
      var t = e.target;
      if (!t || !t.name || t.name === 'acao') return;
      osUpdateSalvarButton();
    });
  }
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
