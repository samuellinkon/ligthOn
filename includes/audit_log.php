<?php
/**
 * Registo append-only de auditoria (tabela audit_logs).
 * Nunca lança exceção para o chamador; falhas vão para error_log.
 */

require_once __DIR__ . '/db.php';

const AUDIT_LOG_PAYLOAD_MAX_BYTES = 16000;
const AUDIT_LOG_EXPORT_MAX_ROWS   = 5000;

/**
 * Valor escalar para comparação em diff de auditoria.
 */
function audit_log_valor_normalizado(mixed $v): string
{
    if ($v === null) {
        return '';
    }
    if (is_bool($v)) {
        return $v ? '1' : '0';
    }
    if (is_int($v) || is_float($v)) {
        if (is_float($v) && is_nan($v)) {
            return '';
        }

        return is_float($v)
            ? rtrim(rtrim(sprintf('%.10F', $v), '0'), '.')
            : (string) $v;
    }
    $s = trim((string) $v);

    return str_replace(["\r\n", "\r"], "\n", $s);
}

/**
 * Lista alterações campo a campo (antes ≠ depois), truncada.
 *
 * @param array<string, mixed> $antes
 * @param array<string, mixed> $depois
 * @param list<string>         $keys
 *
 * @return list<array{campo: string, antes: string, depois: string}>
 */
function audit_log_diff_campos(array $antes, array $depois, array $keys, int $maxValLen = 200, int $maxEntries = 45): array
{
    $out = [];
    foreach ($keys as $k) {
        $a = audit_log_valor_normalizado($antes[$k] ?? null);
        $b = audit_log_valor_normalizado($depois[$k] ?? null);
        if ($a === $b) {
            continue;
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            $a = mb_strlen($a, 'UTF-8') > $maxValLen ? mb_substr($a, 0, $maxValLen, 'UTF-8') . '…' : $a;
            $b = mb_strlen($b, 'UTF-8') > $maxValLen ? mb_substr($b, 0, $maxValLen, 'UTF-8') . '…' : $b;
        } else {
            $a = strlen($a) > $maxValLen ? substr($a, 0, $maxValLen) . '…' : $a;
            $b = strlen($b) > $maxValLen ? substr($b, 0, $maxValLen) . '…' : $b;
        }
        $out[] = ['campo' => $k, 'antes' => $a, 'depois' => $b];
        if (count($out) >= $maxEntries) {
            break;
        }
    }

    return $out;
}

/**
 * Exportação a partir da listagem admin de chamados (CSV, JSON, XLSX, PDF, etc.).
 *
 * @param array<string, mixed> $ctx cliente_id, filtro, q, medicao_mes, periodo_de, periodo_ate, …
 */
function audit_log_chamados_listagem_export(string $formato, array $ctx): void
{
    $cid = (int) ($ctx['cliente_id'] ?? 0);
    $payload = $ctx;
    unset($payload['cliente_id']);
    $payload['formato'] = $formato;
    audit_log_registar('chamados.exportar', 'chamados', null, $cid > 0 ? $cid : null, $payload);
}

/**
 * @param array<string, mixed> $payload
 * @param array{id?:int,nome?:string,perfil?:string}|null $atorOverride Utilizador em contexto sem sessão (ex.: falha de login)
 */
function audit_log_registar(
    string $acao,
    ?string $entidadeTipo = null,
    ?int $entidadeId = null,
    ?int $clienteId = null,
    array $payload = [],
    ?array $atorOverride = null
): void {
    $pdo = db();
    if (!$pdo) {
        return;
    }
    $acao = trim($acao);
    if ($acao === '') {
        return;
    }
    $u = $atorOverride;
    if ($u === null && function_exists('current_user')) {
        $u = current_user();
    }
    $atorId   = isset($u['id']) ? (int) $u['id'] : 0;
    $atorNome = trim((string) ($u['nome'] ?? ''));
    $atorPer  = trim((string) ($u['perfil'] ?? ''));
    if ($atorId <= 0) {
        $atorId = null;
    }
    $ip = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
    if (strlen($ip) > 45) {
        $ip = substr($ip, 0, 45);
    }
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
    if (function_exists('mb_substr')) {
        $ua = mb_substr($ua, 0, 500, 'UTF-8');
    } else {
        $ua = substr($ua, 0, 500);
    }
    $entidadeTipo = $entidadeTipo !== null && $entidadeTipo !== '' ? trim($entidadeTipo) : null;
    if ($entidadeTipo !== null && strlen($entidadeTipo) > 64) {
        $entidadeTipo = substr($entidadeTipo, 0, 64);
    }
    $entidadeId = $entidadeId !== null && $entidadeId > 0 ? $entidadeId : null;
    $clienteId  = $clienteId !== null && $clienteId > 0 ? $clienteId : null;

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $json = '{}';
    }
    if (strlen($json) > AUDIT_LOG_PAYLOAD_MAX_BYTES) {
        $json = substr($json, 0, AUDIT_LOG_PAYLOAD_MAX_BYTES - 20) . ',"_truncado":true}';
    }

    try {
        $st = $pdo->prepare('
            INSERT INTO audit_logs
                (ator_user_id, ator_nome, ator_perfil, acao, entidade_tipo, entidade_id, cliente_id, ip, user_agent, payload)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ');
        $st->execute([
            $atorId,
            $atorNome,
            $atorPer,
            $acao,
            $entidadeTipo,
            $entidadeId,
            $clienteId,
            $ip !== '' ? $ip : null,
            $ua !== '' ? $ua : null,
            $json,
        ]);
    } catch (Throwable $e) {
        error_log('[crm_prefeitura] audit_log_registar: ' . $e->getMessage());
    }
}

/**
 * Lista paginada para o painel admin/gestor.
 *
 * @param array<string, mixed> $viewer Utilizador logado (current user)
 * @param array<string, mixed> $filtros de, ate (YYYY-MM-DD, opcionais)
 * @return array{rows: list<array<string,mixed>>, total: int}
 */
function repo_audit_logs_list(array $viewer, int $page, int $perPage, array $filtros = []): array
{
    $pdo = db();
    if (!$pdo || $perPage < 1) {
        return ['rows' => [], 'total' => 0];
    }
    $page = max(1, $page);
    $where = ['1=1'];
    $params = [];

    $perfil = (string) ($viewer['perfil'] ?? '');
    if ($perfil === 'cliente') {
        $cidU = (int) ($viewer['cliente_id'] ?? 0);
        $mid  = $cidU > 0 ? repo_cliente_matriz_raiz_id($cidU) : 0;
        if ($mid <= 0) {
            return ['rows' => [], 'total' => 0];
        }
        $uidLog = (int) ($viewer['id'] ?? 0);
        /* Eventos da matriz e filiais; linhas sem cliente_id só se o próprio utilizador for o ator (evita vazamento). */
        $where[] = '((al.cliente_id IN (SELECT c2.id FROM clientes c2 WHERE c2.id = ? OR c2.empresa_id = ?)) OR (al.cliente_id IS NULL AND al.ator_user_id = ?))';
        $params[] = $mid;
        $params[] = $mid;
        $params[] = $uidLog;
    } elseif ($perfil === 'gestor') {
        $scope = null;
        if (function_exists('gestao_scope_cliente_id')) {
            $scope = gestao_scope_cliente_id($viewer);
        }
        if ($scope !== null && $scope > 0) {
            $where[] = 'al.cliente_id = ?';
            $params[] = $scope;
        } else {
            return ['rows' => [], 'total' => 0];
        }
    }

    $de = trim((string) ($filtros['de'] ?? ''));
    $ate = trim((string) ($filtros['ate'] ?? ''));
    if ($de !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $de)) {
        $where[] = 'al.criado_em >= ?';
        $params[] = $de . ' 00:00:00';
    }
    if ($ate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) {
        $where[] = 'al.criado_em <= ?';
        $params[] = $ate . ' 23:59:59';
    }

    $sqlWhere = implode(' AND ', $where);
    try {
        $stc = $pdo->prepare("SELECT COUNT(*) FROM audit_logs al WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int) $stc->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $st = $pdo->prepare("
            SELECT al.id,
                   DATE_FORMAT(al.criado_em, '%Y-%m-%d %H:%i:%s') AS criado_em,
                   al.ator_user_id, al.ator_nome, al.ator_perfil, al.acao,
                   al.entidade_tipo, al.entidade_id, al.cliente_id, al.ip,
                   al.user_agent, al.payload
            FROM audit_logs al
            WHERE $sqlWhere
            ORDER BY al.id DESC
            LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset . '
        ');
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['id'] = (int) ($r['id'] ?? 0);
            $r['ator_user_id'] = $r['ator_user_id'] !== null ? (int) $r['ator_user_id'] : null;
            $r['entidade_id'] = $r['entidade_id'] !== null ? (int) $r['entidade_id'] : null;
            $r['cliente_id'] = $r['cliente_id'] !== null ? (int) $r['cliente_id'] : null;
        }
        unset($r);

        return ['rows' => $rows, 'total' => $total];
    } catch (Throwable $e) {
        error_log('[crm_prefeitura] repo_audit_logs_list: ' . $e->getMessage());

        return ['rows' => [], 'total' => 0];
    }
}

/**
 * Mesmos filtros que repo_audit_logs_list; no máximo AUDIT_LOG_EXPORT_MAX_ROWS linhas.
 *
 * @param array<string, mixed> $viewer
 * @param array<string, mixed> $filtros
 * @return list<array<string, mixed>>
 */
function repo_audit_logs_export_rows(array $viewer, array $filtros = []): array
{
    $pdo = db();
    if (!$pdo) {
        return [];
    }
    $where = ['1=1'];
    $params = [];

    $perfil = (string) ($viewer['perfil'] ?? '');
    if ($perfil === 'cliente') {
        $cidU = (int) ($viewer['cliente_id'] ?? 0);
        $mid  = $cidU > 0 ? repo_cliente_matriz_raiz_id($cidU) : 0;
        if ($mid <= 0) {
            return [];
        }
        $uidLog = (int) ($viewer['id'] ?? 0);
        $where[] = '((al.cliente_id IN (SELECT c2.id FROM clientes c2 WHERE c2.id = ? OR c2.empresa_id = ?)) OR (al.cliente_id IS NULL AND al.ator_user_id = ?))';
        $params[] = $mid;
        $params[] = $mid;
        $params[] = $uidLog;
    } elseif ($perfil === 'gestor') {
        $scope = function_exists('gestao_scope_cliente_id') ? gestao_scope_cliente_id($viewer) : null;
        if ($scope !== null && $scope > 0) {
            $where[] = 'al.cliente_id = ?';
            $params[] = $scope;
        } else {
            return [];
        }
    }

    $de = trim((string) ($filtros['de'] ?? ''));
    $ate = trim((string) ($filtros['ate'] ?? ''));
    if ($de !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $de)) {
        $where[] = 'al.criado_em >= ?';
        $params[] = $de . ' 00:00:00';
    }
    if ($ate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) {
        $where[] = 'al.criado_em <= ?';
        $params[] = $ate . ' 23:59:59';
    }

    $sqlWhere = implode(' AND ', $where);
    $lim = (int) AUDIT_LOG_EXPORT_MAX_ROWS;
    try {
        $st = $pdo->prepare("
            SELECT al.id,
                   DATE_FORMAT(al.criado_em, '%Y-%m-%d %H:%i:%s') AS criado_em,
                   al.ator_user_id, al.ator_nome, al.ator_perfil, al.acao,
                   al.entidade_tipo, al.entidade_id, al.cliente_id, al.ip,
                   al.user_agent, al.payload
            FROM audit_logs al
            WHERE $sqlWhere
            ORDER BY al.id DESC
            LIMIT $lim
        ");
        $st->execute($params);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[crm_prefeitura] repo_audit_logs_export_rows: ' . $e->getMessage());

        return [];
    }
}
