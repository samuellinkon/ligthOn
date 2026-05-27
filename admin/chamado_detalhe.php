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
require_once __DIR__ . '/../includes/chamado_geo.php';
require_once __DIR__ . '/../includes/chamado_os_fields.php';
require_once __DIR__ . '/../includes/op_chamado_materiais_ui.php';
require_once __DIR__ . '/../includes/chamado_anexos_ui.php';

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
    $__chInativoPost = repo_chamado($id);
    if ($__chInativoPost && isset($__chInativoPost['ativo']) && (int) $__chInativoPost['ativo'] === 0) {
        flash_set('err', 'Chamado inativo — não é possível alterar este registo.');
        header('Location: chamado_detalhe.php?id=' . $id);
        exit;
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
            $acoesPortalCliente = ['responder', 'status', 'cancelar'];
            if (!in_array($acao, $acoesPortalCliente, true)) {
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
                if ($chSt && (($chSt['status'] ?? '') === 'Aguardando Aprovação')) {
                    repo_update_chamado_status($id, 'Em andamento');
                }
            } elseif (!$interna) {
                $chSt = repo_chamado($id);
                $stAtual = (string) ($chSt['status'] ?? '');
                if (!in_array($stAtual, ['Resolvido', 'Fechado', 'Cancelado'], true)) {
                    repo_update_chamado_status($id, 'Em andamento');
                }
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
            $ajaxAnexos = op_chamado_detalhe_is_ajax();
            if (!$temArq) {
                if ($ajaxAnexos) {
                    chamado_anexos_json_send(['ok' => false, 'err' => 'Selecione ao menos um arquivo para enviar.'], 400);
                }
                flash_set('err', 'Selecione ao menos um arquivo para enviar.');
            } else {
                $destino = upload_dir_chamado($id);
                $res = upload_gravar_multiplos($_FILES['anexos'], $destino);
                $arquivosSalvos = 0;
                $novosRows      = [];
                foreach ($res['salvos'] as $arq) {
                    $anexoId = repo_create_chamado_anexo([
                        'chamado_id'      => $id,
                        'resposta_id'     => null,
                        'nome_original'   => $arq['nome_original'],
                        'nome_arquivo'    => $arq['nome_arquivo'],
                        'mime'            => $arq['mime'],
                        'tamanho'         => $arq['tamanho'],
                        'enviado_por'     => $user['nome'] ?? 'Admin',
                        'enviado_tipo'    => 'admin',
                    ]);
                    if ($anexoId) {
                        $row = repo_chamado_anexo((int) $anexoId);
                        if ($row) {
                            $novosRows[] = $row;
                        }
                    }
                    $arquivosSalvos++;
                }
                $msgParts = [];
                if ($arquivosSalvos > 0) {
                    $msgParts[] = $arquivosSalvos . ' anexo(s) adicionado(s).';
                }
                if (!empty($res['erros'])) {
                    $msgParts[] = 'Falhas: ' . implode(' | ', $res['erros']);
                }
                if ($ajaxAnexos) {
                    $okAjax = $arquivosSalvos > 0;
                    chamado_anexos_json_send([
                        'ok'     => $okAjax,
                        'err'    => $okAjax ? '' : ($msgParts ? implode(' ', $msgParts) : 'Não foi possível salvar os anexos.'),
                        'saved'  => $arquivosSalvos,
                        'erros'  => $res['erros'] ?? [],
                        'msg'    => $msgParts ? implode(' ', $msgParts) : '',
                        'novos'  => array_map('chamado_anexo_para_json', $novosRows),
                        'anexos' => chamado_anexos_lista_json($id),
                        'total'  => count(repo_chamado_anexos($id)),
                    ], $okAjax ? 200 : 400);
                }
                if ($arquivosSalvos > 0) {
                    flash_set('ok', implode(' ', $msgParts));
                } else {
                    flash_set('err', $msgParts ? implode(' ', $msgParts) : 'Não foi possível salvar os anexos.');
                }
            }

        } elseif ($acao === 'cancelar') {
            if (!$CRM_CHAMADO_PORTAL) {
                flash_set('err', 'Ação não permitida.');
            } else {
                $stCancel = (string) (repo_chamado($id)['status'] ?? '');
                if (in_array($stCancel, ['Validado', 'Fechado', 'Cancelado'], true)) {
                    flash_set('err', 'Este chamado não pode mais ser cancelado.');
                } elseif (repo_update_chamado_status($id, 'Cancelado', 'cliente')) {
                    flash_set('ok', 'Chamado cancelado.');
                } else {
                    flash_set('err', 'Não foi possível cancelar o chamado.');
                }
            }

        } elseif ($acao === 'validar') {
            if (!$CRM_CHAMADO_PORTAL) {
                flash_set('err', 'Ação não permitida.');
            } else {
                $stVal = (string) (repo_chamado($id)['status'] ?? '');
                if (!in_array($stVal, ['Resolvido', 'Fechado'], true)) {
                    flash_set('err', 'Este chamado ainda não pode ser validado.');
                } elseif (repo_update_chamado_status($id, 'Validado', 'cliente')) {
                    flash_set('ok', 'Atendimento validado com sucesso.');
                } else {
                    flash_set('err', 'Não foi possível validar o chamado.');
                }
            }

        } elseif ($acao === 'status') {
            $perfilSt = $CRM_CHAMADO_PORTAL ? 'cliente' : (string) ($user['perfil'] ?? 'gestor');
            $novo     = (string) ($_POST['status'] ?? 'Em andamento');
            if ($CRM_CHAMADO_PORTAL) {
                $permitidosStatus = ['Validado'];
            } elseif (strtolower($perfilSt) === 'gestor') {
                $permitidosStatus = ['Aberto', 'Em andamento', 'Aguardando Aprovação', 'Resolvido'];
            } elseif (strtolower($perfilSt) === 'admin') {
                $permitidosStatus = ['Aberto', 'Em andamento', 'Aguardando Aprovação', 'Resolvido', 'Validado', 'Fechado', 'Cancelado'];
            } else {
                $permitidosStatus = ['Aberto', 'Em andamento', 'Aguardando Aprovação', 'Resolvido', 'Validado', 'Fechado', 'Cancelado'];
            }
            $errSt = '';
            $okSt  = false;
            $gestaoPlenaSt = in_array(strtolower($perfilSt), ['gestor', 'admin'], true);
            if (!in_array($novo, $permitidosStatus, true)) {
                $errSt = 'Status inválido para o seu perfil.';
            } elseif ($novo === 'Resolvido' && !$gestaoPlenaSt) {
                $valRes = repo_validar_chamado_resolvido($id);
                if (!$valRes['ok']) {
                    $errSt = (string) ($valRes['err'] ?? 'Não foi possível marcar como resolvido.');
                } elseif (repo_update_chamado_status($id, $novo, $perfilSt)) {
                    $okSt = true;
                } else {
                    $errSt = 'Não foi possível atualizar o status.';
                }
            } elseif (repo_update_chamado_status($id, $novo, $perfilSt)) {
                $okSt = true;
            } else {
                $errSt = 'Não foi possível atualizar o status.';
            }
            if (op_chamado_detalhe_is_ajax()) {
                if ($okSt) {
                    op_chamado_mat_json_send([
                        'ok'     => true,
                        'err'    => '',
                        'status' => $novo,
                        'msg'    => 'Status atualizado para "' . $novo . '".',
                    ]);
                }
                op_chamado_mat_json_send(['ok' => false, 'err' => $errSt !== '' ? $errSt : 'Não foi possível atualizar o status.'], 400);
            }
            if ($okSt) {
                if ($CRM_CHAMADO_PORTAL && $novo === 'Validado') {
                    flash_set('ok', 'Atendimento validado com sucesso.');
                } else {
                    flash_set('ok', 'Status atualizado para "' . $novo . '".');
                }
            } else {
                flash_set('err', $errSt !== '' ? $errSt : 'Não foi possível atualizar o status.');
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
            $nEquipe = count($tecnicoIds);
            $equipeCountLabelAjax = $nEquipe === 0
                ? 'Ninguém selecionado'
                : ($nEquipe === 1 ? '1 na equipe' : $nEquipe . ' na equipe');
            if (op_chamado_detalhe_is_ajax()) {
                if ($r['ok']) {
                    op_chamado_mat_json_send([
                        'ok'          => true,
                        'err'         => '',
                        'count'       => $nEquipe,
                        'count_label' => $equipeCountLabelAjax,
                        'tecnico_ids' => $tecnicoIds,
                        'msg'         => $nEquipe === 0 ? 'Equipe removida do chamado.' : 'Equipe atualizada.',
                    ]);
                }
                op_chamado_mat_json_send([
                    'ok'          => false,
                    'err'         => (string) ($r['err'] ?? 'Não foi possível salvar a equipe.'),
                    'count'       => $nEquipe,
                    'count_label' => $equipeCountLabelAjax,
                ], 400);
            }
            if ($r['ok']) {
                if (!$silentAutosave) {
                    flash_set('ok', 'Técnicos vinculados ao chamado.');
                }
            } else {
                flash_set('err', $r['err']);
            }

        } elseif ($acao === 'solicitar_item_devolutivo') {
            $nomeItem = trim((string) ($_POST['item_devolutivo_nome'] ?? ''));
            $codItem  = trim((string) ($_POST['item_devolutivo_codigo'] ?? ''));
            $qtdItem  = (float) str_replace(',', '.', (string) ($_POST['item_devolutivo_qtd'] ?? '1'));
            $obsItem  = trim((string) ($_POST['item_devolutivo_obs'] ?? ''));
            if ($CRM_CHAMADO_PORTAL) {
                flash_set('err', 'Esta ação não está disponível no portal do cliente.');
            } else {
                $rDev = repo_chamado_item_devolutivo_criar_direto(
                    $id,
                    $nomeItem,
                    $codItem !== '' ? $codItem : null,
                    $qtdItem,
                    $obsItem !== '' ? $obsItem : null,
                    'Criado'
                );
                if (op_chamado_detalhe_is_ajax()) {
                    op_chamado_mat_json_for_chamado($id, $rDev['ok'], $rDev['ok'] ? '' : $rDev['err']);
                }
                if ($rDev['ok']) {
                    $txtDevG = 'Item devolutivo adicionado: ' . $nomeItem
                        . ($codItem !== '' ? ' (cód. ' . $codItem . ')' : '')
                        . ' · Qtd: ' . $qtdItem
                        . ($obsItem !== '' ? "\nObs.: " . $obsItem : '');
                    repo_create_chamado_resposta(
                        $id,
                        (string) ($user['nome'] ?? 'Gestor'),
                        'admin',
                        $txtDevG,
                        false,
                        (int) ($user['id'] ?? 0)
                    );
                }
                flash_set(
                    $rDev['ok'] ? 'ok' : 'err',
                    $rDev['ok']
                        ? 'Item devolutivo criado no catálogo (status Criado) e lançado como recolhido.'
                        : $rDev['err']
                );
            }

        } elseif ($acao === 'prioridade') {
            $nova = (string) ($_POST['prioridade'] ?? 'Normal');
            $permitidasPrio = ['Baixa', 'Normal', 'Alta', 'Urgente'];
            $errPrio = '';
            $okPrio  = false;
            if (!in_array($nova, $permitidasPrio, true)) {
                $errPrio = 'Prioridade inválida.';
            } elseif (repo_update_chamado_prioridade($id, $nova)) {
                $okPrio = true;
            } else {
                $errPrio = 'Não foi possível atualizar a prioridade.';
            }
            if (op_chamado_detalhe_is_ajax()) {
                if ($okPrio) {
                    op_chamado_mat_json_send([
                        'ok'         => true,
                        'err'        => '',
                        'prioridade' => $nova,
                        'msg'        => 'Prioridade atualizada para "' . $nova . '".',
                    ]);
                }
                op_chamado_mat_json_send(['ok' => false, 'err' => $errPrio !== '' ? $errPrio : 'Não foi possível atualizar a prioridade.'], 400);
            }
            if ($okPrio) {
                flash_set('ok', 'Prioridade atualizada para "' . $nova . '".');
            } else {
                flash_set('err', $errPrio !== '' ? $errPrio : 'Não foi possível atualizar a prioridade.');
            }

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
            $okOs = repo_update_chamado_os_dados($id, $os);
            if (op_chamado_detalhe_is_ajax()) {
                if ($okOs) {
                    op_chamado_mat_json_send([
                        'ok'  => true,
                        'err' => '',
                        'msg' => 'Ficha da ordem de serviço atualizada.',
                    ]);
                }
                op_chamado_mat_json_send(['ok' => false, 'err' => 'Não foi possível salvar a ficha.'], 400);
            }
            if ($okOs) {
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
            if (op_chamado_detalhe_is_ajax()) {
                op_chamado_mat_json_for_chamado($id, $r['ok'], $r['ok'] ? '' : $r['err']);
            }
            flash_set($r['ok'] ? 'ok' : 'err', $r['ok'] ? 'Item lançado no chamado.' : $r['err']);

        } elseif ($acao === 'chamado_item_qtd') {
            $lid = (int) ($_POST['linha_id'] ?? 0);
            $qtd = (float) str_replace(',', '.', (string) ($_POST['quantidade'] ?? '1'));
            $okQ = $lid > 0 && $qtd > 0 && repo_chamado_item_atualizar_quantidade($lid, $id, $qtd);
            if (op_chamado_detalhe_is_ajax()) {
                op_chamado_mat_json_for_chamado($id, $okQ, $okQ ? '' : 'Não foi possível atualizar a linha.');
            }
            if ($okQ) {
                flash_set('ok', 'Quantidade atualizada.');
            } else {
                flash_set('err', 'Não foi possível atualizar a linha.');
            }

        } elseif ($acao === 'chamado_item_edit') {
            $lid = (int) ($_POST['linha_id'] ?? 0);
            $qtd = (float) str_replace(',', '.', (string) ($_POST['quantidade'] ?? '1'));
            $obs = trim((string) ($_POST['observacao'] ?? ''));
            $okE = $lid > 0 && $qtd > 0 && repo_chamado_item_atualizar_linha($lid, $id, $qtd, $obs !== '' ? $obs : null);
            if (op_chamado_detalhe_is_ajax()) {
                op_chamado_mat_json_for_chamado($id, $okE, $okE ? '' : 'Não foi possível atualizar o lançamento.');
            }
            if ($okE) {
                flash_set('ok', 'Item do atendimento atualizado.');
            } else {
                flash_set('err', 'Não foi possível atualizar o lançamento.');
            }

        } elseif ($acao === 'chamado_item_del') {
            $lid = (int) ($_POST['linha_id'] ?? 0);
            $okD = $lid > 0 && repo_chamado_item_remover($lid, $id);
            if (op_chamado_detalhe_is_ajax()) {
                op_chamado_mat_json_for_chamado($id, $okD, $okD ? '' : 'Não foi possível remover.');
            }
            if ($okD) {
                flash_set('ok', 'Linha removida.');
            } else {
                flash_set('err', 'Não foi possível remover.');
            }

        } elseif ($acao === 'excluir_anexo') {
            $anexoId = (int) ($_POST['anexo_id'] ?? 0);
            $anexo = repo_delete_chamado_anexo($anexoId);
            $okDel = $anexo && (int) $anexo['chamado_id'] === $id;
            if ($okDel) {
                $path = upload_dir_chamado($id) . DIRECTORY_SEPARATOR . $anexo['nome_arquivo'];
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            if (op_chamado_detalhe_is_ajax()) {
                if ($okDel) {
                    chamado_anexos_json_send([
                        'ok'     => true,
                        'err'    => '',
                        'msg'    => 'Anexo removido.',
                        'anexos' => chamado_anexos_lista_json($id),
                    ]);
                }
                chamado_anexos_json_send(['ok' => false, 'err' => 'Anexo não encontrado.'], 404);
            }
            if ($okDel) {
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
        if (isset($chamado['ativo']) && (int) $chamado['ativo'] === 0) {
            flash_set('err', 'Este chamado foi inativado e não está mais disponível no portal.');
            header('Location: chamados.php');
            exit;
        }
    }
    $chamadoInativo = isset($chamado['ativo']) && (int) $chamado['ativo'] === 0;
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

    $chamadoHistorico = [];
    if (!$CRM_CHAMADO_PORTAL) {
        require_once __DIR__ . '/../includes/chamado_historico.php';
        $chamadoHistorico = repo_chamado_historico_list($id, $chamado);
    }

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
    if (!$CRM_CHAMADO_PORTAL && $pontoChamado && !chamado_tem_endereco_cadastrado($chamado, null)) {
        repo_chamado_sincronizar_endereco_do_ponto($id);
        $chReload = repo_chamado($id);
        if ($chReload) {
            $chamado = $chReload;
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
    $chamadoHistorico = [];
    $chamadoInativo = false;
}

$chamadoImportadoBm = chamado_eh_origem_importacao_bm(is_array($chamado ?? null) ? $chamado : null);

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

/* Cabeçalho: problema ou título legado */
$chamadoTituloPrincipal = trim((string) ($chamado['problema_os'] ?? ''));
if ($chamadoTituloPrincipal === '') {
    $chamadoTituloPrincipal = trim((string) ($chamado['titulo'] ?? ''));
}
if ($chamadoTituloPrincipal === '') {
    $chamadoTituloPrincipal = 'Chamado';
}

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

$topTitle    = 'Chamado #' . $chamado['id'];
$topSubtitle = $chamado['titulo'];
$topSearch   = $CRM_CHAMADO_PORTAL ? 'Buscar em meus chamados...' : 'Buscar em chamados...';
$topAction   = ['label' => 'Voltar', 'href' => 'chamados.php', 'icon' => '←'];
$loadComposer = true;
$locPreview   = db_ok()
    ? chamado_resolver_localizacao_preview($chamado, $pontoChamado ?? null)
    : [
        'lat'              => null,
        'lng'              => null,
        'fonte'            => null,
        'modo'             => 'none',
        'geocode_attempts' => [],
        'mapa_query'       => '',
        'nav_query'        => '',
        'show_preview'     => false,
        'label_fonte'      => '',
    ];
$loadLeaflet = !empty($locPreview['show_preview']);
$adminLocSvIframeSrc = '';
if (!$CRM_CHAMADO_PORTAL && $locPreview['lat'] !== null && $locPreview['lng'] !== null) {
    $adminLocLl = rawurlencode(
        number_format((float) $locPreview['lat'], 7, '.', '')
        . ','
        . number_format((float) $locPreview['lng'], 7, '.', '')
    );
    $adminLocSvIframeSrc = 'https://www.google.com/maps?cbll=' . $adminLocLl . '&cbp=11,0,0,0,0&layer=c&output=svembed';
}

$valorChamadoItens = 0.0;
if (db_ok()) {
    foreach ($chamadoMateriaisUtil as $_mVal) {
        $valorChamadoItens += (float) ($_mVal['subtotal'] ?? 0);
    }
}

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

    .chamado-location-field {
      display: flex;
      flex-direction: column;
      gap: 6px;
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

    .chamado-loc-sv-admin .chamado-ponto-streetview__frame-wrap {
      padding-bottom: 0;
      height: 260px;
      border-radius: 12px;
    }

    .chamado-loc-sv-admin .chamado-ponto-streetview__frame {
      border-radius: 12px;
    }

    .os-ponto-preview--mapa-apenas .os-ponto-map-only {
      overflow: hidden;
      border: 1px solid var(--border-soft);
      border-radius: 12px;
      background: #fafafe;
    }

    .os-ponto-preview--mapa-apenas .chamado-map-mini {
      height: 280px;
      margin: 0;
      border: 0;
      border-radius: 12px;
    }

    .os-ponto-preview--mapa-apenas .os-ponto-map-embed {
      position: relative;
      height: 280px;
      border-radius: 12px;
      overflow: hidden;
    }

    .os-ponto-preview--mapa-apenas .os-ponto-map-embed__frame {
      display: block;
      width: 100%;
      height: 100%;
      min-height: 280px;
      border: 0;
      border-radius: 12px;
    }

    #chamado-loc-preview .chamado-map-mini {
      height: 260px;
      margin: 0;
      border: 0;
      border-radius: 12px;
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

    /* Anexos: miniatura para imagens (chamado_download.php?…&inline=1) */
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

    .chamado-historico-card .panel-body {
      padding: 14px 20px 22px;
    }

    .chamado-historico-empty p {
      margin: 0 0 6px;
      font-size: 14px;
    }

    .chamado-historico-timeline {
      list-style: none;
      margin: 0;
      padding: 0;
    }

    .chamado-historico-item {
      position: relative;
      display: flex;
      gap: 14px;
      padding: 0 0 18px 2px;
    }

    .chamado-historico-item:not(:last-child)::before {
      content: '';
      position: absolute;
      left: 9px;
      top: 20px;
      bottom: 0;
      width: 2px;
      background: var(--border-soft, #e8e8f0);
    }

    .chamado-historico-item__dot {
      flex-shrink: 0;
      width: 20px;
      height: 20px;
      margin-top: 2px;
      border-radius: 50%;
      border: 2px solid #fff;
      box-shadow: 0 0 0 1px var(--border-soft, #e0e0ea);
      background: #94a3b8;
    }

    .chamado-historico-item--success .chamado-historico-item__dot { background: #16a34a; }
    .chamado-historico-item--warning .chamado-historico-item__dot { background: #d97706; }
    .chamado-historico-item--danger .chamado-historico-item__dot { background: #dc2626; }
    .chamado-historico-item--info .chamado-historico-item__dot { background: #534ab7; }
    .chamado-historico-item--muted .chamado-historico-item__dot { background: #94a3b8; }

    .chamado-historico-item__body {
      flex: 1;
      min-width: 0;
    }

    .chamado-historico-item__head {
      display: flex;
      flex-wrap: wrap;
      align-items: baseline;
      justify-content: space-between;
      gap: 8px 12px;
    }

    .chamado-historico-item__title {
      font-size: 14px;
      font-weight: 700;
      color: var(--text);
    }

    .chamado-historico-item__time {
      font-size: 12px;
      color: var(--muted);
      white-space: nowrap;
    }

    .chamado-historico-item__detail {
      margin: 6px 0 0;
      font-size: 13px;
      line-height: 1.45;
      color: var(--text-secondary, #475569);
      word-break: break-word;
    }

    .chamado-historico-item__actor {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 8px;
      margin-top: 8px;
      font-size: 13px;
    }

    .chamado-historico-item__actor-label {
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: var(--muted);
    }

    .chamado-historico-item__actor-name {
      font-size: 14px;
      font-weight: 700;
      color: var(--text);
    }

    .chamado-historico-item__badge {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 999px;
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 0.03em;
      text-transform: uppercase;
      background: rgba(83, 74, 183, 0.1);
      color: #534ab7;
    }

  </style>

  <?php if (!empty($chamadoInativo)): ?>
  <div class="panel-note chamado-inativo-banner" role="status" style="margin:0 0 16px;border-color:rgba(220,38,38,.35);background:rgba(254,226,226,.45);">
    <strong>Chamado inativo (exclusão lógica)</strong>
    <p style="margin:6px 0 0;font-size:14px;line-height:1.45;">
      Este registo foi inativado<?= !empty($chamado['excluido_em']) ? ' em ' . htmlspecialchars((string) $chamado['excluido_em']) : '' ?><?= !empty($chamado['excluido_por_nome']) ? ' por ' . htmlspecialchars((string) $chamado['excluido_por_nome']) : '' ?>.
      O histórico permanece consultável; alterações e novas ações estão bloqueadas.
      <a href="chamados.php">Voltar à listagem de chamados</a>
    </p>
  </div>
  <?php endif; ?>

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
          <span class="chamado-header-toolbar__chip chamado-header-toolbar__chip--muted" title="<?= htmlspecialchars($chamadoAbertoStr) ?>"><?= htmlspecialchars($chamadoAbertoCurto) ?></span>
          <?php if ($chamadoImportadoBm): ?>
          <span class="chamado-header-toolbar__chip chamado-header-toolbar__chip--import"
                title="Chamado gerado pela importação do relatório / boletim de medição">Importado BM</span>
          <?php endif; ?>
        </div>
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
          if (!empty($pontoChamado)) {
              $ch_os_vals = chamado_os_aplicar_dados_ponto($ch_os_vals, $pontoChamado, true);
          }
          $endOsPortal = trim((string) ($ch_os_vals['endereco_completo'] ?? ''));
          if ($endOsPortal === '') {
              $endOsPortal = chamado_os_compor_endereco_completo($ch_os_vals) ?? '';
          }
          if ($endOsPortal !== '' && trim((string) ($ch_os_vals['os_logradouro'] ?? '')) === '') {
              foreach (chamado_os_endereco_estruturado_from_texto($endOsPortal, trim((string) ($ch_os_vals['os_bairro'] ?? '')) ?: null) as $kOs => $vOs) {
                  if ($vOs !== '' && trim((string) ($ch_os_vals[$kOs] ?? '')) === '') {
                      $ch_os_vals[$kOs] = $vOs;
                  }
              }
          }
          $ch_os_descricao = (string) ($chamado['descricao'] ?? '');
          $ch_os_mostrar_ponto = !empty($pontosOsForm);
          $ch_os_pontos_opcoes = $pontosOsForm;
          $ch_os_ponto_atual = $pontoChamado ?? null;
          $ch_os_readonly_endereco = true;
          $ch_os_mapa_apenas = true;
          $ch_os_ocultar_solicitante = $chamadoImportadoBm;
          include __DIR__ . '/../includes/chamado_os_grid_markup.php';
          ?>
        </div>
        </fieldset>
      </div>
      <?php else: ?>
      <div id="chamado-form-os-dados" class="card chamado-os-dados-panel" data-chamado-os-ajax="1" style="overflow:hidden;">
        <div class="panel-head">
          <h4>Ordem de serviço</h4>
        </div>
        <div class="form" style="padding: 0 24px 20px;">
          <?php
          $ch_os_vals = $chamado;
          if (!empty($pontoChamado)) {
              $ch_os_vals = chamado_os_aplicar_dados_ponto($ch_os_vals, $pontoChamado, true);
          }
          $endOsPainel = trim((string) ($ch_os_vals['endereco_completo'] ?? ''));
          if ($endOsPainel === '') {
              $endOsPainel = chamado_os_compor_endereco_completo($ch_os_vals) ?? '';
          }
          if ($endOsPainel !== '' && trim((string) ($ch_os_vals['os_logradouro'] ?? '')) === '') {
              foreach (chamado_os_endereco_estruturado_from_texto($endOsPainel, trim((string) ($ch_os_vals['os_bairro'] ?? '')) ?: null) as $kOs => $vOs) {
                  if ($vOs !== '' && trim((string) ($ch_os_vals[$kOs] ?? '')) === '') {
                      $ch_os_vals[$kOs] = $vOs;
                  }
              }
          }
          $ch_os_descricao = (string) ($chamado['descricao'] ?? '');
          $ch_os_mostrar_ponto = !empty($pontosOsForm);
          $ch_os_pontos_opcoes = $pontosOsForm;
          $ch_os_ponto_atual = $pontoChamado ?? null;
          $ch_os_readonly_endereco = false;
          $ch_os_mapa_apenas = true;
          $ch_os_ocultar_solicitante = $chamadoImportadoBm;
          include __DIR__ . '/../includes/chamado_os_grid_markup.php';
          ?>
        </div>
      </div>
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
                'valor_unitario' => (float) ($_ci['valor_unitario'] ?? 0),
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
              <strong data-ch-mat-count="utilizados"><?= (int) $nMatU ?></strong>
            </div>
            <div class="chamado-materiais-card__pill">
              <span>Recolhidos</span>
              <strong data-ch-mat-count="recolhidos"><?= (int) $nMatD ?></strong>
            </div>
            <div class="chamado-materiais-card__pill chamado-materiais-card__pill--valor">
              <span>Valor (itens)</span>
              <strong data-ch-mat-valor>R$ <?= number_format((float) $valorChamadoItens, 2, ',', '.') ?></strong>
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
            <div class="chamado-materiais-empty" data-ch-mat-empty="utilizado" <?= empty($chamadoMateriaisUtil) ? '' : 'hidden' ?>>
              <p>Nenhum item utilizado lançado ainda.</p>
              <small><?= $CRM_CHAMADO_PORTAL ? 'Os itens aplicados pelo atendimento aparecem aqui.' : 'Adicione produtos ou serviços aplicados neste atendimento.' ?></small>
            </div>
            <div class="table-wrap chamado-itens-table chamado-itens-table--admin" data-ch-mat-table-wrap="utilizado" <?= empty($chamadoMateriaisUtil) ? 'hidden' : '' ?>>
              <table class="chamado-materiais-table chamado-materiais-table--uso">
                <thead>
                  <tr>
                    <th>Item</th>
                    <th>Tipo</th>
                    <th class="text-right">V. unit.</th>
                    <th class="text-right">V. total</th>
                    <th class="text-right">Qtd</th>
                    <?php if (!$CRM_CHAMADO_PORTAL): ?>
                    <th class="text-right td-actions">Ações</th>
                    <?php endif; ?>
                  </tr>
                </thead>
                <tbody data-ch-mat-tbody="utilizado">
                  <?php foreach ($chamadoMateriaisUtil as $lm): ?>
                  <?php
                    $qDisp = htmlspecialchars(rtrim(rtrim(sprintf('%.4f', (float) ($lm['quantidade'] ?? 0)), '0'), '.'));
                    $lblItem = (string) ($lm['item_nome'] ?? '');
                    $codLm = trim((string) ($lm['item_codigo'] ?? ''));
                    $descLm = trim((string) ($lm['item_descricao'] ?? ''));
                    $tipLm = $descLm !== '' ? $lblItem . ' — ' . $descLm : $lblItem;
                    $vuLm = (float) ($lm['valor_unitario'] ?? 0);
                    $vtLm = (float) ($lm['subtotal'] ?? 0);
                  ?>
                  <tr>
                    <td class="chamado-mat-col-item"<?= $tipLm !== '' ? ' title="' . htmlspecialchars($tipLm, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
                      <strong><?= htmlspecialchars($lblItem) ?></strong>
                      <?php if ($codLm !== ''): ?>
                        <div class="td-mute" style="font-size:12px;">Cód. <?= htmlspecialchars($codLm) ?></div>
                      <?php endif; ?>
                    </td>
                    <td class="td-mute"><?= htmlspecialchars((string) ($lm['item_tipo'] ?? '')) ?></td>
                    <td class="text-right td-mute"><?= $vuLm > 0 ? 'R$ ' . number_format($vuLm, 2, ',', '.') : '—' ?></td>
                    <td class="text-right"><?= $vtLm > 0 ? 'R$ ' . number_format($vtLm, 2, ',', '.') : '—' ?></td>
                    <td class="text-right"><?= $qDisp ?></td>
                    <?php if (!$CRM_CHAMADO_PORTAL): ?>
                    <td class="text-right td-actions">
                      <button type="button" class="btn btn-secondary btn-sm js-cham-mat-open-edit"
                        data-linha-id="<?= (int) ($lm['id'] ?? 0) ?>"
                        data-qty="<?= $qDisp ?>"
                        data-obs="<?= htmlspecialchars((string) ($lm['observacao'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        data-item-label="<?= htmlspecialchars($lblItem, ENT_QUOTES, 'UTF-8') ?>"
                        data-movimento="utilizado">Editar</button>
                      <button type="button" class="btn btn-danger btn-sm" data-ch-mat-del data-linha-id="<?= (int) ($lm['id'] ?? 0) ?>">Excluir</button>
                    </td>
                    <?php endif; ?>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </section>

          <section class="chamado-materiais-block chamado-materiais-block--dev" aria-labelledby="ch-mat-dev-title">
            <div class="chamado-materiais-block__bar">
              <h5 id="ch-mat-dev-title" class="chamado-materiais-block__title">Devolvidos / recolhidos</h5>
              <?php if (!$CRM_CHAMADO_PORTAL): ?>
              <div class="chamado-materiais-block__actions chamado-materiais-block__actions--stack">
                <button type="button" class="btn btn-primary btn-sm js-cham-mat-open-pick" data-pick-mov="devolvido" data-catalog-tipo="produto"
                  <?= $nCatalogoProduto < 1 ? 'disabled title="Sem produtos no catálogo para recolhimento"' : '' ?>>Recolher produto</button>
                <button type="button" class="btn btn-primary btn-sm" data-ch-solicitar-devolutivo-open title="Novo item devolutivo" aria-label="Novo item devolutivo">+</button>
              </div>
              <?php endif; ?>
            </div>
            <div class="chamado-materiais-empty chamado-materiais-empty--muted" data-ch-mat-empty="devolvido" <?= empty($chamadoMateriaisDev) ? '' : 'hidden' ?>>
              <p>Nenhum item recolhido lançado ainda.</p>
              <small><?= $CRM_CHAMADO_PORTAL ? 'Recolhimentos feitos pela equipe aparecem aqui.' : 'Registre materiais retirados, devolvidos ou recolhidos no atendimento.' ?></small>
            </div>
            <div class="table-wrap chamado-itens-table chamado-itens-table--admin" data-ch-mat-table-wrap="devolvido" <?= empty($chamadoMateriaisDev) ? 'hidden' : '' ?>>
              <table class="chamado-materiais-table chamado-materiais-table--dev">
                <thead>
                  <tr>
                    <th>Item</th>
                    <th>Tipo</th>
                    <th class="text-right">V. unit.</th>
                    <th class="text-right">V. total</th>
                    <th class="text-right">Qtd</th>
                    <?php if (!$CRM_CHAMADO_PORTAL): ?>
                    <th class="text-right td-actions">Ações</th>
                    <?php endif; ?>
                  </tr>
                </thead>
                <tbody data-ch-mat-tbody="devolvido">
                  <?php foreach ($chamadoMateriaisDev as $lm): ?>
                  <?php
                    $qDispD = htmlspecialchars(rtrim(rtrim(sprintf('%.4f', (float) ($lm['quantidade'] ?? 0)), '0'), '.'));
                    $lblItemD = (string) ($lm['item_nome'] ?? '');
                    $codLmD = trim((string) ($lm['item_codigo'] ?? ''));
                    $descLmD = trim((string) ($lm['item_descricao'] ?? ''));
                    $tipLmD = $descLmD !== '' ? $lblItemD . ' — ' . $descLmD : $lblItemD;
                    $vuLmD = (float) ($lm['valor_unitario'] ?? 0);
                    $vtLmD = (float) ($lm['subtotal'] ?? 0);
                  ?>
                  <tr>
                    <td class="chamado-mat-col-item"<?= $tipLmD !== '' ? ' title="' . htmlspecialchars($tipLmD, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
                      <strong><?= htmlspecialchars($lblItemD) ?></strong>
                      <?php if ($codLmD !== ''): ?>
                        <div class="td-mute" style="font-size:12px;">Cód. <?= htmlspecialchars($codLmD) ?></div>
                      <?php endif; ?>
                    </td>
                    <td class="td-mute"><?= htmlspecialchars((string) ($lm['item_tipo'] ?? '')) ?></td>
                    <td class="text-right td-mute"><?= $vuLmD > 0 ? 'R$ ' . number_format($vuLmD, 2, ',', '.') : '—' ?></td>
                    <td class="text-right"><?= $vtLmD > 0 ? 'R$ ' . number_format($vtLmD, 2, ',', '.') : '—' ?></td>
                    <td class="text-right"><?= $qDispD ?></td>
                    <?php if (!$CRM_CHAMADO_PORTAL): ?>
                    <td class="text-right td-actions">
                      <button type="button" class="btn btn-secondary btn-sm js-cham-mat-open-edit"
                        data-linha-id="<?= (int) ($lm['id'] ?? 0) ?>"
                        data-qty="<?= $qDispD ?>"
                        data-obs="<?= htmlspecialchars((string) ($lm['observacao'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        data-item-label="<?= htmlspecialchars($lblItemD, ENT_QUOTES, 'UTF-8') ?>"
                        data-movimento="devolvido">Editar</button>
                      <button type="button" class="btn btn-danger btn-sm" data-ch-mat-del data-linha-id="<?= (int) ($lm['id'] ?? 0) ?>">Excluir</button>
                    </td>
                    <?php endif; ?>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
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


      <?php if (!$chamadoImportadoBm): ?>
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
              <small><?= $CRM_CHAMADO_PORTAL ? 'Use o campo abaixo para enviar uma mensagem ao suporte.' : 'Use o campo abaixo para responder ao portal da prefeitura.' ?></small>
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
          <textarea class="textarea" id="composer-text" name="resposta"
                    placeholder="<?= $CRM_CHAMADO_PORTAL ? 'Escreva uma mensagem para o suporte…' : 'Escreva uma resposta para o portal da prefeitura…' ?>"></textarea>

          <div class="composer-bar composer-bar--send-only">
            <button type="submit" class="btn btn-primary" id="btn-enviar"><?= $CRM_CHAMADO_PORTAL ? 'Enviar' : 'Enviar resposta' ?></button>
          </div>
        </form>
      </div>
      <?php endif; ?>

      <?php if (!$CRM_CHAMADO_PORTAL && !$chamadoImportadoBm): ?>
      <details class="card chamado-historico-card chamado-historico-toggle">
        <summary class="panel-head" style="list-style:none;cursor:pointer;">
          <h4 style="display:inline;margin:0;">Histórico</h4>
          <span class="panel-sub"><?= count($chamadoHistorico) ?> evento(s)</span>
        </summary>
        <div class="panel-body">
          <?php include __DIR__ . '/../includes/chamado_historico_timeline.php'; ?>
        </div>
      </details>
      <?php endif; ?>
    </div>

    <aside class="flex-col" style="gap:18px;">
      <!-- INFORMAÇÕES -->
      <div class="card">
        <div class="panel-head">
          <h4>Informações</h4>
          <div class="panel-head__actions chamado-info-export-actions" aria-label="Exportar chamado">
            <a class="btn btn-ghost btn-sm chamado-header-toolbar__ghost"
               href="<?= htmlspecialchars('chamado_detalhe.php?' . http_build_query(['id' => $id, 'export' => 'pdf'])) ?>"
               target="_blank" rel="noopener"
               title="Resumo do chamado com anexos e fotos do poste — use Imprimir para gerar PDF">PDF</a>
          </div>
        </div>
        <div class="panel-body flex-col" style="gap:12px;">
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
              <span class="muted" style="font-size:11px;display:block;margin-top:6px;">Monte a dupla ou trio em «Equipe no chamado» (gravação automática).</span>
              <?php endif; ?>
            </span>
          </div>

          <form method="post" class="info-row" data-chamado-autosave-form="prioridade">
            <input type="hidden" name="acao" value="prioridade">
            <span>Prioridade</span>
            <span class="info-edit-value">
              <select name="prioridade" class="select" data-chamado-autosave="prioridade"
                <?= $CRM_CHAMADO_PORTAL ? ' disabled title="A prioridade é definida pela equipe de atendimento."' : '' ?>>
                <?php foreach (['Baixa', 'Normal', 'Alta', 'Urgente'] as $p): ?>
                  <option value="<?= htmlspecialchars($p) ?>" <?= ($chamado['prioridade'] ?? '') === $p ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
                <?php endforeach; ?>
              </select>
            </span>
          </form>
          <?php if (!empty($chamado['finalizado_operador_em'])): ?>
            <div class="info-row"><span>Finalizado pelo técnico</span><strong><?= date('d/m/Y H:i', strtotime((string) $chamado['finalizado_operador_em'])) ?></strong></div>
          <?php endif; ?>

          <?php
            $stAtual = (string) ($chamado['status'] ?? '');
            $cliPodeValidar  = $CRM_CHAMADO_PORTAL && in_array($stAtual, ['Resolvido', 'Fechado'], true);
            $cliPodeCancelar = $CRM_CHAMADO_PORTAL && !in_array($stAtual, ['Validado', 'Cancelado', 'Fechado'], true);
            if (!$CRM_CHAMADO_PORTAL) {
                if (strtolower((string) ($user['perfil'] ?? '')) === 'gestor') {
                    $statusOpcoesUi = ['Aberto', 'Em andamento', 'Aguardando Aprovação', 'Resolvido'];
                } else {
                    $statusOpcoesUi = ['Aberto', 'Em andamento', 'Aguardando Aprovação', 'Resolvido', 'Validado', 'Fechado', 'Cancelado'];
                }
                if ($stAtual !== '' && !in_array($stAtual, $statusOpcoesUi, true)) {
                    $statusOpcoesUi[] = $stAtual;
                }
            }
          ?>
          <?php if ($CRM_CHAMADO_PORTAL): ?>
          <div class="info-row">
            <span>Status</span>
            <span class="info-edit-value">
              <span class="badge <?= status_class($stAtual) ?>"><?= htmlspecialchars($stAtual !== '' ? $stAtual : '—') ?></span>
            </span>
          </div>
          <?php if ($cliPodeValidar || $cliPodeCancelar): ?>
          <div class="info-row">
            <span>Ações</span>
            <span class="info-edit-value" style="display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;">
              <?php if ($cliPodeValidar): ?>
              <form method="post"
                    onsubmit="return confirm('Confirmar que o atendimento foi concluído satisfatoriamente? O chamado passará ao status Validado.');">
                <input type="hidden" name="acao" value="validar">
                <button type="submit" class="btn btn-primary btn-sm">Validar</button>
              </form>
              <?php endif; ?>
              <?php if ($cliPodeCancelar): ?>
              <form method="post"
                    onsubmit="return confirm('Cancelar este chamado? Esta ação não pode ser desfeita pelo portal.');">
                <input type="hidden" name="acao" value="cancelar">
                <button type="submit" class="action danger">Cancelar</button>
              </form>
              <?php endif; ?>
            </span>
          </div>
          <?php endif; ?>
          <?php else: ?>
          <form method="post" class="info-row" data-chamado-autosave-form="status">
            <input type="hidden" name="acao" value="status">
            <span>Status</span>
            <span class="info-edit-value">
              <select name="status" class="select" data-chamado-autosave="status">
                <?php foreach ($statusOpcoesUi as $s): ?>
                  <option value="<?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?>" <?= $stAtual === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
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
            <?php if (($locPreview['fonte'] ?? '') === 'ponto' && $locPreview['lat'] !== null && $locPreview['lng'] !== null): ?>
            <div class="info-row"><span>Coordenadas (cadastro)</span>
              <strong class="td-mute"><?= htmlspecialchars(number_format((float) $locPreview['lat'], 6, '.', '')) ?>, <?= htmlspecialchars(number_format((float) $locPreview['lng'], 6, '.', '')) ?></strong>
            </div>
            <?php elseif (($locPreview['fonte'] ?? '') === 'chamado' && $locPreview['lat'] !== null && $locPreview['lng'] !== null): ?>
            <div class="info-row"><span>Coordenadas (chamado)</span>
              <strong class="td-mute"><?= htmlspecialchars(number_format((float) $locPreview['lat'], 6, '.', '')) ?>, <?= htmlspecialchars(number_format((float) $locPreview['lng'], 6, '.', '')) ?></strong>
            </div>
            <?php elseif (!empty($pontoChamado['latitude']) && !empty($pontoChamado['longitude'])): ?>
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

      <?php if (db_ok() && !$CRM_CHAMADO_PORTAL && !$chamadoImportadoBm): ?>
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
          <span class="panel-sub">Quem vai ao campo neste atendimento — em geral dupla ou trio. Toque no nome para incluir ou tirar; a equipe é gravada automaticamente.</span>
        </div>
        <div class="panel-body">
          <?php if (empty($tecnicosChamado)): ?>
            <p class="muted" style="margin:0;font-size:13px;line-height:1.5;">Nenhum operador cadastrado para a empresa deste catálogo. Cadastre operadores na empresa antes de montar a equipe.</p>
          <?php else: ?>
            <div class="equipe-picker-form" id="equipe_picker_form" data-chamado-equipe-ajax="1" autocomplete="off">
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
              <p class="equipe-picker-hint equipe-picker-foot">Selecionados continuam visíveis na busca. Limpe o campo para ver a lista inteira. Alterações na equipe são salvas ao marcar ou desmarcar.</p>
            </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if (db_ok()): ?>
      <div class="card">
        <div class="panel-head">
          <h4>Endereço e mapa</h4>
          <span class="panel-sub">Localização pelo poste cadastrado, coordenadas do chamado, CEP+endereço ou mapa por endereço</span>
        </div>
        <div class="panel-body">
          <div class="chamado-location-grid">
            <?php
            $enderecoMapaTxt = chamado_endereco_efetivo($chamado, $pontoChamado ?? null);
            $latMapaTxt = isset($chamado['latitude']) && $chamado['latitude'] !== null && $chamado['latitude'] !== ''
                ? (string) $chamado['latitude'] : '';
            $lngMapaTxt = isset($chamado['longitude']) && $chamado['longitude'] !== null && $chamado['longitude'] !== ''
                ? (string) $chamado['longitude'] : '';
            if (($latMapaTxt === '' || $lngMapaTxt === '') && !empty($pontoChamado)) {
                $plaMap = $pontoChamado['latitude'] ?? null;
                $ploMap = $pontoChamado['longitude'] ?? null;
                if ($latMapaTxt === '' && $plaMap !== null && $plaMap !== '') {
                    $latMapaTxt = (string) $plaMap;
                }
                if ($lngMapaTxt === '' && $ploMap !== null && $ploMap !== '') {
                    $lngMapaTxt = (string) $ploMap;
                }
            }
            ?>
            <div class="chamado-location-field">
              <span class="chamado-ponto-endereco__label">Endereço completo</span>
              <div class="chamado-ponto-endereco__text"><?= $enderecoMapaTxt !== ''
                  ? nl2br(htmlspecialchars($enderecoMapaTxt, ENT_QUOTES, 'UTF-8'))
                  : '<span class="muted">Não informado</span>' ?></div>
            </div>
            <div class="chamado-location-coords">
              <div class="chamado-location-field">
                <span class="chamado-ponto-endereco__label">Latitude</span>
                <div class="chamado-ponto-endereco__text"><?= $latMapaTxt !== ''
                    ? htmlspecialchars($latMapaTxt, ENT_QUOTES, 'UTF-8')
                    : '<span class="muted">Não informada</span>' ?></div>
              </div>
              <div class="chamado-location-field">
                <span class="chamado-ponto-endereco__label">Longitude</span>
                <div class="chamado-ponto-endereco__text"><?= $lngMapaTxt !== ''
                    ? htmlspecialchars($lngMapaTxt, ENT_QUOTES, 'UTF-8')
                    : '<span class="muted">Não informada</span>' ?></div>
              </div>
            </div>
            <?php if (($locPreview['label_fonte'] ?? '') !== '' && $locPreview['lat'] !== null && $locPreview['lng'] !== null): ?>
            <p class="muted" style="margin:8px 0 0;font-size:12px;">
              <?= htmlspecialchars((string) $locPreview['label_fonte']) ?> —
              <strong><?= htmlspecialchars(number_format((float) $locPreview['lat'], 6, '.', '')) ?>, <?= htmlspecialchars(number_format((float) $locPreview['lng'], 6, '.', '')) ?></strong>
            </p>
            <?php endif; ?>
            <?php if (($locPreview['modo'] ?? '') === 'geocode'): ?>
            <p class="muted" id="chamado-map-geocode-hint-admin" style="margin:8px 0 0;font-size:12px;">A localizar endereço…</p>
            <?php endif; ?>
          </div>
          <?php if (!empty($loadLeaflet)): ?>
          <div id="chamado-loc-preview" style="margin-top:14px;">
            <div id="chamado-loc-sv-wrap" class="chamado-ponto-streetview chamado-loc-sv-admin chamado-location-map"<?= $adminLocSvIframeSrc !== '' ? '' : ' hidden' ?>>
              <div class="chamado-ponto-streetview__head">
                <span id="chamado-loc-sv-label" class="chamado-ponto-streetview__label">Street View</span>
              </div>
              <div class="chamado-ponto-streetview__frame-wrap">
                <iframe id="chamado-loc-sv-frame" class="chamado-ponto-streetview__frame" title="Street View do chamado" allowfullscreen loading="lazy"<?= $adminLocSvIframeSrc !== '' ? ' src="' . htmlspecialchars($adminLocSvIframeSrc, ENT_QUOTES, 'UTF-8') . '"' : '' ?>></iframe>
              </div>
            </div>
            <div id="chamado-map-mini" class="chamado-map-mini" hidden aria-label="Mapa do chamado"></div>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- ANEXOS (gerais do chamado) -->
      <div class="card" id="chamado-anexos-card">
        <div class="panel-head">
          <h4>Anexos</h4>
          <span class="panel-sub" id="chamado-anexos-count"><?= count($anexos) ?> arquivo(s)</span>
        </div>
        <div class="panel-body flex-col" id="chamado-anexos-body" style="gap:10px;">
          <?php if (db_ok() && !$CRM_CHAMADO_PORTAL): ?>
          <div class="chamado-anexos-painel-upload" data-chamado-anexos-ajax="1" style="padding-bottom:10px;border-bottom:1px solid var(--border-soft);margin-bottom:6px;">
            <div class="form-group full" style="margin:0;">
              <label for="chamado_anexos_painel_input">Adicionar arquivos</label>
              <div class="file-upload">
                <div class="file-icon">⇪</div>
                <strong>Clique ou arraste aqui</strong>
                <span>Vários arquivos de uma vez — imagens, PDF e documentos (até 10MB cada). O envio é <strong>imediato</strong> após selecionar.</span>
                <input type="file" id="chamado_anexos_painel_input" name="anexos[]" multiple hidden
                       accept=".pdf,.doc,.docx,.odt,.rtf,.txt,.xls,.xlsx,.ods,.csv,.png,.jpg,.jpeg,.gif,.webp,.zip,.rar,.7z">
              </div>
              <div class="file-list"></div>
            </div>
          </div>
          <?php endif; ?>
          <div id="chamado-anexos-list" class="chamado-anexos-list flex-col" style="gap:10px;">
          <?php if (empty($anexos)): ?>
            <div class="empty-state" id="chamado-anexos-empty" style="padding:12px 0 8px;">
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
              $urlAnexoDl = chamado_anexo_url((int) $a['id']);
              $urlAnexoImg = $anexoEhImagem ? chamado_anexo_url((int) $a['id'], true) : $urlAnexoDl;
            ?>
            <div class="file-item<?= $anexoEhImagem ? ' file-item--thumb' : '' ?>">
              <?php if ($anexoEhImagem): ?>
                <button type="button" class="file-item__thumb js-chamado-anexo-preview"
                        data-preview-src="<?= htmlspecialchars($urlAnexoImg, ENT_QUOTES, 'UTF-8') ?>"
                        data-preview-title="<?= htmlspecialchars($nomeAnexo, ENT_QUOTES, 'UTF-8') ?>"
                        aria-label="Ver imagem em tamanho maior">
                  <img src="<?= htmlspecialchars($urlAnexoImg) ?>" alt="" loading="lazy" width="72" height="72">
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
                <button type="button" class="btn btn-danger btn-sm"
                        data-chamado-anexo-delete
                        data-anexo-id="<?= (int) $a['id'] ?>"
                        data-anexo-nome="<?= htmlspecialchars($nomeAnexo, ENT_QUOTES, 'UTF-8') ?>">Excluir</button>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; endif; ?>
          </div>
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
<?php include __DIR__ . '/../includes/partials/leaflet_basemap_script.php'; ?>
<script src="<?= htmlspecialchars($basePath) ?>assets/js/chamado-loc-preview.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (!window.CrmChamadoLocPreview) return;
  window.CrmChamadoLocPreview.init({
    mapId: 'chamado-map-mini',
    svWrapId: 'chamado-loc-sv-wrap',
    svFrameId: 'chamado-loc-sv-frame',
    svLabelId: 'chamado-loc-sv-label',
    hideExternalTab: true,
    mapOnly: <?= $CRM_CHAMADO_PORTAL ? 'true' : 'false' ?>,
    defaultView: <?= $CRM_CHAMADO_PORTAL ? "'leaflet'" : "'streetview'" ?>,
    lat: <?= $locPreview['lat'] !== null ? json_encode($locPreview['lat']) : 'null' ?>,
    lng: <?= $locPreview['lng'] !== null ? json_encode($locPreview['lng']) : 'null' ?>,
    modo: <?= json_encode($locPreview['modo'] ?? 'none', JSON_UNESCAPED_UNICODE) ?>,
    mapaQuery: <?= json_encode($locPreview['mapa_query'] ?? '', JSON_UNESCAPED_UNICODE) ?>,
    attempts: <?= json_encode($locPreview['geocode_attempts'] ?? [], JSON_UNESCAPED_UNICODE) ?>,
    geocodeCidade: <?= json_encode(trim((string) ($chamado['os_cidade'] ?? '')), JSON_UNESCAPED_UNICODE) ?>,
    geocodeUf: <?= json_encode(strtoupper(preg_replace('/\./', '', trim((string) ($chamado['os_uf'] ?? '')))), JSON_UNESCAPED_UNICODE) ?>,
    hintId: 'chamado-map-geocode-hint-admin',
    scrollWheelZoom: <?= $CRM_CHAMADO_PORTAL ? 'true' : 'false' ?>,
    zoom: <?= $CRM_CHAMADO_PORTAL ? 17 : 15 ?>,
    zoomControl: true
  });
});
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

  var matCard = document.querySelector('.chamado-materiais-card');
  if (matCard) {
    matCard.addEventListener('click', function (e) {
      var btn = e.target.closest('.js-cham-mat-open-edit');
      if (!btn) return;
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
  }

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

})();
</script>
<?php if (db_ok()): ?>
<script src="<?= htmlspecialchars($basePath) ?>assets/js/admin-chamado-autosave.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/admin-chamado-autosave.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (window.AdminChamadoAutosave) {
    AdminChamadoAutosave.init();
  }
});
</script>
<?php endif; ?>
<?php if (!$CRM_CHAMADO_PORTAL && db_ok()): ?>
<script src="<?= htmlspecialchars($basePath) ?>assets/js/admin-chamado-equipe.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/admin-chamado-equipe.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (window.AdminChamadoEquipe) {
    AdminChamadoEquipe.init();
  }
});
</script>
<script src="<?= htmlspecialchars($basePath) ?>assets/js/admin-chamado-anexos.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/admin-chamado-anexos.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (window.AdminChamadoAnexos) {
    AdminChamadoAnexos.init();
  }
});
</script>
<?php endif; ?>
<?php
$devolutivoModalId        = 'ch-solicitar-devolutivo-modal';
$devolutivoModalOpenAttr  = 'data-ch-solicitar-devolutivo-open';
$devolutivoModalCloseAttr = 'data-ch-solicitar-devolutivo-close';
$devolutivoFieldPrefix    = 'ch';
$devolutivoModoGestor     = !$CRM_CHAMADO_PORTAL;
require __DIR__ . '/../includes/partials/chamado_solicitar_devolutivo_modal.php';
?>
<?php if (!$CRM_CHAMADO_PORTAL): ?>
<script src="<?= htmlspecialchars($basePath) ?>assets/js/admin-chamado-materiais.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/admin-chamado-materiais.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (window.AdminChamadoMateriais) {
    AdminChamadoMateriais.init({ canEdit: true });
  }
});
</script>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
