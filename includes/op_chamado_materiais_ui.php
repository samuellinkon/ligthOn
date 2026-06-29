<?php
/**
 * UI / JSON helpers — materiais do chamado (painel operador).
 */

function op_chamado_detalhe_is_ajax(): bool
{
    return (isset($_POST['ajax']) && (string) $_POST['ajax'] === '1')
        || (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
}

function op_chamado_mat_qtd_fmt(float $q): string
{
    $s = rtrim(rtrim(sprintf('%.4f', $q), '0'), '.');

    return $s === '' ? '0' : $s;
}

/**
 * @param array<string, mixed> $lm
 * @return array<string, mixed>
 */
function op_chamado_mat_linha_payload(array $lm): array
{
    return [
        'id'              => (int) ($lm['id'] ?? 0),
        'item_id'         => (int) ($lm['item_id'] ?? 0),
        'nome'            => (string) ($lm['item_nome_exibir'] ?? $lm['item_nome'] ?? ''),
        'codigo'          => trim((string) ($lm['item_codigo'] ?? '')),
        'descricao'       => trim((string) ($lm['item_descricao'] ?? '')),
        'tipo'            => (string) ($lm['item_tipo'] ?? ''),
        'unidade'         => trim((string) ($lm['catalogo_unidade'] ?? 'UN')) ?: 'UN',
        'quantidade'      => (float) ($lm['quantidade'] ?? 0),
        'valor_unitario'  => (float) ($lm['valor_unitario'] ?? 0),
        'subtotal'        => (float) ($lm['subtotal'] ?? 0),
        'movimento'       => repo_chamado_item_movimento_efetivo($lm),
        'observacao'      => trim((string) ($lm['observacao'] ?? '')),
    ];
}

function op_chamado_mat_json_send(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function op_chamado_mat_json_for_chamado(int $chamadoId, bool $ok, string $err = ''): void
{
    $list   = repo_chamado_itens_list($chamadoId);
    $usados = [];
    $dev    = [];
    foreach ($list as $lm) {
        $p = op_chamado_mat_linha_payload($lm);
        if (repo_chamado_item_movimento_efetivo($lm) === 'devolvido') {
            $dev[] = $p;
        } else {
            $usados[] = $p;
        }
    }
    $valorItens = repo_chamado_itens_valor_total($chamadoId);
    op_chamado_mat_json_send([
        'ok'         => $ok,
        'err'        => $err,
        'stats'      => [
            'utilizados'      => count($usados),
            'recolhidos'      => count($dev),
            'valor_itens'     => $valorItens,
            'valor_itens_fmt' => 'R$ ' . number_format($valorItens, 2, ',', '.'),
        ],
        'utilizados' => $usados,
        'devolvidos' => $dev,
    ], $ok ? 200 : 400);
}

/**
 * @param array<string, mixed> $lm
 */
function op_chamado_mat_stack_row_html(array $lm, bool $readonly = false): string
{
    $lid   = (int) ($lm['id'] ?? 0);
    $nome  = htmlspecialchars((string) ($lm['item_nome_exibir'] ?? $lm['item_nome'] ?? ''));
    $qtd   = htmlspecialchars(op_chamado_mat_qtd_fmt((float) ($lm['quantidade'] ?? 0)));
    $obs   = trim((string) ($lm['observacao'] ?? ''));
    $obsH  = $obs !== '' ? '<span class="op-mat-row__obs">' . htmlspecialchars($obs) . '</span>' : '';
    $acts  = '';
    if (!$readonly) {
        $acts = '<div class="op-mat-row__actions">'
            . '<button type="button" class="op-mat-row__qtd" data-op-mat-qtd-edit data-linha-id="' . $lid . '" data-qtd="' . htmlspecialchars(op_chamado_mat_qtd_fmt((float) ($lm['quantidade'] ?? 0)), ENT_QUOTES, 'UTF-8') . '" title="Clique para editar a quantidade">× ' . $qtd . '</button>'
            . '<button type="button" class="op-mat-row__del" data-op-mat-del data-linha-id="' . $lid . '" aria-label="Remover item">'
            . '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/></svg>'
            . '</button></div>';
    } else {
        $acts = '<div class="op-mat-row__actions"><span class="op-mat-row__qtd op-mat-row__qtd--readonly">× ' . $qtd . '</span></div>';
    }

    $body = '<div class="op-mat-row__body"><strong class="op-mat-row__name">' . $nome . '</strong>' . $obsH . '</div>';

    return '<li class="op-mat-row" data-linha-id="' . $lid . '">'
        . '<span class="op-mat-row__icon" aria-hidden="true">'
        . '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>'
        . '</span>'
        . $body
        . $acts
        . '</li>';
}

/**
 * @param list<array<string, mixed>> $items
 */
function op_chamado_mat_stack_list_html(array $items, bool $readonly = false): string
{
    if ($items === []) {
        return '';
    }
    $html = '';
    foreach ($items as $lm) {
        $html .= op_chamado_mat_stack_row_html($lm, $readonly);
    }

    return $html;
}

function op_chamado_detalhe_count_fotos(int $chamadoId): int
{
    $n = 0;
    foreach (repo_chamado_anexos($chamadoId) as $axCnt) {
        $mimeCnt = strtolower((string) ($axCnt['mime'] ?? ''));
        $nomeCnt = strtolower((string) ($axCnt['nome_original'] ?? ''));
        if (strncmp($mimeCnt, 'image/', 8) === 0 || preg_match('/\.(png|jpe?g|gif|webp|bmp)$/i', $nomeCnt)) {
            $n++;
        }
    }

    return $n;
}

/**
 * Grava fotos enviadas em $_FILES['imagens'] no chamado.
 *
 * @param array<string, mixed> $user
 * @return array{salvos: int, items_html: list<string>, err: string, teve_arquivo: bool}
 */
function op_chamado_detalhe_upload_fotos_from_request(int $chamadoId, array $user, bool $comBotaoRemover = true): array
{
    $temArq = !empty($_FILES['imagens']['name']) && (is_array($_FILES['imagens']['name'])
        ? count(array_filter($_FILES['imagens']['name'], static fn ($n) => $n !== '' && $n !== null)) > 0
        : (string) ($_FILES['imagens']['name'] ?? '') !== '');
    if (!$temArq) {
        return ['salvos' => 0, 'items_html' => [], 'err' => '', 'teve_arquivo' => false];
    }

    $salvos     = 0;
    $itemsHtml  = [];
    $destino    = upload_dir_chamado($chamadoId);
    $res        = upload_gravar_multiplos($_FILES['imagens'], $destino);
    $nomeAutor  = (string) ($user['nome'] ?? 'Operador');

    foreach ($res['salvos'] as $arq) {
        $aid = repo_create_chamado_anexo([
            'chamado_id'    => $chamadoId,
            'resposta_id'   => null,
            'nome_original' => $arq['nome_original'],
            'nome_arquivo'  => $arq['nome_arquivo'],
            'mime'          => $arq['mime'],
            'tamanho'       => $arq['tamanho'],
            'enviado_por'   => $nomeAutor,
            'enviado_tipo'  => 'operador',
        ]);
        if (!$aid) {
            continue;
        }
        $salvos++;
        $nomeEsc = htmlspecialchars((string) $arq['nome_original'], ENT_QUOTES, 'UTF-8');
        $rmBtn   = $comBotaoRemover
            ? '<button type="button" class="op-photo-grid__remove" data-anexo-id="' . (int) $aid . '"'
              . ' aria-label="Remover foto ' . $nomeEsc . '">×</button>'
            : '';
        $itemsHtml[] =
            '<div class="op-photo-grid__item" data-anexo-id="' . (int) $aid . '">'
            . '<a class="op-photo-grid__link" href="chamado_download.php?id=' . (int) $aid . '" target="_blank" rel="noopener">'
            . '<img src="chamado_download.php?id=' . (int) $aid . '" alt="' . $nomeEsc . '" loading="lazy">'
            . '</a>'
            . $rmBtn
            . '</div>';
    }

    $err = '';
    if ($salvos < 1) {
        $err = 'Nenhuma imagem aceita.';
    }
    if (!empty($res['erros'])) {
        $err = ($err !== '' ? $err . ' ' : '') . implode(' | ', $res['erros']);
    }

    return [
        'salvos'       => $salvos,
        'items_html'   => $itemsHtml,
        'err'          => $err,
        'teve_arquivo' => true,
    ];
}
