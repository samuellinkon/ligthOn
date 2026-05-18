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

    $titulo = sprintf('Nova mensagem no chamado #%d por %s.', $chamadoId, $nomeAutor);
    $descricao = null;
    if ($preview !== null && $preview !== '') {
        $descricao = function_exists('mb_substr') ? mb_substr($preview, 0, 400, 'UTF-8') : substr($preview, 0, 400);
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
function notificar_tecnicos_chamado_atribuido(int $chamadoId, array $tecnicoUserIds, int $autorId): void
{
    if ($chamadoId <= 0 || !function_exists('repo_notificacoes_table_exists') || !repo_notificacoes_table_exists()) {
        return;
    }
    $titulo = sprintf('Foi atribuído um chamado ao técnico #%d.', $chamadoId);
    foreach ($tecnicoUserIds as $uid) {
        $uid = (int) $uid;
        if ($uid <= 0 || $uid === $autorId) {
            continue;
        }
        repo_notificacao_insert($uid, $chamadoId, null, $titulo, null, 'chamado_tecnico_atribuido');
    }
}
