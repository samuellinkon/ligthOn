<?php
/**
 * Repositório: OS com aprovação do cliente (tabela os_pedidos — não confundir com a tabela legada `os`).
 */

require_once __DIR__ . '/db.php';
// Funções de cliente/catálogo vêm de repository.php (carregar antes ou via require no final de repository).

/**
 * @return list<string>
 */
function repo_os_pedido_status_list(): array
{
    return ['Rascunho', 'Enviada ao cliente', 'Aprovada', 'Rejeitada', 'Concluida', 'Cancelada'];
}

/**
 * @return array<string,mixed>|null
 */
function repo_os_pedido(int $id): ?array
{
    $pdo = db();
    if (!$pdo || $id <= 0) {
        return null;
    }
    $st = $pdo->prepare('
        SELECT p.*, c.empresa AS cliente_empresa, c.nome AS cliente_nome
        FROM os_pedidos p
        INNER JOIN clientes c ON c.id = p.cliente_id
        WHERE p.id = ?
    ');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        return null;
    }
    $r['id']            = (int) $r['id'];
    $r['cliente_id']    = (int) $r['cliente_id'];
    $r['servico_id']    = $r['servico_id'] !== null ? (int) $r['servico_id'] : null;
    $r['criado_por_user_id'] = $r['criado_por_user_id'] !== null ? (int) $r['criado_por_user_id'] : null;
    if ($r['latitude'] !== null) {
        $r['latitude'] = (float) $r['latitude'];
    }
    if ($r['longitude'] !== null) {
        $r['longitude'] = (float) $r['longitude'];
    }

    return $r;
}

/**
 * @return list<array<string,mixed>>
 */
function repo_os_pedido_list_empresa_mes(int $empresaRaizId, string $refYm, ?string $q = null, ?string $status = null): array
{
    if ($empresaRaizId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $refYm)) {
        return [];
    }
    $pdo = db();
    if (!$pdo) {
        return [];
    }
    $sql = "
        SELECT p.*, c.empresa AS cliente_empresa, c.nome AS cliente_nome,
               COALESCE(lin.vsub, 0) AS valor_itens
        FROM os_pedidos p
        INNER JOIN clientes c ON c.id = p.cliente_id
        LEFT JOIN (
            SELECT os_pedido_id, SUM(subtotal) AS vsub
            FROM os_pedido_itens
            WHERE movimento = 'utilizado'
            GROUP BY os_pedido_id
        ) lin ON lin.os_pedido_id = p.id
        WHERE p.ref_ym = ?
          AND p.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
    ";
    $params = [$refYm, $empresaRaizId, $empresaRaizId];
    if ($q !== null && $q !== '') {
        $sql .= " AND (p.titulo LIKE ? OR c.empresa LIKE ? OR c.nome LIKE ?)";
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if ($status === 'Aprovada' || $status === 'Rejeitada') {
        $sql .= ' AND p.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY p.aberto_em DESC, p.id DESC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id']           = (int) $r['id'];
        $r['cliente_id']   = (int) $r['cliente_id'];
        $r['valor_itens']  = (float) ($r['valor_itens'] ?? 0);
    }
    unset($r);

    return $rows;
}

/**
 * Indicadores do mês (contagens e soma de itens "utilizado" por status).
 *
 * @return array{n_total:int,n_aprovada:int,n_rejeitada:int,valor_total:float,valor_aprovadas:float,valor_rejeitadas:float}
 */
function repo_os_pedido_resumo_mes(int $empresaRaizId, string $refYm): array
{
    $vazio = [
        'n_total'          => 0,
        'n_aprovada'       => 0,
        'n_rejeitada'      => 0,
        'valor_total'      => 0.0,
        'valor_aprovadas'  => 0.0,
        'valor_rejeitadas' => 0.0,
    ];
    if ($empresaRaizId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $refYm)) {
        return $vazio;
    }
    $pdo = db();
    if (!$pdo) {
        return $vazio;
    }
    try {
        $sql = "
            SELECT
                COUNT(*) AS n_total,
                SUM(CASE WHEN p.status = 'Aprovada' THEN 1 ELSE 0 END) AS n_aprovada,
                SUM(CASE WHEN p.status = 'Rejeitada' THEN 1 ELSE 0 END) AS n_rejeitada,
                COALESCE(SUM(COALESCE(lin.vsub, 0)), 0) AS valor_total,
                COALESCE(SUM(CASE WHEN p.status = 'Aprovada' THEN COALESCE(lin.vsub, 0) ELSE 0 END), 0) AS valor_aprovadas,
                COALESCE(SUM(CASE WHEN p.status = 'Rejeitada' THEN COALESCE(lin.vsub, 0) ELSE 0 END), 0) AS valor_rejeitadas
            FROM os_pedidos p
            LEFT JOIN (
                SELECT os_pedido_id, SUM(subtotal) AS vsub
                FROM os_pedido_itens
                WHERE movimento = 'utilizado'
                GROUP BY os_pedido_id
            ) lin ON lin.os_pedido_id = p.id
            WHERE p.ref_ym = ?
              AND p.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
        ";
        $st = $pdo->prepare($sql);
        $st->execute([$refYm, $empresaRaizId, $empresaRaizId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $vazio;
        }

        return [
            'n_total'          => (int) ($row['n_total'] ?? 0),
            'n_aprovada'       => (int) ($row['n_aprovada'] ?? 0),
            'n_rejeitada'      => (int) ($row['n_rejeitada'] ?? 0),
            'valor_total'      => (float) ($row['valor_total'] ?? 0),
            'valor_aprovadas'  => (float) ($row['valor_aprovadas'] ?? 0),
            'valor_rejeitadas' => (float) ($row['valor_rejeitadas'] ?? 0),
        ];
    } catch (Throwable $e) {
        return $vazio;
    }
}

/**
 * Resumo por mês (mesma ideia que medição).
 *
 * @return list<array{ym:string,n_os:int,valor_total:float}>
 */
function repo_os_pedido_meses_resumo(int $empresaRaizId, int $limite = 60): array
{
    if ($empresaRaizId <= 0) {
        return [];
    }
    $pdo = db();
    if (!$pdo) {
        return [];
    }
    $limite = max(1, min(120, $limite));
    try {
        $sql = "
            SELECT
                p.ref_ym AS ym,
                COUNT(DISTINCT p.id) AS n_os,
                COALESCE(SUM(lin.vsub), 0) AS valor_total
            FROM os_pedidos p
            LEFT JOIN (
                SELECT os_pedido_id, SUM(subtotal) AS vsub
                FROM os_pedido_itens
                WHERE movimento = 'utilizado'
                GROUP BY os_pedido_id
            ) lin ON lin.os_pedido_id = p.id
            WHERE p.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
            GROUP BY p.ref_ym
            ORDER BY p.ref_ym DESC
            LIMIT " . (int) $limite;
        $st = $pdo->prepare($sql);
        $st->execute([$empresaRaizId, $empresaRaizId]);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $ym = (string) ($row['ym'] ?? '');
            if ($ym === '' || !preg_match('/^\d{4}-\d{2}$/', $ym)) {
                continue;
            }
            $out[] = [
                'ym'          => $ym,
                'n_os'        => (int) ($row['n_os'] ?? 0),
                'valor_total' => (float) ($row['valor_total'] ?? 0),
            ];
        }

        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @param array<string,mixed> $d
 */
function repo_os_pedido_criar(array $d): ?int
{
    $pdo = db();
    if (!$pdo) {
        return null;
    }
    $refYm = trim((string) ($d['ref_ym'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $refYm)) {
        $refYm = date('Y-m');
    }
    $cid = (int) ($d['cliente_id'] ?? 0);
    if ($cid <= 0) {
        return null;
    }
    $end = trim((string) ($d['endereco_completo'] ?? ''));
    $la  = $d['latitude']  ?? null;
    $lo  = $d['longitude'] ?? null;
    $laF = $la !== null && $la !== '' ? (float) str_replace(',', '.', (string) $la) : null;
    $loF = $lo !== null && $lo !== '' ? (float) str_replace(',', '.', (string) $lo) : null;

    $st = $pdo->prepare('
        INSERT INTO os_pedidos
            (cliente_id, ref_ym, titulo, descricao, endereco_completo, latitude, longitude, servico_id, prioridade, status, responsavel, criado_por_user_id)
        VALUES (?,?,?,?,?,?,?,?,?,\'Rascunho\',?,?)
    ');
    $st->execute([
        $cid,
        $refYm,
        (string) ($d['titulo'] ?? ''),
        trim((string) ($d['descricao'] ?? '')) !== '' ? trim((string) $d['descricao']) : null,
        $end !== '' ? $end : null,
        $laF,
        $loF,
        !empty($d['servico_id']) ? (int) $d['servico_id'] : null,
        (string) ($d['prioridade'] ?? 'Normal'),
        trim((string) ($d['responsavel'] ?? '')) !== '' ? trim((string) $d['responsavel']) : null,
        !empty($d['criado_por_user_id']) ? (int) $d['criado_por_user_id'] : null,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * @param array<string,mixed> $d
 * @return array{ok:bool, err:string}
 */
function repo_os_pedido_atualizar_rascunho(int $id, array $d): array
{
    $pdo = db();
    if (!$pdo || $id <= 0) {
        return ['ok' => false, 'err' => 'Dados inválidos.'];
    }
    $p = repo_os_pedido($id);
    if (!$p) {
        return ['ok' => false, 'err' => 'OS não encontrada.'];
    }
    $stAt = (string) ($p['status'] ?? '');
    if (!in_array($stAt, ['Rascunho', 'Rejeitada'], true)) {
        return ['ok' => false, 'err' => 'Só é possível editar em Rascunho ou após rejeição.'];
    }
    $refYm = trim((string) ($d['ref_ym'] ?? $p['ref_ym'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $refYm)) {
        $refYm = (string) $p['ref_ym'];
    }
    $end = trim((string) ($d['endereco_completo'] ?? ''));
    $la  = $d['latitude']  ?? $p['latitude'] ?? null;
    $lo  = $d['longitude'] ?? $p['longitude'] ?? null;
    $laF = $la !== null && $la !== '' ? (float) str_replace(',', '.', (string) $la) : null;
    $loF = $lo !== null && $lo !== '' ? (float) str_replace(',', '.', (string) $lo) : null;

    $sid = !empty($d['servico_id']) ? (int) $d['servico_id'] : null;
    if (array_key_exists('servico_id', $d) && (int) $d['servico_id'] <= 0) {
        $sid = null;
    }
    $st = $pdo->prepare('
        UPDATE os_pedidos SET
            ref_ym = ?,
            titulo = ?,
            descricao = ?,
            endereco_completo = ?,
            latitude = ?,
            longitude = ?,
            servico_id = ?,
            prioridade = ?,
            responsavel = ?
        WHERE id = ?
    ');
    $ok = $st->execute([
        $refYm,
        (string) ($d['titulo'] ?? $p['titulo']),
        trim((string) ($d['descricao'] ?? '')) !== '' ? trim((string) $d['descricao']) : null,
        $end !== '' ? $end : null,
        $laF,
        $loF,
        $sid,
        (string) ($d['prioridade'] ?? 'Normal'),
        trim((string) ($d['responsavel'] ?? '')) !== '' ? trim((string) $d['responsavel']) : null,
        $id,
    ]);

    return ['ok' => (bool) $ok, 'err' => $ok ? '' : 'Falha ao salvar.'];
}

/**
 * @return array{ok:bool, err:string}
 */
function repo_os_pedido_enviar_cliente(int $id): array
{
    $pdo = db();
    if (!$pdo || $id <= 0) {
        return ['ok' => false, 'err' => 'Dados inválidos.'];
    }
    $p = repo_os_pedido($id);
    if (!$p) {
        return ['ok' => false, 'err' => 'OS não encontrada.'];
    }
    if (!in_array($p['status'] ?? '', ['Rascunho', 'Rejeitada'], true)) {
        return ['ok' => false, 'err' => 'Status não permite envio.'];
    }
    $st = $pdo->prepare("UPDATE os_pedidos SET status = 'Enviada ao cliente', enviada_cliente_em = NOW() WHERE id = ? AND status IN ('Rascunho','Rejeitada')");

    return ['ok' => (bool) $st->execute([$id]), 'err' => ''];
}

/**
 * @return array{ok:bool, err:string}
 */
function repo_os_pedido_marcar_concluida(int $id): array
{
    $pdo = db();
    if (!$pdo || $id <= 0) {
        return ['ok' => false, 'err' => 'Dados inválidos.'];
    }
    $p = repo_os_pedido($id);
    if (!$p) {
        return ['ok' => false, 'err' => 'OS não encontrada.'];
    }
    if (($p['status'] ?? '') !== 'Aprovada') {
        return ['ok' => false, 'err' => 'Só é possível concluir após aprovação do cliente.'];
    }
    $st = $pdo->prepare("UPDATE os_pedidos SET status = 'Concluida' WHERE id = ? AND status = 'Aprovada'");

    return ['ok' => (bool) $st->execute([$id]), 'err' => ''];
}

/**
 * @return array{ok:bool, err:string}
 */
function repo_os_pedido_cancelar(int $id): array
{
    $pdo = db();
    if (!$pdo || $id <= 0) {
        return ['ok' => false, 'err' => 'Dados inválidos.'];
    }
    $p = repo_os_pedido($id);
    if (!$p) {
        return ['ok' => false, 'err' => 'OS não encontrada.'];
    }
    if (in_array($p['status'] ?? '', ['Concluida', 'Cancelada'], true)) {
        return ['ok' => false, 'err' => 'OS já finalizada.'];
    }
    $st = $pdo->prepare("UPDATE os_pedidos SET status = 'Cancelada' WHERE id = ?");

    return ['ok' => (bool) $st->execute([$id]), 'err' => ''];
}

/**
 * @return array{ok:bool, err:string}
 */
function repo_os_pedido_cliente_aprovar(int $id): array
{
    $pdo = db();
    if (!$pdo || $id <= 0) {
        return ['ok' => false, 'err' => 'Dados inválidos.'];
    }
    $p = repo_os_pedido($id);
    if (!$p) {
        return ['ok' => false, 'err' => 'OS não encontrada.'];
    }
    if (($p['status'] ?? '') !== 'Enviada ao cliente') {
        return ['ok' => false, 'err' => 'Esta OS não aguarda aprovação.'];
    }
    $st = $pdo->prepare("UPDATE os_pedidos SET status = 'Aprovada', aprovada_cliente_em = NOW(), rejeitada_motivo = NULL WHERE id = ? AND status = 'Enviada ao cliente'");

    return ['ok' => (bool) $st->execute([$id]), 'err' => ''];
}

/**
 * @return array{ok:bool, err:string}
 */
function repo_os_pedido_cliente_rejeitar(int $id, string $motivo): array
{
    $pdo = db();
    if (!$pdo || $id <= 0) {
        return ['ok' => false, 'err' => 'Dados inválidos.'];
    }
    $motivo = trim($motivo);
    if ($motivo === '') {
        return ['ok' => false, 'err' => 'Informe o motivo da rejeição.'];
    }
    $p = repo_os_pedido($id);
    if (!$p) {
        return ['ok' => false, 'err' => 'OS não encontrada.'];
    }
    if (($p['status'] ?? '') !== 'Enviada ao cliente') {
        return ['ok' => false, 'err' => 'Esta OS não aguarda aprovação.'];
    }
    $st = $pdo->prepare("UPDATE os_pedidos SET status = 'Rejeitada', rejeitada_motivo = ?, aprovada_cliente_em = NULL WHERE id = ? AND status = 'Enviada ao cliente'");

    return ['ok' => (bool) $st->execute([$motivo, $id]), 'err' => ''];
}

/**
 * @return list<array<string,mixed>>
 */
function repo_os_pedido_itens_list(int $osPedidoId): array
{
    $pdo = db();
    if (!$pdo || $osPedidoId <= 0) {
        return [];
    }
    $st = $pdo->prepare('
        SELECT ci.id, ci.os_pedido_id, ci.item_id, ci.movimento, ci.quantidade, ci.valor_unitario, ci.subtotal, ci.observacao,
               i.nome AS item_nome, i.tipo AS item_tipo, i.codigo AS item_codigo, i.unidade AS catalogo_unidade
        FROM os_pedido_itens ci
        INNER JOIN cliente_itens i ON i.id = ci.item_id
        WHERE ci.os_pedido_id = ?
        ORDER BY ci.id ASC
    ');
    $st->execute([$osPedidoId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id']            = (int) $r['id'];
        $r['os_pedido_id']  = (int) $r['os_pedido_id'];
        $r['item_id']       = (int) $r['item_id'];
        $r['quantidade']    = (float) $r['quantidade'];
        $r['valor_unitario'] = (float) $r['valor_unitario'];
        $r['subtotal']      = (float) $r['subtotal'];
    }
    unset($r);

    return $rows;
}

function repo_os_pedido_itens_valor_total(int $osPedidoId): float
{
    $pdo = db();
    if (!$pdo || $osPedidoId <= 0) {
        return 0.0;
    }
    $st = $pdo->prepare("SELECT COALESCE(SUM(subtotal),0) FROM os_pedido_itens WHERE os_pedido_id = ? AND movimento = 'utilizado'");
    $st->execute([$osPedidoId]);

    return (float) $st->fetchColumn();
}

/**
 * @return array{ok: bool, err: string}
 */
function repo_os_pedido_item_adicionar(int $osPedidoId, int $itemId, float $quantidade, string $movimento = 'utilizado', ?string $observacao = null): array
{
    $pdo = db();
    if (!$pdo || $osPedidoId <= 0 || $itemId <= 0 || $quantidade <= 0) {
        return ['ok' => false, 'err' => 'Dados inválidos.'];
    }
    $movimento = strtolower(trim($movimento));
    if (!in_array($movimento, ['utilizado', 'devolvido'], true)) {
        return ['ok' => false, 'err' => 'Movimento inválido.'];
    }
    $p = repo_os_pedido($osPedidoId);
    if (!$p) {
        return ['ok' => false, 'err' => 'OS não encontrada.'];
    }
    if (in_array($p['status'] ?? '', ['Concluida', 'Cancelada', 'Aprovada'], true)) {
        return ['ok' => false, 'err' => 'Não é possível alterar itens após aprovação ou encerramento.'];
    }
    $cid  = (int) $p['cliente_id'];
    $it   = repo_cliente_item_por_id($itemId, $cid);
    if (!$it || empty($it['ativo'])) {
        return ['ok' => false, 'err' => 'Item não disponível para este cliente.'];
    }
    $vu  = (float) ($it['valor_unitario'] ?? 0);
    $sub = round($quantidade * $vu, 4);
    try {
        $st = $pdo->prepare('
            INSERT INTO os_pedido_itens (os_pedido_id, item_id, movimento, quantidade, valor_unitario, subtotal, observacao)
            VALUES (?,?,?,?,?,?,?)
        ');
        $obs = trim((string) $observacao);
        $ok  = $st->execute([$osPedidoId, $itemId, $movimento, $quantidade, $vu, $sub, $obs !== '' ? $obs : null]);

        return ['ok' => (bool) $ok, 'err' => $ok ? '' : 'Não foi possível inserir.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'err' => $e->getMessage()];
    }
}

function repo_os_pedido_item_atualizar_quantidade(int $linhaId, int $osPedidoId, float $quantidade): bool
{
    $pdo = db();
    if (!$pdo || $linhaId <= 0 || $osPedidoId <= 0 || $quantidade <= 0) {
        return false;
    }
    $p = repo_os_pedido($osPedidoId);
    if ($p && in_array($p['status'] ?? '', ['Concluida', 'Cancelada', 'Aprovada'], true)) {
        return false;
    }
    $st = $pdo->prepare('SELECT valor_unitario FROM os_pedido_itens WHERE id = ? AND os_pedido_id = ?');
    $st->execute([$linhaId, $osPedidoId]);
    $vu = $st->fetchColumn();
    if ($vu === false) {
        return false;
    }
    $vuF = (float) $vu;
    $sub = round($quantidade * $vuF, 4);
    $up  = $pdo->prepare('UPDATE os_pedido_itens SET quantidade = ?, subtotal = ? WHERE id = ? AND os_pedido_id = ?');

    return (bool) $up->execute([$quantidade, $sub, $linhaId, $osPedidoId]);
}

function repo_os_pedido_item_remover(int $linhaId, int $osPedidoId): bool
{
    $pdo = db();
    if (!$pdo) {
        return false;
    }
    $p = repo_os_pedido($osPedidoId);
    if ($p && in_array($p['status'] ?? '', ['Concluida', 'Cancelada', 'Aprovada'], true)) {
        return false;
    }
    $st = $pdo->prepare('DELETE FROM os_pedido_itens WHERE id = ? AND os_pedido_id = ?');

    return (bool) $st->execute([$linhaId, $osPedidoId]);
}

/**
 * @return list<array<string,mixed>>
 */
function repo_os_pedido_respostas(int $osPedidoId, bool $somentePublicas): array
{
    $pdo = db();
    if (!$pdo || $osPedidoId <= 0) {
        return [];
    }
    $sql = '
        SELECT id, os_pedido_id, autor, tipo, texto, interna, DATE_FORMAT(enviado_em, "%Y-%m-%d %H:%i") AS data
        FROM os_pedido_respostas
        WHERE os_pedido_id = ?';
    if ($somentePublicas) {
        $sql .= ' AND (interna = 0 OR interna IS NULL)';
    }
    $sql .= ' ORDER BY enviado_em ASC, id ASC';
    $st  = $pdo->prepare($sql);
    $st->execute([$osPedidoId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id']           = (int) $r['id'];
        $r['os_pedido_id'] = (int) $r['os_pedido_id'];
        $r['interna']      = !empty($r['interna']);
    }
    unset($r);

    return $rows;
}

function repo_os_pedido_resposta_criar(int $osPedidoId, string $autor, string $tipo, string $texto, bool $interna = false): ?int
{
    $pdo = db();
    if (!$pdo) {
        return null;
    }
    $tipo = $tipo === 'cliente' ? 'cliente' : 'admin';
    $st   = $pdo->prepare('
        INSERT INTO os_pedido_respostas (os_pedido_id, autor, tipo, texto, interna, enviado_em)
        VALUES (?,?,?,?,?,NOW())
    ');
    $st->execute([$osPedidoId, $autor, $tipo, $texto, $interna ? 1 : 0]);

    return (int) $pdo->lastInsertId();
}

function repo_os_pedido_anexo_criar(array $d): ?int
{
    $pdo = db();
    if (!$pdo) {
        return null;
    }
    $st = $pdo->prepare('
        INSERT INTO os_pedido_anexos
            (os_pedido_id, resposta_id, nome_original, nome_arquivo, mime, tamanho, enviado_por, enviado_tipo)
        VALUES (?,?,?,?,?,?,?,?)
    ');
    $st->execute([
        (int) $d['os_pedido_id'],
        !empty($d['resposta_id']) ? (int) $d['resposta_id'] : null,
        $d['nome_original'],
        $d['nome_arquivo'],
        $d['mime'] ?? null,
        (int) ($d['tamanho'] ?? 0),
        $d['enviado_por']  ?? null,
        ($d['enviado_tipo'] ?? 'admin') === 'cliente' ? 'cliente' : 'admin',
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * @return list<array<string,mixed>>
 */
function repo_os_pedido_anexos(int $osPedidoId): array
{
    $pdo = db();
    if (!$pdo) {
        return [];
    }
    $st = $pdo->prepare('
        SELECT id, os_pedido_id, resposta_id, nome_original, nome_arquivo, mime, tamanho, enviado_por, enviado_tipo,
               DATE_FORMAT(enviado_em, "%Y-%m-%d %H:%i") AS enviado_em
        FROM os_pedido_anexos
        WHERE os_pedido_id = ?
        ORDER BY enviado_em DESC, id DESC
    ');
    $st->execute([$osPedidoId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id']           = (int) $r['id'];
        $r['os_pedido_id'] = (int) $r['os_pedido_id'];
        $r['resposta_id']  = $r['resposta_id'] !== null ? (int) $r['resposta_id'] : null;
        $r['tamanho']      = (int) $r['tamanho'];
    }
    unset($r);

    return $rows;
}

/**
 * @return array<string,mixed>|null
 */
function repo_os_pedido_anexo(int $anexoId): ?array
{
    $pdo = db();
    if (!$pdo) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM os_pedido_anexos WHERE id = ? LIMIT 1');
    $st->execute([$anexoId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        return null;
    }
    $r['id']            = (int) $r['id'];
    $r['os_pedido_id']  = (int) $r['os_pedido_id'];
    $r['resposta_id']   = $r['resposta_id'] !== null ? (int) $r['resposta_id'] : null;
    $r['tamanho']       = (int) $r['tamanho'];

    return $r;
}

function repo_os_pedido_anexo_excluir(int $anexoId, int $osPedidoId): ?array
{
    $pdo = db();
    if (!$pdo) {
        return null;
    }
    $a = repo_os_pedido_anexo($anexoId);
    if (!$a || (int) $a['os_pedido_id'] !== $osPedidoId) {
        return null;
    }
    $st = $pdo->prepare('DELETE FROM os_pedido_anexos WHERE id = ? AND os_pedido_id = ?');
    if (!$st->execute([$anexoId, $osPedidoId])) {
        return null;
    }

    return $a;
}

function repo_os_pedido_excluir(int $id): bool
{
    $pdo = db();
    if (!$pdo || $id <= 0) {
        return false;
    }
    $p = repo_os_pedido($id);
    if (!$p || ($p['status'] ?? '') !== 'Rascunho') {
        return false;
    }
    $st = $pdo->prepare('DELETE FROM os_pedidos WHERE id = ? AND status = \'Rascunho\'');

    return (bool) $st->execute([$id]);
}

/**
 * @return list<array<string,mixed>>
 */
function repo_os_pedido_cliente_lista(
    int $clientePortalId,
    ?string $refYm = null,
    ?string $q = null,
    ?string $dataDe = null,
    ?string $dataAte = null
): array
{
    if ($clientePortalId <= 0) {
        return [];
    }
    $raiz = repo_cliente_matriz_raiz_id($clientePortalId);
    if ($raiz <= 0) {
        $raiz = $clientePortalId;
    }
    $pdo = db();
    if (!$pdo) {
        return [];
    }
    $sql  = "
        SELECT p.*, c.empresa AS cliente_empresa
        FROM os_pedidos p
        INNER JOIN clientes c ON c.id = p.cliente_id
        WHERE p.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
    ";
    $par = [$raiz, $raiz];
    if ($refYm !== null && preg_match('/^\d{4}-\d{2}$/', $refYm)) {
        $sql  .= ' AND p.ref_ym = ?';
        $par[] = $refYm;
    }
    if ($dataDe !== null && $dataAte !== null
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDe)
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAte)
        && $dataDe <= $dataAte
    ) {
        $sql .= ' AND DATE(p.aberto_em) BETWEEN ? AND ?';
        $par[] = $dataDe;
        $par[] = $dataAte;
    }
    if ($q !== null && $q !== '') {
        $sql .= ' AND p.titulo LIKE ?';
        $par[] = '%' . $q . '%';
    }
    $sql .= ' ORDER BY p.ref_ym DESC, p.aberto_em DESC, p.id DESC';
    $st = $pdo->prepare($sql);
    $st->execute($par);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id']         = (int) $r['id'];
        $r['cliente_id'] = (int) $r['cliente_id'];
    }
    unset($r);

    return $rows;
}
