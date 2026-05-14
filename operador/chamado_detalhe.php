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
        $msgs      = [];
        $falhas    = [];
        $salvos    = 0;
        $anexosPre = repo_chamado_anexos($id);
        $countFotosAntes = 0;
        foreach ($anexosPre as $axPre) {
            $mimePre = strtolower((string) ($axPre['mime'] ?? ''));
            $nomePre = strtolower((string) ($axPre['nome_original'] ?? ''));
            if (strpos($mimePre, 'image/') === 0 || preg_match('/\.(png|jpe?g|gif|webp|bmp)$/i', $nomePre)) {
                $countFotosAntes++;
            }
        }

        $end = trim((string) ($_POST['endereco_completo'] ?? ''));
        $endForRepo = $end !== '' ? $end : null;
        $latCh = isset($ch['latitude']) && $ch['latitude'] !== null && $ch['latitude'] !== '' ? (float) $ch['latitude'] : null;
        $lngCh = isset($ch['longitude']) && $ch['longitude'] !== null && $ch['longitude'] !== '' ? (float) $ch['longitude'] : null;
        repo_update_chamado_localizacao($id, $endForRepo, $latCh, $lngCh);

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

        $temArq = !empty($_FILES['imagens']['name']) && (is_array($_FILES['imagens']['name'])
            ? count(array_filter($_FILES['imagens']['name'], fn ($n) => $n !== '' && $n !== null)) > 0
            : (string) ($_FILES['imagens']['name'] ?? '') !== '');
        if ($temArq) {
            $destino = upload_dir_chamado($id);
            $res = upload_gravar_multiplos($_FILES['imagens'], $destino);
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

        if (empty($falhas) && ($countFotosAntes + $salvos) < 1) {
            $falhas[] = 'Inclua pelo menos uma foto do atendimento antes de concluir o chamado.';
        }

        if (empty($falhas)) {
            $nome = (string) ($user['nome'] ?? 'Operador');
            $r = repo_operador_chamado_finalizar($id, (int) ($user['id'] ?? 0), $empresaId, $nome);
            if ($r['ok']) {
                $msgs[] = 'Chamado enviado para aprovação do gestor.';
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
        $endFRepo = $endF !== '' ? $endF : null;
        $latF = isset($ch['latitude']) && $ch['latitude'] !== null && $ch['latitude'] !== '' ? (float) $ch['latitude'] : null;
        $lngF = isset($ch['longitude']) && $ch['longitude'] !== null && $ch['longitude'] !== '' ? (float) $ch['longitude'] : null;
        repo_update_chamado_localizacao($id, $endFRepo, $latF, $lngF);

        $temArq = !empty($_FILES['imagens']['name']) && (is_array($_FILES['imagens']['name'])
            ? count(array_filter($_FILES['imagens']['name'], fn ($n) => $n !== '' && $n !== null)) > 0
            : (string) ($_FILES['imagens']['name'] ?? '') !== '');
        if (!$temArq) {
            flash_set('ok', 'Referência no local guardada.');
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
try {
    $chamadoMateriaisOp = repo_chamado_itens_list($id);
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
$qtdFotosExistentes = count($imagensOp);
$jaFechado          = in_array((string) ($chamado['status'] ?? ''), ['Resolvido', 'Fechado'], true)
    || !empty($chamado['finalizado_operador_em']);
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
$topbarHideTitle = true;
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-operador.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <style>
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
    .op-ref-editor{margin-top:10px}
    .op-atend-actions{display:flex;flex-direction:column;gap:10px}
    .op-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    .op-actions .btn{width:100%;justify-content:center}
    .op-btn-tall{min-height:48px;font-size:15px;font-weight:600;border-radius:14px}
    .op-btn-block{width:100%;justify-content:center}
    .op-btn-cta{min-height:52px;font-size:16px;font-weight:700;border-radius:14px;box-shadow:0 4px 14px rgba(83,74,183,.35)}
    .op-fixed-save-bar{position:fixed;bottom:0;left:var(--sidebar-w);right:0;z-index:100;padding:12px 16px calc(12px + env(safe-area-inset-bottom,0px));background:linear-gradient(to top,rgba(255,255,255,.98),#fff);border-top:1px solid var(--border);box-shadow:0 -8px 24px rgba(15,23,42,.08)}
    .op-fixed-save-bar .btn{width:100%;justify-content:center}
    .op-fotos-section{display:flex;flex-direction:column;gap:12px;padding-top:4px;border-top:1px solid var(--border)}
    .op-fotos-head{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}
    .op-fotos-head strong{font-size:15px}
    #op-photo-clear{margin:0;width:100%}
    .op-photo-preview{display:none;grid-template-columns:repeat(2,1fr);gap:12px}
    @media(min-width:540px){.op-photo-preview{grid-template-columns:repeat(3,1fr)}}
    .op-photo-preview.active{display:grid}
    .op-photo-preview__fig{margin:0;border-radius:16px;overflow:hidden;background:#f8fafc;border:1px solid var(--border);box-shadow:0 4px 16px rgba(15,23,42,.08)}
    .op-photo-preview__fig img{width:100%;aspect-ratio:4/3;object-fit:cover;display:block}
    .op-photo-preview__fig figcaption{padding:8px 10px;font-size:11px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .op-photo-grid-wrap{max-height:280px;overflow-y:auto;border-radius:16px;padding:2px}
    .op-photo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(104px,1fr));gap:10px}
    .op-photo-grid a{display:block;border-radius:14px;overflow:hidden;border:1px solid var(--border);background:#f1f5f9;transition:transform .15s ease,box-shadow .15s ease}
    .op-photo-grid a:active{transform:scale(.98)}
    .op-photo-grid img{width:100%;aspect-ratio:1;object-fit:cover;display:block;vertical-align:middle}
    .op-photo-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;padding:28px 16px;border:2px dashed var(--border);border-radius:16px;background:var(--surface-raised,#f8fafc);text-align:center}
    .op-photo-empty__ico{width:48px;height:48px;border-radius:50%;border:2px dashed #cbd5e1;background:#fff}
    .op-photo-empty p{margin:0;font-size:14px;font-weight:600;color:var(--muted)}
    .op-photo-empty small{font-size:12px;color:var(--muted);max-width:280px;line-height:1.45}
    .op-fotos-section:has(.op-photo-preview.active) .op-photo-empty{display:none!important}
    .op-map{height:260px;border-radius:16px;overflow:hidden;background:#f1f5f9;border:1px solid var(--border)}
    .op-item-combo{position:relative;width:100%}
    .op-item-combo__dd{position:absolute;left:0;right:0;top:calc(100% + 4px);max-height:min(280px,50vh);overflow-y:auto;background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:0 12px 36px rgba(15,23,42,.12);z-index:60;display:none}
    .op-item-combo.is-open .op-item-combo__dd{display:block}
    .op-item-combo__opt{display:block;width:100%;padding:10px 12px;margin:0;border:0;border-bottom:1px solid var(--border);background:transparent;text-align:left;font-size:14px;line-height:1.35;cursor:pointer;color:inherit;font-family:inherit}
    .op-item-combo__opt:last-child{border-bottom:0}
    .op-item-combo__opt:hover,.op-item-combo__opt.is-active{background:#eff6ff}
    .op-item-combo__empty{padding:12px;font-size:13px;color:var(--muted)}
    .chamado-materiais-op-add{margin-bottom:16px;padding:14px;border:1px solid var(--border);border-radius:16px;background:var(--surface-raised,#f8fafc)}
    .op-mobile-shell .chamado-materiais-card .panel-body{padding:18px 18px 22px}
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
    .op-local-card .op-card-body{display:flex;flex-direction:column;gap:12px}
    .op-local-text{margin:0;color:var(--muted);line-height:1.6;font-size:15px}
    .op-ticket-desc{margin-top:12px;padding-top:14px;border-top:1px solid var(--border)}
    .op-ticket-desc__title{margin:0 0 8px;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--muted)}
    .op-ticket-desc__body{margin:0;font-size:15px;line-height:1.65;color:var(--text,#334155)}
    .op-thread-empty{padding:24px 16px;text-align:center;margin:0}
    .op-local-nav{margin-bottom:0}
    #chamado-map-mini{margin-top:12px}
    .op-thread-reply-form{border-top:1px solid var(--border);padding:16px 18px}
    .op-thread-card .op-thread-wrap{padding:12px 14px 8px}
    .op-mobile-shell .op-thread-card .thread .msg{margin-bottom:14px}
    .op-mobile-shell .op-thread-card .thread .msg:last-child{margin-bottom:0}
    .op-thread-card .bubble p:last-child{margin-bottom:0}
    .op-resumo-card .op-card-head h4{font-size:14px;font-weight:600;color:var(--muted)}
    .op-resumo-card .op-card-body{padding:12px 18px 16px}
    .op-ticket-header.ticket-header{padding:12px 16px 14px;border-radius:18px;margin-block:16px 20px}
    .op-ticket-banner{font-size:13px;font-weight:600;padding:8px 12px;border-radius:12px;margin-bottom:10px;line-height:1.4}
    .op-ticket-banner--done{background:linear-gradient(135deg,#ecfdf5,#d1fae5);color:#065f46;border:1px solid #a7f3d0}
    .op-ticket-banner--hold{background:#fffbeb;color:#92400e;border:1px solid #fde68a}
    .op-ticket-top{margin-bottom:8px}
    .op-ticket-kicker{display:block;font-size:11px;font-weight:700;letter-spacing:.06em;color:var(--primary,#534ab7);margin-bottom:4px}
    .op-ticket-title{margin:0;font-size:18px;font-weight:800;line-height:1.25;color:var(--text,#0f172a)}
    @media(min-width:721px){.op-ticket-title{font-size:20px}}
    .op-ticket-meta{display:flex;flex-wrap:nowrap;gap:8px;overflow-x:auto;padding-bottom:4px;margin:0 -4px 8px;-webkit-overflow-scrolling:touch;scrollbar-width:thin}
    .op-ticket-meta::-webkit-scrollbar{height:4px}
    .op-ticket-meta::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:4px}
    .op-ticket-chip{flex:0 0 auto;font-size:12px;font-weight:600;padding:6px 11px;border-radius:999px;border:1px solid var(--border);background:#fff;white-space:nowrap}
    .op-ticket-chip--status{border-width:0}
    .op-ticket-sub{display:flex;flex-direction:column;gap:4px;font-size:13px;color:var(--muted);line-height:1.45}
    .op-ticket-sub span{display:block}
    .op-sr-file{position:fixed;left:-9999px;top:0;width:2px;height:2px;opacity:0.01;clip:auto;overflow:hidden}
    @media(max-width:720px){
      .content{padding:12px}
      .op-actions--cam{grid-template-columns:1fr 1fr}
      .op-mat-table-only{display:none!important}
      .op-mat-cards-only{display:flex!important}
      .op-map{height:220px}
    }
  </style>

  <div class="ticket-header op-ticket-header">
    <?php if ($jaFechado): ?>
      <div class="op-ticket-banner op-ticket-banner--done" role="status">
        <?php if (in_array((string) ($chamado['status'] ?? ''), ['Resolvido', 'Fechado'], true)): ?>
          Chamado finalizado. Pode ainda anexar fotos ao chamado.
        <?php else: ?>
          Chamado já enviado ao gestor. Pode ainda <strong>anexar fotos</strong>; alteração de referência depende do gestor.
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <div class="op-ticket-top">
      <span class="op-ticket-kicker">CHAMADO #<?= (int) $chamado['id'] ?></span>
      <h2 class="op-ticket-title"><?= htmlspecialchars((string) ($chamado['titulo'] ?? '')) ?></h2>
    </div>
    <div class="op-ticket-meta" aria-label="Resumo do chamado">
      <span class="op-ticket-chip op-ticket-chip--status badge <?= htmlspecialchars(status_class($chamado['status'] ?? '')) ?>"><?= htmlspecialchars((string) ($chamado['status'] ?? '—')) ?></span>
      <span class="op-ticket-chip">Prioridade: <?= htmlspecialchars((string) ($chamado['prioridade'] ?? '—')) ?></span>
    </div>
    <div class="op-ticket-sub">
      <?php if (!empty($chamado['servico_nome'])): ?>
        <span>Serviço: <strong class="op-ticket-sub-strong"><?= htmlspecialchars((string) $chamado['servico_nome']) ?></strong></span>
      <?php endif; ?>
      <?php if (!empty($chamado['data'])): ?>
        <span>Aberto em <?= date('d/m/Y H:i', strtotime((string) $chamado['data'])) ?></span>
      <?php endif; ?>
    </div>
    <?php if (trim((string) ($chamado['descricao'] ?? '')) !== ''): ?>
    <div class="op-ticket-desc">
      <h3 class="op-ticket-desc__title">Descrição</h3>
      <p class="op-ticket-desc__body"><?= nl2br(htmlspecialchars((string) ($chamado['descricao'] ?? ''))) ?></p>
    </div>
    <?php endif; ?>
  </div>

  <div class="op-mobile-shell<?= !$jaFechado ? ' op-mobile-shell--fixed-save' : '' ?>">
      <form id="op-os-form" class="op-card op-card--primary" method="post" enctype="multipart/form-data"
            data-max-file-bytes="<?= (int) UPLOAD_MAX_BYTES ?>"
            data-fotos-salvas="<?= (int) $qtdFotosExistentes ?>">
        <input type="hidden" name="acao" id="op-form-acao" value="enviar_os">
        <input type="file" id="op-pick-cam" class="op-sr-file crm-no-custom-file" accept="image/*" capture="environment" multiple tabindex="-1" aria-hidden="true">
        <input type="file" id="op-pick-gal" class="op-sr-file crm-no-custom-file" accept="image/*" multiple tabindex="-1" aria-hidden="true">
        <input type="file" name="imagens[]" id="op-imagens-master" class="op-sr-file crm-no-custom-file" accept="image/png,image/jpeg,image/jpg,image/gif,image/webp,image/*" multiple tabindex="-1" aria-hidden="true">
        <div class="op-card-head">
          <div>
            <h4>Atendimento</h4>
            <p class="op-card-lead muted">As fotos gravam-se ao escolher na câmera ou galeria. Use <strong>Salvar chamado</strong> para enviar ao gestor quando terminar.</p>
          </div>
        </div>
        <div class="op-card-body op-atend-body">
          <?php if ($jaFechado): ?>
            <p class="op-atend-note">Este chamado já foi enviado ao gestor. Pode ainda <strong>anexar fotos</strong> (gravam automaticamente ao selecionar).</p>
          <?php endif; ?>

          <?php
            $refLocal = trim((string) ($chamado['endereco_completo'] ?? ''));
          ?>
          <div class="form-group op-form-group">
            <?php if (!$jaFechado): ?>
              <label for="endereco_completo" id="lbl-endereco-ref">Referência no local (opcional)</label>
            <?php else: ?>
              <span class="op-ref-field-label" id="lbl-endereco-ref">Referência no local (opcional)</span>
            <?php endif; ?>
            <div class="op-ref-row">
              <div class="op-ref-plain" id="endereco_completo_display" role="region" aria-labelledby="lbl-endereco-ref"><?php
                if ($refLocal === '') {
                    echo '<span class="muted">Sem referência guardada.</span>';
                } else {
                    echo htmlspecialchars($refLocal);
                }
              ?></div>
              <button type="button" class="btn btn-secondary op-ref-copy-btn" id="op-endereco-copy"<?= $jaFechado ? ' data-copy="' . htmlspecialchars($refLocal, ENT_QUOTES, 'UTF-8') . '"' : '' ?><?= $refLocal === '' ? ' disabled' : '' ?>>Copiar</button>
            </div>
            <?php if (!$jaFechado): ?>
              <textarea class="textarea op-ref-editor" id="endereco_completo" name="endereco_completo" rows="2" placeholder="Ex.: Rua X, próximo ao mercado"><?= htmlspecialchars($refLocal) ?></textarea>
            <?php endif; ?>
          </div>
          <?php if ($jaFechado): ?>
            <small class="muted" style="display:block;margin-top:-8px;">Alteração de endereço após envio depende do gestor.</small>
          <?php endif; ?>

          <div class="op-atend-actions">
            <div class="op-actions op-actions--cam">
              <button type="button" class="btn btn-secondary op-btn-tall" id="op-btn-cam">Abrir câmera</button>
              <button type="button" class="btn btn-secondary op-btn-tall" id="op-btn-gal">Galeria</button>
            </div>
            <?php if (!$jaFechado): ?>
              <small class="muted" style="margin:0;font-size:12px;line-height:1.45;text-align:center;">Grava a referência no local e envia o chamado ao gestor para aprovação. É necessária pelo menos uma foto no chamado.</small>
            <?php endif; ?>
          </div>

          <div class="op-fotos-section">
            <div class="op-fotos-head">
              <strong>Fotos do atendimento</strong>
              <span class="panel-sub" id="op-photo-count-hint"><?= (int) $qtdFotosExistentes ?> salva(s)<span id="op-photo-pending-hint"></span></span>
            </div>
            <button type="button" class="btn btn-ghost" id="op-photo-clear" hidden>Limpar fotos selecionadas</button>
            <div class="op-photo-preview" id="op-photo-preview" aria-live="polite"></div>
            <?php if (!empty($imagensOp)): ?>
              <div class="op-photo-grid-wrap">
                <div class="op-photo-grid">
                  <?php foreach ($imagensOp as $img): ?>
                    <a href="chamado_download.php?id=<?= (int) $img['id'] ?>" target="_blank" rel="noopener">
                      <img src="chamado_download.php?id=<?= (int) $img['id'] ?>" alt="<?= htmlspecialchars((string) $img['nome_original']) ?>" loading="lazy">
                    </a>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php else: ?>
              <div class="op-photo-empty" role="status">
                <span class="op-photo-empty__ico" aria-hidden="true"></span>
                <p>Nenhuma foto guardada ainda</p>
                <small><?= $jaFechado
                  ? 'As novas imagens gravam-se ao selecionar na câmera ou galeria.'
                  : 'As novas imagens gravam-se ao selecionar. Depois use <strong>Salvar chamado</strong> para enviar ao gestor.' ?></small>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </form>
      <?php if (!$jaFechado): ?>
      <div class="op-fixed-save-bar" role="region" aria-label="Ações do chamado">
        <button type="button" class="btn btn-primary op-btn-block op-btn-cta" id="op-btn-salvar-chamado">Salvar chamado</button>
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
              <strong><?= count($itensUsadosOp) ?></strong>
            </div>
            <div class="chamado-materiais-card__pill">
              <span>Recolhidos</span>
              <strong><?= count($itensDevolvidosOp) ?></strong>
            </div>
          </div>
        </div>
        <div class="panel-body chamado-materiais-card__body">
          <section class="chamado-materiais-block chamado-materiais-block--uso" aria-labelledby="ch-op-mat-uso-title">
            <div class="chamado-materiais-block__bar">
              <h5 id="ch-op-mat-uso-title" class="chamado-materiais-block__title">Itens utilizados</h5>
            </div>
            <div class="chamado-materiais-op-add">
              <form method="post" class="flex-col op-mat-add-form" data-op-item-form>
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
            </div>
            <?php if (empty($itensUsadosOp)): ?>
              <div class="chamado-materiais-empty">
                <p>Nenhum item utilizado lançado ainda.</p>
                <small>Adicione produtos ou serviços aplicados neste atendimento.</small>
              </div>
            <?php else: ?>
              <div class="op-mat-table-only">
                <div class="table-wrap chamado-itens-table chamado-itens-table--admin">
                  <table class="chamado-materiais-table chamado-materiais-table--uso">
                    <thead>
                      <tr>
                        <th>Item</th>
                        <th>Tipo</th>
                        <th class="text-right">Qtd</th>
                        <th class="text-right">Valor</th>
                        <th class="text-right td-actions">Ações</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($itensUsadosOp as $lm): ?>
                      <?php
                        $qU = htmlspecialchars(rtrim(rtrim(sprintf('%.4f', (float) ($lm['quantidade'] ?? 0)), '0'), '.'));
                        $nomeU = (string) ($lm['item_nome'] ?? '');
                        $codU = trim((string) ($lm['item_codigo'] ?? ''));
                      ?>
                      <tr>
                        <td>
                          <strong><?= htmlspecialchars($nomeU) ?></strong>
                          <?php if ($codU !== ''): ?>
                            <div class="td-mute" style="font-size:12px;">Cód. <?= htmlspecialchars($codU) ?></div>
                          <?php endif; ?>
                          <?php if (!empty($lm['observacao'])): ?>
                            <div class="td-mute" style="font-size:12px;margin-top:4px;"><?= htmlspecialchars((string) $lm['observacao']) ?></div>
                          <?php endif; ?>
                        </td>
                        <td class="td-mute"><?= htmlspecialchars((string) ($lm['item_tipo'] ?? '')) ?></td>
                        <td class="text-right"><?= $qU ?></td>
                        <td class="text-right td-mute">R$ <?= number_format((float) ($lm['subtotal'] ?? 0), 2, ',', '.') ?></td>
                        <td class="text-right td-actions">
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
              </div>
              <div class="op-mat-cards-only" aria-label="Itens utilizados">
                <?php foreach ($itensUsadosOp as $lm): ?>
                  <?php
                    $qUc = htmlspecialchars(rtrim(rtrim(sprintf('%.4f', (float) ($lm['quantidade'] ?? 0)), '0'), '.'));
                    $nomeUc = (string) ($lm['item_nome'] ?? '');
                    $codUc = trim((string) ($lm['item_codigo'] ?? ''));
                  ?>
                  <article class="op-mat-card">
                    <h3 class="op-mat-card__title"><?= htmlspecialchars($nomeUc) ?></h3>
                    <div class="op-mat-card__meta">
                      <?php if ($codUc !== ''): ?><span>Cód. <?= htmlspecialchars($codUc) ?></span><?php endif; ?>
                      <span><?= htmlspecialchars((string) ($lm['item_tipo'] ?? '')) ?></span>
                      <span>Qtd <strong><?= $qUc ?></strong></span>
                      <span>Valor <strong>R$ <?= number_format((float) ($lm['subtotal'] ?? 0), 2, ',', '.') ?></strong></span>
                    </div>
                    <?php if (!empty($lm['observacao'])): ?>
                      <p class="op-mat-card__obs"><?= htmlspecialchars((string) $lm['observacao']) ?></p>
                    <?php endif; ?>
                    <div class="op-mat-card__foot">
                      <form method="post" data-confirm="Remover esta linha?" data-confirm-danger>
                        <input type="hidden" name="acao" value="chamado_item_del">
                        <input type="hidden" name="linha_id" value="<?= (int) ($lm['id'] ?? 0) ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                      </form>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>

          <section class="chamado-materiais-block chamado-materiais-block--dev" aria-labelledby="ch-op-mat-dev-title">
            <div class="chamado-materiais-block__bar">
              <h5 id="ch-op-mat-dev-title" class="chamado-materiais-block__title">Devolvidos / recolhidos</h5>
            </div>
            <div class="chamado-materiais-op-add">
              <form method="post" class="flex-col op-mat-add-form" data-op-item-form>
                <input type="hidden" name="acao" value="chamado_item_add">
                <input type="hidden" name="movimento" value="devolvido">
                <input type="hidden" name="item_id" data-op-item-id value="">
                <div class="op-item-combo" data-op-item-combo data-filter-empty="Nenhum item corresponde à busca.">
                  <input type="text" data-op-item-search class="input op-mat-search" role="combobox" aria-expanded="false"
                         aria-autocomplete="list" autocomplete="off"
                         placeholder="Buscar produto no catálogo"
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
                <button type="submit" class="btn btn-secondary op-mat-submit" <?= empty($servicos) ? 'disabled' : '' ?>>Adicionar recolhido</button>
              </form>
            </div>
            <?php if (empty($itensDevolvidosOp)): ?>
              <div class="chamado-materiais-empty chamado-materiais-empty--muted">
                <p>Nenhum item recolhido lançado ainda.</p>
                <small>Registre materiais retirados, devolvidos ou recolhidos no atendimento.</small>
              </div>
            <?php else: ?>
              <div class="op-mat-table-only">
                <div class="table-wrap chamado-itens-table chamado-itens-table--admin">
                  <table class="chamado-materiais-table chamado-materiais-table--dev">
                    <thead>
                      <tr>
                        <th>Item</th>
                        <th>Tipo</th>
                        <th class="text-right">Qtd</th>
                        <th class="text-right td-actions">Ações</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($itensDevolvidosOp as $lm): ?>
                      <?php
                        $qD = htmlspecialchars(rtrim(rtrim(sprintf('%.4f', (float) ($lm['quantidade'] ?? 0)), '0'), '.'));
                        $nomeD = (string) ($lm['item_nome'] ?? '');
                        $codD = trim((string) ($lm['item_codigo'] ?? ''));
                      ?>
                      <tr>
                        <td>
                          <strong><?= htmlspecialchars($nomeD) ?></strong>
                          <?php if ($codD !== ''): ?>
                            <div class="td-mute" style="font-size:12px;">Cód. <?= htmlspecialchars($codD) ?></div>
                          <?php endif; ?>
                          <?php if (!empty($lm['observacao'])): ?>
                            <div class="td-mute" style="font-size:12px;margin-top:4px;"><?= htmlspecialchars((string) $lm['observacao']) ?></div>
                          <?php endif; ?>
                        </td>
                        <td class="td-mute"><?= htmlspecialchars((string) ($lm['item_tipo'] ?? '')) ?></td>
                        <td class="text-right"><?= $qD ?></td>
                        <td class="text-right td-actions">
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
              </div>
              <div class="op-mat-cards-only" aria-label="Itens recolhidos">
                <?php foreach ($itensDevolvidosOp as $lm): ?>
                  <?php
                    $qDc = htmlspecialchars(rtrim(rtrim(sprintf('%.4f', (float) ($lm['quantidade'] ?? 0)), '0'), '.'));
                    $nomeDc = (string) ($lm['item_nome'] ?? '');
                    $codDc = trim((string) ($lm['item_codigo'] ?? ''));
                  ?>
                  <article class="op-mat-card">
                    <h3 class="op-mat-card__title"><?= htmlspecialchars($nomeDc) ?></h3>
                    <div class="op-mat-card__meta">
                      <?php if ($codDc !== ''): ?><span>Cód. <?= htmlspecialchars($codDc) ?></span><?php endif; ?>
                      <span><?= htmlspecialchars((string) ($lm['item_tipo'] ?? '')) ?></span>
                      <span>Qtd <strong><?= $qDc ?></strong></span>
                    </div>
                    <?php if (!empty($lm['observacao'])): ?>
                      <p class="op-mat-card__obs"><?= htmlspecialchars((string) $lm['observacao']) ?></p>
                    <?php endif; ?>
                    <div class="op-mat-card__foot">
                      <form method="post" data-confirm="Remover esta linha?" data-confirm-danger>
                        <input type="hidden" name="acao" value="chamado_item_del">
                        <input type="hidden" name="linha_id" value="<?= (int) ($lm['id'] ?? 0) ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                      </form>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>
        </div>
      </div>

      <div class="op-card op-local-card">
        <div class="op-card-head">
          <h4>Local do chamado</h4>
        </div>
        <div class="op-card-body">
          <p class="op-local-label muted" style="margin:0 0 4px;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">Endereço / referência (cadastro)</p>
          <?php if (!empty($chamado['endereco_completo'])): ?>
            <p class="op-local-text"><?= nl2br(htmlspecialchars((string) $chamado['endereco_completo'])) ?></p>
          <?php else: ?>
            <p class="op-local-text muted">Sem endereço neste cadastro. Use a <strong>referência no local</strong> no card Atendimento.</p>
          <?php endif; ?>
          <?php if ($loadLeaflet): ?>
            <p class="muted" style="font-size:12px;margin:0;line-height:1.5;">
              Coordenadas: <strong><?= htmlspecialchars(number_format((float) $chamado['latitude'], 6, '.', '')) ?>, <?= htmlspecialchars(number_format((float) $chamado['longitude'], 6, '.', '')) ?></strong>
            </p>
            <div class="op-actions op-local-nav">
              <?php
                $latUrl = number_format((float) $chamado['latitude'], 7, '.', '');
                $lngUrl = number_format((float) $chamado['longitude'], 7, '.', '');
                $coordUrl = rawurlencode($latUrl . ',' . $lngUrl);
              ?>
              <a class="btn btn-primary op-btn-tall" target="_blank" rel="noopener"
                 href="https://www.google.com/maps/search/?api=1&amp;query=<?= $coordUrl ?>">Google Maps</a>
              <a class="btn btn-secondary op-btn-tall" target="_blank" rel="noopener"
                 href="https://waze.com/ul?ll=<?= $coordUrl ?>&amp;navigate=yes">Waze</a>
            </div>
            <div id="chamado-map-mini" class="op-map" aria-label="Mapa do local do chamado"></div>
          <?php else: ?>
            <p class="muted" style="margin:0;font-size:13px;line-height:1.5;">Sem coordenadas no cadastro. Complemente com referência no card Atendimento.</p>
          <?php endif; ?>
        </div>
      </div>

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
            window.appAlert('Selecione um produto ou serviço da lista.', 'Itens do chamado');
          }
        }
      });
    }
  }

  var itemForms = Array.prototype.slice.call(document.querySelectorAll('form[data-op-item-form]'));
  itemForms.forEach(initItemPicker);

  var opForm = document.getElementById('op-os-form');
  var master = document.getElementById('op-imagens-master');
  var pickCam = document.getElementById('op-pick-cam');
  var pickGal = document.getElementById('op-pick-gal');
  var btnCam = document.getElementById('op-btn-cam');
  var btnGal = document.getElementById('op-btn-gal');
  var btnClear = document.getElementById('op-photo-clear');
  var preview = document.getElementById('op-photo-preview');
  var acaoInput = document.getElementById('op-form-acao');
  var btnSalvarChamado = document.getElementById('op-btn-salvar-chamado');
  var pendingHint = document.getElementById('op-photo-pending-hint');

  if (!opForm || !master || !preview || !acaoInput) {
    return;
  }

  var maxBytes = parseInt(opForm.getAttribute('data-max-file-bytes') || '15728640', 10) || 15728640;
  var fotosSalvas = parseInt(opForm.getAttribute('data-fotos-salvas') || '0', 10) || 0;

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
    if (btnClear) {
      btnClear.hidden = n < 1;
    }
  }

  function renderPreview() {
    preview.innerHTML = '';
    var files = Array.prototype.slice.call(dt.files || []);
    preview.classList.toggle('active', files.length > 0);
    files.forEach(function (file) {
      var url = URL.createObjectURL(file);
      var fig = document.createElement('figure');
      fig.className = 'op-photo-preview__fig';
      var img = document.createElement('img');
      var cap = document.createElement('figcaption');
      img.src = url;
      img.alt = file.name;
      img.onload = function () { URL.revokeObjectURL(url); };
      cap.textContent = file.name + ' (' + formatBytes(file.size) + ')';
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
  if (btnClear) {
    btnClear.addEventListener('click', function () {
      dt = new DataTransfer();
      syncMasterFiles();
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

  function doSubmit(acao) {
    var pendErros = validatePendingFiles();
    if (pendErros.length) {
      alertMsg(pendErros.join('\n'), 'Fotos inválidas');
      return;
    }
    acaoInput.value = acao;
    if (typeof opForm.requestSubmit === 'function') {
      opForm.requestSubmit();
    } else {
      HTMLFormElement.prototype.submit.call(opForm);
    }
  }

  function onSalvarChamadoClick() {
    var novas = dt.files.length;
    if (fotosSalvas + novas < 1) {
      alertMsg('Inclua pelo menos uma foto do atendimento antes de concluir o chamado.', 'Salvar chamado');
      return;
    }
    var msg = 'Enviar este chamado ao gestor para aprovação?';
    if (typeof window.appConfirm === 'function') {
      window.appConfirm({ message: msg, title: 'Confirmar', danger: false }).then(function (ok) {
        if (!ok) return;
        doSubmit('enviar_os');
      });
    } else if (window.confirm(msg)) {
      doSubmit('enviar_os');
    }
  }

  if (btnSalvarChamado) btnSalvarChamado.addEventListener('click', onSalvarChamadoClick);
})();
</script>
<script>
(function () {
  var btn = document.getElementById('op-endereco-copy');
  var ta = document.getElementById('endereco_completo');
  var disp = document.getElementById('endereco_completo_display');
  if (!btn) return;

  function refText() {
    if (ta) return ta.value || '';
    return btn.getAttribute('data-copy') || '';
  }

  function syncPreviewFromTa() {
    if (!ta || !disp) return;
    var v = ta.value;
    if (!v.trim()) {
      disp.innerHTML = '<span class="muted">Sem referência guardada.</span>';
    } else {
      disp.textContent = v;
    }
    btn.disabled = !v.trim();
  }

  if (ta && disp) {
    ta.addEventListener('input', syncPreviewFromTa);
  }

  btn.addEventListener('click', function () {
    var t = refText();
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
