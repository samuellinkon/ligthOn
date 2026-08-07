<?php
/**
 * Notificações de chamado:
 * ciclo oficial (criação → técnico finaliza → gestor aprova → cliente valida)
 * + mensagens + reabertura pelo cliente.
 *
 * Medição (BM / custo) permanece em funções separadas.
 */

declare(strict_types=1);

/**
 * @param list<int> $destinatarios
 */
function _notificar_chamado_enviar(
    int $chamadoId,
    array $destinatarios,
    string $titulo,
    string $descricao,
    string $tipo,
    int $excluirUsuarioId = 0
): void {
    if ($chamadoId <= 0 || !function_exists('repo_notificacoes_table_exists') || !repo_notificacoes_table_exists()) {
        return;
    }
    if (!function_exists('repo_notificacao_insert')) {
        return;
    }

    $desc = function_exists('mb_substr')
        ? mb_substr($descricao, 0, 200, 'UTF-8')
        : substr($descricao, 0, 200);

    $dest = array_values(array_unique(array_filter(
        array_map('intval', $destinatarios),
        static fn (int $id): bool => $id > 0 && ($excluirUsuarioId <= 0 || $id !== $excluirUsuarioId)
    )));

    foreach ($dest as $uid) {
        repo_notificacao_insert($uid, $chamadoId, null, $titulo, $desc, $tipo);
    }
}

/**
 * Nova mensagem no chamado.
 *
 * @param int|null $mensagemId ID em chamado_respostas
 * @param string|null $preview Trecho da mensagem (opcional)
 */
function criarNotificacoesChamado(int $chamadoId, ?int $mensagemId, int $autorId, bool $interna, ?string $preview = null): void
{
    if ($autorId <= 0 || $chamadoId <= 0 || !function_exists('repo_notificacoes_table_exists') || !repo_notificacoes_table_exists()) {
        return;
    }
    if (!function_exists('repo_notificacao_insert')) {
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
 * 1) Chamado criado → gestão (admin/gestores) + clientes da empresa.
 */
function notificar_chamado_criado(int $chamadoId, int $autorUsuarioId = 0): void
{
    if ($chamadoId <= 0 || !function_exists('repo_chamado')) {
        return;
    }
    $ch = repo_chamado($chamadoId);
    if (!$ch) {
        return;
    }

    $tituloCh = trim((string) ($ch['titulo'] ?? ''));
    $prio     = trim((string) ($ch['prioridade'] ?? ''));
    $titulo   = sprintf('Novo chamado #%d', $chamadoId);
    $desc     = $tituloCh !== '' ? $tituloCh : 'Um novo chamado foi aberto.';
    if ($prio !== '') {
        $desc = 'Prioridade ' . $prio . ' — ' . $desc;
    }

    // Gestão (interno)
    _notificar_chamado_enviar(
        $chamadoId,
        repo_notificacao_destinatarios_chamado($chamadoId, true),
        $titulo,
        $desc,
        'chamado_criado',
        $autorUsuarioId
    );

    // Cliente — confirma abertura do chamado (autor cliente é excluído).
    _notificar_chamado_enviar(
        $chamadoId,
        repo_notificacao_destinatarios_clientes_chamado($chamadoId),
        $titulo,
        $desc !== '' ? $desc : 'Seu chamado foi registrado e será encaminhado para atendimento.',
        'chamado_criado',
        $autorUsuarioId
    );
}

/**
 * Técnico recém-atribuído — única notificação do perfil operador.
 *
 * @param list<int> $tecnicoUserIds
 */
function notificar_tecnicos_chamado_atribuido(int $chamadoId, array $tecnicoUserIds, int $autorId): void
{
    if ($chamadoId <= 0 || !function_exists('repo_notificacoes_table_exists') || !repo_notificacoes_table_exists()) {
        return;
    }
    if (!function_exists('repo_notificacao_insert')) {
        return;
    }

    $titulo = sprintf('Chamado #%d atribuído a você', $chamadoId);
    $desc   = 'Um chamado foi atribuído a você. Abra para iniciar o atendimento.';

    foreach ($tecnicoUserIds as $uid) {
        $uid = (int) $uid;
        if ($uid <= 0 || ($autorId > 0 && $uid === $autorId)) {
            continue;
        }
        repo_notificacao_insert($uid, $chamadoId, null, $titulo, $desc, 'chamado_tecnico_atribuido');
    }
}

/**
 * Cliente: técnico foi designado ao chamado (filtro "Atendido por técnico").
 *
 * @param list<int> $tecnicoUserIds
 */
function notificar_cliente_chamado_tecnico_atribuido(int $chamadoId, array $tecnicoUserIds, int $autorId = 0): void
{
    if ($chamadoId <= 0 || $tecnicoUserIds === []) {
        return;
    }

    $nomes = [];
    if (function_exists('repo_user_by_id')) {
        foreach ($tecnicoUserIds as $tid) {
            $tid = (int) $tid;
            if ($tid <= 0) {
                continue;
            }
            $u = repo_user_by_id($tid);
            $nome = trim((string) ($u['nome'] ?? ''));
            if ($nome !== '') {
                $nomes[] = $nome;
            }
        }
    }
    $quem = $nomes !== [] ? implode(', ', $nomes) : 'um técnico';

    _notificar_chamado_enviar(
        $chamadoId,
        repo_notificacao_destinatarios_clientes_chamado($chamadoId),
        sprintf('Chamado #%d em atendimento', $chamadoId),
        'Técnico designado: ' . $quem . '. Acompanhe o progresso do atendimento.',
        'chamado_em_atendimento',
        $autorId
    );
}

/**
 * 2) Técnico finalizou → gestão (aguarda aprovação).
 */
function notificar_chamado_finalizado_tecnico(int $chamadoId, int $autorUsuarioId = 0): void
{
    if ($chamadoId <= 0) {
        return;
    }
    _notificar_chamado_enviar(
        $chamadoId,
        repo_notificacao_destinatarios_chamado($chamadoId, true),
        sprintf('Chamado #%d finalizado pelo técnico', $chamadoId),
        'O técnico finalizou o atendimento. O chamado aguarda aprovação do gestor.',
        'chamado_finalizado_tecnico',
        $autorUsuarioId
    );
}

/**
 * 3) Gestor aprovou → cliente, técnicos e demais envolvidos.
 */
function notificar_chamado_aprovado_gestor(int $chamadoId, int $autorUsuarioId = 0): void
{
    if ($chamadoId <= 0) {
        return;
    }
    _notificar_chamado_enviar(
        $chamadoId,
        repo_notificacao_destinatarios_chamado($chamadoId, false),
        sprintf('Chamado #%d aprovado pelo gestor', $chamadoId),
        'O gestor aprovou o atendimento. O chamado aguarda validação do cliente.',
        'chamado_aprovado_gestor',
        $autorUsuarioId
    );
}

/**
 * 4) Cliente validou → gestão, técnicos e operadores.
 */
function notificar_chamado_validado_cliente(int $chamadoId, int $autorUsuarioId = 0): void
{
    if ($chamadoId <= 0) {
        return;
    }
    _notificar_chamado_enviar(
        $chamadoId,
        repo_notificacao_destinatarios_chamado($chamadoId, false),
        sprintf('Chamado #%d validado pelo cliente', $chamadoId),
        'O cliente validou o chamado.',
        'chamado_validado_cliente',
        $autorUsuarioId
    );
}

/**
 * Cliente reabriu o chamado → gestão.
 */
function notificar_chamado_reaberto(int $chamadoId, int $autorUsuarioId = 0): void
{
    if ($chamadoId <= 0) {
        return;
    }
    _notificar_chamado_enviar(
        $chamadoId,
        repo_notificacao_destinatarios_chamado($chamadoId, true),
        sprintf('Chamado #%d reaberto pelo cliente', $chamadoId),
        'O chamado voltou ao status Aberto para novo atendimento.',
        'chamado_reaberto',
        $autorUsuarioId
    );
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
