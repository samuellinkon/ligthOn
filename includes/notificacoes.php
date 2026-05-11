<?php
/**
 * Notificações de chamados — agrupa destinatários e grava uma linha por usuário.
 */

declare(strict_types=1);

/**
 * @param int|null $mensagemId ID em chamado_respostas (pode ser null se chamador não passar)
 */
function criarNotificacoesChamado(int $chamadoId, ?int $mensagemId, int $autorId, bool $interna): void
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

    foreach ($dest as $uid) {
        repo_notificacao_insert($uid, $chamadoId, $mensagemId, $titulo, $descricao, 'chamado_mensagem');
    }
}
