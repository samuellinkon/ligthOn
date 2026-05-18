<?php
/**
 * Linha do tempo do chamado (auditoria + marcos do registro).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit_log.php';

/**
 * @return array{titulo: string, icone: string, tone: string}
 */
function chamado_historico_evento_meta(string $acao): array
{
    static $map = [
        'chamado.criar'                  => ['titulo' => 'Chamado criado', 'icone' => 'criar', 'tone' => 'success'],
        'chamado.alterar'                => ['titulo' => 'Ficha da OS alterada', 'icone' => 'editar', 'tone' => 'info'],
        'chamado.alterar_localizacao'    => ['titulo' => 'Localização atualizada', 'icone' => 'mapa', 'tone' => 'info'],
        'chamado.status'                 => ['titulo' => 'Status alterado', 'icone' => 'status', 'tone' => 'info'],
        'chamado.alterar_responsavel'    => ['titulo' => 'Responsável atualizado', 'icone' => 'pessoa', 'tone' => 'info'],
        'chamado.resposta'               => ['titulo' => 'Mensagem na conversa', 'icone' => 'mensagem', 'tone' => 'info'],
        'chamado.anexo'                  => ['titulo' => 'Anexo enviado', 'icone' => 'anexo', 'tone' => 'info'],
        'chamado.operador.enviar_os'     => ['titulo' => 'Operador enviou para aprovação', 'icone' => 'enviar', 'tone' => 'warning'],
        'chamado.gestor.aprovar'         => ['titulo' => 'Gestor aprovou o atendimento', 'icone' => 'aprovar', 'tone' => 'success'],
        'chamado.tecnico.atribuir'        => ['titulo' => 'Técnico(s) atribuído(s)', 'icone' => 'pessoa', 'tone' => 'info'],
        'chamado.item.adicionar'         => ['titulo' => 'Item lançado', 'icone' => 'item', 'tone' => 'info'],
        'chamado.item.alterar'           => ['titulo' => 'Item alterado', 'icone' => 'item', 'tone' => 'info'],
        'chamado.item.remover'           => ['titulo' => 'Item removido', 'icone' => 'item', 'tone' => 'danger'],
        'chamado.excluir'                => ['titulo' => 'Chamado excluído', 'icone' => 'excluir', 'tone' => 'danger'],
        'chamado.inativar'               => ['titulo' => 'Chamado inativado', 'icone' => 'excluir', 'tone' => 'danger'],
        'chamado.exportar'               => ['titulo' => 'Exportação gerada', 'icone' => 'exportar', 'tone' => 'muted'],
        'chamado.marco.abertura'         => ['titulo' => 'Chamado aberto', 'icone' => 'criar', 'tone' => 'success'],
        'chamado.marco.finalizado'       => ['titulo' => 'Operador finalizou atendimento', 'icone' => 'enviar', 'tone' => 'warning'],
        'chamado.marco.aprovado'         => ['titulo' => 'Gestor aprovou atendimento', 'icone' => 'aprovar', 'tone' => 'success'],
    ];

    if (isset($map[$acao])) {
        return $map[$acao];
    }

    return ['titulo' => str_replace(['chamado.', '.'], ['', ' '], $acao), 'icone' => 'info', 'tone' => 'muted'];
}

/**
 * @param array<string, mixed> $payload
 */
function chamado_historico_detalhe_por_acao(string $acao, array $payload): string
{
    switch ($acao) {
        case 'chamado.criar':
            $p = [];
            if (!empty($payload['titulo'])) {
                $p[] = (string) $payload['titulo'];
            }
            if (!empty($payload['prioridade'])) {
                $p[] = 'Prioridade: ' . $payload['prioridade'];
            }
            if (!empty($payload['status'])) {
                $p[] = 'Status: ' . $payload['status'];
            }
            return implode(' · ', $p);

        case 'chamado.status':
            return trim((string) ($payload['status_anterior'] ?? '—')) . ' → ' . trim((string) ($payload['status_novo'] ?? '—'));

        case 'chamado.alterar_responsavel':
            return trim((string) ($payload['antes'] ?? '—')) . ' → ' . trim((string) ($payload['depois'] ?? '—'));

        case 'chamado.alterar':
        case 'chamado.alterar_localizacao':
            $alt = $payload['alteracoes'] ?? [];
            if (is_array($alt) && $alt !== []) {
                $parts = [];
                foreach (array_slice($alt, 0, 4) as $a) {
                    if (!is_array($a)) {
                        continue;
                    }
                    $campo = (string) ($a['campo'] ?? '');
                    $parts[] = $campo !== ''
                        ? $campo . ': ' . ($a['antes'] ?? '—') . ' → ' . ($a['depois'] ?? '—')
                        : '';
                }
                $parts = array_values(array_filter($parts, static fn ($x) => $x !== ''));
                $txt = implode(' · ', $parts);
                if (count($alt) > 4) {
                    $txt .= ' (+' . (count($alt) - 4) . ' campo(s))';
                }
                return $txt !== '' ? $txt : 'Alterações na ficha';
            }
            return 'Alterações na ficha da OS';

        case 'chamado.resposta':
            $txt = trim((string) ($payload['texto'] ?? ''));
            if ($txt !== '') {
                return $txt;
            }
            return !empty($payload['interna']) ? 'Nota interna registrada' : 'Mensagem registrada';

        case 'chamado.anexo':
            $nome = trim((string) ($payload['nome_original'] ?? ''));
            $tipo = (string) ($payload['tipo_ficheiro'] ?? '');
            if ($tipo === 'imagem') {
                return ($nome !== '' ? $nome : 'Imagem') . ' (foto)';
            }
            return $nome !== '' ? $nome : 'Arquivo anexado';

        case 'chamado.operador.enviar_os':
            return 'Status: ' . (string) ($payload['status_novo'] ?? 'Aguardando Aprovação');

        case 'chamado.gestor.aprovar':
        case 'chamado.marco.aprovado':
            return 'Status: Resolvido';

        case 'chamado.tecnico.atribuir':
            $nomes = $payload['tecnicos'] ?? [];
            if (is_array($nomes) && $nomes !== []) {
                return implode(', ', array_map('strval', $nomes));
            }
            return trim((string) ($payload['responsavel'] ?? ''));

        case 'chamado.item.adicionar':
            $mov = (string) ($payload['movimento'] ?? 'utilizado');
            $movLbl = $mov === 'devolvido' ? 'Recolhido' : 'Utilizado';
            $qtd = $payload['quantidade'] ?? '';
            $nome = trim((string) ($payload['item_nome'] ?? ''));
            return $nome . ' · ' . $movLbl . ($qtd !== '' ? ' · Qtd ' . $qtd : '');

        case 'chamado.item.alterar':
            return trim((string) ($payload['item_nome'] ?? 'Item')) . ' · Qtd ' . ($payload['quantidade'] ?? '—');

        case 'chamado.item.remover':
            return trim((string) ($payload['item_nome'] ?? 'Item removido'));

        case 'chamado.exportar':
            return 'Formato: ' . (string) ($payload['formato'] ?? 'documento');

        default:
            return '';
    }
}

/**
 * @param array<int, array{nome: string, perfil: string}> $userCache
 * @param array<string, mixed> $chamado
 * @return array{ator: string, perfil: string}
 */
function chamado_historico_resolver_ator(
    string $atorNome,
    string $atorPerfil,
    ?int $atorUserId,
    string $acao,
    array $payload,
    ?PDO $pdo,
    array &$userCache,
    array $chamado = []
): array {
    $ator   = trim($atorNome);
    $perfil = trim($atorPerfil);

    if ($ator === '' && $acao === 'chamado.resposta') {
        $ator = trim((string) ($payload['autor'] ?? ''));
        if ($perfil === '') {
            $perfil = chamado_historico_tipo_para_perfil((string) ($payload['tipo'] ?? ''));
        }
    }
    if ($ator === '' && $acao === 'chamado.anexo') {
        $ep = $payload['enviado_por'] ?? '';
        if (is_string($ep) && trim($ep) !== '') {
            $ator = trim($ep);
        } elseif (is_numeric($ep) && (int) $ep > 0) {
            $atorUserId = (int) $ep;
        }
        if ($perfil === '') {
            $perfil = chamado_historico_tipo_para_perfil((string) ($payload['enviado_tipo'] ?? ''));
        }
    }

    if ($atorUserId !== null && $atorUserId > 0 && $pdo instanceof PDO) {
        $u = chamado_historico_usuario_por_id($pdo, $atorUserId, $userCache);
        if ($u !== null) {
            if ($ator === '') {
                $ator = $u['nome'];
            }
            if ($perfil === '') {
                $perfil = $u['perfil'];
            }
        }
    }

    if ($ator === '' && $acao === 'chamado.marco.finalizado') {
        $uid = (int) ($chamado['finalizado_operador_user_id'] ?? 0);
        if ($uid > 0 && $pdo instanceof PDO) {
            $u = chamado_historico_usuario_por_id($pdo, $uid, $userCache);
            if ($u !== null) {
                $ator   = $u['nome'];
                $perfil = $u['perfil'];
            }
        }
        if ($ator === '') {
            $ator = trim((string) ($chamado['tecnico_nome'] ?? ''));
            if ($ator === '' && !empty($chamado['tecnico_nomes'][0])) {
                $ator = (string) $chamado['tecnico_nomes'][0];
            }
            if ($perfil === '') {
                $perfil = 'operador';
            }
        }
    }

    if ($ator === '' && ($acao === 'chamado.gestor.aprovar' || $acao === 'chamado.marco.aprovado')) {
        $uid = (int) ($chamado['aprovado_gestor_user_id'] ?? 0);
        if ($uid > 0 && $pdo instanceof PDO) {
            $u = chamado_historico_usuario_por_id($pdo, $uid, $userCache);
            if ($u !== null) {
                $ator   = $u['nome'];
                $perfil = $u['perfil'];
            }
        }
        if ($ator === '') {
            $ator = trim((string) ($chamado['aprovado_gestor_nome'] ?? ''));
            if ($perfil === '') {
                $perfil = 'gestor';
            }
        }
    }

    if ($ator === '' && $acao === 'chamado.marco.abertura') {
        $ator   = trim((string) ($payload['aberto_por'] ?? ''));
        $perfil = trim((string) ($payload['aberto_perfil'] ?? ''));
    }

    if ($ator === '' && str_starts_with($acao, 'chamado.item.')) {
        if ($atorUserId === null || $atorUserId <= 0) {
            $uid = (int) ($payload['criado_por_user_id'] ?? 0);
            if ($uid <= 0) {
                $uid = (int) ($chamado['finalizado_operador_user_id'] ?? 0);
            }
            if ($uid <= 0) {
                $uid = (int) ($chamado['tecnico_user_id'] ?? 0);
            }
            if ($uid > 0) {
                $atorUserId = $uid;
            }
        }
        if ($atorUserId !== null && $atorUserId > 0 && $pdo instanceof PDO) {
            $u = chamado_historico_usuario_por_id($pdo, $atorUserId, $userCache);
            if ($u !== null) {
                $ator   = $u['nome'];
                $perfil = $u['perfil'];
            }
        }
        if ($ator === '') {
            $ator = trim((string) ($chamado['tecnico_nome'] ?? ''));
            if ($ator === '' && !empty($chamado['tecnico_nomes'][0])) {
                $ator = (string) $chamado['tecnico_nomes'][0];
            }
            if ($ator === '') {
                $ator = trim((string) ($chamado['responsavel'] ?? ''));
            }
            if ($perfil === '' && $ator !== '') {
                $perfil = 'operador';
            }
        }
    }

    return ['ator' => $ator, 'perfil' => $perfil];
}

function chamado_historico_tipo_para_perfil(string $tipo): string
{
    return match (strtolower(trim($tipo))) {
        'operador' => 'operador',
        'cliente'  => 'cliente',
        'gestor'   => 'gestor',
        'admin'    => 'admin',
        default    => '',
    };
}

/**
 * @param array<int, array{nome: string, perfil: string}> $cache
 * @return array{nome: string, perfil: string}|null
 */
function chamado_historico_usuario_por_id(PDO $pdo, int $userId, array &$cache): ?array
{
    if ($userId <= 0) {
        return null;
    }
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }
    try {
        $st = $pdo->prepare('SELECT nome, perfil FROM usuarios WHERE id = ? LIMIT 1');
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $cache[$userId] = [
            'nome'   => trim((string) ($row['nome'] ?? '')),
            'perfil' => trim((string) ($row['perfil'] ?? '')),
        ];

        return $cache[$userId];
    } catch (Throwable $e) {
        return null;
    }
}

function chamado_historico_perfil_label(string $perfil): string
{
    return match (strtolower(trim($perfil))) {
        'admin'    => 'Admin',
        'gestor'   => 'Gestor',
        'operador' => 'Operador',
        'cliente'  => 'Portal',
        'sistema'  => 'Sistema',
        default    => $perfil !== '' ? ucfirst($perfil) : '',
    };
}

/**
 * @param array<string, mixed> $ev
 * @param array<string, mixed> $payload
 * @param array<int, array{nome: string, perfil: string}|null> $userCache
 * @param array<string, mixed> $chamado
 */
function chamado_historico_aplicar_ator(
    array &$ev,
    array $payload,
    ?PDO $pdo,
    array &$userCache,
    array $chamado = []
): void {
    $res = chamado_historico_resolver_ator(
        (string) ($ev['ator'] ?? ''),
        (string) ($ev['perfil'] ?? ''),
        isset($ev['ator_user_id']) ? (int) $ev['ator_user_id'] : null,
        (string) ($ev['acao'] ?? ''),
        $payload,
        $pdo,
        $userCache,
        $chamado
    );
    if ($res['ator'] !== '') {
        $ev['ator']   = $res['ator'];
        $ev['perfil'] = $res['perfil'];
    } elseif (str_starts_with((string) ($ev['acao'] ?? ''), 'chamado.item.')) {
        $ev['ator']   = 'Equipe de campo';
        $ev['perfil'] = 'operador';
    } else {
        $ev['ator']   = 'Utilizador não identificado';
        $ev['perfil'] = $res['perfil'];
    }
    $ev['perfil_label'] = chamado_historico_perfil_label($ev['perfil']);
    if ($ev['perfil_label'] === '' && $ev['ator'] === 'Utilizador não identificado') {
        $ev['perfil_label'] = 'Sem registo';
    }
}

/**
 * @param array<string, mixed> $row audit_logs row
 * @return array<string, mixed>
 */
function chamado_historico_evento_from_audit(array $row): array
{
    $acao = (string) ($row['acao'] ?? '');
    $meta = chamado_historico_evento_meta($acao);
    $payload = [];
    if (!empty($row['payload'])) {
        $decoded = json_decode((string) $row['payload'], true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }
    if ($acao === 'chamado.resposta' && !empty($payload['interna'])) {
        $meta['titulo'] = 'Nota interna na conversa';
        $meta['tone'] = 'muted';
    }
    if ($acao === 'chamado.anexo' && (($payload['tipo_ficheiro'] ?? '') === 'imagem')) {
        $meta['titulo'] = 'Foto anexada';
    }

    $detalhe = chamado_historico_detalhe_por_acao($acao, $payload);
    $atorUserId = isset($row['ator_user_id']) && $row['ator_user_id'] !== null ? (int) $row['ator_user_id'] : null;

    return [
        'ts'           => (string) ($row['criado_em'] ?? ''),
        'acao'         => $acao,
        'titulo'       => $meta['titulo'],
        'detalhe'      => $detalhe,
        'ator'         => trim((string) ($row['ator_nome'] ?? '')),
        'perfil'       => trim((string) ($row['ator_perfil'] ?? '')),
        'ator_user_id' => $atorUserId,
        'payload'      => $payload,
        'icone'        => $meta['icone'],
        'tone'         => $meta['tone'],
        'origem'       => 'audit',
    ];
}

/**
 * @param array<string, mixed> $partial
 * @return array<string, mixed>
 */
function chamado_historico_evento_sintetico(
    string $acao,
    string $ts,
    string $ator,
    string $perfil,
    string $detalhe,
    ?int $atorUserId = null,
    array $payload = []
): array {
    $meta = chamado_historico_evento_meta($acao);

    return [
        'ts'           => $ts,
        'acao'         => $acao,
        'titulo'       => $meta['titulo'],
        'detalhe'      => $detalhe,
        'ator'         => $ator,
        'perfil'       => $perfil,
        'ator_user_id' => $atorUserId,
        'payload'      => $payload,
        'icone'        => $meta['icone'],
        'tone'         => $meta['tone'],
        'origem'       => 'marco',
    ];
}

/**
 * @param array<string, mixed>|null $chamado
 * @return list<array<string, mixed>>
 */
function repo_chamado_historico_list(int $chamadoId, ?array $chamado = null): array
{
    if ($chamadoId <= 0 || !db_ok()) {
        return [];
    }
    if ($chamado === null) {
        $chamado = function_exists('repo_chamado') ? repo_chamado($chamadoId) : null;
    }
    if (!$chamado) {
        return [];
    }

    $pdo = db();
    if (!$pdo) {
        return [];
    }

    $eventos = [];
    $acoesPresentes = [];
    $auditItensLinha = [];
    $userCache = [];

    try {
        $st = $pdo->prepare('
            SELECT id, criado_em, ator_user_id, ator_nome, ator_perfil, acao, payload
            FROM audit_logs
            WHERE entidade_tipo = ? AND entidade_id = ?
            ORDER BY criado_em ASC, id ASC
        ');
        $st->execute(['chamado', $chamadoId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $ev = chamado_historico_evento_from_audit($row);
            if ($ev['ts'] !== '') {
                $eventos[] = $ev;
                $acoesPresentes[$ev['acao'] . '@' . substr($ev['ts'], 0, 16)] = true;
                $payloadAudit = is_array($ev['payload'] ?? null) ? $ev['payload'] : [];
                $lidAudit = (int) ($payloadAudit['linha_id'] ?? 0);
                if ($lidAudit > 0 && str_starts_with((string) ($ev['acao'] ?? ''), 'chamado.item.')) {
                    $auditItensLinha[$lidAudit] = true;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[crm_prefeitura] repo_chamado_historico_list audit: ' . $e->getMessage());
    }

    $tsAberto = trim((string) ($chamado['data'] ?? ''));
    if ($tsAberto !== '' && !isset($acoesPresentes['chamado.criar@' . substr($tsAberto, 0, 16)])) {
        $criadorNome = '';
        $criadorPerfil = '';
        $criadorId = null;
        foreach ($eventos as $evCriar) {
            if (($evCriar['acao'] ?? '') === 'chamado.criar') {
                $criadorNome   = trim((string) ($evCriar['ator'] ?? ''));
                $criadorPerfil = trim((string) ($evCriar['perfil'] ?? ''));
                $criadorId     = isset($evCriar['ator_user_id']) ? (int) $evCriar['ator_user_id'] : null;
                break;
            }
        }
        $eventos[] = chamado_historico_evento_sintetico(
            'chamado.marco.abertura',
            $tsAberto,
            $criadorNome,
            $criadorPerfil,
            trim((string) ($chamado['titulo'] ?? '')) !== '' ? (string) $chamado['titulo'] : 'Registro inicial do chamado',
            $criadorId
        );
    }

    $finOp = trim((string) ($chamado['finalizado_operador_em'] ?? ''));
    if ($finOp !== '') {
        $temAuditFin = false;
        foreach ($eventos as $ev) {
            if ($ev['acao'] === 'chamado.operador.enviar_os' && abs(strtotime($ev['ts']) - strtotime($finOp)) < 120) {
                $temAuditFin = true;
                break;
            }
        }
        if (!$temAuditFin) {
            $eventos[] = chamado_historico_evento_sintetico(
                'chamado.marco.finalizado',
                $finOp,
                '',
                'operador',
                'Enviado para aprovação do gestor',
                isset($chamado['finalizado_operador_user_id']) ? (int) $chamado['finalizado_operador_user_id'] : null
            );
        }
    }

    $aprov = trim((string) ($chamado['aprovado_gestor_em'] ?? ''));
    if ($aprov !== '') {
        $temAuditAp = false;
        foreach ($eventos as $ev) {
            if ($ev['acao'] === 'chamado.gestor.aprovar' && abs(strtotime($ev['ts']) - strtotime($aprov)) < 120) {
                $temAuditAp = true;
                break;
            }
        }
        if (!$temAuditAp) {
            $eventos[] = chamado_historico_evento_sintetico(
                'chamado.marco.aprovado',
                $aprov,
                trim((string) ($chamado['aprovado_gestor_nome'] ?? '')),
                'gestor',
                'Atendimento aprovado e chamado resolvido',
                isset($chamado['aprovado_gestor_user_id']) ? (int) $chamado['aprovado_gestor_user_id'] : null
            );
        }
    }

    $temColCriadoPor = function_exists('repo_chamado_itens_tem_criado_por') && repo_chamado_itens_tem_criado_por();
    if ($temColCriadoPor && function_exists('repo_chamado_itens_backfill_criado_por')) {
        repo_chamado_itens_backfill_criado_por($chamadoId);
    }
    try {
        $sqlItens = '
            SELECT ci.id, ci.movimento, ci.quantidade, ci.observacao,
                   DATE_FORMAT(ci.criado_em, \'%Y-%m-%d %H:%i:%s\') AS criado_em,
                   i.nome AS item_nome
        ';
        if ($temColCriadoPor) {
            $sqlItens .= ',
                   ci.criado_por_user_id,
                   uc.nome AS criador_nome,
                   uc.perfil AS criador_perfil
            ';
        }
        $sqlItens .= '
            FROM chamado_itens ci
            INNER JOIN cliente_itens i ON i.id = ci.item_id
        ';
        if ($temColCriadoPor) {
            $sqlItens .= ' LEFT JOIN usuarios uc ON uc.id = ci.criado_por_user_id ';
        }
        $sqlItens .= ' WHERE ci.chamado_id = ? ORDER BY ci.criado_em ASC, ci.id ASC ';

        $stIt = $pdo->prepare($sqlItens);
        $stIt->execute([$chamadoId]);
        foreach ($stIt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $linha) {
            $linhaId = (int) ($linha['id'] ?? 0);
            if ($linhaId > 0 && isset($auditItensLinha[$linhaId])) {
                continue;
            }
            $tsItem = (string) ($linha['criado_em'] ?? '');
            if ($tsItem === '') {
                continue;
            }
            $jaTem = false;
            $itemNome = (string) ($linha['item_nome'] ?? '');
            foreach ($eventos as $ev) {
                if (($ev['acao'] ?? '') !== 'chamado.item.adicionar') {
                    continue;
                }
                $pEv = is_array($ev['payload'] ?? null) ? $ev['payload'] : [];
                if ($linhaId > 0 && (int) ($pEv['linha_id'] ?? 0) === $linhaId) {
                    $jaTem = true;
                    break;
                }
                if (abs(strtotime((string) ($ev['ts'] ?? '')) - strtotime($tsItem)) < 120
                    && $itemNome !== ''
                    && str_contains((string) ($ev['detalhe'] ?? ''), $itemNome)) {
                    $jaTem = true;
                    break;
                }
            }
            if ($jaTem) {
                continue;
            }
            $mov = (string) ($linha['movimento'] ?? 'utilizado');
            $meta = chamado_historico_evento_meta('chamado.item.adicionar');
            $criadorId = $temColCriadoPor && !empty($linha['criado_por_user_id'])
                ? (int) $linha['criado_por_user_id']
                : null;
            $eventos[] = [
                'ts'           => $tsItem,
                'acao'         => 'chamado.item.adicionar',
                'titulo'       => $meta['titulo'],
                'detalhe'      => chamado_historico_detalhe_por_acao('chamado.item.adicionar', [
                    'movimento'  => $mov,
                    'quantidade' => rtrim(rtrim(sprintf('%.4f', (float) ($linha['quantidade'] ?? 0)), '0'), '.'),
                    'item_nome'  => $itemNome,
                ]),
                'ator'         => $temColCriadoPor ? trim((string) ($linha['criador_nome'] ?? '')) : '',
                'perfil'       => $temColCriadoPor ? trim((string) ($linha['criador_perfil'] ?? '')) : '',
                'ator_user_id' => $criadorId,
                'payload'      => ['criado_por_user_id' => $criadorId, 'linha_id' => $linhaId],
                'icone'        => $meta['icone'],
                'tone'         => $meta['tone'],
                'origem'       => 'item',
            ];
        }
    } catch (Throwable $e) {
        error_log('[crm_prefeitura] repo_chamado_historico_list itens: ' . $e->getMessage());
    }

    usort($eventos, static function (array $a, array $b): int {
        return strcmp((string) ($b['ts'] ?? ''), (string) ($a['ts'] ?? ''));
    });

    foreach ($eventos as &$ev) {
        $payload = is_array($ev['payload'] ?? null) ? $ev['payload'] : [];
        chamado_historico_aplicar_ator($ev, $payload, $pdo, $userCache, $chamado);
        unset($ev['payload'], $ev['ator_user_id']);

        $ts = strtotime((string) ($ev['ts'] ?? ''));
        $ev['ts_label'] = $ts !== false ? date('d/m/Y H:i', $ts) : (string) ($ev['ts'] ?? '');
    }
    unset($ev);

    $criadorAbertura = null;
    foreach ($eventos as $evCriar) {
        if (($evCriar['acao'] ?? '') === 'chamado.criar') {
            $criadorAbertura = $evCriar;
            break;
        }
    }
    if ($criadorAbertura !== null) {
        foreach ($eventos as &$evAb) {
            if (($evAb['acao'] ?? '') !== 'chamado.marco.abertura') {
                continue;
            }
            if (($evAb['ator'] ?? '') === 'Utilizador não identificado' || trim((string) ($evAb['ator'] ?? '')) === '') {
                $evAb['ator']         = (string) ($criadorAbertura['ator'] ?? $evAb['ator']);
                $evAb['perfil']       = (string) ($criadorAbertura['perfil'] ?? $evAb['perfil']);
                $evAb['perfil_label'] = (string) ($criadorAbertura['perfil_label'] ?? $evAb['perfil_label']);
            }
        }
        unset($evAb);
    }

    return $eventos;
}
