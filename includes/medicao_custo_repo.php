<?php
/**
 * Repositório: custos adicionais na medição mensal (aprovação do cliente → BM).
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function repo_medicao_custos_table_exists(): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $pdo = db();
    if (!$pdo) {
        $cache = false;

        return false;
    }
    try {
        $st = $pdo->query("SHOW TABLES LIKE 'medicao_custos'");
        $cache = $st && $st->fetchColumn() !== false;
    } catch (Throwable $e) {
        $cache = false;
    }

    return $cache;
}

/** Perfil do utilizador: admin | gestor | cliente | operador */
function medicao_custo_user_perfil(?array $user = null): string
{
    if ($user === null && function_exists('current_user')) {
        $user = current_user();
    }

    return strtolower(trim((string) ($user['perfil'] ?? '')));
}

/** Admin e gestor: criar lançamentos. */
function medicao_custo_pode_criar(?array $user = null): bool
{
    return in_array(medicao_custo_user_perfil($user), ['admin', 'gestor'], true);
}

/** Admin e gestor: editar e excluir (pendente/rejeitado). */
function medicao_custo_pode_editar(?array $user = null): bool
{
    return in_array(medicao_custo_user_perfil($user), ['admin', 'gestor'], true);
}

/** Admin (painel), gestor (se portal) e cliente: aprovar ou rejeitar pendentes. */
function medicao_custo_pode_aprovar(?array $user = null): bool
{
    return in_array(medicao_custo_user_perfil($user), ['admin', 'cliente'], true);
}

/** Secção visível no painel admin (admin e gestor; operador/técnico não). */
function medicao_custo_secao_visivel_gestao(?array $user = null): bool
{
    return in_array(medicao_custo_user_perfil($user), ['admin', 'gestor'], true);
}

/**
 * @return list<string>
 */
function repo_medicao_custo_status_list(): array
{
    return ['Pendente', 'Aprovado', 'Rejeitado'];
}

function repo_medicao_custos_aba_para_status(?string $aba): ?string
{
    $aba = strtolower(trim((string) $aba));
    if ($aba === '' || $aba === 'todas' || $aba === 'todos') {
        return null;
    }
    if ($aba === 'pendentes') {
        return 'Pendente';
    }
    if ($aba === 'aprovados') {
        return 'Aprovado';
    }
    if ($aba === 'rejeitados') {
        return 'Rejeitado';
    }

    return null;
}

function medicao_custo_status_badge_class(string $status): string
{
    $map = [
        'Pendente'  => 'waiting medicao-custo-status--pendente',
        'Aprovado'  => 'done medicao-custo-status--aprovado',
        'Rejeitado' => 'urgent medicao-custo-status--rejeitado',
    ];

    return $map[$status] ?? (function_exists('status_class') ? status_class($status) : 'plain');
}

/**
 * @return array{rows:list<array<string,mixed>>,totais:array{n_total:int,n_pendente:int,n_aprovado:int,n_rejeitado:int,valor_aprovado:float,valor_pendente:float,valor_rejeitado:float}}
 */
function repo_medicao_custos_list(int $matrizId, string $refYm, ?string $statusFiltro = null): array
{
    $empty = [
        'rows'   => [],
        'totais' => [
            'n_total'        => 0,
            'n_pendente'     => 0,
            'n_aprovado'     => 0,
            'n_rejeitado'    => 0,
            'valor_aprovado' => 0.0,
            'valor_pendente' => 0.0,
            'valor_rejeitado'=> 0.0,
        ],
    ];
    if (!repo_medicao_custos_table_exists() || $matrizId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $refYm)) {
        return $empty;
    }
    $pdo = db();
    if (!$pdo) {
        return $empty;
    }

    $sql = "
        SELECT c.*,
               DATE_FORMAT(c.criado_em, '%d/%m/%Y %H:%i') AS criado_em_br,
               DATE_FORMAT(c.aprovado_em, '%d/%m/%Y %H:%i') AS aprovado_em_br,
               DATE_FORMAT(c.rejeitado_em, '%d/%m/%Y %H:%i') AS rejeitado_em_br,
               u.nome AS criado_por_nome
        FROM medicao_custos c
        LEFT JOIN usuarios u ON u.id = c.criado_por_user_id
        WHERE c.cliente_matriz_id = ? AND c.ref_ym = ?
    ";
    $params = [$matrizId, $refYm];
    if ($statusFiltro !== null && in_array($statusFiltro, repo_medicao_custo_status_list(), true)) {
        $sql .= ' AND c.status = ?';
        $params[] = $statusFiltro;
    }
    $sql .= ' ORDER BY c.criado_em DESC, c.id DESC';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $totais = [
        'n_total'         => 0,
        'n_pendente'      => 0,
        'n_aprovado'      => 0,
        'n_rejeitado'     => 0,
        'valor_aprovado'  => 0.0,
        'valor_pendente'  => 0.0,
        'valor_rejeitado' => 0.0,
    ];

    $stTot = $pdo->prepare('
        SELECT status, COUNT(*) AS n, COALESCE(SUM(valor_total), 0) AS v
        FROM medicao_custos
        WHERE cliente_matriz_id = ? AND ref_ym = ?
        GROUP BY status
    ');
    $stTot->execute([$matrizId, $refYm]);
    foreach ($stTot->fetchAll(PDO::FETCH_ASSOC) ?: [] as $tr) {
        $stt = (string) ($tr['status'] ?? '');
        $n   = (int) ($tr['n'] ?? 0);
        $v   = (float) ($tr['v'] ?? 0);
        $totais['n_total'] += $n;
        if ($stt === 'Pendente') {
            $totais['n_pendente']     = $n;
            $totais['valor_pendente'] = $v;
        } elseif ($stt === 'Aprovado') {
            $totais['n_aprovado']     = $n;
            $totais['valor_aprovado'] = $v;
        } elseif ($stt === 'Rejeitado') {
            $totais['n_rejeitado']     = $n;
            $totais['valor_rejeitado'] = $v;
        }
    }

    foreach ($rows as &$r) {
        $r = repo_medicao_custo_row_normalizar($r);
    }
    unset($r);

    return ['rows' => $rows, 'totais' => $totais];
}

/**
 * @param array<string,mixed> $r
 * @return array<string,mixed>
 */
function repo_medicao_custo_row_normalizar(array $r): array
{
    $r['id']                 = (int) ($r['id'] ?? 0);
    $r['cliente_matriz_id']  = (int) ($r['cliente_matriz_id'] ?? 0);
    $r['item_id']            = isset($r['item_id']) && $r['item_id'] !== null ? (int) $r['item_id'] : null;
    $r['quantidade']         = (float) ($r['quantidade'] ?? 0);
    $r['valor_unitario']     = (float) ($r['valor_unitario'] ?? 0);
    $r['valor_total']        = (float) ($r['valor_total'] ?? 0);
    $r['criado_por_user_id'] = isset($r['criado_por_user_id']) && $r['criado_por_user_id'] !== null
        ? (int) $r['criado_por_user_id'] : null;

    return $r;
}

/**
 * @return array<string,mixed>|null
 */
function repo_medicao_custo(int $id, int $matrizId): ?array
{
    if (!repo_medicao_custos_table_exists() || $id <= 0 || $matrizId <= 0) {
        return null;
    }
    $pdo = db();
    if (!$pdo) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM medicao_custos WHERE id = ? AND cliente_matriz_id = ? LIMIT 1');
    $st->execute([$id, $matrizId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);

    return $r ? repo_medicao_custo_row_normalizar($r) : null;
}

/**
 * @param array<string,mixed> $d
 * @return array{ok:bool, err:string, id?:int}
 */
function repo_medicao_custo_validar_payload(array $d, int $matrizId): array
{
    if ($matrizId <= 0) {
        return ['ok' => false, 'err' => 'Empresa inválida.'];
    }
    $refYm = trim((string) ($d['ref_ym'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $refYm)) {
        return ['ok' => false, 'err' => 'Mês de referência inválido.'];
    }
    $descricao = trim((string) ($d['descricao'] ?? ''));
    if ($descricao === '') {
        return ['ok' => false, 'err' => 'Informe a descrição do custo.'];
    }
    if (function_exists('mb_strlen') && mb_strlen($descricao, 'UTF-8') > 255) {
        return ['ok' => false, 'err' => 'Descrição muito longa (máx. 255 caracteres).'];
    }
    $qtd = (float) str_replace(',', '.', (string) ($d['quantidade'] ?? '1'));
    if ($qtd <= 0) {
        return ['ok' => false, 'err' => 'Quantidade deve ser maior que zero.'];
    }
    $vu = (float) str_replace(',', '.', (string) ($d['valor_unitario'] ?? '0'));
    if ($vu < 0) {
        return ['ok' => false, 'err' => 'Valor unitário inválido.'];
    }
    $vt = round($qtd * $vu, 4);
    if ($vt <= 0) {
        return ['ok' => false, 'err' => 'Valor total deve ser maior que zero.'];
    }
    $itemId = (int) ($d['item_id'] ?? 0);
    if ($itemId <= 0) {
        return ['ok' => false, 'err' => 'Selecione um item do catálogo.'];
    }
    if (!function_exists('repo_cliente_item_por_id')) {
        return ['ok' => false, 'err' => 'Catálogo indisponível.'];
    }
    $item = repo_cliente_item_por_id($itemId, $matrizId);
    if (!$item) {
        return ['ok' => false, 'err' => 'Item do catálogo não encontrado nesta empresa.'];
    }
    $codigo = trim((string) ($d['item_codigo'] ?? ''));
    if ($codigo !== '' && strlen($codigo) > 32) {
        return ['ok' => false, 'err' => 'Código muito longo (máx. 32).'];
    }
    $unidade = trim((string) ($d['unidade'] ?? 'UN'));
    if ($unidade === '') {
        $unidade = 'UN';
    }

    return [
        'ok'  => true,
        'err' => '',
        'payload' => [
            'ref_ym'          => $refYm,
            'item_id'         => $itemId > 0 ? $itemId : null,
            'item_codigo'     => $codigo,
            'descricao'       => $descricao,
            'unidade'         => $unidade,
            'quantidade'      => $qtd,
            'valor_unitario'  => $vu,
            'valor_total'     => $vt,
            'observacao'      => trim((string) ($d['observacao'] ?? '')) ?: null,
        ],
    ];
}

/**
 * @param array<string,mixed> $d
 * @return array{ok:bool, err:string, custo?:array<string,mixed>}
 */
function repo_medicao_custo_criar(int $matrizId, array $d, int $userId): array
{
    if (!repo_medicao_custos_table_exists()) {
        return ['ok' => false, 'err' => 'Funcionalidade indisponível (execute a migration 054).'];
    }
    $val = repo_medicao_custo_validar_payload($d, $matrizId);
    if (!$val['ok']) {
        return ['ok' => false, 'err' => $val['err']];
    }
    /** @var array<string,mixed> $p */
    $p = $val['payload'];
    if ($p['item_id'] !== null && $p['item_codigo'] === '' && function_exists('repo_cliente_item_por_id')) {
        $item = repo_cliente_item_por_id((int) $p['item_id'], $matrizId);
        if ($item) {
            $p['item_codigo'] = trim((string) ($item['codigo'] ?? ''));
            if (trim((string) ($p['unidade'] ?? '')) === 'UN' && trim((string) ($item['unidade'] ?? '')) !== '') {
                $p['unidade'] = (string) $item['unidade'];
            }
        }
    }
    if ($p['item_codigo'] === '') {
        $p['item_codigo'] = 'CUSTO';
    }

    $pdo = db();
    if (!$pdo) {
        return ['ok' => false, 'err' => 'Banco indisponível.'];
    }
    $st = $pdo->prepare('
        INSERT INTO medicao_custos (
            cliente_matriz_id, ref_ym, item_id, item_codigo, descricao, unidade,
            quantidade, valor_unitario, valor_total, status, observacao, criado_por_user_id
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
    ');
    $ok = $st->execute([
        $matrizId,
        $p['ref_ym'],
        $p['item_id'],
        $p['item_codigo'],
        $p['descricao'],
        $p['unidade'],
        $p['quantidade'],
        $p['valor_unitario'],
        $p['valor_total'],
        'Pendente',
        $p['observacao'],
        $userId > 0 ? $userId : null,
    ]);
    if (!$ok) {
        return ['ok' => false, 'err' => 'Não foi possível gravar o custo.'];
    }
    $id = (int) $pdo->lastInsertId();
    $custo = repo_medicao_custo($id, $matrizId);
    medicao_custo_notificar_clientes_pendentes($matrizId, $p['ref_ym'], $id);

    return ['ok' => true, 'err' => '', 'custo' => $custo];
}

/**
 * @param array<string,mixed> $d
 * @return array{ok:bool, err:string, custo?:array<string,mixed>}
 */
function repo_medicao_custo_atualizar(int $id, int $matrizId, array $d, int $userId): array
{
    if (!repo_medicao_custos_table_exists()) {
        return ['ok' => false, 'err' => 'Funcionalidade indisponível.'];
    }
    $atual = repo_medicao_custo($id, $matrizId);
    if (!$atual) {
        return ['ok' => false, 'err' => 'Custo não encontrado.'];
    }
    $stAtual = (string) ($atual['status'] ?? '');
    if (!in_array($stAtual, ['Pendente', 'Rejeitado'], true)) {
        return ['ok' => false, 'err' => 'Só é possível editar custos pendentes ou rejeitados.'];
    }
    $d['ref_ym'] = (string) ($atual['ref_ym'] ?? $d['ref_ym'] ?? '');
    $val = repo_medicao_custo_validar_payload($d, $matrizId);
    if (!$val['ok']) {
        return ['ok' => false, 'err' => $val['err']];
    }
    /** @var array<string,mixed> $p */
    $p = $val['payload'];
    if ($p['item_id'] !== null && $p['item_codigo'] === '' && function_exists('repo_cliente_item_por_id')) {
        $item = repo_cliente_item_por_id((int) $p['item_id'], $matrizId);
        if ($item) {
            $p['item_codigo'] = trim((string) ($item['codigo'] ?? ''));
        }
    }
    if ($p['item_codigo'] === '') {
        $p['item_codigo'] = 'CUSTO';
    }

    $pdo = db();
    if (!$pdo) {
        return ['ok' => false, 'err' => 'Banco indisponível.'];
    }
    $st = $pdo->prepare('
        UPDATE medicao_custos SET
            item_id = ?, item_codigo = ?, descricao = ?, unidade = ?,
            quantidade = ?, valor_unitario = ?, valor_total = ?,
            status = \'Pendente\', observacao = ?,
            rejeitado_motivo = NULL, rejeitado_em = NULL, rejeitado_por_user_id = NULL,
            aprovado_em = NULL, aprovado_por_user_id = NULL
        WHERE id = ? AND cliente_matriz_id = ? AND status IN (\'Pendente\', \'Rejeitado\')
    ');
    $ok = $st->execute([
        $p['item_id'],
        $p['item_codigo'],
        $p['descricao'],
        $p['unidade'],
        $p['quantidade'],
        $p['valor_unitario'],
        $p['valor_total'],
        $p['observacao'],
        $id,
        $matrizId,
    ]);
    if (!$ok || $st->rowCount() < 1) {
        return ['ok' => false, 'err' => 'Não foi possível atualizar o custo.'];
    }
    $custo = repo_medicao_custo($id, $matrizId);
    if ($stAtual === 'Rejeitado') {
        medicao_custo_notificar_clientes_pendentes($matrizId, (string) ($atual['ref_ym'] ?? ''), $id);
    }

    return ['ok' => true, 'err' => '', 'custo' => $custo];
}

/**
 * @return array{ok:bool, err:string}
 */
function repo_medicao_custo_excluir(int $id, int $matrizId): array
{
    if (!repo_medicao_custos_table_exists()) {
        return ['ok' => false, 'err' => 'Funcionalidade indisponível.'];
    }
    $pdo = db();
    if (!$pdo) {
        return ['ok' => false, 'err' => 'Banco indisponível.'];
    }
    $st = $pdo->prepare('
        DELETE FROM medicao_custos
        WHERE id = ? AND cliente_matriz_id = ? AND status IN (\'Pendente\', \'Rejeitado\')
    ');

    return [
        'ok'  => $st->execute([$id, $matrizId]) && $st->rowCount() > 0,
        'err' => $st->rowCount() > 0 ? '' : 'Custo não encontrado ou não pode ser excluído.',
    ];
}

/**
 * @return array{ok:bool, err:string, custo?:array<string,mixed>}
 */
function repo_medicao_custo_cliente_aprovar(int $id, int $matrizId, int $userId): array
{
    if (!repo_medicao_custos_table_exists()) {
        return ['ok' => false, 'err' => 'Funcionalidade indisponível.'];
    }
    $atual = repo_medicao_custo($id, $matrizId);
    if (!$atual) {
        return ['ok' => false, 'err' => 'Custo não encontrado.'];
    }
    if (($atual['status'] ?? '') !== 'Pendente') {
        return ['ok' => false, 'err' => 'Este custo não aguarda aprovação.'];
    }
    $pdo = db();
    if (!$pdo) {
        return ['ok' => false, 'err' => 'Banco indisponível.'];
    }
    $st = $pdo->prepare('
        UPDATE medicao_custos SET
            status = \'Aprovado\',
            aprovado_em = NOW(), aprovado_por_user_id = ?,
            rejeitado_motivo = NULL, rejeitado_em = NULL, rejeitado_por_user_id = NULL
        WHERE id = ? AND cliente_matriz_id = ? AND status = \'Pendente\'
    ');
    $ok = $st->execute([$userId > 0 ? $userId : null, $id, $matrizId]);
    if (!$ok || $st->rowCount() < 1) {
        return ['ok' => false, 'err' => 'Não foi possível aprovar o custo.'];
    }

    return ['ok' => true, 'err' => '', 'custo' => repo_medicao_custo($id, $matrizId)];
}

/**
 * @return array{ok:bool, err:string, custo?:array<string,mixed>}
 */
function repo_medicao_custo_cliente_rejeitar(int $id, int $matrizId, int $userId, string $motivo): array
{
    if (!repo_medicao_custos_table_exists()) {
        return ['ok' => false, 'err' => 'Funcionalidade indisponível.'];
    }
    $motivo = trim($motivo);
    if ($motivo === '') {
        return ['ok' => false, 'err' => 'Informe o motivo da rejeição.'];
    }
    $atual = repo_medicao_custo($id, $matrizId);
    if (!$atual) {
        return ['ok' => false, 'err' => 'Custo não encontrado.'];
    }
    if (($atual['status'] ?? '') !== 'Pendente') {
        return ['ok' => false, 'err' => 'Este custo não aguarda aprovação.'];
    }
    $pdo = db();
    if (!$pdo) {
        return ['ok' => false, 'err' => 'Banco indisponível.'];
    }
    $st = $pdo->prepare('
        UPDATE medicao_custos SET
            status = \'Rejeitado\',
            rejeitado_motivo = ?, rejeitado_em = NOW(), rejeitado_por_user_id = ?,
            aprovado_em = NULL, aprovado_por_user_id = NULL
        WHERE id = ? AND cliente_matriz_id = ? AND status = \'Pendente\'
    ');
    $ok = $st->execute([$motivo, $userId > 0 ? $userId : null, $id, $matrizId]);
    if (!$ok || $st->rowCount() < 1) {
        return ['ok' => false, 'err' => 'Não foi possível rejeitar o custo.'];
    }

    return ['ok' => true, 'err' => '', 'custo' => repo_medicao_custo($id, $matrizId)];
}

/**
 * @return list<array<string,mixed>>
 */
function repo_medicao_custos_aprovados_bm(int $matrizId, string $refYm): array
{
    $pkg = repo_medicao_custos_list($matrizId, $refYm, 'Aprovado');

    return $pkg['rows'];
}

/** Soma dos custos adicionais aprovados no mês (compõem o BM). */
function repo_medicao_custos_valor_aprovado(int $matrizId, string $refYm): float
{
    if ($matrizId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $refYm)) {
        return 0.0;
    }
    $pkg = repo_medicao_custos_list($matrizId, $refYm, 'Aprovado');

    return round((float) ($pkg['totais']['valor_aprovado'] ?? 0), 2);
}

/**
 * @return list<array{id:int,nome:string,codigo:string,unidade:string,valor_unitario:float}>
 */
function repo_medicao_custo_buscar_itens_catalogo(int $matrizId, string $q, int $limit = 25): array
{
    if ($matrizId <= 0 || !function_exists('repo_cliente_itens_list')) {
        return [];
    }
    $q = trim($q);
    $limit = max(1, min(50, $limit));
    $itens = repo_cliente_itens_list($matrizId, true);
    $out   = [];
    $qLow  = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
    foreach ($itens as $it) {
        if ($q !== '') {
            $nome = function_exists('mb_strtolower')
                ? mb_strtolower((string) ($it['nome'] ?? ''), 'UTF-8')
                : strtolower((string) ($it['nome'] ?? ''));
            $cod  = function_exists('mb_strtolower')
                ? mb_strtolower((string) ($it['codigo'] ?? ''), 'UTF-8')
                : strtolower((string) ($it['codigo'] ?? ''));
            if (!str_contains($nome, $qLow) && !str_contains($cod, $qLow)) {
                continue;
            }
        }
        $tipo = strtolower(trim((string) ($it['tipo'] ?? 'produto')));
        $tipoLabel = $tipo === 'servico' ? 'Serviço' : 'Produto';
        $out[] = [
            'id'             => (int) ($it['id'] ?? 0),
            'nome'           => (string) ($it['nome'] ?? ''),
            'codigo'         => (string) ($it['codigo'] ?? ''),
            'unidade'        => (string) ($it['unidade'] ?? 'UN'),
            'valor_unitario' => (float) ($it['valor_unitario'] ?? 0),
            'tipo'           => $tipo,
            'tipo_label'     => $tipoLabel,
        ];
        if (count($out) >= $limit) {
            break;
        }
    }

    return $out;
}

/** Quantidade para exibição (até 4 decimais, sem zeros à direita). */
function medicao_custo_fmt_quantidade(float $valor): string
{
    $fmt = number_format($valor, 4, ',', '.');

    return rtrim(rtrim($fmt, '0'), ',');
}

function medicao_custo_bm_codigo_exibir(array $custo): string
{
    $cod = trim((string) ($custo['item_codigo'] ?? ''));
    if ($cod !== '' && strtoupper($cod) !== 'CUSTO') {
        return $cod;
    }

    return 'CUSTO-' . (int) ($custo['id'] ?? 0);
}

function medicao_custo_bm_key(int $custoId, string $itemCodigo, int $itemId = 0): string
{
    $cod = trim($itemCodigo);
    if ($cod !== '' && strtoupper($cod) !== 'CUSTO') {
        if (function_exists('medicao_bm_boletim_v2_key_from_cod')) {
            return medicao_bm_boletim_v2_key_from_cod($cod, $itemId);
        }

        return $cod;
    }
    if ($itemId > 0) {
        if (function_exists('medicao_bm_boletim_v2_key_from_cod')) {
            return medicao_bm_boletim_v2_key_from_cod('', $itemId);
        }

        return 'ID:' . $itemId;
    }
    if (function_exists('medicao_bm_boletim_v2_key_from_cod')) {
        return medicao_bm_boletim_v2_key_from_cod('CUSTO:' . $custoId);
    }

    return 'CUSTO:' . $custoId;
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function medicao_bm_boletim_v2_anexar_custos_aprovados(array $rows, float &$valorMedidoSomaTotal, int $matrizId, string $refYm): array
{
    if (!repo_medicao_custos_table_exists()) {
        return $rows;
    }
    $custos = repo_medicao_custos_aprovados_bm($matrizId, $refYm);
    if ($custos === []) {
        return $rows;
    }

    $periodoPhp = strtotime($refYm . '-01 12:00:00');
    $dataExcel  = null;
    if ($periodoPhp !== false && class_exists('PhpOffice\\PhpSpreadsheet\\Shared\\Date')) {
        $dataExcel = \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($periodoPhp);
    }

    $keyStringFn = function_exists('medicao_bm_boletim_key_string')
        ? 'medicao_bm_boletim_key_string'
        : static fn (mixed $k): string => (string) $k;

    /** @var array<string, int> $idxPorKey */
    $idxPorKey = [];
    foreach ($rows as $i => $row) {
        $idxPorKey[$keyStringFn($row['_key'] ?? '')] = $i;
    }

    foreach ($custos as $c) {
        $id      = (int) ($c['id'] ?? 0);
        $itemId  = (int) ($c['item_id'] ?? 0);
        $qtd     = (float) ($c['quantidade'] ?? 0);
        $mv      = (float) ($c['valor_total'] ?? 0);
        if ($mv <= 0.0 && $qtd <= 0.0) {
            continue;
        }
        $k       = medicao_custo_bm_key($id, (string) ($c['item_codigo'] ?? ''), $itemId);
        $kStr    = $keyStringFn($k);
        $cod     = medicao_custo_bm_codigo_exibir($c);
        $nome    = trim((string) ($c['descricao'] ?? ''));
        if ($nome === '') {
            $nome = 'Custo adicional';
        }
        $unidade = trim((string) ($c['unidade'] ?? 'UN')) ?: 'UN';

        $valorMedidoSomaTotal += $mv;

        if (isset($idxPorKey[$kStr])) {
            $i = $idxPorKey[$kStr];
            $vqBase = (float) ($rows[$i]['_saldo_exec_v'] ?? 0) + (float) ($rows[$i]['_val_acum'] ?? 0);
            $rows[$i]['_medido_q']        = (float) ($rows[$i]['_medido_q'] ?? 0) + $qtd;
            $rows[$i]['_val_med_periodo'] = (float) ($rows[$i]['_val_med_periodo'] ?? 0) + $mv;
            $rows[$i]['_acum_q']          = (float) ($rows[$i]['_medido_q'] ?? 0) + (float) ($rows[$i]['_soma_bm_q'] ?? 0);
            $rows[$i]['_val_acum']        = (float) ($rows[$i]['_val_med_periodo'] ?? 0) + (float) ($rows[$i]['_val_acum_ant'] ?? 0);
            $rows[$i]['_saldo_exec_q']    = (float) ($rows[$i]['_estoque_saldo'] ?? 0) - (float) ($rows[$i]['_acum_q'] ?? 0);
            $rows[$i]['_saldo_exec_v']    = $vqBase - (float) ($rows[$i]['_val_acum'] ?? 0);
            if (abs($vqBase) > 1e-12) {
                $rows[$i]['_dev_fin_ratio'] = (float) ($rows[$i]['_val_acum'] ?? 0) / $vqBase;
            }
            continue;
        }

        $rows[] = [
            '_key'              => $k,
            '_codigo_display'   => $cod,
            '_nome'             => $nome . ' (custo adicional)',
            '_unidade'          => $unidade,
            '_estoque_saldo'    => 0.0,
            '_data_ini_excel'   => $dataExcel,
            '_soma_bm_q'        => 0.0,
            '_medido_q'         => $qtd,
            '_acum_q'           => $qtd,
            '_saldo_exec_q'     => 0.0,
            '_val_acum_ant'     => 0.0,
            '_val_med_periodo'  => $mv,
            '_val_acum'         => $mv,
            '_saldo_exec_v'     => 0.0,
            '_dev_fin_ratio'    => null,
        ];
        $idxPorKey[$kStr] = count($rows) - 1;
    }

    return $rows;
}

function medicao_custo_notificar_clientes_pendentes(int $matrizId, string $refYm, int $custoId): void
{
    if (!function_exists('repo_notificacao_insert') || !function_exists('repo_usuarios_por_cliente')) {
        return;
    }
    if (!repo_notificacoes_table_exists()) {
        return;
    }
    $chamadoId = medicao_custo_placeholder_chamado_id($matrizId);
    if ($chamadoId <= 0) {
        return;
    }
    $mesLabel = function_exists('medicao_mes_label_pt') ? medicao_mes_label_pt($refYm) : $refYm;
    $titulo   = 'Custo adicional pendente · ' . $refYm;
    $desc     = 'Há um custo pendente de aprovação na medição de ' . $mesLabel . ' (' . $refYm . ').';
    $usuarios = repo_usuarios_por_cliente($matrizId);
    foreach ($usuarios as $u) {
        if (strtolower((string) ($u['perfil'] ?? '')) !== 'cliente') {
            continue;
        }
        $uid = (int) ($u['id'] ?? 0);
        if ($uid > 0) {
            repo_notificacao_insert($uid, $chamadoId, null, $titulo, $desc, 'medicao_custo_pendente');
        }
    }
}

function medicao_custo_placeholder_chamado_id(int $matrizId): int
{
    $pdo = db();
    if (!$pdo || $matrizId <= 0) {
        return 0;
    }
    try {
        $st = $pdo->prepare('
            SELECT id FROM chamados
            WHERE cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
            ORDER BY id DESC LIMIT 1
        ');
        $st->execute([$matrizId, $matrizId]);
        $id = (int) ($st->fetchColumn() ?: 0);

        return $id;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * @param array<string,mixed> $custo
 * @return array<string,mixed>
 */
function medicao_custo_para_json(array $custo): array
{
    return [
        'id'              => (int) ($custo['id'] ?? 0),
        'item_id'         => $custo['item_id'] ?? null,
        'item_codigo'     => (string) ($custo['item_codigo'] ?? ''),
        'descricao'       => (string) ($custo['descricao'] ?? ''),
        'unidade'         => (string) ($custo['unidade'] ?? 'UN'),
        'quantidade'      => (float) ($custo['quantidade'] ?? 0),
        'valor_unitario'  => (float) ($custo['valor_unitario'] ?? 0),
        'valor_total'     => (float) ($custo['valor_total'] ?? 0),
        'status'          => (string) ($custo['status'] ?? ''),
        'observacao'      => (string) ($custo['observacao'] ?? ''),
        'rejeitado_motivo'=> (string) ($custo['rejeitado_motivo'] ?? ''),
        'criado_em_br'    => (string) ($custo['criado_em_br'] ?? ''),
        'aprovado_em_br'  => (string) ($custo['aprovado_em_br'] ?? ''),
        'rejeitado_em_br' => (string) ($custo['rejeitado_em_br'] ?? ''),
        'criado_por_nome' => (string) ($custo['criado_por_nome'] ?? ''),
    ];
}
