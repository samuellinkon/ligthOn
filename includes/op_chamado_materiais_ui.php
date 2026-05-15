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
        'id'         => (int) ($lm['id'] ?? 0),
        'item_id'    => (int) ($lm['item_id'] ?? 0),
        'nome'       => (string) ($lm['item_nome'] ?? ''),
        'codigo'     => trim((string) ($lm['item_codigo'] ?? '')),
        'tipo'       => (string) ($lm['item_tipo'] ?? ''),
        'unidade'    => trim((string) ($lm['catalogo_unidade'] ?? 'UN')) ?: 'UN',
        'quantidade' => (float) ($lm['quantidade'] ?? 0),
        'movimento'  => (string) ($lm['movimento'] ?? 'utilizado'),
        'observacao' => trim((string) ($lm['observacao'] ?? '')),
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
        if (($p['movimento'] ?? '') === 'devolvido') {
            $dev[] = $p;
        } else {
            $usados[] = $p;
        }
    }
    op_chamado_mat_json_send([
        'ok'         => $ok,
        'err'        => $err,
        'stats'      => ['utilizados' => count($usados), 'recolhidos' => count($dev)],
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
    $nome  = htmlspecialchars((string) ($lm['item_nome'] ?? ''));
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
