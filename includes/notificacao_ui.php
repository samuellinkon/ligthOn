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

    if ($tipo === 'chamado_tecnico_atribuido' || str_contains($titulo, 'atribuído') || str_contains($titulo, 'atribuido')) {
        return 'assigned';
    }
    if (str_contains($titulo, 'validado')) {
        return 'validated';
    }
    if (str_contains($titulo, 'resolvido')) {
        return 'resolved';
    }
    if (str_contains($titulo, 'urgente')) {
        return 'urgent';
    }
    if ($tipo === 'chamado_criado' || str_contains($titulo, 'novo chamado')) {
        return 'urgent';
    }
    if ($tipo === 'chamado_mensagem' || str_contains($titulo, 'mensagem')) {
        return 'message';
    }
    if ($tipo === 'chamado_status') {
        return 'status';
    }

    return 'info';
}

function notificacao_ui_titulo(array $n): string
{
    $titulo = trim((string) ($n['titulo'] ?? ''));
    $cid    = notificacao_ui_extrair_chamado_id($n);
    $idPart = $cid > 0 ? '#' . $cid : '';

    if (preg_match('/foi atribuído um chamado ao técnico/i', $titulo)) {
        return 'Chamado ' . $idPart . ' atribuído a você';
    }
    if (preg_match('/chamado\s*#\d+\s*:\s*resolvido/i', $titulo)) {
        return 'Chamado ' . $idPart . ' foi resolvido';
    }
    if (preg_match('/chamado\s*#\d+\s*:\s*validado/i', $titulo)) {
        return 'Chamado ' . $idPart . ' validado';
    }
    if (preg_match('/nova mensagem no chamado/i', $titulo)) {
        return 'Nova mensagem no chamado ' . $idPart;
    }
    if (preg_match('/novo chamado/i', $titulo)) {
        return 'Novo chamado ' . $idPart;
    }

    return $titulo !== '' ? $titulo : 'Notificação';
}

function notificacao_ui_descricao(array $n): string
{
    $desc = trim((string) ($n['descricao'] ?? ''));
    if ($desc !== '') {
        return $desc;
    }

    return match (notificacao_ui_tipo($n)) {
        'assigned'  => 'Você foi definido como responsável técnico por este chamado.',
        'resolved'  => 'O atendimento foi marcado como resolvido e aguarda validação, se aplicável.',
        'validated' => 'O chamado foi conferido e validado pela gestão.',
        'message'   => 'Há uma nova mensagem neste chamado. Abra para ler e responder.',
        'urgent'    => 'Este chamado requer atenção prioritária.',
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
