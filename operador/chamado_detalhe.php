<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/chamado_geo.php';
require_once __DIR__ . '/../includes/chamado_os_fields.php';
$user = require_auth('operador');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_operador('chamados');
require_once __DIR__ . '/../includes/op_chamado_materiais_ui.php';

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

    $msgEdicaoEncerrada = 'Chamado encerrado pela gestão. Itens e fotos não podem mais ser alterados.';
    $acoesEdicaoMateriaisFotos = [
        'chamado_item_add',
        'chamado_item_qtd',
        'chamado_item_del',
        'fotos',
        'excluir_foto',
        'solicitar_item_devolutivo',
    ];
    if (in_array($acao, $acoesEdicaoMateriaisFotos, true) && operador_chamado_materiais_fotos_bloqueados($ch)) {
        if (op_chamado_detalhe_is_ajax()) {
            if (in_array($acao, ['fotos', 'excluir_foto'], true)) {
                op_chamado_mat_json_send([
                    'ok'  => false,
                    'err' => $msgEdicaoEncerrada,
                    'msg' => $msgEdicaoEncerrada,
                ], 403);
            }
            op_chamado_mat_json_for_chamado($id, false, $msgEdicaoEncerrada);
        }
        flash_set('err', $msgEdicaoEncerrada);
        header('Location: chamado_detalhe.php?id=' . $id);
        exit;
    }

    if ($acao === 'salvar_op') {
        if (operador_chamado_materiais_fotos_bloqueados($ch)) {
            if (op_chamado_detalhe_is_ajax()) {
                op_chamado_mat_json_send([
                    'ok'  => false,
                    'err' => $msgEdicaoEncerrada,
                    'msg' => $msgEdicaoEncerrada,
                ], 403);
            }
            flash_set('err', $msgEdicaoEncerrada);
            header('Location: chamado_detalhe.php?id=' . $id);
            exit;
        }

        $msgsSalvar = [];
        $falhasSalvar = [];
        $jaEnviado = !empty($ch['finalizado_operador_em'])
            || (string) ($ch['status'] ?? '') === 'Aguardando Aprovação';

        if (!$jaEnviado) {
            $end = trim((string) ($_POST['endereco_completo'] ?? ''));
            if ($end === '' && trim((string) ($ch['endereco_completo'] ?? '')) !== '') {
                $end = trim((string) $ch['endereco_completo']);
            }
            $endForRepo = $end !== '' ? $end : null;
            $latCh = isset($ch['latitude']) && $ch['latitude'] !== null && $ch['latitude'] !== ''
                ? (float) $ch['latitude'] : null;
            $lngCh = isset($ch['longitude']) && $ch['longitude'] !== null && $ch['longitude'] !== ''
                ? (float) $ch['longitude'] : null;
            repo_update_chamado_localizacao($id, $endForRepo, $latCh, $lngCh);
        }

        $podeRemoverFoto = !operador_chamado_materiais_fotos_bloqueados($ch);
        $upSalvar = op_chamado_detalhe_upload_fotos_from_request($id, $user, $podeRemoverFoto);
        if ($upSalvar['teve_arquivo']) {
            if ($upSalvar['salvos'] > 0) {
                $msgsSalvar[] = $upSalvar['salvos'] . ' imagem(ns) enviada(s).';
            } elseif ($upSalvar['err'] !== '') {
                $falhasSalvar[] = $upSalvar['err'];
            }
        }

        if ($falhasSalvar !== []) {
            $msgErr = implode(' | ', $falhasSalvar);
            if (op_chamado_detalhe_is_ajax()) {
                op_chamado_mat_json_send([
                    'ok'           => false,
                    'err'          => $msgErr,
                    'msg'          => $msgErr,
                    'fotos_salvas' => op_chamado_detalhe_count_fotos($id),
                    'items_html'   => $upSalvar['items_html'],
                ], 400);
            }
            flash_set('err', $msgErr);
            header('Location: chamado_detalhe.php?id=' . $id);
            exit;
        }

        if ($msgsSalvar === []) {
            $msgsSalvar[] = 'Alterações salvas.';
        }

        $msgOk = implode(' ', $msgsSalvar);
        if (op_chamado_detalhe_is_ajax()) {
            op_chamado_mat_json_send([
                'ok'                => true,
                'msg'               => $msgOk,
                'fotos_salvas'      => op_chamado_detalhe_count_fotos($id),
                'items_html'        => $upSalvar['items_html'],
                'ja_enviado_gestor' => $jaEnviado,
            ]);
        }
        flash_set('ok', $msgOk);
        header('Location: chamado_detalhe.php?id=' . $id);
        exit;

    } elseif ($acao === 'enviar_os') {
        $msgs      = [];
        $falhas    = [];
        $countFotosAntes = op_chamado_detalhe_count_fotos($id);

        $end = trim((string) ($_POST['endereco_completo'] ?? ''));
        if ($end === '' && trim((string) ($ch['endereco_completo'] ?? '')) !== '') {
            $end = trim((string) $ch['endereco_completo']);
        }
        $endForRepo = $end !== '' ? $end : null;
        $latCh = isset($ch['latitude']) && $ch['latitude'] !== null && $ch['latitude'] !== '' ? (float) $ch['latitude'] : null;
        $lngCh = isset($ch['longitude']) && $ch['longitude'] !== null && $ch['longitude'] !== '' ? (float) $ch['longitude'] : null;
        repo_update_chamado_localizacao($id, $endForRepo, $latCh, $lngCh);

        $podeRemoverFoto = !operador_chamado_materiais_fotos_bloqueados($ch);
        $upEnvio = op_chamado_detalhe_upload_fotos_from_request($id, $user, $podeRemoverFoto);
        $salvos = $upEnvio['salvos'];
        if ($salvos > 0) {
            $msgs[] = $salvos . ' imagem(ns) enviada(s).';
        }
        if ($upEnvio['teve_arquivo'] && $salvos < 1 && $upEnvio['err'] !== '') {
            $falhas[] = $upEnvio['err'];
        }

        if (empty($falhas) && ($countFotosAntes + $salvos) < 1) {
            $falhas[] = 'Inclua pelo menos uma foto do atendimento antes de concluir o chamado.';
        }

        if (empty($falhas)) {
            $nome = (string) ($user['nome'] ?? 'Operador');
            $r = repo_operador_chamado_finalizar($id, (int) ($user['id'] ?? 0), $empresaId, $nome);
            if ($r['ok']) {
                $msgs[] = 'Chamado enviado ao gestor (Aguardando Aprovação).';
                $msgOk = implode(' ', $msgs);
                if (op_chamado_detalhe_is_ajax()) {
                    op_chamado_mat_json_send([
                        'ok'                => true,
                        'msg'               => $msgOk,
                        'fotos_salvas'      => op_chamado_detalhe_count_fotos($id),
                        'items_html'        => $upEnvio['items_html'],
                        'ja_enviado_gestor' => true,
                        'status'            => 'Aguardando Aprovação',
                        'status_class'      => status_class('Aguardando Aprovação'),
                    ]);
                }
                flash_set('ok', $msgOk);
                header('Location: chamado_detalhe.php?id=' . $id . '&salvo=1');
                exit;
            }
            if (op_chamado_detalhe_is_ajax()) {
                op_chamado_mat_json_send([
                    'ok'  => false,
                    'err' => $r['err'],
                    'msg' => $r['err'],
                ], 400);
            }
            flash_set('err', $r['err']);
        } else {
            $msgErr = implode(' | ', array_filter($falhas));
            if (op_chamado_detalhe_is_ajax()) {
                op_chamado_mat_json_send([
                    'ok'  => false,
                    'err' => $msgErr,
                    'msg' => $msgErr,
                ], 400);
            }
            flash_set('err', $msgErr);
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
        flash_set($r['ok'] ? 'ok' : 'err', $r['ok'] ? 'Chamado enviado ao gestor (Aguardando Aprovação).' : $r['err']);
    } elseif ($acao === 'solicitar_item_devolutivo') {
        $nomeItem = trim((string) ($_POST['item_devolutivo_nome'] ?? ''));
        $codItem  = trim((string) ($_POST['item_devolutivo_codigo'] ?? ''));
        $qtdItem  = (float) str_replace(',', '.', (string) ($_POST['item_devolutivo_qtd'] ?? '1'));
        $obsItem  = trim((string) ($_POST['item_devolutivo_obs'] ?? ''));
        if ($nomeItem === '') {
            flash_set('err', 'Informe o nome do item devolutivo.');
        } else {
            require_once __DIR__ . '/../includes/notificacoes.php';
            $tituloNotif = sprintf('Solicitação de item devolutivo no chamado #%d: %s', $id, mb_substr($nomeItem, 0, 80, 'UTF-8'));
            $descNotif   = trim($codItem !== '' ? 'Código: ' . $codItem . ' · ' : '') . 'Qtd: ' . $qtdItem
                . ($obsItem !== '' ? ' · ' . $obsItem : '');
            $destGest = repo_notificacao_destinatarios_chamado($id, true);
            foreach (array_unique($destGest) as $uidDest) {
                if ((int) $uidDest === (int) ($user['id'] ?? 0)) {
                    continue;
                }
                repo_notificacao_insert((int) $uidDest, $id, null, $tituloNotif, $descNotif, 'chamado_item_devolutivo');
            }
            audit_log_registar('chamado.item_devolutivo.solicitar', 'chamado', $id, (int) ($ch['cliente_id'] ?? 0) ?: null, [
                'nome' => $nomeItem, 'codigo' => $codItem, 'qtd' => $qtdItem, 'obs' => $obsItem,
            ]);
            $txtDev = 'Solicitação de item devolutivo: ' . $nomeItem
                . ($codItem !== '' ? ' (cód. ' . $codItem . ')' : '')
                . ' · Qtd: ' . $qtdItem
                . ($obsItem !== '' ? "\nObs.: " . $obsItem : '');
            repo_create_chamado_resposta(
                $id,
                (string) ($user['nome'] ?? 'Operador'),
                'operador',
                $txtDev,
                false,
                (int) ($user['id'] ?? 0)
            );
            flash_set('ok', 'Solicitação enviada ao gestor.');
        }
    } elseif ($acao === 'chamado_item_add') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $qtd    = (float) str_replace(',', '.', (string) ($_POST['quantidade'] ?? '1'));
        $mov    = (string) ($_POST['movimento'] ?? 'utilizado');
        $obs    = trim((string) ($_POST['observacao'] ?? ''));
        $r      = repo_chamado_item_adicionar($id, $itemId, $qtd, $mov, $obs !== '' ? $obs : null);
        if (op_chamado_detalhe_is_ajax()) {
            op_chamado_mat_json_for_chamado($id, $r['ok'], $r['ok'] ? '' : $r['err']);
        }
        flash_set($r['ok'] ? 'ok' : 'err', $r['ok'] ? 'Item lançado.' : $r['err']);
    } elseif ($acao === 'chamado_item_qtd') {
        $lid = (int) ($_POST['linha_id'] ?? 0);
        $qtd = (float) str_replace(',', '.', (string) ($_POST['quantidade'] ?? '1'));
        $okQ = $lid > 0 && $qtd > 0 && repo_chamado_item_atualizar_quantidade($lid, $id, $qtd);
        if (op_chamado_detalhe_is_ajax()) {
            op_chamado_mat_json_for_chamado($id, $okQ, $okQ ? '' : 'Não foi possível atualizar.');
        }
        if ($okQ) {
            flash_set('ok', 'Quantidade atualizada.');
        } else {
            flash_set('err', 'Não foi possível atualizar.');
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
    } elseif ($acao === 'responder') {
        $texto = trim((string) ($_POST['resposta'] ?? ''));
        if ($texto === '') {
            flash_set('err', 'Escreva uma mensagem antes de enviar.');
        } else {
            $rid = repo_create_chamado_resposta(
                $id,
                (string) ($user['nome'] ?? 'Operador'),
                'operador',
                $texto,
                false,
                (int) ($user['id'] ?? 0)
            );
            flash_set($rid ? 'ok' : 'err', $rid ? 'Comentário publicado. Os utilizadores ligados ao chamado recebem notificação.' : 'Não foi possível gravar o comentário.');
        }
    } elseif ($acao === 'fotos') {
        $endF = trim((string) ($_POST['endereco_completo'] ?? ''));
        if ($endF === '' && trim((string) ($ch['endereco_completo'] ?? '')) !== '') {
            $endF = trim((string) $ch['endereco_completo']);
        }
        $endFRepo = $endF !== '' ? $endF : null;
        $latF = isset($ch['latitude']) && $ch['latitude'] !== null && $ch['latitude'] !== '' ? (float) $ch['latitude'] : null;
        $lngF = isset($ch['longitude']) && $ch['longitude'] !== null && $ch['longitude'] !== '' ? (float) $ch['longitude'] : null;
        repo_update_chamado_localizacao($id, $endFRepo, $latF, $lngF);

        $temArq = !empty($_FILES['imagens']['name']) && (is_array($_FILES['imagens']['name'])
            ? count(array_filter($_FILES['imagens']['name'], fn ($n) => $n !== '' && $n !== null)) > 0
            : (string) ($_FILES['imagens']['name'] ?? '') !== '');
        $salvos  = 0;
        $errFoto = '';
        $novosHtml = [];
        if ($temArq) {
            $destino = upload_dir_chamado($id);
            $res     = upload_gravar_multiplos($_FILES['imagens'], $destino);
            foreach ($res['salvos'] as $arq) {
                $aid = repo_create_chamado_anexo([
                    'chamado_id'    => $id,
                    'resposta_id'   => null,
                    'nome_original' => $arq['nome_original'],
                    'nome_arquivo'  => $arq['nome_arquivo'],
                    'mime'          => $arq['mime'],
                    'tamanho'       => $arq['tamanho'],
                    'enviado_por'   => $user['nome'] ?? 'Operador',
                    'enviado_tipo'  => 'operador',
                ]);
                if ($aid) {
                    $salvos++;
                    $nomeEsc = htmlspecialchars((string) $arq['nome_original'], ENT_QUOTES, 'UTF-8');
                    $novosHtml[] =
                        '<div class="op-photo-grid__item" data-anexo-id="' . (int) $aid . '">'
                        . '<a class="op-photo-grid__link" href="chamado_download.php?id=' . (int) $aid . '" target="_blank" rel="noopener">'
                        . '<img src="chamado_download.php?id=' . (int) $aid . '" alt="' . $nomeEsc . '" loading="lazy">'
                        . '</a>'
                        . '<button type="button" class="op-photo-grid__remove" data-anexo-id="' . (int) $aid . '"'
                        . ' aria-label="Remover foto ' . $nomeEsc . '">×</button></div>';
                }
            }
            if ($salvos < 1) {
                $errFoto = 'Nenhuma imagem aceita.';
            }
            if (!empty($res['erros'])) {
                $errFoto = ($errFoto !== '' ? $errFoto . ' ' : '') . implode(' | ', $res['erros']);
            }
        }
        if (op_chamado_detalhe_is_ajax()) {
            $fotosRestantes = 0;
            foreach (repo_chamado_anexos($id) as $axCnt) {
                $mimeCnt = strtolower((string) ($axCnt['mime'] ?? ''));
                $nomeCnt = strtolower((string) ($axCnt['nome_original'] ?? ''));
                if (strncmp($mimeCnt, 'image/', 8) === 0 || preg_match('/\.(png|jpe?g|gif|webp|bmp)$/i', $nomeCnt)) {
                    $fotosRestantes++;
                }
            }
            op_chamado_mat_json_send([
                'ok'           => $temArq ? $salvos > 0 : true,
                'err'          => $errFoto,
                'fotos_salvas' => $fotosRestantes,
                'items_html'   => $novosHtml,
                'msg'          => $salvos > 0 ? $salvos . ' imagem(ns) enviada(s).' : ($temArq ? $errFoto : ''),
            ], ($temArq && $salvos < 1) ? 400 : 200);
        }
        if (!$temArq) {
            flash_set('ok', 'Referência no local guardada.');
        } else {
            flash_set($salvos > 0 ? 'ok' : 'err', $salvos > 0 ? $salvos . ' imagem(ns) enviada(s).' : ($errFoto !== '' ? $errFoto : 'Nenhuma imagem aceita.'));
        }
    } elseif ($acao === 'excluir_foto') {
        $anexoId = (int) ($_POST['anexo_id'] ?? 0);
        $okDel   = false;
        $errDel  = 'Foto não encontrada.';
        $anexo   = $anexoId > 0 ? repo_chamado_anexo($anexoId) : null;
        if ($anexo && (int) ($anexo['chamado_id'] ?? 0) === $id) {
            $mimeDel = strtolower((string) ($anexo['mime'] ?? ''));
            $nomeDel = strtolower((string) ($anexo['nome_original'] ?? ''));
            $ehImg   = strncmp($mimeDel, 'image/', 8) === 0
                || preg_match('/\.(png|jpe?g|gif|webp|bmp)$/i', $nomeDel);
            if ($ehImg) {
                $deleted = repo_delete_chamado_anexo($anexoId);
                if ($deleted) {
                    $pathDel = upload_dir_chamado($id) . DIRECTORY_SEPARATOR . ($deleted['nome_arquivo'] ?? '');
                    if (is_file($pathDel)) {
                        @unlink($pathDel);
                    }
                    $okDel = true;
                } else {
                    $errDel = 'Não foi possível remover a foto.';
                }
            } else {
                $errDel = 'Anexo inválido.';
            }
        }
        if (op_chamado_detalhe_is_ajax()) {
            $fotosRestantes = 0;
            foreach (repo_chamado_anexos($id) as $axCnt) {
                $mimeCnt = strtolower((string) ($axCnt['mime'] ?? ''));
                $nomeCnt = strtolower((string) ($axCnt['nome_original'] ?? ''));
                if (strncmp($mimeCnt, 'image/', 8) === 0 || preg_match('/\.(png|jpe?g|gif|webp|bmp)$/i', $nomeCnt)) {
                    $fotosRestantes++;
                }
            }
            op_chamado_mat_json_send([
                'ok'           => $okDel,
                'err'          => $okDel ? '' : $errDel,
                'fotos_salvas' => $fotosRestantes,
                'msg'          => $okDel ? 'Foto removida.' : $errDel,
            ], $okDel ? 200 : 400);
        }
        flash_set($okDel ? 'ok' : 'err', $okDel ? 'Foto removida.' : $errDel);
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

$chamadoImportadoBm = chamado_eh_origem_importacao_bm($chamado);

$chamadoClienteId   = (int) ($chamado['cliente_id'] ?? 0);
$respostas          = repo_chamado_respostas_do_ticket($id, true);
$servicosAll        = $chamadoClienteId > 0 ? repo_cliente_itens_list($chamadoClienteId, true) : [];
$servicos           = array_values(array_filter(
    $servicosAll,
    static fn ($ci) => strtolower(trim((string) ($ci['tipo'] ?? ''))) === 'produto'
));
$chamadoMateriaisOp = [];
try {
    $chamadoMateriaisOp = repo_chamado_itens_list($id);
} catch (Throwable $e) {
    $chamadoMateriaisOp = [];
}
$anexos            = repo_chamado_anexos($chamado['id']);
$itensUsadosOp     = array_values(array_filter($chamadoMateriaisOp, fn ($i) => repo_chamado_item_movimento_efetivo($i) !== 'devolvido'));
$itensDevolvidosOp = array_values(array_filter($chamadoMateriaisOp, fn ($i) => repo_chamado_item_movimento_efetivo($i) === 'devolvido'));
$imagensOp         = [];
foreach ($anexos as $ax) {
    $mime = strtolower((string) ($ax['mime'] ?? ''));
    $nome = strtolower((string) ($ax['nome_original'] ?? ''));
    if (strpos($mime, 'image/') === 0 || preg_match('/\.(png|jpe?g|gif|webp|bmp)$/i', $nome)) {
        $imagensOp[] = $ax;
    }
}
$qtdFotosExistentes = count($imagensOp);
$statusCh           = (string) ($chamado['status'] ?? '');
$jaEnviadoGestor    = !empty($chamado['finalizado_operador_em'])
    || $statusCh === 'Aguardando Aprovação';
$opEdicaoEncerrada  = operador_chamado_materiais_fotos_bloqueados($chamado);
$anexosPorResposta = [];
foreach ($anexos as $a) {
    if (!empty($a['resposta_id'])) {
        $anexosPorResposta[(int) $a['resposta_id']][] = $a;
    }
}

$opCatalogoItensJs = [];
foreach ($servicos as $ci) {
    $iid   = (int) ($ci['id'] ?? 0);
    $tipo  = strtolower(trim((string) ($ci['tipo'] ?? '')));
    $nome  = (string) ($ci['nome'] ?? '');
    $cod   = trim((string) ($ci['codigo'] ?? ''));
    $un    = trim((string) ($ci['unidade'] ?? 'UN')) ?: 'UN';
    $cat   = $tipo === 'produto' ? 'Produto' : ($tipo === 'servico' ? 'Serviço' : ucfirst($tipo));
    $hay   = mb_strtolower($nome . ' ' . $cod . ' ' . $cat . ' ' . $tipo . ' ' . $un . ' #' . $iid, 'UTF-8');
    $opCatalogoItensJs[] = [
        'id'        => $iid,
        'nome'      => $nome,
        'label'     => $nome,
        'tipo'      => $tipo,
        'categoria' => $cat,
        'codigo'    => $cod,
        'unidade'   => $un,
        'estoque'   => array_key_exists('estoque_saldo', $ci) ? (float) $ci['estoque_saldo'] : null,
        'hay'       => $hay,
    ];
}

$topTitle    = 'Chamado #' . $chamado['id'];
$topSubtitle = (string) ($chamado['titulo'] ?? '');
$topSearch   = '';
$topAction   = ['label' => 'Voltar', 'href' => 'chamados.php', 'icon' => '←'];

$pontoChamado = null;
$pidPonto     = (int) ($chamado['ponto_iluminacao_id'] ?? 0);
if ($pidPonto > 0) {
    $pontoChamado = repo_ponto_iluminacao($pidPonto);
    if ($pontoChamado && (int) ($pontoChamado['cliente_id'] ?? 0) !== $chamadoClienteId) {
        $pontoChamado = null;
    }
}
if ($pontoChamado && !chamado_tem_endereco_cadastrado($chamado, null)) {
    repo_chamado_sincronizar_endereco_do_ponto($id);
    $chReload = repo_chamado($id);
    if ($chReload) {
        $chamado = $chReload;
    }
}
$enderecoChamado = chamado_endereco_efetivo($chamado, $pontoChamado);
$locPreview      = chamado_resolver_localizacao_preview($chamado, $pontoChamado);
$loadLeaflet = !empty($locPreview['show_preview']);

$opPageFlash     = function_exists('flash_get') ? flash_get() : null;
$opSalvoRecente  = isset($_GET['salvo']) && (string) $_GET['salvo'] === '1';
$opSalvoOk       = $opSalvoRecente && !empty($opPageFlash) && ($opPageFlash['tipo'] ?? '') === 'ok';
$topbarSkipFlash = true;

include __DIR__ . '/../includes/head.php';
$topbarHideTitle = true;
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-operador.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <?php if (!empty($opPageFlash) && !$opSalvoOk): ?>
  <div class="op-inline-flash op-inline-flash--<?= ($opPageFlash['tipo'] ?? '') === 'err' ? 'err' : 'ok' ?>" role="alert" aria-live="polite">
    <?= htmlspecialchars((string) ($opPageFlash['msg'] ?? '')) ?>
  </div>
  <?php endif; ?>
  <style>
    .op-inline-flash{max-width:760px;margin:0 auto 14px;padding:12px 16px;border-radius:14px;font-size:14px;font-weight:600;line-height:1.45}
    .op-inline-flash--ok{background:#ecfdf5;border:1px solid #6ee7b7;color:#065f46}
    .op-inline-flash--err{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b}
    .op-mobile-shell{display:flex;flex-direction:column;gap:22px;max-width:760px;margin:0 auto;padding-block:6px 28px}
    .op-mobile-shell--fixed-save{padding-bottom:calc(96px + env(safe-area-inset-bottom,0px))}
    .op-card{background:#fff;border:1px solid var(--border);border-radius:20px;box-shadow:0 4px 24px rgba(15,23,42,.06);overflow:hidden}
    .op-card--primary{border-color:rgba(83,74,183,.22);box-shadow:0 8px 32px rgba(83,74,183,.1)}
    .op-card-head{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
    .op-card-head h4{margin:0;font-size:17px;font-weight:700}
    .op-card-lead{margin:4px 0 0;font-size:13px;line-height:1.45}
    .op-card-body{padding:16px 18px 18px}
    .op-atend-body{display:flex;flex-direction:column;gap:16px}
    .op-atend-note{margin:0;font-size:14px;line-height:1.5;color:var(--muted)}
    .op-atend-note strong{color:var(--text,#0f172a)}
    .op-form-group{margin:0}
    .op-ref-field-label{display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:var(--text,#0f172a)}
    .op-ref-row{display:flex;align-items:flex-start;gap:10px}
    .op-ref-plain{flex:1;min-width:0;font-size:15px;line-height:1.55;color:var(--text,#334155);padding:12px 14px;border-radius:12px;background:var(--surface-raised,#f1f5f9);border:1px solid var(--border,#e2e8f0);white-space:pre-wrap;word-break:break-word;min-height:48px;box-sizing:border-box}
    .op-ref-copy-btn{flex-shrink:0;align-self:flex-start;white-space:nowrap}
    .op-ref-map{margin-top:12px}
    .op-ref-nav{margin-top:10px;margin-bottom:0}
    .op-atend-actions{display:flex;flex-direction:column;gap:10px}
    .op-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    .op-actions .btn{width:100%;justify-content:center}
    .op-btn-tall{min-height:48px;font-size:15px;font-weight:600;border-radius:14px}
    .op-btn-block{width:100%;justify-content:center}
    .op-btn-cta{min-height:52px;font-size:16px;font-weight:700;border-radius:14px;box-shadow:0 4px 14px rgba(83,74,183,.35)}
    .op-fixed-save-bar{position:fixed;bottom:0;left:var(--sidebar-w);right:0;z-index:100;padding:12px 16px calc(12px + env(safe-area-inset-bottom,0px));background:linear-gradient(to top,rgba(255,255,255,.98),#fff);border-top:1px solid var(--border);box-shadow:0 -8px 24px rgba(15,23,42,.08)}
    .op-fixed-save-bar__inner{display:grid;grid-template-columns:1fr 1.15fr;gap:10px;max-width:760px;margin:0 auto}
    .op-fixed-save-bar__inner--single{grid-template-columns:1fr}
    .op-fixed-save-bar .btn{width:100%;justify-content:center;min-height:48px}
    .op-save-ack-bar{position:fixed;bottom:0;left:var(--sidebar-w);right:0;z-index:101;display:flex;align-items:center;justify-content:center;gap:10px;padding:14px 18px calc(14px + env(safe-area-inset-bottom,0px));background:linear-gradient(135deg,#059669,#10b981);color:#fff;font-size:15px;font-weight:700;line-height:1.35;text-align:center;box-shadow:0 -8px 28px rgba(5,150,105,.35);animation:op-save-ack-in .35s ease}
    .op-save-ack-bar.is-out{opacity:0;transform:translateY(12px);transition:opacity .35s ease,transform .35s ease}
    .op-save-ack-bar__icon{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:rgba(255,255,255,.22);font-size:16px;font-weight:800;flex-shrink:0}
    @keyframes op-save-ack-in{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
    .op-ticket-banner--highlight{animation:op-banner-pulse 1.1s ease 2}
    @keyframes op-banner-pulse{0%,100%{box-shadow:0 0 0 0 rgba(16,185,129,.45)}50%{box-shadow:0 0 0 8px rgba(16,185,129,0)}}
    @media(max-width:720px){.op-fixed-save-bar,.op-save-ack-bar{left:0}}
    .op-fotos-section{display:flex;flex-direction:column;gap:12px;padding-top:4px;border-top:1px solid var(--border)}
    .op-fotos-head{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}
    .op-fotos-head strong{font-size:15px}
    .op-photo-preview{display:none;grid-template-columns:repeat(2,1fr);gap:12px}
    @media(min-width:540px){.op-photo-preview{grid-template-columns:repeat(3,1fr)}}
    .op-photo-preview.active{display:grid}
    .op-photo-preview__fig{position:relative;margin:0;border-radius:16px;overflow:hidden;background:#f8fafc;border:1px solid var(--border);box-shadow:0 4px 16px rgba(15,23,42,.08)}
    .op-photo-preview__fig img{width:100%;aspect-ratio:4/3;object-fit:cover;display:block}
    .op-photo-preview__fig figcaption{padding:8px 10px;font-size:11px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .op-photo-preview__remove,.op-photo-grid__remove{position:absolute;top:6px;right:6px;z-index:2;width:28px;height:28px;padding:0;border:none;border-radius:50%;background:rgba(15,23,42,.72);color:#fff;font-size:18px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(0,0,0,.25)}
    .op-photo-preview__remove:hover,.op-photo-grid__remove:hover{background:rgba(185,28,28,.92)}
    .op-photo-grid-wrap{max-height:280px;overflow-y:auto;border-radius:16px;padding:2px}
    .op-photo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(104px,1fr));gap:10px}
    .op-photo-grid__item{position:relative;border-radius:14px;overflow:hidden}
    .op-photo-grid__link{display:block;border:1px solid var(--border);background:#f1f5f9;border-radius:14px;overflow:hidden;transition:transform .15s ease,box-shadow .15s ease}
    .op-photo-grid__link:active{transform:scale(.98)}
    .op-photo-grid__link img{width:100%;aspect-ratio:1;object-fit:cover;display:block;vertical-align:middle}
    .op-map{height:260px;border-radius:16px;overflow:hidden;background:#f1f5f9;border:1px solid var(--border)}
    .op-loc-view-bar{display:flex;gap:8px;margin:10px 0 0}
    .op-loc-streetview{margin-top:0}
    .op-loc-streetview .chamado-ponto-streetview__frame-wrap{padding-bottom:0;height:260px;border-radius:16px}
    .op-loc-streetview .chamado-ponto-streetview__frame{border-radius:16px}
    .op-item-combo{position:relative;width:100%}
    .op-item-combo__dd{position:absolute;left:0;right:0;top:calc(100% + 4px);max-height:min(320px,50vh);overflow-y:auto;background:#faf8f5;border:1px solid #e8e4df;border-radius:14px;box-shadow:0 12px 36px rgba(15,23,42,.12);z-index:60;display:none;padding:6px}
    .op-item-combo.is-open .op-item-combo__dd{display:block}
    .op-item-combo__opt{display:block;width:100%;padding:12px 14px;margin:0;border:0;border-bottom:1px solid #ebe6e0;background:#fff;text-align:left;cursor:pointer;color:inherit;font-family:inherit;border-radius:10px}
    .op-item-combo__opt:last-child{border-bottom:0}
    .op-item-combo__opt:hover,.op-item-combo__opt.is-active{background:#eff6ff}
    .op-item-combo__opt-main{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:4px}
    .op-item-combo__opt-name{font-size:14px;font-weight:700;color:var(--text,#0f172a);line-height:1.3;flex:1;min-width:0}
    .op-item-combo__opt-meta{font-size:12px;color:var(--muted,#64748b);line-height:1.35}
    .op-item-combo__stock{flex-shrink:0;font-size:11px;font-weight:700;padding:4px 8px;border-radius:999px;background:#dcfce7;color:#166534;white-space:nowrap}
    .op-item-combo__stock--low{background:#ffedd5;color:#c2410c}
    .op-item-combo__empty{padding:12px;font-size:13px;color:var(--muted)}
    .op-mat-panel{margin-bottom:14px;padding:14px;border:1px solid var(--border);border-radius:16px;background:#faf8f5}
    .chamado-materiais-op-add{margin-bottom:16px;padding:0;border:0;background:transparent}
    .op-mat-stack{list-style:none;margin:12px 0 0;padding:0;display:flex;flex-direction:column;gap:8px}
    .op-mat-row{display:flex;align-items:center;gap:12px;padding:12px 14px;border:1px solid var(--border);border-radius:14px;background:#fff;box-shadow:0 1px 4px rgba(15,23,42,.04)}
    .op-mat-row__icon{flex-shrink:0;width:36px;height:36px;border-radius:10px;background:#f1f5f9;color:#64748b;display:flex;align-items:center;justify-content:center}
    .op-mat-row__body{flex:1;min-width:0}
    .op-mat-row__name{display:block;font-size:14px;font-weight:700;line-height:1.3;margin:0}
    .op-mat-row__obs{display:block;font-size:12px;color:var(--muted);margin-top:4px;line-height:1.35}
    .op-mat-row__actions{display:flex;align-items:center;gap:8px;flex-shrink:0}
    .op-mat-row__qtd{border:1px solid var(--border);background:#f8fafc;border-radius:10px;padding:8px 12px;font-size:13px;font-weight:700;color:var(--text);cursor:pointer;font-family:inherit}
    .op-mat-row__qtd--readonly{cursor:default;border-color:transparent;background:transparent}
    .op-mat-row__del{border:0;background:transparent;color:#dc2626;padding:8px;border-radius:10px;cursor:pointer;display:flex;align-items:center;justify-content:center}
    .op-mat-row__del:hover{background:#fef2f2}
    .op-mat-footnote{display:flex;align-items:flex-start;gap:8px;margin:10px 0 0;font-size:12px;color:var(--muted);line-height:1.45}
    .op-mat-footnote svg{flex-shrink:0;margin-top:1px;opacity:.75}
    .op-mat-empty{margin-top:12px}
    .op-mobile-shell .chamado-materiais-card .panel-body{padding:18px 18px 22px}
    .op-mobile-shell .chamado-materiais-card__head{flex-direction:column;align-items:stretch}
    .op-mobile-shell .chamado-materiais-card__stats{width:100%;display:grid;grid-template-columns:1fr 1fr;gap:10px;justify-content:stretch}
    .op-mobile-shell .chamado-materiais-card__pill{min-width:0;text-align:center;box-sizing:border-box}
    .op-mat-add-form{gap:12px!important}
    .op-mat-search{min-height:50px;font-size:16px;border-radius:14px}
    .op-mat-add-row{display:flex;gap:10px;align-items:stretch}
    .op-mat-qtd{max-width:108px;min-height:48px;border-radius:12px;text-align:center;font-weight:600}
    .op-mat-obs{flex:1;min-height:48px;border-radius:12px}
    .op-mat-submit{width:100%;min-height:48px;border-radius:14px;font-weight:700}
    .op-mat-table-only{display:block}
    .op-mat-cards-only{display:none;flex-direction:column;gap:12px}
    .op-mat-card{border:1px solid var(--border);border-radius:16px;padding:14px 16px;background:#fff;box-shadow:0 2px 10px rgba(15,23,42,.04)}
    .op-mat-card__title{font-size:15px;font-weight:700;margin:0 0 6px;line-height:1.3}
    .op-mat-card__meta{display:flex;flex-wrap:wrap;gap:8px 14px;font-size:13px;color:var(--muted);margin-bottom:8px}
    .op-mat-card__obs{font-size:13px;color:var(--muted);margin:0 0 12px;line-height:1.45}
    .op-mat-card__foot{display:flex;justify-content:flex-end}
    .chamado-materiais-card__body > section.chamado-materiais-block{margin-block:20px}
    .chamado-materiais-card__body > section.chamado-materiais-block:first-child{margin-top:0}
    .chamado-materiais-card__body > section.chamado-materiais-block:last-child{margin-bottom:0}
    .chamado-materiais-block--dev{margin-top:0;padding-top:22px;border-top:2px solid #e2e8f0}
    .chamado-materiais-block--dev .chamado-materiais-op-add{background:#f8fafc}
    .op-local-in-atend{padding-bottom:4px;border-bottom:1px solid var(--border);margin-bottom:4px}
    .op-local-in-atend .op-local-label{margin-top:-2px}
    .op-ticket-desc{margin-top:12px;padding-top:14px;border-top:1px solid var(--border)}
    .op-ticket-desc__title{margin:0 0 8px;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--muted)}
    .op-ticket-desc__body{margin:0;font-size:15px;line-height:1.65;color:var(--text,#334155)}
    .op-thread-empty{padding:24px 16px;text-align:center;margin:0}
    .op-local-nav{margin-bottom:0}
    .op-thread-reply-form{border-top:1px solid var(--border);padding:16px 18px}
    .op-thread-card .op-thread-wrap{padding:12px 14px 8px}
    .op-mobile-shell .op-thread-card .thread .msg{margin-bottom:14px}
    .op-mobile-shell .op-thread-card .thread .msg:last-child{margin-bottom:0}
    .op-thread-card .bubble p:last-child{margin-bottom:0}
    .op-resumo-card .op-card-head h4{font-size:14px;font-weight:600;color:var(--muted)}
    .op-resumo-card .op-card-body{padding:12px 18px 16px}
    .op-mobile-shell .op-ticket-header.ticket-header{margin:0 0 4px}
    .op-ticket-header.ticket-header{
      display:flex;flex-direction:column;align-items:stretch;justify-content:flex-start;flex-wrap:nowrap;gap:10px;
      width:100%;padding:14px 16px 16px;border-radius:18px;
      box-sizing:border-box;overflow-x:hidden
    }
    .op-ticket-header.ticket-header > *{min-width:0;max-width:100%;box-sizing:border-box}
    .op-ticket-banner{width:100%;font-size:13px;font-weight:600;padding:10px 12px;border-radius:12px;margin:0;line-height:1.45;box-sizing:border-box}
    .op-ticket-banner--done{background:linear-gradient(135deg,#ecfdf5,#d1fae5);color:#065f46;border:1px solid #a7f3d0}
    .op-ticket-banner--hold{background:#fffbeb;color:#92400e;border:1px solid #fde68a}
    .op-ticket-top{margin:0}
    .op-ticket-kicker{display:block;font-size:11px;font-weight:700;letter-spacing:.06em;color:var(--primary,#534ab7);margin-bottom:4px}
    .op-ticket-title{margin:0;font-size:18px;font-weight:800;line-height:1.3;color:var(--text,#0f172a);overflow-wrap:anywhere;word-break:break-word}
    @media(min-width:721px){.op-ticket-title{font-size:20px;line-height:1.25}}
    .op-ticket-subline{overflow-wrap:anywhere;word-break:break-word}
    .op-ticket-meta{display:flex;flex-wrap:wrap;gap:8px;margin:0;overflow:visible;width:100%}
    .op-ticket-chip{flex:0 1 auto;max-width:100%;font-size:12px;font-weight:600;padding:6px 11px;border-radius:999px;border:1px solid var(--border);background:#fff;white-space:normal;line-height:1.3;box-sizing:border-box}
    .op-ticket-chip--status{border-width:0}
    .op-ticket-chip--import{font-weight:600;color:#64748b;background:#f1f5f9;border-color:#e2e8f0}
    .op-ticket-sub{display:flex;flex-direction:column;gap:4px;font-size:13px;color:var(--muted);line-height:1.45}
    .op-ticket-sub span{display:block}
    .op-sr-file{position:fixed;left:-9999px;top:0;width:2px;height:2px;opacity:0.01;clip:auto;overflow:hidden}
    @media(max-width:720px){
      .content{padding:12px 12px 20px}
      .op-ticket-header.ticket-header{padding:12px 14px 14px;border-radius:16px}
      .op-ticket-title{font-size:17px}
      .op-ticket-banner{font-size:12px;padding:9px 11px}
      .op-ticket-chip{font-size:11px;padding:5px 10px}
      .op-actions--cam{grid-template-columns:1fr 1fr}
      .op-mat-table-only{display:none!important}
      .op-mat-cards-only{display:flex!important}
      .op-map{height:220px}
    }
  </style>

  <div class="op-mobile-shell<?= !$opEdicaoEncerrada ? ' op-mobile-shell--fixed-save' : '' ?>">

  <div class="ticket-header op-ticket-header">
    <?php if ($jaEnviadoGestor || $opEdicaoEncerrada): ?>
      <div class="op-ticket-banner op-ticket-banner--done<?= $opSalvoOk ? ' op-ticket-banner--highlight' : '' ?>" id="op-ticket-banner-status" role="status">
        <?php if ($opEdicaoEncerrada): ?>
          Chamado encerrado pela gestão. Itens e fotos não podem mais ser alterados.
        <?php else: ?>
          Chamado enviado ao gestor. Ainda pode <strong>adicionar itens e fotos</strong>; alteração de endereço depende do gestor.
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <div class="op-ticket-top">
      <span class="op-ticket-kicker">Chamado #<?= (int) $chamado['id'] ?></span>
      <h2 class="op-ticket-title"><?= htmlspecialchars($enderecoChamado !== '' ? $enderecoChamado : ((string) ($chamado['titulo'] ?? 'Sem endereço'))) ?></h2>
      <?php if ($enderecoChamado !== '' && trim((string) ($chamado['titulo'] ?? '')) !== ''): ?>
      <p class="op-ticket-subline muted" style="margin:6px 0 0;font-size:14px;line-height:1.4;"><?= htmlspecialchars((string) ($chamado['titulo'] ?? '')) ?></p>
      <?php endif; ?>
    </div>
    <div class="op-ticket-meta" aria-label="Resumo do chamado">
      <span class="op-ticket-chip op-ticket-chip--status badge <?= htmlspecialchars(status_class($chamado['status'] ?? '')) ?>"><?= htmlspecialchars((string) ($chamado['status'] ?? '—')) ?></span>
      <span class="op-ticket-chip">Prioridade: <?= htmlspecialchars((string) ($chamado['prioridade'] ?? '—')) ?></span>
      <?php if (!empty($chamado['data'])): ?>
      <span class="op-ticket-chip"><?= date('d/m/Y H:i', strtotime((string) $chamado['data'])) ?></span>
      <?php endif; ?>
      <?php if ($chamadoImportadoBm): ?>
      <span class="op-ticket-chip op-ticket-chip--import"
            title="Chamado gerado pela importação do relatório / boletim de medição">Importado BM</span>
      <?php endif; ?>
    </div>
    <?php if (trim((string) ($chamado['descricao'] ?? '')) !== ''): ?>
    <div class="op-ticket-desc">
      <h3 class="op-ticket-desc__title">Descrição</h3>
      <p class="op-ticket-desc__body"><?= nl2br(htmlspecialchars((string) ($chamado['descricao'] ?? ''))) ?></p>
    </div>
    <?php endif; ?>
  </div>
      <form id="op-os-form" class="op-card op-card--primary" method="post" enctype="multipart/form-data"
            data-max-file-bytes="<?= (int) UPLOAD_MAX_BYTES ?>"
            data-fotos-salvas="<?= (int) $qtdFotosExistentes ?>"
            data-ja-enviado-gestor="<?= $jaEnviadoGestor ? '1' : '0' ?>"
            data-chamado-id="<?= (int) $id ?>">
        <input type="hidden" name="acao" id="op-form-acao" value="">
        <input type="file" id="op-pick-cam" class="op-sr-file crm-no-custom-file" accept="image/*" capture="environment" multiple tabindex="-1" aria-hidden="true">
        <input type="file" id="op-pick-gal" class="op-sr-file crm-no-custom-file" accept="image/*" multiple tabindex="-1" aria-hidden="true">
        <input type="file" name="imagens[]" id="op-imagens-master" class="op-sr-file crm-no-custom-file" accept="image/png,image/jpeg,image/jpg,image/gif,image/webp,image/*" multiple tabindex="-1" aria-hidden="true">
        <div class="op-card-head">
          <div>
            <h4>Atendimento</h4>
            <p class="op-card-lead muted"><?php if ($jaEnviadoGestor): ?>
              Fotos gravam ao selecionar. Use <strong>Salvar alterações</strong> na barra inferior para gravar fotos pendentes.
            <?php else: ?>
              Fotos gravam ao selecionar. Use <strong>Salvar</strong> na barra inferior para gravar pendentes e <strong>Enviar ao gestor</strong> quando terminar.
            <?php endif; ?></p>
          </div>
        </div>
        <div class="op-card-body op-atend-body">
          <?php if ($jaEnviadoGestor && !$opEdicaoEncerrada): ?>
            <p class="op-atend-note">Este chamado já foi enviado ao gestor. Ainda pode <strong>adicionar itens e fotos</strong> (fotos gravam automaticamente ao selecionar).</p>
          <?php endif; ?>

          <?php
            $refLocal = $enderecoChamado;
            $latMap = $locPreview['lat'];
            $lngMap = $locPreview['lng'];
            $temCoordsEfetivas = $latMap !== null && $lngMap !== null;
            $navEndereco = (string) ($locPreview['nav_query'] ?? '');
            $coordUrlAtend = $navEndereco !== '' ? rawurlencode($navEndereco) : '';
            $mostrarMapaAtend = $loadLeaflet;
          ?>
          <div class="form-group op-form-group op-local-in-atend">
            <span class="op-ref-field-label" id="lbl-endereco-ref">Local do chamado</span>
            <p class="op-local-label muted" style="margin:0 0 8px;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">Endereço / referência (cadastro)</p>
            <div class="op-ref-row">
              <div class="op-ref-plain" id="endereco_completo_display" role="region" aria-labelledby="lbl-endereco-ref"><?php
                if ($refLocal === '') {
                    echo '<span class="muted">Sem endereço no cadastro.</span>';
                } else {
                    echo nl2br(htmlspecialchars($refLocal));
                }
              ?></div>
              <button type="button" class="btn btn-secondary op-ref-copy-btn" id="op-endereco-copy" data-copy="<?= htmlspecialchars($refLocal, ENT_QUOTES, 'UTF-8') ?>"<?= $refLocal === '' ? ' disabled' : '' ?>>Copiar</button>
            </div>
            <?php if (!$jaEnviadoGestor): ?>
              <input type="hidden" name="endereco_completo" value="<?= htmlspecialchars($refLocal, ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>
            <?php if ($temCoordsEfetivas): ?>
              <p class="muted op-local-coords" style="font-size:12px;margin:10px 0 0;line-height:1.5;">
                <?php if (($locPreview['label_fonte'] ?? '') !== ''): ?>
                  <?= htmlspecialchars((string) $locPreview['label_fonte']) ?> —
                <?php endif; ?>
                Coordenadas: <strong><?= htmlspecialchars(number_format($latMap, 6, '.', '')) ?>, <?= htmlspecialchars(number_format($lngMap, 6, '.', '')) ?></strong>
              </p>
            <?php endif; ?>
            <?php if ($mostrarMapaAtend && $coordUrlAtend !== ''): ?>
              <div class="op-actions op-ref-nav">
                <a class="btn btn-primary op-btn-tall" target="_blank" rel="noopener"
                   href="https://www.google.com/maps/search/?api=1&amp;query=<?= $coordUrlAtend ?>">Google Maps</a>
                <?php if ($temCoordsEfetivas): ?>
                <a class="btn btn-secondary op-btn-tall" target="_blank" rel="noopener"
                   href="https://waze.com/ul?ll=<?= $coordUrlAtend ?>&amp;navigate=yes">Waze</a>
                <?php endif; ?>
              </div>
              <?php if (($locPreview['modo'] ?? '') === 'geocode'): ?>
                <p class="muted" id="chamado-map-geocode-hint" style="margin:8px 0 0;font-size:12px;">A localizar endereço…</p>
              <?php endif; ?>
              <div id="op-loc-preview" class="op-ref-map">
                <div class="op-loc-view-bar" role="group" aria-label="Visualização do local">
                  <button type="button" class="btn btn-sm btn-primary" id="op-loc-sv-street-btn">Street View</button>
                  <button type="button" class="btn btn-sm btn-secondary" id="op-loc-sv-map-btn">Mapa</button>
                </div>
                <div id="chamado-map-atendimento" class="op-map" hidden aria-label="Mapa do endereço do chamado"></div>
                <div id="op-loc-streetview-wrap" class="chamado-ponto-streetview op-loc-streetview" hidden>
                  <div class="chamado-ponto-streetview__frame-wrap">
                    <iframe id="op-loc-streetview-frame" class="chamado-ponto-streetview__frame" title="Street View do chamado" allowfullscreen loading="lazy"></iframe>
                  </div>
                </div>
              </div>
            <?php endif; ?>
          </div>
          <?php if ($jaEnviadoGestor): ?>
            <small class="muted" style="display:block;margin-top:-8px;">Alteração de endereço após envio depende do gestor.</small>
          <?php endif; ?>

          <?php if (!$opEdicaoEncerrada): ?>
          <div class="op-atend-actions">
            <div class="op-actions op-actions--cam">
              <button type="button" class="btn btn-secondary op-btn-tall" id="op-btn-cam">Abrir câmera</button>
              <button type="button" class="btn btn-secondary op-btn-tall" id="op-btn-gal">Galeria</button>
            </div>
          </div>
          <?php endif; ?>

          <div class="op-fotos-section">
            <div class="op-fotos-head">
              <strong>Fotos do atendimento</strong>
              <span class="panel-sub" id="op-photo-count-hint"><?= (int) $qtdFotosExistentes ?> salva(s)<span id="op-photo-pending-hint"></span></span>
            </div>
            <div class="op-photo-preview" id="op-photo-preview" aria-live="polite"></div>
            <div class="op-photo-grid-wrap" id="op-photo-grid-wrap"<?= empty($imagensOp) ? ' hidden' : '' ?>>
              <div class="op-photo-grid" id="op-photo-grid">
                <?php foreach ($imagensOp as $img): ?>
                  <div class="op-photo-grid__item" data-anexo-id="<?= (int) $img['id'] ?>">
                    <a class="op-photo-grid__link" href="chamado_download.php?id=<?= (int) $img['id'] ?>" target="_blank" rel="noopener">
                      <img src="chamado_download.php?id=<?= (int) $img['id'] ?>" alt="<?= htmlspecialchars((string) $img['nome_original']) ?>" loading="lazy">
                    </a>
                    <?php if (!$opEdicaoEncerrada): ?>
                    <button type="button" class="op-photo-grid__remove" data-anexo-id="<?= (int) $img['id'] ?>"
                            aria-label="Remover foto <?= htmlspecialchars((string) $img['nome_original'], ENT_QUOTES, 'UTF-8') ?>">×</button>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
      </form>
      <?php if (!$opEdicaoEncerrada): ?>
      <div class="op-fixed-save-bar" role="region" aria-label="Ações do chamado">
        <div class="op-fixed-save-bar__inner<?= $jaEnviadoGestor ? ' op-fixed-save-bar__inner--single' : '' ?>">
          <button type="button" class="btn btn-secondary op-btn-block" id="op-btn-salvar-rascunho"
                  title="Grava fotos pendentes sem enviar ao gestor"><?= $jaEnviadoGestor ? 'Salvar alterações' : 'Salvar' ?></button>
          <?php if (!$jaEnviadoGestor): ?>
          <button type="button" class="btn btn-primary op-btn-block op-btn-cta" id="op-btn-enviar-gestor">Enviar ao gestor</button>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="card chamado-materiais-card">
        <div class="panel-head chamado-materiais-card__head">
          <div class="chamado-materiais-card__intro">
            <h4>Itens do atendimento</h4>
            <span class="panel-sub">Controle de materiais utilizados e recolhidos neste chamado</span>
          </div>
          <div class="chamado-materiais-card__stats" aria-label="Resumo rápido">
            <div class="chamado-materiais-card__pill">
              <span>Utilizados</span>
              <strong data-op-mat-count="utilizados"><?= count($itensUsadosOp) ?></strong>
            </div>
            <div class="chamado-materiais-card__pill">
              <span>Recolhidos</span>
              <strong data-op-mat-count="recolhidos"><?= count($itensDevolvidosOp) ?></strong>
            </div>
          </div>
        </div>
        <div class="panel-body chamado-materiais-card__body">
          <section class="chamado-materiais-block chamado-materiais-block--uso" aria-labelledby="ch-op-mat-uso-title">
            <div class="chamado-materiais-block__bar">
              <h5 id="ch-op-mat-uso-title" class="chamado-materiais-block__title">Itens utilizados</h5>
            </div>
            <?php if (!$opEdicaoEncerrada): ?>
            <div class="chamado-materiais-op-add op-mat-panel">
              <form method="post" class="flex-col op-mat-add-form" data-op-item-form data-op-mat-movimento="utilizado" data-catalog-filter="produto">
                <input type="hidden" name="acao" value="chamado_item_add">
                <input type="hidden" name="movimento" value="utilizado">
                <input type="hidden" name="item_id" data-op-item-id value="">
                <div class="op-item-combo" data-op-item-combo data-filter-empty="Nenhum item corresponde à busca.">
                  <input type="text" data-op-item-search class="input op-mat-search" role="combobox" aria-expanded="false"
                         aria-autocomplete="list" autocomplete="off"
                         placeholder="Buscar no catálogo (toque ou digite)"
                         <?= empty($servicos) ? 'disabled' : '' ?>>
                  <div class="op-item-combo__dd" data-op-item-dd role="listbox" hidden></div>
                </div>
                <?php if (empty($servicos)): ?>
                  <small class="muted">Nenhum produto ou serviço ativo no catálogo desta empresa.</small>
                <?php endif; ?>
                <div class="op-mat-add-row">
                  <input type="text" name="quantidade" class="input op-mat-qtd" value="1" inputmode="decimal" placeholder="Qtd" aria-label="Quantidade">
                  <input type="text" name="observacao" class="input op-mat-obs" placeholder="Observação opcional">
                </div>
                <button type="submit" class="btn btn-primary op-mat-submit" <?= empty($servicos) ? 'disabled' : '' ?>>Adicionar utilizado</button>
              </form>
              <p class="op-mat-footnote op-mat-footnote--stock">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                Estoque baixo em destaque previne erros
              </p>
            </div>
            <?php endif; ?>
            <div class="chamado-materiais-empty op-mat-empty" data-op-mat-empty="utilizado" <?= empty($itensUsadosOp) ? '' : 'hidden' ?>>
              <p>Nenhum item utilizado lançado ainda.</p>
              <small>Adicione produtos ou serviços aplicados neste atendimento.</small>
            </div>
            <ul class="op-mat-stack" data-op-mat-stack="utilizado" aria-label="Itens utilizados adicionados">
              <?= op_chamado_mat_stack_list_html($itensUsadosOp, $opEdicaoEncerrada) ?>
            </ul>
            <?php if (!$opEdicaoEncerrada && !empty($itensUsadosOp)): ?>
            <p class="op-mat-footnote op-mat-footnote--edit">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
              Clique na quantidade para editar
            </p>
            <?php endif; ?>
          </section>

          <section class="chamado-materiais-block chamado-materiais-block--dev" aria-labelledby="ch-op-mat-dev-title">
            <div class="chamado-materiais-block__bar">
              <h5 id="ch-op-mat-dev-title" class="chamado-materiais-block__title">Devolvidos / recolhidos</h5>
            </div>
            <?php if (!$opEdicaoEncerrada): ?>
            <div class="chamado-materiais-op-add op-mat-panel">
              <form method="post" class="flex-col op-mat-add-form" data-op-item-form data-op-mat-movimento="devolvido" data-catalog-filter="produto">
                <input type="hidden" name="acao" value="chamado_item_add">
                <input type="hidden" name="movimento" value="devolvido">
                <input type="hidden" name="item_id" data-op-item-id value="">
                <div class="op-item-combo" data-op-item-combo data-filter-empty="Nenhum produto corresponde à busca.">
                  <input type="text" data-op-item-search class="input op-mat-search" role="combobox" aria-expanded="false"
                         aria-autocomplete="list" autocomplete="off"
                         placeholder="Buscar produto no catálogo"
                         <?= empty($servicos) ? 'disabled' : '' ?>>
                  <div class="op-item-combo__dd" data-op-item-dd role="listbox" hidden></div>
                </div>
                <?php if (empty($servicos)): ?>
                  <small class="muted">Nenhum produto ativo no catálogo desta empresa.</small>
                <?php endif; ?>
                <div class="op-mat-add-row">
                  <input type="text" name="quantidade" class="input op-mat-qtd" value="1" inputmode="decimal" placeholder="Qtd" aria-label="Quantidade">
                  <input type="text" name="observacao" class="input op-mat-obs" placeholder="Observação opcional">
                </div>
                <button type="submit" class="btn btn-primary op-mat-submit op-mat-submit--recolhido" <?= empty($servicos) ? 'disabled' : '' ?>>Adicionar recolhido</button>
              </form>
              <div class="chamado-materiais-block__actions chamado-materiais-block__actions--stack" style="margin-top:10px;">
                <button type="button" class="btn btn-ghost btn-sm" data-op-solicitar-devolutivo-open>Solicitar novo item devolutivo</button>
              </div>
            </div>
            <?php endif; ?>
            <div class="chamado-materiais-empty chamado-materiais-empty--muted op-mat-empty" data-op-mat-empty="devolvido" <?= empty($itensDevolvidosOp) ? '' : 'hidden' ?>>
              <p>Nenhum item recolhido lançado ainda.</p>
              <small>Registre materiais retirados, devolvidos ou recolhidos no atendimento.</small>
            </div>
            <ul class="op-mat-stack" data-op-mat-stack="devolvido" aria-label="Itens recolhidos adicionados">
              <?= op_chamado_mat_stack_list_html($itensDevolvidosOp, $opEdicaoEncerrada) ?>
            </ul>
            <?php if (!$opEdicaoEncerrada && !empty($itensDevolvidosOp)): ?>
            <p class="op-mat-footnote op-mat-footnote--edit">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
              Clique na quantidade para editar · atualiza sem recarregar a página
            </p>
            <?php endif; ?>
          </section>
        </div>
      </div>


      <?php if (!$chamadoImportadoBm): ?>
      <div class="op-card op-thread-card">
        <div class="op-card-head">
          <h4>Conversa</h4>
          <span class="panel-sub"><?= count($respostas) ?> mensagem(ns)</span>
        </div>
        <div class="op-thread-wrap">
          <div class="thread">
            <?php if (empty($respostas)): ?>
              <div class="empty-state op-thread-empty">
                <p class="muted">Sem mensagens públicas ainda.</p>
              </div>
            <?php else: foreach ($respostas as $r): ?>
              <?php
                $tipo = (string) ($r['tipo'] ?? '');
                $cls  = in_array($tipo, ['operador', 'admin', 'gestor'], true) ? 'me' : '';
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
        <form method="post" class="op-thread-reply-form">
          <input type="hidden" name="acao" value="responder">
          <div class="form-group" style="margin:0 0 12px;">
            <label for="op_resposta_txt">Comentário na conversa</label>
            <textarea id="op_resposta_txt" name="resposta" class="textarea" rows="3" required placeholder="Ex.: cheguei ao local, material aplicado, próximos passos…"></textarea>
          </div>
          <button type="submit" class="btn btn-primary op-btn-tall">Enviar comentário</button>
          <small class="muted" style="display:block;margin-top:10px;line-height:1.45;">Visível a gestores, cliente e restantes técnicos da empresa; gera notificação no sistema.</small>
        </form>
      </div>
      <?php endif; ?>

      <div class="op-card op-resumo-card">
        <div class="op-card-head"><h4>Resumo</h4></div>
        <div class="op-card-body flex-col" style="gap:10px;">
          <div class="info-row"><span>Status</span><strong><?= htmlspecialchars((string) ($chamado['status'] ?? '')) ?></strong></div>
          <div class="info-row"><span>Aberto em</span><strong><?= !empty($chamado['data']) ? date('d/m/Y H:i', strtotime((string) $chamado['data'])) : '—' ?></strong></div>
          <?php if (!empty($chamado['finalizado_operador_em'])): ?>
            <div class="info-row"><span>Finalizado</span><strong><?= htmlspecialchars((string) $chamado['finalizado_operador_em']) ?></strong></div>
          <?php endif; ?>
        </div>
      </div>
  </div>

  <?php if ($opSalvoOk): ?>
  <div class="op-save-ack-bar" id="op-save-ack-bar" role="status" aria-live="polite">
    <span class="op-save-ack-bar__icon" aria-hidden="true">✓</span>
    <span>Chamado enviado ao gestor com sucesso</span>
  </div>
  <?php endif; ?>

</section>

<script src="<?= $basePath ?>assets/js/op-chamado-materiais.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/op-chamado-materiais.js') ?>"></script>
<script>
(function () {
  if (window.OpChamadoMateriais) {
    OpChamadoMateriais.init({
      catalog: <?= json_encode($opCatalogoItensJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>,
      readonly: <?= $opEdicaoEncerrada ? 'true' : 'false' ?>
    });
  }

  var opForm = document.getElementById('op-os-form');
  var master = document.getElementById('op-imagens-master');
  var pickCam = document.getElementById('op-pick-cam');
  var pickGal = document.getElementById('op-pick-gal');
  var btnCam = document.getElementById('op-btn-cam');
  var btnGal = document.getElementById('op-btn-gal');
  var preview = document.getElementById('op-photo-preview');
  var acaoInput = document.getElementById('op-form-acao');
  var btnSalvarRascunho = document.getElementById('op-btn-salvar-rascunho');
  var btnEnviarGestor = document.getElementById('op-btn-enviar-gestor');
  var pendingHint = document.getElementById('op-photo-pending-hint');
  var countHint = document.getElementById('op-photo-count-hint');
  var photoGridWrap = document.getElementById('op-photo-grid-wrap');
  var photoGrid = document.getElementById('op-photo-grid');

  if (!opForm || !master || !preview || !acaoInput) {
    return;
  }

  var maxBytes = parseInt(opForm.getAttribute('data-max-file-bytes') || '15728640', 10) || 15728640;
  var fotosSalvas = parseInt(opForm.getAttribute('data-fotos-salvas') || '0', 10) || 0;

  function countFotosNoGrid() {
    return photoGrid ? photoGrid.querySelectorAll('.op-photo-grid__item').length : 0;
  }

  function totalFotosAtendimento() {
    return Math.max(fotosSalvas, countFotosNoGrid()) + dt.files.length;
  }

  var dt = new DataTransfer();

  function extOk(name) {
    var m = (name || '').toLowerCase().match(/\.([a-z0-9]+)$/i);
    var ext = m ? m[1] : '';
    return ['png', 'jpg', 'jpeg', 'gif', 'webp'].indexOf(ext) !== -1;
  }

  function fileAceito(file) {
    if (!file) return false;
    var t = file.type || '';
    if (t === 'image/heic' || t === 'image/heif') return false;
    if (t === 'image/svg+xml') return false;
    if (extOk(file.name)) return true;
    if (t.indexOf('image/') !== 0) return false;
    return (
      t === 'image/png' ||
      t === 'image/jpeg' ||
      t === 'image/jpg' ||
      t === 'image/pjpeg' ||
      t === 'image/gif' ||
      t === 'image/webp' ||
      t === 'image/x-png'
    );
  }

  function formatBytes(n) {
    if (n < 1024) return n + ' B';
    if (n < 1048576) return (n / 1024).toFixed(1).replace('.', ',') + ' KB';
    return (n / 1048576).toFixed(1).replace('.', ',') + ' MB';
  }

  function alertMsg(msg, titulo) {
    titulo = titulo || 'Fotos';
    if (typeof window.appAlert === 'function') {
      window.appAlert(msg, titulo);
    } else {
      window.alert(msg);
    }
  }

  function syncMasterFiles() {
    master.files = dt.files;
    updatePendingUi();
    renderPreview();
  }

  function updatePendingUi() {
    var n = dt.files.length;
    if (pendingHint) {
      pendingHint.textContent = n ? ' · +' + n + ' nova(s)' : '';
    }
  }

  function updateSavedCountUi() {
    var n = countFotosNoGrid();
    fotosSalvas = Math.max(fotosSalvas, n);
    opForm.setAttribute('data-fotos-salvas', String(fotosSalvas));
    if (!countHint) return;
    countHint.innerHTML = n + ' salva(s)<span id="op-photo-pending-hint"></span>';
    pendingHint = document.getElementById('op-photo-pending-hint');
    updatePendingUi();
    if (photoGridWrap) photoGridWrap.hidden = n < 1;
  }

  function removePendingFile(fileToRemove) {
    var next = new DataTransfer();
    Array.prototype.forEach.call(dt.files, function (file) {
      if (file !== fileToRemove) {
        next.items.add(file);
      }
    });
    dt = next;
    syncMasterFiles();
  }

  function renderPreview() {
    preview.innerHTML = '';
    var files = Array.prototype.slice.call(dt.files || []);
    preview.classList.toggle('active', files.length > 0);
    files.forEach(function (file) {
      var url = URL.createObjectURL(file);
      var fig = document.createElement('figure');
      fig.className = 'op-photo-preview__fig';
      var btnRm = document.createElement('button');
      btnRm.type = 'button';
      btnRm.className = 'op-photo-preview__remove';
      btnRm.setAttribute('aria-label', 'Remover ' + file.name);
      btnRm.textContent = '×';
      btnRm.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        removePendingFile(file);
      });
      var img = document.createElement('img');
      var cap = document.createElement('figcaption');
      img.src = url;
      img.alt = file.name;
      img.onload = function () { URL.revokeObjectURL(url); };
      cap.textContent = file.name + ' (' + formatBytes(file.size) + ')';
      fig.appendChild(btnRm);
      fig.appendChild(img);
      fig.appendChild(cap);
      preview.appendChild(fig);
    });
  }

  function addFilesFromList(list) {
    var erros = [];
    var novos = Array.prototype.slice.call(list || []);
    novos.forEach(function (file) {
      if (!fileAceito(file)) {
        erros.push(file.name + ': use PNG, JPG, GIF ou WEBP (HEIC não é aceito).');
        return;
      }
      if (file.size > maxBytes) {
        erros.push(file.name + ': arquivo maior que ' + formatBytes(maxBytes) + '.');
        return;
      }
      dt.items.add(file);
    });
    if (erros.length) {
      alertMsg(erros.join('\n'), 'Fotos inválidas');
    }
    syncMasterFiles();
    if (dt.files.length > 0) {
      doSubmit('fotos');
    }
  }

  if (pickCam && btnCam) {
    btnCam.addEventListener('click', function () { pickCam.click(); });
    pickCam.addEventListener('change', function () {
      if (pickCam.files && pickCam.files.length) addFilesFromList(pickCam.files);
      pickCam.value = '';
    });
  }
  if (pickGal && btnGal) {
    btnGal.addEventListener('click', function () { pickGal.click(); });
    pickGal.addEventListener('change', function () {
      if (pickGal.files && pickGal.files.length) addFilesFromList(pickGal.files);
      pickGal.value = '';
    });
  }
  function confirmDelete(msg, title) {
    if (typeof window.appConfirm === 'function') {
      return window.appConfirm({ message: msg, title: title || 'Fotos', danger: true });
    }
    return Promise.resolve(window.confirm(msg));
  }

  function deleteSavedPhoto(anexoId, itemEl) {
    var fd = new FormData();
    fd.append('acao', 'excluir_foto');
    fd.append('ajax', '1');
    fd.append('anexo_id', String(anexoId));
    fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
    })
      .then(function (r) { return r.text().then(function (t) {
        var trimmed = (t || '').trim();
        if (!trimmed) throw new Error('Resposta vazia.');
        return JSON.parse(trimmed);
      }); })
      .then(function (data) {
        if (!data || !data.ok) {
          alertMsg((data && data.err) || 'Não foi possível remover a foto.');
          return;
        }
        if (itemEl && itemEl.parentNode) itemEl.parentNode.removeChild(itemEl);
        fotosSalvas = typeof data.fotos_salvas === 'number' ? data.fotos_salvas : Math.max(0, fotosSalvas - 1);
        updateSavedCountUi();
      })
      .catch(function (err) {
        alertMsg((err && err.message) || 'Erro de rede ao remover foto.');
      });
  }

  if (photoGrid) {
    photoGrid.addEventListener('click', function (e) {
      var btn = e.target.closest('.op-photo-grid__remove');
      if (!btn) return;
      e.preventDefault();
      e.stopPropagation();
      var aid = parseInt(btn.getAttribute('data-anexo-id') || '0', 10);
      if (!aid) return;
      var item = btn.closest('.op-photo-grid__item');
      confirmDelete('Remover esta foto do atendimento?', 'Fotos').then(function (ok) {
        if (ok) deleteSavedPhoto(aid, item);
      });
    });
  }

  function validatePendingFiles() {
    var errs = [];
    Array.prototype.slice.call(dt.files || []).forEach(function (file) {
      if (!fileAceito(file)) errs.push(file.name + ': tipo não permitido.');
      if (file.size > maxBytes) errs.push(file.name + ': arquivo muito grande.');
    });
    return errs;
  }

  function fetchPostJson(fd) {
    return fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
    }).then(function (r) {
      return r.text().then(function (t) {
        var trimmed = (t || '').trim();
        if (!trimmed) throw new Error('Resposta vazia.');
        return JSON.parse(trimmed);
      });
    });
  }

  function applyFotoUploadResponse(data) {
    if (photoGrid && data.items_html && data.items_html.length) {
      data.items_html.forEach(function (html) {
        var wrap = document.createElement('div');
        wrap.innerHTML = html;
        var node = wrap.firstElementChild;
        if (node) photoGrid.appendChild(node);
      });
      if (photoGridWrap) photoGridWrap.hidden = false;
    }
    dt = new DataTransfer();
    syncMasterFiles();
    fotosSalvas = typeof data.fotos_salvas === 'number' ? data.fotos_salvas : fotosSalvas;
    updateSavedCountUi();
  }

  function showOpToast(msg, kind) {
    if (!msg) return;
    if (typeof window.appToast === 'function') {
      window.appToast(msg, kind === 'err' ? 'info' : 'ok');
    }
  }

  function setSaveBarBusy(busy, labelSalvar, labelEnviar) {
    [btnSalvarRascunho, btnEnviarGestor].forEach(function (btn) {
      if (!btn) return;
      btn.disabled = !!busy;
      btn.setAttribute('aria-busy', busy ? 'true' : 'false');
    });
    if (btnSalvarRascunho && labelSalvar) btnSalvarRascunho.textContent = labelSalvar;
    if (btnEnviarGestor && labelEnviar) btnEnviarGestor.textContent = labelEnviar;
  }

  function submitFotosAjax() {
    var pendErros = validatePendingFiles();
    if (pendErros.length) {
      alertMsg(pendErros.join('\n'), 'Fotos inválidas');
      return;
    }
    var fd = new FormData(opForm);
    fd.set('acao', 'fotos');
    fd.append('ajax', '1');
    fetchPostJson(fd)
      .then(function (data) {
        if (!data || !data.ok) {
          alertMsg((data && data.err) || (data && data.msg) || 'Não foi possível enviar as fotos.');
          return;
        }
        applyFotoUploadResponse(data);
      })
      .catch(function (err) {
        alertMsg((err && err.message) || 'Erro de rede ao enviar fotos.');
      });
  }

  function postAcaoAjax(acao, labels) {
    var pendErros = validatePendingFiles();
    if (pendErros.length) {
      alertMsg(pendErros.join('\n'), 'Fotos inválidas');
      return Promise.resolve(false);
    }
    var fd = new FormData(opForm);
    fd.set('acao', acao);
    fd.append('ajax', '1');
    setSaveBarBusy(true, labels.salvar || 'Salvando…', labels.enviar || 'Enviando…');
    return fetchPostJson(fd)
      .then(function (data) {
        if (!data || !data.ok) {
          alertMsg((data && data.err) || (data && data.msg) || 'Não foi possível concluir a operação.');
          return false;
        }
        if (data.items_html && data.items_html.length) {
          applyFotoUploadResponse(data);
        } else if (typeof data.fotos_salvas === 'number') {
          fotosSalvas = data.fotos_salvas;
          updateSavedCountUi();
        }
        showOpToast(data.msg || 'Salvo.', 'ok');
        if (data.ja_enviado_gestor && acao === 'enviar_os') {
          markEnviadoGestorUI(data);
        }
        return true;
      })
      .catch(function (err) {
        alertMsg((err && err.message) || 'Erro de rede.');
        return false;
      })
      .finally(function () {
        var lblSalvar = opForm.getAttribute('data-ja-enviado-gestor') === '1' ? 'Salvar alterações' : 'Salvar';
        setSaveBarBusy(false, lblSalvar, btnEnviarGestor ? 'Enviar ao gestor' : null);
      });
  }

  function markEnviadoGestorUI(data) {
    opForm.setAttribute('data-ja-enviado-gestor', '1');
    var header = document.querySelector('.op-ticket-header');
    if (header && !document.getElementById('op-ticket-banner-status')) {
      var div = document.createElement('div');
      div.id = 'op-ticket-banner-status';
      div.className = 'op-ticket-banner op-ticket-banner--done op-ticket-banner--highlight';
      div.setAttribute('role', 'status');
      div.innerHTML = 'Chamado enviado ao gestor. Ainda pode <strong>adicionar itens e fotos</strong>; alteração de endereço depende do gestor.';
      header.insertBefore(div, header.firstChild);
    }
    var chip = document.querySelector('.op-ticket-chip--status');
    if (chip && data.status) {
      chip.textContent = data.status;
      if (data.status_class) {
        chip.className = 'op-ticket-chip op-ticket-chip--status badge ' + data.status_class;
      }
    }
    if (btnEnviarGestor && btnEnviarGestor.parentNode) {
      btnEnviarGestor.remove();
      var inner = document.querySelector('.op-fixed-save-bar__inner');
      if (inner) inner.classList.add('op-fixed-save-bar__inner--single');
    }
    var endInput = opForm.querySelector('input[name="endereco_completo"]');
    if (endInput) endInput.remove();
    var atendBody = document.querySelector('.op-atend-body');
    if (atendBody && !atendBody.querySelector('.op-atend-note')) {
      var note = document.createElement('p');
      note.className = 'op-atend-note';
      note.innerHTML = 'Este chamado já foi enviado ao gestor. Ainda pode <strong>adicionar itens e fotos</strong> (fotos gravam automaticamente ao selecionar).';
      atendBody.insertBefore(note, atendBody.firstChild);
    }
    showOpSaveAckBar();
  }

  function showOpSaveAckBar() {
    if (document.getElementById('op-save-ack-bar')) return;
    var bar = document.createElement('div');
    bar.className = 'op-save-ack-bar';
    bar.id = 'op-save-ack-bar';
    bar.setAttribute('role', 'status');
    bar.innerHTML = '<span class="op-save-ack-bar__icon" aria-hidden="true">✓</span><span>Chamado enviado ao gestor com sucesso</span>';
    document.body.appendChild(bar);
    setTimeout(function () {
      bar.classList.add('is-out');
      setTimeout(function () { bar.remove(); }, 400);
    }, 5500);
  }

  function doSubmit(acao) {
    if (acao === 'fotos') {
      submitFotosAjax();
    }
  }

  opForm.addEventListener('submit', function (e) {
    e.preventDefault();
  });

  function materiaisPendentesNosFormularios() {
    var card = document.querySelector('.chamado-materiais-card');
    if (!card) return [];
    var msgs = [];
    card.querySelectorAll('form[data-op-item-form]').forEach(function (form) {
      var itemId = form.querySelector('[data-op-item-id]');
      var search = form.querySelector('[data-op-item-search]');
      var mov = form.getAttribute('data-op-mat-movimento') || 'utilizado';
      var rotulo = mov === 'devolvido' ? 'recolhido' : 'utilizado';
      if (itemId && itemId.value && parseInt(itemId.value, 10) > 0) {
        msgs.push('Há um item ' + rotulo + ' selecionado que ainda não foi adicionado. Use «Adicionar ' + rotulo + '» ou limpe a busca.');
      } else if (search && search.value.trim()) {
        msgs.push('Há texto na busca de itens ' + rotulo + ' sem item confirmado na lista.');
      }
    });
    return msgs;
  }

  function onSalvarRascunhoClick() {
    var pendMat = materiaisPendentesNosFormularios();
    if (pendMat.length) {
      alertMsg(pendMat.join('\n'), 'Itens do chamado');
      return;
    }
    postAcaoAjax('salvar_op', { salvar: 'Salvando…', enviar: null });
  }

  function onEnviarGestorClick() {
    var pendMat = materiaisPendentesNosFormularios();
    if (pendMat.length) {
      alertMsg(pendMat.join('\n'), 'Itens do chamado');
      return;
    }
    if (totalFotosAtendimento() < 1) {
      alertMsg('Inclua pelo menos uma foto do atendimento antes de concluir o chamado.', 'Enviar ao gestor');
      return;
    }
    var msg = 'Enviar este chamado ao gestor? O status passará para Aguardando Aprovação.';
    var run = function () {
      postAcaoAjax('enviar_os', { salvar: 'Salvando…', enviar: 'Enviando…' });
    };
    if (typeof window.appConfirm === 'function') {
      window.appConfirm({ message: msg, title: 'Confirmar', danger: false }).then(function (ok) {
        if (ok) run();
      });
    } else if (window.confirm(msg)) {
      run();
    }
  }

  updateSavedCountUi();

  if (btnSalvarRascunho) btnSalvarRascunho.addEventListener('click', onSalvarRascunhoClick);
  if (btnEnviarGestor) btnEnviarGestor.addEventListener('click', onEnviarGestorClick);
})();
</script>
<?php if ($opSalvoOk): ?>
<script>
(function () {
  var chamadoId = <?= (int) $id ?>;
  var banner = document.getElementById('op-ticket-banner-status');
  var ackBar = document.getElementById('op-save-ack-bar');

  function cleanSalvoQuery() {
    if (!window.history.replaceState) return;
    history.replaceState(null, '', 'chamado_detalhe.php?id=' + chamadoId);
  }

  requestAnimationFrame(function () {
    if (banner) {
      banner.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  });

  var msg = <?= json_encode((string) ($opPageFlash['msg'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
  if (msg && typeof window.appToast === 'function') {
    window.appToast(msg, 'ok');
  }

  if (ackBar) {
    setTimeout(function () {
      ackBar.classList.add('is-out');
      setTimeout(function () {
        ackBar.remove();
        cleanSalvoQuery();
      }, 400);
    }, 5500);
  } else {
    setTimeout(cleanSalvoQuery, 800);
  }
})();
</script>
<?php elseif (!empty($opPageFlash)): ?>
<script>
(function () {
  var msg = <?= json_encode((string) ($opPageFlash['msg'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
  var kind = <?= json_encode(($opPageFlash['tipo'] ?? '') === 'err' ? 'info' : 'ok') ?>;
  if (!msg) return;
  if (typeof window.appToast === 'function') {
    window.appToast(msg, kind);
  }
})();
</script>
<?php endif; ?>
<script>
(function () {
  var btn = document.getElementById('op-endereco-copy');
  if (!btn) return;

  btn.addEventListener('click', function () {
    var t = btn.getAttribute('data-copy') || '';
    if (!t.trim()) return;
    var label = btn.textContent;
    function flashOk() {
      btn.textContent = 'Copiado';
      setTimeout(function () { btn.textContent = label; }, 1600);
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(t).then(flashOk).catch(function () {
        window.alert('Não foi possível copiar para a área de transferência.');
      });
    } else {
      try {
        var x = document.createElement('textarea');
        x.value = t;
        x.style.position = 'fixed';
        x.style.left = '-9999px';
        document.body.appendChild(x);
        x.select();
        document.execCommand('copy');
        document.body.removeChild(x);
        flashOk();
      } catch (e) {
        window.alert('Não foi possível copiar.');
      }
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
    mapId: 'chamado-map-atendimento',
    svWrapId: 'op-loc-streetview-wrap',
    svFrameId: 'op-loc-streetview-frame',
    svMapBtnId: 'op-loc-sv-map-btn',
    svStreetBtnId: 'op-loc-sv-street-btn',
    dualViewButtons: true,
    hideExternalTab: true,
    hintId: 'chamado-map-geocode-hint',
    lat: <?= $locPreview['lat'] !== null ? json_encode($locPreview['lat']) : 'null' ?>,
    lng: <?= $locPreview['lng'] !== null ? json_encode($locPreview['lng']) : 'null' ?>,
    modo: <?= json_encode($locPreview['modo'] ?? 'none', JSON_UNESCAPED_UNICODE) ?>,
    mapaQuery: <?= json_encode($locPreview['mapa_query'] ?? '', JSON_UNESCAPED_UNICODE) ?>,
    attempts: <?= json_encode($locPreview['geocode_attempts'] ?? [], JSON_UNESCAPED_UNICODE) ?>,
    geocodeCidade: <?= json_encode(trim((string) ($chamado['os_cidade'] ?? '')), JSON_UNESCAPED_UNICODE) ?>,
    geocodeUf: <?= json_encode(strtoupper(preg_replace('/\./', '', trim((string) ($chamado['os_uf'] ?? '')))), JSON_UNESCAPED_UNICODE) ?>,
    scrollWheelZoom: false,
    zoomControl: true
  });
});
</script>
<?php endif; ?>

<?php
$devolutivoModalId        = 'op-solicitar-devolutivo-modal';
$devolutivoModalOpenAttr  = 'data-op-solicitar-devolutivo-open';
$devolutivoModalCloseAttr = 'data-op-solicitar-devolutivo-close';
$devolutivoFieldPrefix    = 'op';
require __DIR__ . '/../includes/partials/chamado_solicitar_devolutivo_modal.php';
?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
