<?php
/**
 * Helpers de apresentação de notificações (espelham a lógica do topbar-notifications.js).
 */

declare(strict_types=1);

function notificacao_ui_extrair_chamado_id(array $n): int
{
    $cid = (int) ($n['chamado_id'] ?? 0);
    if ($cid > 0) {
        return $cid;
    }
    $link = (string) ($n['link'] ?? '');
    if (preg_match('/[?&]id=(\d+)/', $link, $m)) {
        return (int) $m[1];
    }
    $titulo = (string) ($n['titulo'] ?? '');
    if (preg_match('/#(\d+)/', $titulo, $m)) {
        return (int) $m[1];
    }

    return 0;
}

function notificacao_ui_tipo(array $n): string
{
    $tipo   = strtolower(trim((string) ($n['tipo'] ?? '')));
    $titulo = mb_strtolower((string) ($n['titulo'] ?? ''), 'UTF-8');

    if ($tipo === 'chamado_criado' || preg_match('/^novo chamado\s*#/', $titulo)) {
        return 'created';
    }
    if (
        $tipo === 'chamado_tecnico_atribuido'
        || str_contains($titulo, 'atribuído a você')
        || str_contains($titulo, 'atribuido a você')
        || str_contains($titulo, 'atribuído a voce')
        || str_contains($titulo, 'atribuido a voce')
    ) {
        return 'assigned';
    }
    if (
        $tipo === 'chamado_finalizado_tecnico'
        || $tipo === 'chamado_em_atendimento'
        || str_contains($titulo, 'finalizado pelo técnico')
        || str_contains($titulo, 'finalizado pelo tecnico')
        || str_contains($titulo, 'em atendimento')
        || str_contains($titulo, 'técnico designado')
        || str_contains($titulo, 'tecnico designado')
    ) {
        return 'finalized';
    }
    if (
        $tipo === 'chamado_aprovado_gestor'
        || str_contains($titulo, 'aprovado pelo gestor')
        || (str_contains($titulo, 'foi resolvido') && !str_contains($titulo, 'validado'))
    ) {
        return 'approved';
    }
    if (
        $tipo === 'chamado_validado_cliente'
        || str_contains($titulo, 'validado pelo cliente')
        || str_contains($titulo, 'aprovado pelo cliente')
        || str_contains($titulo, 'validado')
    ) {
        return 'validated';
    }
    if ($tipo === 'chamado_reaberto' || str_contains($titulo, 'reaberto')) {
        return 'reopened';
    }
    if ($tipo === 'chamado_mensagem' || str_contains($titulo, 'mensagem')) {
        return 'message';
    }
    if ($tipo === 'medicao_bm_importado' || str_contains($titulo, 'importação bm') || str_contains($titulo, 'importacao bm')) {
        return 'bm';
    }
    if ($tipo === 'medicao_custo_pendente' || str_contains($titulo, 'custo adicional') || str_contains($titulo, 'custo pendente')) {
        return 'custo';
    }

    return 'info';
}

function notificacao_ui_icone(string $tipo): string
{
    return match ($tipo) {
        'created'   => '✚',
        'assigned'  => '👤',
        'finalized' => '⏳',
        'approved'  => '✓',
        'validated' => '★',
        'message'   => '💬',
        'reopened'  => '↺',
        'bm'        => '📊',
        'custo'     => '💰',
        default     => 'ℹ',
    };
}

function notificacao_ui_titulo(array $n): string
{
    $titulo = trim((string) ($n['titulo'] ?? ''));
    $cid    = notificacao_ui_extrair_chamado_id($n);
    $idPart = $cid > 0 ? '#' . $cid : '';
    $tipo   = notificacao_ui_tipo($n);

    return match ($tipo) {
        'created'   => 'Novo chamado ' . $idPart,
        'assigned'  => 'Chamado ' . $idPart . ' atribuído a você',
        'finalized' => str_contains(mb_strtolower($titulo, 'UTF-8'), 'em atendimento')
            || str_contains(mb_strtolower((string) ($n['tipo'] ?? ''), 'UTF-8'), 'em_atendimento')
            ? 'Chamado ' . $idPart . ' em atendimento'
            : 'Chamado ' . $idPart . ' finalizado pelo técnico',
        'approved'  => 'Chamado ' . $idPart . ' aprovado pelo gestor',
        'validated' => 'Chamado ' . $idPart . ' validado pelo cliente',
        'reopened'  => (str_contains(mb_strtolower($titulo, 'UTF-8'), 'cancelou a validação')
            || str_contains(mb_strtolower($titulo, 'UTF-8'), 'cancelou a validacao'))
            ? 'Chamado ' . $idPart . ': cliente cancelou a validação'
            : 'Chamado ' . $idPart . ' reaberto pelo cliente',
        'message'   => 'Nova mensagem no chamado ' . $idPart,
        default     => $titulo !== '' ? $titulo : 'Notificação',
    };
}

function notificacao_ui_descricao(array $n): string
{
    $desc = trim((string) ($n['descricao'] ?? ''));
    if ($desc !== '') {
        return $desc;
    }

    return match (notificacao_ui_tipo($n)) {
        'created'   => 'Um novo chamado foi aberto. Abra para analisar e encaminhar.',
        'assigned'  => 'Um chamado foi atribuído a você. Abra para iniciar o atendimento.',
        'finalized' => 'O técnico finalizou o atendimento. O chamado aguarda aprovação do gestor.',
        'approved'  => 'O gestor aprovou o atendimento. O chamado aguarda validação do cliente.',
        'validated' => 'O cliente validou o chamado.',
        'reopened'  => (str_contains(mb_strtolower((string) ($n['titulo'] ?? ''), 'UTF-8'), 'cancelou a validação')
            || str_contains(mb_strtolower((string) ($n['titulo'] ?? ''), 'UTF-8'), 'cancelou a validacao'))
            ? 'O cliente cancelou a validação. O chamado voltou para Aguardando Aprovação.'
            : 'O chamado voltou ao status Aberto para novo atendimento.',
        'message'   => 'Há uma nova mensagem neste chamado. Abra para ler e responder.',
        'bm'        => 'Uma nova medição BM foi importada. Abra o mês para conferir os dados.',
        'custo'     => 'Há um custo pendente de aprovação na medição.',
        default     => 'Atualização relacionada a este chamado.',
    };
}

function notificacao_ui_data_relativa(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    try {
        $d = new DateTimeImmutable($raw);
    } catch (Throwable $e) {
        return $raw;
    }
    $now  = new DateTimeImmutable('now');
    $diff = $now->getTimestamp() - $d->getTimestamp();
    if ($diff < 45) {
        return 'agora há pouco';
    }
    if ($diff < 90) {
        return 'há 1 minuto';
    }
    $min = (int) floor($diff / 60);
    if ($min < 60) {
        return 'há ' . $min . ($min === 1 ? ' minuto' : ' minutos');
    }
    $hrs = (int) floor($min / 60);
    if ($hrs < 24) {
        return 'há ' . $hrs . ($hrs === 1 ? ' hora' : ' horas');
    }
    $timeStr = $d->format('H:i');
    $startToday = $now->setTime(0, 0, 0);
    $startThat  = $d->setTime(0, 0, 0);
    $dayDiff    = (int) $startToday->diff($startThat)->days;
    if ($dayDiff === 0) {
        return 'hoje às ' . $timeStr;
    }
    if ($dayDiff === 1) {
        return 'ontem às ' . $timeStr;
    }
    if ($dayDiff < 7) {
        return 'há ' . $dayDiff . ' dias';
    }

    return $d->format('d/m/Y') . ' às ' . $timeStr;
}
