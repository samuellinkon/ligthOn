<?php
/**
 * Notificações de chamados — agrupa destinatários e grava uma linha por usuário.
 */

declare(strict_types=1);

/**
 * @param int|null $mensagemId ID em chamado_respostas (pode ser null se chamador não passar)
 * @param string|null $preview Trecho da mensagem (opcional) para aparecer na lista de notificações
 */
function criarNotificacoesChamado(int $chamadoId, ?int $mensagemId, int $autorId, bool $interna, ?string $preview = null): void
{
    if ($autorId <= 0 || !function_exists('repo_notificacoes_table_exists') || !repo_notificacoes_table_exists()) {
        return;
    }

    $dest = repo_notificacao_destinatarios_chamado($chamadoId, $interna);
    $dest = array_values(array_unique(array_filter($dest, static fn (int $id): bool => $id > 0 && $id !== $autorId)));

    $nomeAutor = 'Usuário';
    if (function_exists('repo_user_by_id')) {
        $aut = repo_user_by_id($autorId);
        if ($aut && trim((string) ($aut['nome'] ?? '')) !== '') {
            $nomeAutor = trim((string) $aut['nome']);
        }
    }

    $titulo = sprintf('Nova mensagem no chamado #%d', $chamadoId);
    $descricao = 'Mensagem de ' . $nomeAutor . '. Abra o chamado para ler e responder.';
    if ($preview !== null && $preview !== '') {
        $trecho = function_exists('mb_substr') ? mb_substr($preview, 0, 200, 'UTF-8') : substr($preview, 0, 200);
        $descricao = $trecho;
    }

    foreach ($dest as $uid) {
        repo_notificacao_insert($uid, $chamadoId, $mensagemId, $titulo, $descricao, 'chamado_mensagem');
    }
}

/**
 * Notifica técnicos recém-atribuídos a um chamado.
 *
 * @param list<int> $tecnicoUserIds
 */
/**
 * Alerta admin e gestores quando um chamado é aberto (ex.: portal do cliente).
 */
function notificar_chamado_criado(int $chamadoId, int $autorUsuarioId = 0): void
{
    if ($chamadoId <= 0 || !function_exists('repo_notificacoes_table_exists') || !repo_notificacoes_table_exists()) {
        return;
    }
    if (!function_exists('repo_chamado')) {
        return;
    }
    $ch = repo_chamado($chamadoId);
    if (!$ch) {
        return;
    }

    $dest = repo_notificacao_destinatarios_chamado($chamadoId, true);
    $dest = array_values(array_unique(array_filter(
        $dest,
        static fn (int $id): bool => $id > 0 && ($autorUsuarioId <= 0 || $id !== $autorUsuarioId)
    )));

    $tituloCh = trim((string) ($ch['titulo'] ?? ''));
    $prio     = trim((string) ($ch['prioridade'] ?? ''));
    $titulo   = sprintf('Novo chamado #%d', $chamadoId);
    $desc     = $tituloCh !== '' ? $tituloCh : 'Chamado aberto pelo portal da prefeitura.';
    if ($prio !== '') {
        $desc = 'Prioridade ' . $prio . ' — ' . $desc;
    }
    $desc = function_exists('mb_substr') ? mb_substr($desc, 0, 200, 'UTF-8') : substr($desc, 0, 200);

    foreach ($dest as $uid) {
        repo_notificacao_insert($uid, $chamadoId, null, $titulo, $desc, 'chamado_criado');
    }
}

function notificar_tecnicos_chamado_atribuido(int $chamadoId, array $tecnicoUserIds, int $autorId): void
{
    if ($chamadoId <= 0 || !function_exists('repo_notificacoes_table_exists') || !repo_notificacoes_table_exists()) {
        return;
    }
    $titulo = sprintf('Chamado #%d atribuído a você', $chamadoId);
    $descricao = 'Você foi definido como responsável técnico por este chamado.';
    foreach ($tecnicoUserIds as $uid) {
        $uid = (int) $uid;
        if ($uid <= 0 || $uid === $autorId) {
            continue;
        }
        repo_notificacao_insert($uid, $chamadoId, null, $titulo, $descricao, 'chamado_tecnico_atribuido');
    }
}

/**
 * Alerta gestores e usuários do portal (cliente) após importação BM do mês.
 *
 * @param 'planilha'|'chamados' $variante
 */
function notificar_medicao_bm_importado(
    int $clienteMatrizId,
    string $refYm,
    int $autorUserId = 0,
    string $variante = 'planilha',
    int $quantidade = 0,
    ?string $nomeArquivo = null,
    int $chamadoIdPreferido = 0
): void {
    if ($clienteMatrizId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $refYm)) {
        return;
    }
    if (!function_exists('repo_notificacoes_table_exists') || !repo_notificacoes_table_exists()) {
        return;
    }
    if (!function_exists('repo_notificacao_destinatarios_medicao_bm')) {
        return;
    }

    $chamadoId = $chamadoIdPreferido > 0 ? $chamadoIdPreferido : 0;
    if ($chamadoId <= 0) {
        require_once __DIR__ . '/medicao_custo_repo.php';
        $chamadoId = medicao_custo_placeholder_chamado_id($clienteMatrizId);
    }
    if ($chamadoId <= 0) {
        return;
    }

    $dest = repo_notificacao_destinatarios_medicao_bm($clienteMatrizId);
    $dest = array_values(array_unique(array_filter(
        $dest,
        static fn (int $id): bool => $id > 0 && ($autorUserId <= 0 || $id !== $autorUserId)
    )));
    if ($dest === []) {
        return;
    }

    $mesLabel = function_exists('medicao_mes_label_pt') ? medicao_mes_label_pt($refYm) : $refYm;
    $empresa  = '';
    if (function_exists('repo_cliente')) {
        $cli = repo_cliente($clienteMatrizId);
        $empresa = trim((string) ($cli['empresa'] ?? ''));
    }

    $titulo = 'Nova importação BM · ' . $refYm;
    if ($variante === 'chamados') {
        $n = max(0, $quantidade);
        $desc = $n > 0
            ? sprintf(
                'Relatório detalhado importado: %d chamado(s) validado(s) em %s (%s).',
                $n,
                $mesLabel,
                $refYm
            )
            : sprintf('Relatório detalhado BM gravado para %s (%s).', $mesLabel, $refYm);
    } else {
        $n = max(0, $quantidade);
        $arq = $nomeArquivo !== null && trim($nomeArquivo) !== '' ? trim($nomeArquivo) : 'planilha BM';
        $desc = sprintf(
            'Planilha BM importada (%s): %d item(ns) em %s (%s).',
            function_exists('mb_substr') ? mb_substr($arq, 0, 80, 'UTF-8') : substr($arq, 0, 80),
            $n,
            $mesLabel,
            $refYm
        );
    }
    if ($empresa !== '') {
        $desc = $empresa . ' — ' . $desc;
    }
    $desc = function_exists('mb_substr') ? mb_substr($desc, 0, 200, 'UTF-8') : substr($desc, 0, 200);

    foreach ($dest as $uid) {
        repo_notificacao_insert($uid, $chamadoId, null, $titulo, $desc, 'medicao_bm_importado');
    }
}
