<?php
/**
 * Repository: funções que leem dados do banco e devolvem
 * arrays no MESMO formato dos mocks originais. Assim as páginas
 * existentes continuam funcionando sem qualquer alteração.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/upload.php';
require_once __DIR__ . '/chamado_os_fields.php';

/* ------------------------------------------------------------------ */
/*  CONFIG (app_config)                                                 */
/* ------------------------------------------------------------------ */

function repo_config_defaults(): array
{
    return [
        'debug_mode'         => '0',
        'clientes_modo'             => 'unico',
        'catalogo_cliente_padrao_id' => '',
        'mail_from'          => '',
        'mail_from_name'     => '',
        'smtp_enabled'       => '0',
        'smtp_host'          => '',
        'smtp_port'          => '587',
        'smtp_encryption'   => 'tls',
        'smtp_user'          => '',
        'smtp_password'      => '',
        'api_public_enabled' => '0',
        'api_public_key'     => '',
        'api_secret_hash'    => '',
    ];
}

/** @var array<string, string>|null */
$_repo_config_cache = null;

function repo_config_invalidate(): void
{
    global $_repo_config_cache;
    $_repo_config_cache = null;
}

function repo_config_all(): array
{
    global $_repo_config_cache;
    if ($_repo_config_cache !== null) {
        return $_repo_config_cache;
    }

    $out = repo_config_defaults();
    $pdo = db();
    if (!$pdo) {
        global $MOCK_APP_CONFIG;
        if (!empty($MOCK_APP_CONFIG) && is_array($MOCK_APP_CONFIG)) {
            $out = array_merge($out, $MOCK_APP_CONFIG);
        }
        $_repo_config_cache = $out;
        return $out;
    }
    try {
        $stmt = $pdo->query('SELECT chave, valor FROM app_config');
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $out[$row['chave']] = $row['valor'];
            }
        }
    } catch (Throwable $e) {
        /* tabela ainda não existe */
    }
    $_repo_config_cache = $out;
    return $out;
}

function repo_config_get(string $chave): string
{
    $all = repo_config_all();
    return (string) ($all[$chave] ?? '');
}

function repo_config_set(string $chave, ?string $valor): bool
{
    $pdo = db();
    if (!$pdo) {
        global $MOCK_APP_CONFIG;
        if (!isset($MOCK_APP_CONFIG) || !is_array($MOCK_APP_CONFIG)) {
            $MOCK_APP_CONFIG = repo_config_defaults();
        }
        $MOCK_APP_CONFIG[$chave] = $valor ?? '';
        repo_config_invalidate();
        return true;
    }
    try {
        $stmt = $pdo->prepare('
            INSERT INTO app_config (chave, valor) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE valor = VALUES(valor)
        ');
        $ok = $stmt->execute([$chave, $valor]);
        if ($ok) {
            repo_config_invalidate();
        }
        return $ok;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * PIX exibido na cobrança: apenas chave/tipo cadastrados na própria cobrança (conta).
 *
 * @return array{chave: string, tipo: string, origem: string}
 */
function repo_conta_pix_resolvido(array $conta): array
{
    $k = trim((string) ($conta['pix_chave'] ?? ''));
    $t = trim((string) ($conta['pix_tipo'] ?? ''));
    if ($k !== '') {
        return ['chave' => $k, 'tipo' => $t, 'origem' => 'cobrança'];
    }
    return ['chave' => '', 'tipo' => '', 'origem' => ''];
}

/* ------------------------------------------------------------------ */
/*  CLIENTES                                                          */
/* ------------------------------------------------------------------ */
function repo_clientes(): array
{
    $pdo = db();
    if (!$pdo) return [];

    $sql = "
        SELECT
            c.id, c.empresa_id, c.nome, c.empresa, c.email, c.telefone, c.doc, c.status,
            DATE_FORMAT(c.desde, '%Y-%m-%d') AS desde,
            c.obs,
            (SELECT COUNT(*) FROM chamados ch WHERE ch.cliente_id = c.id) AS chamados,
            (SELECT COUNT(*) FROM chamados ch
              WHERE ch.cliente_id = c.id
                AND ch.status IN ('Aberto','Em andamento','Aguardando')) AS pendentes
        FROM clientes c
        ORDER BY c.id
    ";

    $rows = $pdo->query($sql)->fetchAll();
    foreach ($rows as &$r) {
        $r['id']                = (int) $r['id'];
        $r['empresa_id']        = isset($r['empresa_id']) && $r['empresa_id'] !== null ? (int) $r['empresa_id'] : null;
        $r['chamados']          = (int) $r['chamados'];
        $r['pendentes']         = (int) $r['pendentes'];
    }
    return $rows;
}

function repo_cliente(int $id): ?array
{
    foreach (repo_clientes() as $c) {
        if ((int) $c['id'] === $id) return $c;
    }
    return null;
}

/**
 * Empresas raiz (sem matriz pai) — para vínculo de operador/gestor.
 *
 * @return list<array<string,mixed>>
 */
function repo_clientes_empresas(): array
{
    $pdo = db();
    if (!$pdo) {
        return [];
    }
    try {
        $rows = $pdo->query('
            SELECT id, empresa_id, nome, empresa, email, status
            FROM clientes
            WHERE empresa_id IS NULL
            ORDER BY empresa ASC, id ASC
        ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['id']         = (int) $r['id'];
            $r['empresa_id'] = isset($r['empresa_id']) && $r['empresa_id'] !== null ? (int) $r['empresa_id'] : null;
        }
        unset($r);

        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Contas cliente (raiz + filhas) sob uma empresa.
 *
 * @return list<array<string,mixed>>
 */
function repo_clientes_na_empresa(int $empresaRaizId): array
{
    $pdo = db();
    if (!$pdo || $empresaRaizId <= 0) {
        return [];
    }
    try {
        $st = $pdo->prepare('
            SELECT id, empresa_id, nome, empresa, email, status
            FROM clientes
            WHERE id = ? OR empresa_id = ?
            ORDER BY (empresa_id IS NULL) DESC, empresa ASC, id ASC
        ');
        $st->execute([$empresaRaizId, $empresaRaizId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['id']         = (int) $r['id'];
            $r['empresa_id'] = isset($r['empresa_id']) && $r['empresa_id'] !== null ? (int) $r['empresa_id'] : null;
        }
        unset($r);

        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * A linha `clientes.id` é a própria empresa raiz ou um cliente filho dela.
 */
function repo_cliente_pertence_empresa(int $clienteRowId, int $empresaRaizId): bool
{
    if ($clienteRowId <= 0 || $empresaRaizId <= 0) {
        return false;
    }
    if ($clienteRowId === $empresaRaizId) {
        return true;
    }
    $pdo = db();
    if (!$pdo) {
        return false;
    }
    try {
        $st = $pdo->prepare('
            SELECT 1 FROM clientes
            WHERE id = ? AND (empresa_id = ? OR id = ?)
            LIMIT 1
        ');
        $st->execute([$clienteRowId, $empresaRaizId, $empresaRaizId]);

        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * ID da empresa raiz (matriz) para uma linha de `clientes` (raiz ou unidade filha).
 */
function repo_cliente_matriz_raiz_id(int $clienteRowId): int
{
    if ($clienteRowId <= 0) {
        return 0;
    }
    $row = repo_cliente($clienteRowId);
    if (!$row) {
        return $clienteRowId;
    }
    $pai = isset($row['empresa_id']) && $row['empresa_id'] !== null && $row['empresa_id'] !== ''
        ? (int) $row['empresa_id'] : 0;

    return $pai > 0 ? $pai : $clienteRowId;
}

/**
 * Primeira empresa raiz (matriz) da instância — para modo "apenas 1 cliente" no admin.
 *
 * @return positive-int|null
 */
function repo_cliente_raiz_principal_id(): ?int
{
    $pdo = db();
    if (!$pdo) {
        return null;
    }
    try {
        $st = $pdo->query('SELECT id FROM clientes WHERE empresa_id IS NULL ORDER BY id ASC LIMIT 1');
        $id = $st ? $st->fetchColumn() : false;
        if ($id !== false && $id !== null && (int) $id > 0) {
            return (int) $id;
        }
        $st2 = $pdo->query('SELECT id FROM clientes ORDER BY id ASC LIMIT 1');
        $id2 = $st2 ? $st2->fetchColumn() : false;
        if ($id2 !== false && $id2 !== null && (int) $id2 > 0) {
            return (int) $id2;
        }
    } catch (Throwable $e) {
        return null;
    }

    return null;
}

/**
 * Dono do catálogo usado no admin quando não há cliente_id na URL (menu Catálogo, modo 1 cliente, etc.).
 * Usa app_config catalogo_cliente_padrao_id se for um cliente existente; senão a primeira matriz (raiz).
 *
 * @return positive-int|null
 */
function repo_catalogo_cliente_id_padrao_admin(): ?int
{
    $raw = trim((string) repo_config_get('catalogo_cliente_padrao_id'));
    if ($raw !== '' && ctype_digit($raw)) {
        $cid = (int) $raw;
        if ($cid > 0 && repo_cliente($cid)) {
            $dono = repo_cliente_catalogo_dono_id($cid);

            return $dono > 0 ? $dono : null;
        }
    }

    return repo_cliente_raiz_principal_id();
}

/* ------------------------------------------------------------------ */
/*  CHAMADOS                                                          */
/* ------------------------------------------------------------------ */

/**
 * @return array{0: ?float, 1: ?float}
 */
function _repo_parse_latlng_pair(?string $latStr, ?string $lngStr): array
{
    $latStr = $latStr !== null ? trim(str_replace(',', '.', $latStr)) : '';
    $lngStr = $lngStr !== null ? trim(str_replace(',', '.', $lngStr)) : '';
    if ($latStr === '' || $lngStr === '') {
        return [null, null];
    }
    if (!is_numeric($latStr) || !is_numeric($lngStr)) {
        return [null, null];
    }
    $la = (float) $latStr;
    $lo = (float) $lngStr;
    if ($la < -90.0 || $la > 90.0 || $lo < -180.0 || $lo > 180.0) {
        return [null, null];
    }
    return [$la, $lo];
}

function _repo_chamado_row_normalizar_geo(array &$r): void
{
    if (array_key_exists('latitude', $r)) {
        $r['latitude'] = ($r['latitude'] !== null && $r['latitude'] !== '')
            ? (float) $r['latitude'] : null;
    }
    if (array_key_exists('longitude', $r)) {
        $r['longitude'] = ($r['longitude'] !== null && $r['longitude'] !== '')
            ? (float) $r['longitude'] : null;
    }
}

/**
 * Chamados com coordenadas no intervalo de datas (aberto_em), para mapa.
 *
 * @return list<array{id:int,titulo:string,status:string,data:string,cliente:string,endereco_completo:?string,lat:float,lng:float}>
 */
function repo_chamados_mapa_pins(string $dataDe, string $dataAte, ?int $clienteId = null): array
{
    $pdo = db();
    if (!$pdo) {
        return [];
    }
    $de  = strlen($dataDe) === 10 ? $dataDe : date('Y-m-d', strtotime('-30 days'));
    $ate = strlen($dataAte) === 10 ? $dataAte : date('Y-m-d');
    if (strcmp($de, $ate) > 0) {
        $tmp = $de;
        $de  = $ate;
        $ate = $tmp;
    }
    try {
        if ($clienteId !== null && $clienteId > 0) {
            $clienteId = repo_cliente_matriz_raiz_id($clienteId);
        }
        $scopeSql = ($clienteId !== null && $clienteId > 0)
            ? ' AND ch.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?) '
            : '';
        $stmt = $pdo->prepare('
            SELECT
                ch.id,
                ch.titulo,
                ch.status,
                ch.latitude,
                ch.longitude,
                ch.endereco_completo,
                DATE_FORMAT(ch.aberto_em, \'%Y-%m-%d %H:%i\') AS data,
                c.empresa AS cliente
            FROM chamados ch
            JOIN clientes c ON c.id = ch.cliente_id
            WHERE ch.latitude IS NOT NULL
              AND ch.longitude IS NOT NULL
              AND DATE(ch.aberto_em) >= ?
              AND DATE(ch.aberto_em) <= ?
              ' . $scopeSql . '
            ORDER BY ch.aberto_em DESC
        ');
        $params = [$de, $ate];
        if ($clienteId !== null && $clienteId > 0) {
            $params[] = $clienteId;
            $params[] = $clienteId;
        }
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out  = [];
        foreach ($rows as $r) {
            $la = $r['latitude'] !== null && $r['latitude'] !== '' ? (float) $r['latitude'] : null;
            $lo = $r['longitude'] !== null && $r['longitude'] !== '' ? (float) $r['longitude'] : null;
            if ($la === null || $lo === null) {
                continue;
            }
            $out[] = [
                'id'                 => (int) $r['id'],
                'titulo'             => (string) ($r['titulo'] ?? ''),
                'status'             => (string) ($r['status'] ?? ''),
                'data'               => (string) ($r['data'] ?? ''),
                'cliente'            => (string) ($r['cliente'] ?? ''),
                'endereco_completo'  => isset($r['endereco_completo']) && $r['endereco_completo'] !== ''
                    ? (string) $r['endereco_completo'] : null,
                'lat'                => $la,
                'lng'                => $lo,
            ];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function _repo_ponto_iluminacao_normalizar(array &$r): void
{
    $r['id'] = (int) ($r['id'] ?? 0);
    $r['cliente_id'] = (int) ($r['cliente_id'] ?? 0);
    foreach (['latitude', 'longitude'] as $k) {
        if (array_key_exists($k, $r)) {
            $r[$k] = ($r[$k] !== null && $r[$k] !== '') ? (float) $r[$k] : null;
        }
    }
    foreach (['chamados_abertos', 'chamados_total'] as $k) {
        if (array_key_exists($k, $r)) {
            $r[$k] = (int) $r[$k];
        }
    }
}

function repo_ponto_iluminacao(int $id): ?array
{
    $pdo = db();
    if (!$pdo || $id <= 0) {
        return null;
    }
    try {
        $st = $pdo->prepare('
            SELECT pi.*, c.empresa AS cliente_empresa
            FROM pontos_iluminacao pi
            JOIN clientes c ON c.id = pi.cliente_id
            WHERE pi.id = ?
            LIMIT 1
        ');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return null;
        }
        _repo_ponto_iluminacao_normalizar($r);
        return $r;
    } catch (Throwable $e) {
        return null;
    }
}

function repo_ponto_iluminacao_pertence_cliente(int $pontoId, int $clienteId): bool
{
    $p = repo_ponto_iluminacao($pontoId);
    return $p && (int) ($p['cliente_id'] ?? 0) === $clienteId;
}

function repo_ponto_iluminacao_pertence_empresa(int $pontoId, int $empresaRaizId): bool
{
    $p = repo_ponto_iluminacao($pontoId);
    return $p && repo_cliente_pertence_empresa((int) ($p['cliente_id'] ?? 0), $empresaRaizId);
}

/**
 * @return list<array<string,mixed>>
 */
function repo_pontos_iluminacao_list(int $clienteId, bool $escopoEmpresa = false, string $q = '', string $status = ''): array
{
    $pdo = db();
    if (!$pdo || $clienteId <= 0) {
        return [];
    }
    $where = [];
    $params = [];
    if ($escopoEmpresa) {
        $where[] = 'pi.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)';
        $params[] = $clienteId;
        $params[] = $clienteId;
    } else {
        $where[] = 'pi.cliente_id = ?';
        $params[] = $clienteId;
    }
    if (in_array($status, ['Ativo', 'Inativo'], true)) {
        $where[] = 'pi.status = ?';
        $params[] = $status;
    }
    $q = trim($q);
    if ($q !== '') {
        $where[] = '(pi.codigo_poste LIKE ? OR pi.identificador_externo LIKE ? OR pi.endereco_completo LIKE ? OR pi.bairro LIKE ? OR pi.referencia LIKE ?)';
        $term = '%' . $q . '%';
        array_push($params, $term, $term, $term, $term, $term);
    }
    try {
        $sqlWhere = implode(' AND ', $where);
        $st = $pdo->prepare("
            SELECT
                pi.*,
                c.empresa AS cliente_empresa,
                COUNT(ch.id) AS chamados_total,
                SUM(CASE WHEN ch.status IN ('Aberto','Em andamento','Aguardando') THEN 1 ELSE 0 END) AS chamados_abertos
            FROM pontos_iluminacao pi
            JOIN clientes c ON c.id = pi.cliente_id
            LEFT JOIN chamados ch ON ch.ponto_iluminacao_id = pi.id
            WHERE $sqlWhere
            GROUP BY pi.id
            ORDER BY pi.codigo_poste ASC, pi.id ASC
        ");
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            _repo_ponto_iluminacao_normalizar($r);
        }
        unset($r);
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @return list<array<string,mixed>>
 */
function repo_pontos_iluminacao_options(int $clienteId): array
{
    return repo_pontos_iluminacao_list($clienteId, false, '', 'Ativo');
}

/**
 * @return array{ok:bool,err:string,id:int}
 */
function repo_ponto_iluminacao_salvar(array $d): array
{
    $pdo = db();
    if (!$pdo) {
        return ['ok' => false, 'err' => 'Banco indisponível.', 'id' => 0];
    }
    $id = (int) ($d['id'] ?? 0);
    $clienteId = (int) ($d['cliente_id'] ?? 0);
    $codigo = trim((string) ($d['codigo_poste'] ?? ''));
    if ($clienteId <= 0 || !repo_cliente($clienteId)) {
        return ['ok' => false, 'err' => 'Cliente inválido.', 'id' => 0];
    }
    if ($codigo === '') {
        return ['ok' => false, 'err' => 'Informe o ID/código do poste.', 'id' => 0];
    }
    [$lat, $lng] = _repo_parse_latlng_pair(
        isset($d['latitude']) ? (string) $d['latitude'] : null,
        isset($d['longitude']) ? (string) $d['longitude'] : null
    );
    $status = (string) ($d['status'] ?? 'Ativo');
    if (!in_array($status, ['Ativo', 'Inativo'], true)) {
        $status = 'Ativo';
    }
    $vals = [
        'cliente_id' => $clienteId,
        'codigo_poste' => $codigo,
        'identificador_externo' => trim((string) ($d['identificador_externo'] ?? '')) ?: null,
        'endereco_completo' => trim((string) ($d['endereco_completo'] ?? '')) ?: null,
        'bairro' => trim((string) ($d['bairro'] ?? '')) ?: null,
        'referencia' => trim((string) ($d['referencia'] ?? '')) ?: null,
        'latitude' => $lat,
        'longitude' => $lng,
        'status' => $status,
        'observacoes' => trim((string) ($d['observacoes'] ?? '')) ?: null,
    ];
    try {
        if ($id > 0) {
            $st = $pdo->prepare('
                UPDATE pontos_iluminacao
                   SET cliente_id = :cliente_id,
                       codigo_poste = :codigo_poste,
                       identificador_externo = :identificador_externo,
                       endereco_completo = :endereco_completo,
                       bairro = :bairro,
                       referencia = :referencia,
                       latitude = :latitude,
                       longitude = :longitude,
                       status = :status,
                       observacoes = :observacoes
                 WHERE id = :id
            ');
            $vals['id'] = $id;
            $st->execute($vals);
            return ['ok' => true, 'err' => '', 'id' => $id];
        }
        $st = $pdo->prepare('
            INSERT INTO pontos_iluminacao
                (cliente_id, codigo_poste, identificador_externo, endereco_completo, bairro, referencia, latitude, longitude, status, observacoes)
            VALUES
                (:cliente_id, :codigo_poste, :identificador_externo, :endereco_completo, :bairro, :referencia, :latitude, :longitude, :status, :observacoes)
        ');
        $st->execute($vals);
        return ['ok' => true, 'err' => '', 'id' => (int) $pdo->lastInsertId()];
    } catch (Throwable $e) {
        $msg = strpos($e->getMessage(), 'Duplicate') !== false
            ? 'Já existe um poste com este código para o cliente.'
            : 'Erro ao salvar ponto: ' . $e->getMessage();
        return ['ok' => false, 'err' => $msg, 'id' => $id];
    }
}

/**
 * @param list<array<string,mixed>> $linhas
 * @return array{ok:bool,err:string,processados:int,erros:list<string>}
 */
function repo_pontos_iluminacao_importar_linhas(int $clienteId, array $linhas): array
{
    $pdo = db();
    $ret = ['ok' => false, 'err' => '', 'processados' => 0, 'erros' => []];
    if (!$pdo) {
        $ret['err'] = 'Banco indisponível.';
        return $ret;
    }
    if ($clienteId <= 0 || !repo_cliente($clienteId)) {
        $ret['err'] = 'Cliente inválido.';
        return $ret;
    }
    if (!$linhas) {
        $ret['err'] = 'Nenhuma linha para importar.';
        return $ret;
    }

    $sql = '
        INSERT INTO pontos_iluminacao
            (cliente_id, codigo_poste, identificador_externo, endereco_completo, bairro, referencia, latitude, longitude, status, observacoes)
        VALUES
            (:cliente_id, :codigo_poste, :identificador_externo, :endereco_completo, :bairro, :referencia, :latitude, :longitude, :status, :observacoes)
        ON DUPLICATE KEY UPDATE
            identificador_externo = VALUES(identificador_externo),
            endereco_completo = VALUES(endereco_completo),
            bairro = VALUES(bairro),
            referencia = VALUES(referencia),
            latitude = VALUES(latitude),
            longitude = VALUES(longitude),
            status = VALUES(status),
            observacoes = VALUES(observacoes)
    ';

    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare($sql);
        foreach ($linhas as $idx => $linha) {
            $codigo = trim((string) ($linha['codigo_poste'] ?? ''));
            if ($codigo === '') {
                $ret['erros'][] = 'Linha ' . ($idx + 1) . ': código do poste vazio.';
                continue;
            }
            try {
                $st->execute([
                    'cliente_id' => $clienteId,
                    'codigo_poste' => $codigo,
                    'identificador_externo' => trim((string) ($linha['identificador_externo'] ?? '')) ?: null,
                    'endereco_completo' => trim((string) ($linha['endereco_completo'] ?? '')) ?: null,
                    'bairro' => trim((string) ($linha['bairro'] ?? '')) ?: null,
                    'referencia' => trim((string) ($linha['referencia'] ?? '')) ?: null,
                    'latitude' => ($linha['latitude'] ?? null) !== null ? (float) $linha['latitude'] : null,
                    'longitude' => ($linha['longitude'] ?? null) !== null ? (float) $linha['longitude'] : null,
                    'status' => in_array((string) ($linha['status'] ?? 'Ativo'), ['Ativo', 'Inativo'], true) ? (string) $linha['status'] : 'Ativo',
                    'observacoes' => trim((string) ($linha['observacoes'] ?? '')) ?: null,
                ]);
                $ret['processados']++;
            } catch (Throwable $e) {
                $ret['erros'][] = 'Poste ' . $codigo . ': ' . $e->getMessage();
            }
        }
        $pdo->commit();
        $ret['ok'] = $ret['processados'] > 0;
        if (!$ret['ok']) {
            $ret['err'] = 'Nenhum ponto foi importado.';
        }
        return $ret;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $ret['err'] = 'Erro ao importar pontos: ' . $e->getMessage();
        return $ret;
    }
}

function repo_ponto_iluminacao_delete(int $id): bool
{
    $pdo = db();
    if (!$pdo || $id <= 0) {
        return false;
    }
    try {
        foreach (repo_ponto_iluminacao_imagens_list($id) as $img) {
            $path = upload_dir_ponto_iluminacao($id) . DIRECTORY_SEPARATOR . ($img['nome_arquivo'] ?? '');
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $dir = upload_dir_ponto_iluminacao($id);
        if (is_dir($dir)) {
            @rmdir($dir);
        }
        $st = $pdo->prepare('DELETE FROM pontos_iluminacao WHERE id = ?');
        return $st->execute([$id]);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return list<array<string,mixed>>
 */
function repo_ponto_iluminacao_imagens_list(int $pontoId): array
{
    $pdo = db();
    if (!$pdo || $pontoId <= 0) {
        return [];
    }
    try {
        $st = $pdo->prepare('
            SELECT id, ponto_iluminacao_id, nome_original, nome_arquivo, mime, tamanho, `principal`, ordem,
                   DATE_FORMAT(enviado_em, \'%Y-%m-%d %H:%i\') AS enviado_em
            FROM ponto_iluminacao_imagens
            WHERE ponto_iluminacao_id = ?
            ORDER BY `principal` DESC, ordem ASC, id ASC
        ');
        $st->execute([$pontoId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['id'] = (int) ($r['id'] ?? 0);
            $r['ponto_iluminacao_id'] = (int) ($r['ponto_iluminacao_id'] ?? 0);
            $r['tamanho'] = (int) ($r['tamanho'] ?? 0);
            $r['principal'] = (int) ($r['principal'] ?? 0);
            $r['ordem'] = (int) ($r['ordem'] ?? 0);
        }
        unset($r);

        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

function repo_ponto_iluminacao_imagem(int $imagemId): ?array
{
    $pdo = db();
    if (!$pdo || $imagemId <= 0) {
        return null;
    }
    try {
        $st = $pdo->prepare('SELECT * FROM ponto_iluminacao_imagens WHERE id = ? LIMIT 1');
        $st->execute([$imagemId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return null;
        }
        $r['id'] = (int) $r['id'];
        $r['ponto_iluminacao_id'] = (int) ($r['ponto_iluminacao_id'] ?? 0);
        $r['tamanho'] = (int) ($r['tamanho'] ?? 0);
        $r['principal'] = (int) ($r['principal'] ?? 0);
        $r['ordem'] = (int) ($r['ordem'] ?? 0);

        return $r;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @return array{ok:bool,err:string,id:int}
 */
function repo_ponto_iluminacao_imagem_inserir(int $pontoId, string $nomeOriginal, string $nomeArquivo, ?string $mime, int $tamanho, bool $principal): array
{
    $pdo = db();
    if (!$pdo || $pontoId <= 0 || !repo_ponto_iluminacao($pontoId)) {
        return ['ok' => false, 'err' => 'Poste inválido.', 'id' => 0];
    }
    $nomeOriginal = trim($nomeOriginal);
    if (function_exists('mb_substr')) {
        $nomeOriginal = mb_substr($nomeOriginal, 0, 255, 'UTF-8');
    } else {
        $nomeOriginal = substr($nomeOriginal, 0, 255);
    }
    if ($mime !== null && $mime !== '') {
        $mime = function_exists('mb_substr')
            ? mb_substr((string) $mime, 0, 100, 'UTF-8')
            : substr((string) $mime, 0, 100);
    } else {
        $mime = null;
    }
    try {
        if ($principal) {
            $pdo->prepare('UPDATE ponto_iluminacao_imagens SET `principal` = 0 WHERE ponto_iluminacao_id = ?')->execute([$pontoId]);
            $ordem = 0;
        } else {
            $stM = $pdo->prepare('SELECT COALESCE(MAX(ordem), 0) + 1 AS n FROM ponto_iluminacao_imagens WHERE ponto_iluminacao_id = ?');
            $stM->execute([$pontoId]);
            $ordem = (int) ($stM->fetchColumn() ?: 1);
        }
        $st = $pdo->prepare('
            INSERT INTO ponto_iluminacao_imagens
                (ponto_iluminacao_id, nome_original, nome_arquivo, mime, tamanho, `principal`, ordem)
            VALUES (?,?,?,?,?,?,?)
        ');
        $st->execute([
            $pontoId,
            $nomeOriginal,
            $nomeArquivo,
            $mime,
            $tamanho,
            $principal ? 1 : 0,
            $ordem,
        ]);

        return ['ok' => true, 'err' => '', 'id' => (int) $pdo->lastInsertId()];
    } catch (Throwable $e) {
        $raw = $e->getMessage();
        if (stripos($raw, 'não existe') !== false
            || stripos($raw, "doesn't exist") !== false
            || stripos($raw, 'Unknown table') !== false
            || stripos($raw, 'Base table') !== false) {
            return ['ok' => false, 'err' => 'Tabela de fotos do poste não encontrada. Rode migrate.php ou execute database/migrations/034_ponto_iluminacao_imagens.sql no MySQL.', 'id' => 0];
        }
        if (stripos($raw, 'Data too long') !== false || stripos($raw, '1406') !== false) {
            return ['ok' => false, 'err' => 'Nome ou metadado do arquivo excede o limite do banco; tente renomear a imagem.', 'id' => 0];
        }
        $short = preg_replace('/\s+/', ' ', $raw);
        if (strlen($short) > 140) {
            $short = substr($short, 0, 137) . '...';
        }

        return ['ok' => false, 'err' => 'Erro ao registrar imagem: ' . $short, 'id' => 0];
    }
}

function repo_ponto_iluminacao_imagem_definir_principal(int $imagemId, int $pontoId): bool
{
    $pdo = db();
    if (!$pdo || $imagemId <= 0 || $pontoId <= 0) {
        return false;
    }
    $img = repo_ponto_iluminacao_imagem($imagemId);
    if (!$img || (int) ($img['ponto_iluminacao_id'] ?? 0) !== $pontoId) {
        return false;
    }
    try {
        $pdo->prepare('UPDATE ponto_iluminacao_imagens SET `principal` = 0 WHERE ponto_iluminacao_id = ?')->execute([$pontoId]);
        $pdo->prepare('UPDATE ponto_iluminacao_imagens SET `principal` = 1, ordem = 0 WHERE id = ? AND ponto_iluminacao_id = ?')->execute([$imagemId, $pontoId]);

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Remove registro e arquivo; se era a principal e há outras, promove a primeira secundária.
 *
 * @return array<string,mixed>|null metadados para unlink se já removido do BD
 */
function repo_ponto_iluminacao_imagem_excluir(int $imagemId, int $pontoId): ?array
{
    $pdo = db();
    if (!$pdo || $imagemId <= 0 || $pontoId <= 0) {
        return null;
    }
    $img = repo_ponto_iluminacao_imagem($imagemId);
    if (!$img || (int) ($img['ponto_iluminacao_id'] ?? 0) !== $pontoId) {
        return null;
    }
    $eraPrincipal = !empty($img['principal']);
    $nomeFs = (string) ($img['nome_arquivo'] ?? '');
    $path = upload_dir_ponto_iluminacao($pontoId) . DIRECTORY_SEPARATOR . $nomeFs;
    try {
        $st = $pdo->prepare('DELETE FROM ponto_iluminacao_imagens WHERE id = ? AND ponto_iluminacao_id = ?');
        $st->execute([$imagemId, $pontoId]);
        if (is_file($path)) {
            @unlink($path);
        }
        if ($eraPrincipal) {
            $st2 = $pdo->prepare('SELECT id FROM ponto_iluminacao_imagens WHERE ponto_iluminacao_id = ? ORDER BY ordem ASC, id ASC LIMIT 1');
            $st2->execute([$pontoId]);
            $next = $st2->fetchColumn();
            if ($next) {
                $pdo->prepare('UPDATE ponto_iluminacao_imagens SET `principal` = 1, ordem = 0 WHERE id = ?')->execute([(int) $next]);
            }
        }

        return $img;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @param list<int> $pontoIds
 * @return array<int,array<string,mixed>>
 */
function repo_pontos_iluminacao_imagens_principais(array $pontoIds): array
{
    $pdo = db();
    $pontoIds = array_values(array_unique(array_filter(array_map('intval', $pontoIds), function (int $id): bool {
        return $id > 0;
    })));
    if (!$pdo || !$pontoIds) {
        return [];
    }

    try {
        $placeholders = implode(',', array_fill(0, count($pontoIds), '?'));
        $st = $pdo->prepare("
            SELECT id, ponto_iluminacao_id, nome_original, `principal`
            FROM ponto_iluminacao_imagens
            WHERE ponto_iluminacao_id IN ($placeholders)
            ORDER BY ponto_iluminacao_id ASC, `principal` DESC, ordem ASC, id ASC
        ");
        $st->execute($pontoIds);
        $out = [];
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
            $pontoId = (int) ($r['ponto_iluminacao_id'] ?? 0);
            if ($pontoId <= 0 || isset($out[$pontoId])) {
                continue;
            }
            $out[$pontoId] = [
                'id' => (int) ($r['id'] ?? 0),
                'nome_original' => (string) ($r['nome_original'] ?? ''),
                'principal' => (int) ($r['principal'] ?? 0),
            ];
        }

        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @param list<int> $pontoIds
 * @return array<int,list<array<string,mixed>>>
 */
function repo_pontos_iluminacao_chamados_historico(array $pontoIds, int $limitePorPonto = 3): array
{
    $pdo = db();
    $pontoIds = array_values(array_unique(array_filter(array_map('intval', $pontoIds), function (int $id): bool {
        return $id > 0;
    })));
    $limitePorPonto = max(1, min(10, $limitePorPonto));
    if (!$pdo || !$pontoIds) {
        return [];
    }

    try {
        $placeholders = implode(',', array_fill(0, count($pontoIds), '?'));
        $st = $pdo->prepare("
            SELECT
                id, ponto_iluminacao_id, titulo, status, prioridade,
                DATE_FORMAT(aberto_em, '%Y-%m-%d %H:%i') AS data
            FROM chamados
            WHERE ponto_iluminacao_id IN ($placeholders)
            ORDER BY ponto_iluminacao_id ASC, aberto_em DESC, id DESC
        ");
        $st->execute($pontoIds);
        $out = [];
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
            $pontoId = (int) ($r['ponto_iluminacao_id'] ?? 0);
            if ($pontoId <= 0) {
                continue;
            }
            if (!isset($out[$pontoId])) {
                $out[$pontoId] = [];
            }
            if (count($out[$pontoId]) >= $limitePorPonto) {
                continue;
            }
            $out[$pontoId][] = [
                'id' => (int) ($r['id'] ?? 0),
                'titulo' => (string) ($r['titulo'] ?? ''),
                'status' => (string) ($r['status'] ?? ''),
                'prioridade' => (string) ($r['prioridade'] ?? ''),
                'data' => (string) ($r['data'] ?? ''),
            ];
        }

        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @return list<array<string,mixed>>
 */
function repo_pontos_iluminacao_mapa(int $clienteId, bool $escopoEmpresa = false): array
{
    $rows = repo_pontos_iluminacao_list($clienteId, $escopoEmpresa, '', 'Ativo');
    $out = [];
    foreach ($rows as $r) {
        if (($r['latitude'] ?? null) === null || ($r['longitude'] ?? null) === null) {
            continue;
        }
        $out[] = [
            'id' => (int) $r['id'],
            'codigo_poste' => (string) ($r['codigo_poste'] ?? ''),
            'identificador_externo' => (string) ($r['identificador_externo'] ?? ''),
            'cliente' => (string) ($r['cliente_empresa'] ?? ''),
            'endereco_completo' => (string) ($r['endereco_completo'] ?? ''),
            'bairro' => (string) ($r['bairro'] ?? ''),
            'status' => (string) ($r['status'] ?? ''),
            'chamados_abertos' => (int) ($r['chamados_abertos'] ?? 0),
            'chamados_total' => (int) ($r['chamados_total'] ?? 0),
            'lat' => (float) $r['latitude'],
            'lng' => (float) $r['longitude'],
        ];
    }

    if (!$out) {
        return $out;
    }

    $pontoIds = array_map(function (array $r): int {
        return (int) ($r['id'] ?? 0);
    }, $out);
    $imagens = repo_pontos_iluminacao_imagens_principais($pontoIds);
    $historicos = repo_pontos_iluminacao_chamados_historico($pontoIds, 3);

    foreach ($out as &$ponto) {
        $pontoId = (int) ($ponto['id'] ?? 0);
        $img = $imagens[$pontoId] ?? null;
        $ponto['foto_url'] = $img ? ('ponto_iluminacao_imagem.php?id=' . (int) ($img['id'] ?? 0)) : '';
        $ponto['foto_nome'] = $img ? (string) ($img['nome_original'] ?? '') : '';
        $ponto['chamados_historico'] = $historicos[$pontoId] ?? [];
    }
    unset($ponto);

    return $out;
}

function repo_chamados(): array
{
    $pdo = db();
    if (!$pdo) return [];

    $sql = "
        SELECT
            ch.id, ch.cliente_id, ch.titulo, ch.descricao,
            ch.endereco_completo, ch.latitude, ch.longitude,
            ch.prioridade, ch.status, ch.responsavel,
            DATE_FORMAT(ch.aberto_em, '%Y-%m-%d %H:%i') AS data,
            c.empresa AS cliente
        FROM chamados ch
        JOIN clientes c ON c.id = ch.cliente_id
        ORDER BY ch.id DESC
    ";
    $rows = $pdo->query($sql)->fetchAll();
    foreach ($rows as &$r) {
        $r['id']         = (int) $r['id'];
        $r['cliente_id'] = (int) $r['cliente_id'];
        _repo_chamado_row_normalizar_geo($r);
    }
    return $rows;
}

function repo_chamado_respostas(): array
{
    $pdo = db();
    if (!$pdo) return [];

    $sql = "
        SELECT id, chamado_id, autor, tipo, texto, interna,
               DATE_FORMAT(enviado_em, '%Y-%m-%d %H:%i') AS data
        FROM chamado_respostas
        ORDER BY chamado_id, enviado_em
    ";
    $out = [];
    foreach ($pdo->query($sql) as $r) {
        $cid = (int) $r['chamado_id'];
        $r['id']      = (int) $r['id'];
        $r['interna'] = (int) $r['interna'];
        unset($r['chamado_id']);
        $out[$cid][] = $r;
    }
    return $out;
}

/* ------------------------------------------------------------------ */
/*  KANBAN                                                            */
/* ------------------------------------------------------------------ */
function repo_kanban(): array
{
    $pdo = db();
    if (!$pdo) return [];

    $labels = [
        'todo'     => 'A Fazer',
        'progress' => 'Em Progresso',
        'review'   => 'Revisão',
        'done'     => 'Concluído',
    ];

    $board = [];
    foreach ($labels as $key => $label) {
        $board[$key] = ['label' => $label, 'cards' => []];
    }

    $sql = "
        SELECT k.id, k.coluna, k.cliente_id, k.titulo, k.descricao, k.prioridade,
               k.responsaveis, k.prazo, k.chamado_id,
               cli.empresa AS cliente_empresa
        FROM kanban_cards k
        LEFT JOIN clientes cli ON cli.id = k.cliente_id
        ORDER BY k.coluna, k.ordem, k.id
    ";
    foreach ($pdo->query($sql) as $c) {
        $col = $c['coluna'];
        if (!isset($board[$col])) continue;
        $card = [
            'id'              => (int) $c['id'],
            'titulo'          => $c['titulo'],
            'descricao'       => $c['descricao'],
            'prioridade'      => $c['prioridade'],
            'responsaveis'    => $c['responsaveis']
                ? array_map('trim', explode(',', $c['responsaveis']))
                : [],
            'prazo'           => $c['prazo'],
            'cliente_id'      => !empty($c['cliente_id']) ? (int) $c['cliente_id'] : null,
            'cliente_empresa' => $c['cliente_empresa'] ?? null,
        ];
        if (!empty($c['chamado_id'])) {
            $card['chamado_id'] = (int) $c['chamado_id'];
        }
        $board[$col]['cards'][] = $card;
    }

    return $board;
}

/* ------------------------------------------------------------------ */
/*  OS                                                                */
/* ------------------------------------------------------------------ */
function repo_os(): array
{
    $pdo = db();
    if (!$pdo) return [];

    $sql = "
        SELECT
            o.id, o.chamado_id, o.cliente_id, o.titulo, o.descricao, o.tipo,
            o.horas_previstas, o.horas_realizadas, o.valor_hora, o.status,
            o.responsavel, o.dentro_contrato,
            DATE_FORMAT(o.data_abertura,  '%Y-%m-%d') AS data_abertura,
            DATE_FORMAT(o.data_conclusao, '%Y-%m-%d') AS data_conclusao,
            c.empresa AS cliente
        FROM os o
        JOIN clientes c ON c.id = o.cliente_id
        ORDER BY o.id DESC
    ";
    $rows = $pdo->query($sql)->fetchAll();
    foreach ($rows as &$r) {
        $r['id']               = (int) $r['id'];
        $r['cliente_id']       = (int) $r['cliente_id'];
        $r['chamado_id']       = $r['chamado_id'] !== null ? (int) $r['chamado_id'] : null;
        $r['horas_previstas']  = (float) $r['horas_previstas'];
        $r['horas_realizadas'] = (float) $r['horas_realizadas'];
        $r['valor_hora']       = (float) $r['valor_hora'];
        $r['dentro_contrato']  = (bool) $r['dentro_contrato'];
    }
    return $rows;
}

/**
 * Uma OS por id (mesmo formato de repo_os).
 *
 * @return array<string,mixed>|null
 */
function repo_os_by_id(int $id): ?array
{
    $pdo = db();
    if (!$pdo || $id <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('
        SELECT
            o.id, o.chamado_id, o.cliente_id, o.titulo, o.descricao, o.tipo,
            o.horas_previstas, o.horas_realizadas, o.valor_hora, o.status,
            o.responsavel, o.dentro_contrato,
            DATE_FORMAT(o.data_abertura,  \'%Y-%m-%d\') AS data_abertura,
            DATE_FORMAT(o.data_conclusao, \'%Y-%m-%d\') AS data_conclusao,
            c.empresa AS cliente
        FROM os o
        JOIN clientes c ON c.id = o.cliente_id
        WHERE o.id = ?
        LIMIT 1
    ');
    $stmt->execute([$id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        return null;
    }
    $r['id']               = (int) $r['id'];
    $r['cliente_id']       = (int) $r['cliente_id'];
    $r['chamado_id']       = $r['chamado_id'] !== null ? (int) $r['chamado_id'] : null;
    $r['horas_previstas']  = (float) $r['horas_previstas'];
    $r['horas_realizadas'] = (float) $r['horas_realizadas'];
    $r['valor_hora']       = (float) $r['valor_hora'];
    $r['dentro_contrato']  = (bool) $r['dentro_contrato'];

    return $r;
}

/* ------------------------------------------------------------------ */
/*  CONTAS                                                            */
/* ------------------------------------------------------------------ */
function repo_contas(): array
{
    $pdo = db();
    if (!$pdo) return [];

    $sql = "
        SELECT
            ct.id, ct.cliente_id, ct.tipo_cobranca, ct.os_id, ct.descricao, ct.valor,
            DATE_FORMAT(ct.vencimento, '%Y-%m-%d') AS vencimento,
            ct.status,
            ct.observacoes, ct.boleto_linha, ct.boleto_url,
            ct.boleto_arquivo, ct.boleto_original,
            ct.pix_chave, ct.pix_tipo,
            c.empresa AS cliente
        FROM contas ct
        JOIN clientes c ON c.id = ct.cliente_id
        ORDER BY ct.vencimento DESC, ct.id DESC
    ";
    try {
        $rows = $pdo->query($sql)->fetchAll();
    } catch (Throwable $e) {
        $sqlMid = "
            SELECT
                ct.id, ct.cliente_id, ct.descricao, ct.valor,
                DATE_FORMAT(ct.vencimento, '%Y-%m-%d') AS vencimento,
                ct.status,
                ct.observacoes, ct.boleto_linha, ct.boleto_url,
                ct.boleto_arquivo, ct.boleto_original,
                ct.pix_chave, ct.pix_tipo,
                c.empresa AS cliente
            FROM contas ct
            JOIN clientes c ON c.id = ct.cliente_id
            ORDER BY ct.vencimento DESC, ct.id DESC
        ";
        try {
            $rows = $pdo->query($sqlMid)->fetchAll();
            foreach ($rows as &$r) {
                $r['tipo_cobranca'] = 'mensalidade';
                $r['os_id']         = null;
            }
            unset($r);
        } catch (Throwable $e2) {
            $sqlLegacy = "
                SELECT
                    ct.id, ct.cliente_id, ct.descricao, ct.valor,
                    DATE_FORMAT(ct.vencimento, '%Y-%m-%d') AS vencimento,
                    ct.status,
                    c.empresa AS cliente
                FROM contas ct
                JOIN clientes c ON c.id = ct.cliente_id
                ORDER BY ct.vencimento DESC, ct.id DESC
            ";
            $rows = $pdo->query($sqlLegacy)->fetchAll();
            foreach ($rows as &$r) {
                $r['observacoes']   = null;
                $r['boleto_linha']  = null;
                $r['boleto_url']    = null;
                $r['boleto_arquivo'] = null;
                $r['boleto_original'] = null;
                $r['pix_chave']     = null;
                $r['pix_tipo']      = null;
                $r['tipo_cobranca'] = 'mensalidade';
                $r['os_id']         = null;
                $r['id']            = (int) $r['id'];
                $r['cliente_id']    = (int) $r['cliente_id'];
                $r['valor']         = (float) $r['valor'];
            }
            unset($r);

            return $rows;
        }
    }
    foreach ($rows as &$r) {
        $r['id']            = (int) $r['id'];
        $r['cliente_id']    = (int) $r['cliente_id'];
        $r['valor']         = (float) $r['valor'];
        $r['os_id']         = isset($r['os_id']) && $r['os_id'] !== null ? (int) $r['os_id'] : null;
        $r['tipo_cobranca'] = (string) ($r['tipo_cobranca'] ?? 'mensalidade');
    }
    unset($r);

    return $rows;
}

function repo_conta(int $id): ?array
{
    foreach (repo_contas() as $c) {
        if ((int) $c['id'] === $id) {
            return $c;
        }
    }
    return null;
}

/**
 * Agregação financeira por cliente, tipo de cobrança e status (vencimento no mês de referência).
 *
 * @return array{
 *   linhas: list<array{cliente_id:int,cliente:string,tipo_cobranca:string,status:string,qtd:int,valor:float}>,
 *   totais: array{total:float, realizado:float, previsto:float, pago:float, pendente:float, vencido:float, qtd:int}
 * }
 */
function repo_relatorio_financeiro_mes(string $anoMes, ?int $filtroClienteId): array
{
    $emptyTotais = [
        'total'     => 0.0,
        'realizado' => 0.0,
        'previsto'  => 0.0,
        'pago'      => 0.0,
        'pendente'  => 0.0,
        'vencido'   => 0.0,
        'qtd'       => 0,
    ];
    if (!preg_match('/^\d{4}-\d{2}$/', $anoMes)) {
        $anoMes = date('Y-m');
    }
    $inicio = $anoMes . '-01';
    $fimT   = strtotime($inicio);
    $fim    = $fimT ? date('Y-m-t', $fimT) : $anoMes . '-28';

    $pdo = db();
    if (!$pdo) {
        global $MOCK_CONTAS;
        $todas = isset($MOCK_CONTAS) && is_array($MOCK_CONTAS) ? $MOCK_CONTAS : [];
        $agg   = [];
        foreach ($todas as $c) {
            $ven = (string) ($c['vencimento'] ?? '');
            if ($ven === '' || $ven < $inicio || $ven > $fim) {
                continue;
            }
            $cid = (int) ($c['cliente_id'] ?? 0);
            if ($filtroClienteId && $filtroClienteId > 0 && $cid !== $filtroClienteId) {
                continue;
            }
            $tipo = (string) ($c['tipo_cobranca'] ?? 'mensalidade');
            $st   = (string) ($c['status'] ?? '');
            $key  = $cid . '|' . $tipo . '|' . $st;
            if (!isset($agg[$key])) {
                $agg[$key] = [
                    'cliente_id'    => $cid,
                    'cliente'       => (string) ($c['cliente'] ?? ''),
                    'tipo_cobranca' => $tipo,
                    'status'        => $st,
                    'qtd'           => 0,
                    'valor'         => 0.0,
                ];
            }
            $agg[$key]['qtd']++;
            $agg[$key]['valor'] += (float) ($c['valor'] ?? 0);
        }
        $linhas = array_values($agg);
        usort($linhas, static function (array $a, array $b): int {
            $cmp = strcmp($a['cliente'], $b['cliente']);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strcmp($a['tipo_cobranca'], $b['tipo_cobranca']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp($a['status'], $b['status']);
        });

        $tot = $emptyTotais;
        foreach ($linhas as $r) {
            $v  = (float) $r['valor'];
            $st = (string) ($r['status'] ?? '');
            $tot['total'] += $v;
            $tot['qtd']   += (int) $r['qtd'];
            if ($st === 'Pago') {
                $tot['pago'] += $v;
                $tot['realizado'] += $v;
            } elseif ($st === 'Pendente') {
                $tot['pendente'] += $v;
                $tot['previsto'] += $v;
            } elseif ($st === 'Vencido') {
                $tot['vencido'] += $v;
                $tot['previsto'] += $v;
            }
        }

        return ['linhas' => $linhas, 'totais' => $tot, 'inicio' => $inicio, 'fim' => $fim];
    }

    $params = [$inicio, $fim];
    $cliF   = '';
    if ($filtroClienteId && $filtroClienteId > 0) {
        $cliF   = ' AND ct.cliente_id = ?';
        $params[] = $filtroClienteId;
    }

    $sql = "
        SELECT
            c.id AS cliente_id,
            c.empresa AS cliente,
            COALESCE(ct.tipo_cobranca, 'mensalidade') AS tipo_cobranca,
            ct.status,
            COUNT(*) AS qtd,
            SUM(ct.valor) AS valor
        FROM contas ct
        JOIN clientes c ON c.id = ct.cliente_id
        WHERE ct.vencimento >= ? AND ct.vencimento <= ?
        $cliF
        GROUP BY c.id, c.empresa, COALESCE(ct.tipo_cobranca, 'mensalidade'), ct.status
        ORDER BY c.empresa ASC, tipo_cobranca ASC, ct.status ASC
    ";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $sql2 = "
            SELECT
                c.id AS cliente_id,
                c.empresa AS cliente,
                'mensalidade' AS tipo_cobranca,
                ct.status,
                COUNT(*) AS qtd,
                SUM(ct.valor) AS valor
            FROM contas ct
            JOIN clientes c ON c.id = ct.cliente_id
            WHERE ct.vencimento >= ? AND ct.vencimento <= ?
            $cliF
            GROUP BY c.id, c.empresa, ct.status
            ORDER BY c.empresa ASC, ct.status ASC
        ";
        $stmt = $pdo->prepare($sql2);
        $stmt->execute($params);
        $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    foreach ($linhas as &$row) {
        $row['cliente_id']    = (int) $row['cliente_id'];
        $row['qtd']           = (int) $row['qtd'];
        $row['valor']         = (float) $row['valor'];
        $row['tipo_cobranca'] = (string) ($row['tipo_cobranca'] ?? 'mensalidade');
    }
    unset($row);

    $tot = $emptyTotais;
    foreach ($linhas as $r) {
        $v = (float) $r['valor'];
        $st = (string) ($r['status'] ?? '');
        $tot['total'] += $v;
        $tot['qtd']   += (int) $r['qtd'];
        if ($st === 'Pago') {
            $tot['pago'] += $v;
            $tot['realizado'] += $v;
        } elseif ($st === 'Pendente') {
            $tot['pendente'] += $v;
            $tot['previsto'] += $v;
        } elseif ($st === 'Vencido') {
            $tot['vencido'] += $v;
            $tot['previsto'] += $v;
        }
    }

    return ['linhas' => $linhas, 'totais' => $tot, 'inicio' => $inicio, 'fim' => $fim];
}

/**
 * Cobranças do mês (vencimento) — linha a linha, cruzamento conta × cliente.
 *
 * @return list<array{id:int,cliente_id:int,cliente:string,descricao:string,vencimento:string,valor:float,status:string,tipo_cobranca:string,os_id:?int}>
 */
function repo_relatorio_financeiro_contas_linhas(string $anoMes, ?int $filtroClienteId): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $anoMes)) {
        $anoMes = date('Y-m');
    }
    $inicio = $anoMes . '-01';
    $fimT   = strtotime($inicio);
    $fim    = $fimT ? date('Y-m-t', $fimT) : $anoMes . '-28';

    $pdo = db();
    if (!$pdo) {
        global $MOCK_CONTAS;
        $out   = [];
        $todas = isset($MOCK_CONTAS) && is_array($MOCK_CONTAS) ? $MOCK_CONTAS : [];
        foreach ($todas as $c) {
            $ven = (string) ($c['vencimento'] ?? '');
            if ($ven === '' || $ven < $inicio || $ven > $fim) {
                continue;
            }
            $cid = (int) ($c['cliente_id'] ?? 0);
            if ($filtroClienteId && $filtroClienteId > 0 && $cid !== $filtroClienteId) {
                continue;
            }
            $out[] = [
                'id'            => (int) ($c['id'] ?? 0),
                'cliente_id'    => $cid,
                'cliente'       => (string) ($c['cliente'] ?? ''),
                'descricao'     => (string) ($c['descricao'] ?? ''),
                'vencimento'    => $ven,
                'valor'         => (float) ($c['valor'] ?? 0),
                'status'        => (string) ($c['status'] ?? ''),
                'tipo_cobranca' => (string) ($c['tipo_cobranca'] ?? 'mensalidade'),
                'os_id'         => isset($c['os_id']) && $c['os_id'] !== null && $c['os_id'] !== ''
                    ? (int) $c['os_id'] : null,
            ];
        }
        usort($out, static function (array $a, array $b): int {
            $c = strcmp($a['cliente'], $b['cliente']);
            if ($c !== 0) {
                return $c;
            }
            $c = strcmp($a['vencimento'], $b['vencimento']);
            if ($c !== 0) {
                return $c;
            }

            return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
        });

        return $out;
    }

    $params = [$inicio, $fim];
    $cliF   = '';
    if ($filtroClienteId && $filtroClienteId > 0) {
        $cliF     = ' AND ct.cliente_id = ?';
        $params[] = $filtroClienteId;
    }

    $sql = "
        SELECT
            ct.id,
            ct.cliente_id,
            c.empresa AS cliente,
            ct.descricao,
            DATE_FORMAT(ct.vencimento, '%Y-%m-%d') AS vencimento,
            ct.valor,
            ct.status,
            COALESCE(ct.tipo_cobranca, 'mensalidade') AS tipo_cobranca,
            ct.os_id
        FROM contas ct
        JOIN clientes c ON c.id = ct.cliente_id
        WHERE ct.vencimento >= ? AND ct.vencimento <= ?
        $cliF
        ORDER BY c.empresa ASC, ct.vencimento ASC, ct.id ASC
    ";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        try {
            $sql2 = "
                SELECT
                    ct.id,
                    ct.cliente_id,
                    c.empresa AS cliente,
                    ct.descricao,
                    DATE_FORMAT(ct.vencimento, '%Y-%m-%d') AS vencimento,
                    ct.valor,
                    ct.status,
                    'mensalidade' AS tipo_cobranca,
                    NULL AS os_id
                FROM contas ct
                JOIN clientes c ON c.id = ct.cliente_id
                WHERE ct.vencimento >= ? AND ct.vencimento <= ?
                $cliF
                ORDER BY c.empresa ASC, ct.vencimento ASC, ct.id ASC
            ";
            $stmt = $pdo->prepare($sql2);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e2) {
            return [];
        }
    }

    foreach ($rows as &$r) {
        $r['id']             = (int) $r['id'];
        $r['cliente_id']     = (int) $r['cliente_id'];
        $r['valor']          = (float) $r['valor'];
        $r['tipo_cobranca']  = (string) ($r['tipo_cobranca'] ?? 'mensalidade');
        $r['os_id']          = isset($r['os_id']) && $r['os_id'] !== null && $r['os_id'] !== ''
            ? (int) $r['os_id'] : null;
    }
    unset($r);

    return $rows;
}

/* ------------------------------------------------------------------ */
/*  SUPORTE                                                           */
/* ------------------------------------------------------------------ */
function repo_suporte(?int $empresaRaizOuContaId = null): array
{
    $pdo = db();
    if (!$pdo) return [];

    $sql = "
        SELECT
            s.id, s.cliente_id, s.pergunta, s.detalhe, s.status, s.resposta,
            DATE_FORMAT(s.enviado_em, '%Y-%m-%d %H:%i') AS data,
            c.empresa AS cliente
        FROM suporte s
        JOIN clientes c ON c.id = s.cliente_id
        %s
        ORDER BY s.enviado_em DESC
    ";
    $scope = '';
    $params = [];
    if ($empresaRaizOuContaId !== null && $empresaRaizOuContaId > 0) {
        $scope = ' WHERE s.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?) ';
        $params = [$empresaRaizOuContaId, $empresaRaizOuContaId];
    }
    $sql = str_replace('%s', $scope, $sql);
    if ($params !== []) {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll();
    } else {
        $rows = $pdo->query($sql)->fetchAll();
    }
    foreach ($rows as &$r) {
        $r['id']         = (int) $r['id'];
        $r['cliente_id'] = (int) $r['cliente_id'];
    }
    return $rows;
}

/* ================================================================== */
/*                          ESCRITAS / MUTAÇÕES                       */
/* ================================================================== */

/* ---- Clientes ---- */
function repo_create_cliente(array $d): ?int
{
    $pdo = db();
    if (!$pdo) return null;

    $stmt = $pdo->prepare('
        INSERT INTO clientes
            (empresa_id, nome, empresa, email, telefone, doc, status, desde, obs)
        VALUES (?,?,?,?,?,?,?,?,?)
    ');
    $stmt->execute([
        !empty($d['empresa_id']) ? (int) $d['empresa_id'] : null,
        $d['nome']              ?? '',
        $d['empresa']           ?? '',
        $d['email']             ?? null,
        $d['telefone']          ?? null,
        $d['doc']               ?? null,
        $d['status']            ?? 'Ativo',
        $d['desde']             ?? date('Y-m-d'),
        $d['obs']               ?? null,
    ]);
    return (int) $pdo->lastInsertId();
}

function repo_update_cliente(int $id, array $d): bool
{
    $pdo = db();
    if (!$pdo) return false;

    $stmt = $pdo->prepare('
        UPDATE clientes
           SET empresa_id = :empresa_id,
               nome = :nome,
               empresa = :empresa,
               email = :email,
               telefone = :telefone,
               doc = :doc,
               status = :status,
               obs = :obs
         WHERE id = :id
    ');
    $empresaId = !empty($d['empresa_id']) ? (int) $d['empresa_id'] : null;
    if ($empresaId === $id) {
        $empresaId = null;
    }

    return $stmt->execute([
        ':id'                 => $id,
        ':empresa_id'         => $empresaId,
        ':nome'               => $d['nome']     ?? '',
        ':empresa'            => $d['empresa']  ?? '',
        ':email'              => $d['email']    ?? null,
        ':telefone'           => $d['telefone'] ?? null,
        ':doc'                => $d['doc']      ?? null,
        ':status'             => $d['status']   ?? 'Ativo',
        ':obs'                => $d['obs']      ?? null,
    ]);
}

function repo_delete_cliente(int $id): bool
{
    $pdo = db();
    if (!$pdo) return false;

    // Remove também os arquivos físicos de anexos (o banco cascateia).
    $stmtAnexos = $pdo->prepare('SELECT nome_arquivo FROM cliente_anexos WHERE cliente_id = ?');
    $stmtAnexos->execute([$id]);
    $baseDir = __DIR__ . '/../uploads/clientes/' . $id;
    foreach ($stmtAnexos->fetchAll() as $a) {
        $path = $baseDir . DIRECTORY_SEPARATOR . $a['nome_arquivo'];
        if (is_file($path)) @unlink($path);
    }
    if (is_dir($baseDir)) @rmdir($baseDir);

    $stmt = $pdo->prepare('DELETE FROM clientes WHERE id = ?');
    return $stmt->execute([$id]);
}

/**
 * Exclui a empresa (matriz), todas as unidades (clientes filhos), chamados, pontos, medições BM,
 * usuários do portal/equipe vinculados e anexos. Não remove usuários perfil admin.
 *
 * @param int $qualquerClienteId ID da ficha (matriz ou unidade)
 * @param int $preservarUsuarioId ID do usuário logado (nunca removido)
 */
function repo_delete_empresa_completa(int $qualquerClienteId, int $preservarUsuarioId = 0): bool
{
    $pdo = db();
    if (!$pdo || $qualquerClienteId <= 0) {
        return false;
    }
    $matrizId = repo_cliente_matriz_raiz_id($qualquerClienteId);
    if ($matrizId <= 0) {
        return false;
    }
    $arvore = repo_clientes_na_empresa($matrizId);
    if ($arvore === []) {
        return false;
    }
    $ids = [];
    foreach ($arvore as $row) {
        $i = (int) ($row['id'] ?? 0);
        if ($i > 0) {
            $ids[$i] = true;
        }
    }
    $ids = array_keys($ids);
    sort($ids);
    $children = [];
    foreach ($ids as $xid) {
        if ($xid !== $matrizId) {
            $children[] = $xid;
        }
    }

    try {
        $pdo->beginTransaction();

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $sqlUsers = "
            DELETE FROM usuarios
            WHERE perfil <> 'admin'
              AND id <> ?
              AND (
                  cliente_id IN ($ph)
                  OR empresa_id IN ($ph)
              )
        ";
        $params = array_merge([$preservarUsuarioId], $ids, $ids);
        $stU = $pdo->prepare($sqlUsers);
        $stU->execute($params);

        foreach ($children as $cid) {
            if (!repo_delete_cliente($cid)) {
                throw new RuntimeException('falha ao excluir unidade');
            }
        }
        if (!repo_delete_cliente($matrizId)) {
            throw new RuntimeException('falha ao excluir matriz');
        }

        $rawCfg = trim((string) repo_config_get('catalogo_cliente_padrao_id'));
        if ($rawCfg !== '' && ctype_digit($rawCfg) && in_array((int) $rawCfg, $ids, true)) {
            repo_config_set('catalogo_cliente_padrao_id', '');
        }

        $pdo->commit();

        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return false;
    }
}

/* ---- Consultas focadas em um cliente ---- */
function repo_chamados_cliente(int $clienteId, int $limit = 0): array
{
    $todos = repo_chamados();
    $res = array_values(array_filter($todos, fn($c) => (int) $c['cliente_id'] === $clienteId));
    usort($res, fn($a, $b) => strcmp($b['aberto_em'] ?? '', $a['aberto_em'] ?? ''));
    if ($limit > 0) $res = array_slice($res, 0, $limit);
    return $res;
}

function repo_os_cliente(int $clienteId, ?string $inicio = null, ?string $fim = null): array
{
    $pdo = db();
    if (!$pdo) return [];

    $sql = "SELECT * FROM os WHERE cliente_id = ?";
    $params = [$clienteId];
    if ($inicio && $fim) {
        $sql .= " AND data_abertura >= ? AND data_abertura <= ?";
        $params[] = $inicio;
        $params[] = $fim;
    }
    $sql .= " ORDER BY COALESCE(data_abertura, '1970-01-01') DESC, id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['id']               = (int) $r['id'];
        $r['cliente_id']       = (int) $r['cliente_id'];
        $r['chamado_id']       = $r['chamado_id'] !== null ? (int) $r['chamado_id'] : null;
        $r['horas_previstas']  = (float) $r['horas_previstas'];
        $r['horas_realizadas'] = (float) $r['horas_realizadas'];
        $r['valor_hora']       = (float) $r['valor_hora'];
        $r['dentro_contrato']  = (bool) $r['dentro_contrato'];
    }
    return $rows;
}

function repo_contas_cliente(int $clienteId): array
{
    $todas = repo_contas();
    return array_values(array_filter($todas, fn($c) => (int) $c['cliente_id'] === $clienteId));
}

/**
 * Contas da empresa (raiz + contas cliente filhas).
 *
 * @return list<array<string,mixed>>
 */
function repo_contas_na_empresa(int $empresaRaizId): array
{
    $todas = repo_contas();

    return array_values(array_filter($todas, function ($c) use ($empresaRaizId) {
        return repo_cliente_pertence_empresa((int) ($c['cliente_id'] ?? 0), $empresaRaizId);
    }));
}

/**
 * Um chamado (mesmo formato de repo_chamados).
 *
 * @return array<string,mixed>|null
 */
function repo_chamado(int $id): ?array
{
    $pdo = db();
    if (!$pdo || $id <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('
        SELECT
            ch.id, ch.cliente_id, ch.titulo, ch.descricao,
            ch.contribuinte_cpf, ch.contribuinte_nome, ch.contribuinte_telefone, ch.contribuinte_email,
            DATE_FORMAT(ch.data_abertura_os, \'%Y-%m-%d\') AS data_abertura_os,
            ch.origem_os, ch.problema_os, ch.tipo_os, ch.ponto_referencia,
            ch.os_cep, ch.os_logradouro, ch.os_numero, ch.os_complemento, ch.os_bairro, ch.os_cidade, ch.os_uf,
            ch.endereco_completo, ch.latitude, ch.longitude,
            ch.ponto_iluminacao_id, ch.servico_id,
            DATE_FORMAT(ch.finalizado_operador_em, \'%Y-%m-%d %H:%i\') AS finalizado_operador_em,
            ch.finalizado_operador_user_id,
            ch.tecnico_user_id,
            DATE_FORMAT(ch.aprovado_gestor_em, \'%Y-%m-%d %H:%i\') AS aprovado_gestor_em,
            ch.aprovado_gestor_user_id,
            ch.checklist_realizado,
            ch.prioridade, ch.status, ch.responsavel,
            DATE_FORMAT(ch.aberto_em, \'%Y-%m-%d %H:%i\') AS data,
            c.empresa AS cliente,
            ut.nome AS tecnico_nome,
            ua.nome AS aprovado_gestor_nome,
            s.nome AS servico_nome,
            s.tipo AS servico_tipo,
            s.valor_unitario AS servico_valor_unitario,
            pi.codigo_poste AS ponto_codigo_poste
        FROM chamados ch
        JOIN clientes c ON c.id = ch.cliente_id
        LEFT JOIN usuarios ut ON ut.id = ch.tecnico_user_id
        LEFT JOIN usuarios ua ON ua.id = ch.aprovado_gestor_user_id
        LEFT JOIN cliente_itens s ON s.id = ch.servico_id
        LEFT JOIN pontos_iluminacao pi ON pi.id = ch.ponto_iluminacao_id
        WHERE ch.id = ?
        LIMIT 1
    ');
    $stmt->execute([$id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        return null;
    }
    $r['id']         = (int) $r['id'];
    $r['cliente_id'] = (int) $r['cliente_id'];
    $r['ponto_iluminacao_id'] = isset($r['ponto_iluminacao_id']) && $r['ponto_iluminacao_id'] !== null ? (int) $r['ponto_iluminacao_id'] : null;
    $r['servico_id'] = isset($r['servico_id']) && $r['servico_id'] !== null ? (int) $r['servico_id'] : null;
    if (array_key_exists('servico_valor_unitario', $r) && $r['servico_valor_unitario'] !== null) {
        $r['servico_valor_unitario'] = (float) $r['servico_valor_unitario'];
    }
    $r['finalizado_operador_user_id'] = isset($r['finalizado_operador_user_id']) && $r['finalizado_operador_user_id'] !== null
        ? (int) $r['finalizado_operador_user_id'] : null;
    $r['tecnico_user_id'] = isset($r['tecnico_user_id']) && $r['tecnico_user_id'] !== null ? (int) $r['tecnico_user_id'] : null;
    $r['aprovado_gestor_user_id'] = isset($r['aprovado_gestor_user_id']) && $r['aprovado_gestor_user_id'] !== null
        ? (int) $r['aprovado_gestor_user_id'] : null;
    _repo_chamado_row_normalizar_geo($r);

    return $r;
}

/**
 * Respostas de um chamado (para thread). Se $somentePublicas, omite internas (portal cliente).
 *
 * @return list<array<string,mixed>>
 */
function repo_chamado_respostas_do_ticket(int $chamadoId, bool $somentePublicas): array
{
    $pdo = db();
    if (!$pdo || $chamadoId <= 0) {
        return [];
    }
    $sql = '
        SELECT id, autor, tipo, texto, interna,
               DATE_FORMAT(enviado_em, \'%Y-%m-%d %H:%i\') AS data
        FROM chamado_respostas
        WHERE chamado_id = ?
    ';
    if ($somentePublicas) {
        $sql .= ' AND interna = 0';
    }
    $sql .= ' ORDER BY enviado_em ASC, id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$chamadoId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id']      = (int) $r['id'];
        $r['interna'] = (int) $r['interna'];
    }
    unset($r);

    return $rows;
}

/**
 * Chamados visíveis ao operador (toda a carteira da empresa: raiz + clientes filhos).
 *
 * @return array{rows: list<array<string,mixed>>, total: int}
 */
function repo_chamados_operador_list(int $empresaRaizId, string $filtro, string $q, int $page, int $perPage, ?int $operadorUserId = null): array
{
    $pdo = db();
    if (!$pdo || $empresaRaizId <= 0 || $perPage < 1) {
        return ['rows' => [], 'total' => 0];
    }
    $filtro = strtolower(trim($filtro));
    $q      = trim($q);

    $where = ['ch.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)'];
    $params = [$empresaRaizId, $empresaRaizId];
    if ($operadorUserId !== null && $operadorUserId > 0) {
        $where[] = 'ch.tecnico_user_id = ?';
        $params[] = $operadorUserId;
    }

    if ($filtro === 'andamento') {
        $where[] = 'ch.status IN (\'Aberto\',\'Em andamento\')';
    } elseif ($filtro === 'aguardando') {
        $where[] = 'ch.status = ?';
        $params[] = 'Aguardando';
    } elseif ($filtro === 'resolvido') {
        $where[] = 'ch.status IN (\'Resolvido\',\'Fechado\',\'Cancelado\')';
    }

    if ($q !== '') {
        if (ctype_digit($q)) {
            $where[] = 'ch.id = ?';
            $params[] = (int) $q;
        } else {
            $where[] = '(ch.titulo LIKE ? OR ch.descricao LIKE ?)';
            $term      = '%' . $q . '%';
            $params[] = $term;
            $params[] = $term;
        }
    }

    $sqlWhere = implode(' AND ', $where);
    $stmt     = $pdo->prepare("SELECT COUNT(*) FROM chamados ch WHERE $sqlWhere");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    $page          = max(1, $page);
    $totalPages    = max(1, (int) ceil($total / $perPage));
    $page          = min($page, $totalPages);
    $offset        = ($page - 1) * $perPage;

    $sql = "
        SELECT
            ch.id, ch.cliente_id, ch.titulo, ch.descricao,
            ch.endereco_completo, ch.latitude, ch.longitude,
            ch.servico_id, ch.finalizado_operador_em, ch.tecnico_user_id, ch.aprovado_gestor_em,
            ch.prioridade, ch.status, ch.responsavel,
            DATE_FORMAT(ch.aberto_em, '%Y-%m-%d %H:%i') AS data,
            c.empresa AS cliente,
            ut.nome AS tecnico_nome,
            s.nome AS servico_nome,
            s.tipo AS servico_tipo,
            s.valor_unitario AS servico_valor_unitario
        FROM chamados ch
        JOIN clientes c ON c.id = ch.cliente_id
        LEFT JOIN usuarios ut ON ut.id = ch.tecnico_user_id
        LEFT JOIN cliente_itens s ON s.id = ch.servico_id
        WHERE $sqlWhere
        ORDER BY ch.aberto_em DESC, ch.id DESC
        LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id']         = (int) $r['id'];
        $r['cliente_id'] = (int) $r['cliente_id'];
        $r['servico_id'] = isset($r['servico_id']) && $r['servico_id'] !== null ? (int) $r['servico_id'] : null;
        $r['tecnico_user_id'] = isset($r['tecnico_user_id']) && $r['tecnico_user_id'] !== null ? (int) $r['tecnico_user_id'] : null;
        _repo_chamado_row_normalizar_geo($r);
    }
    unset($r);

    return ['rows' => $rows, 'total' => $total];
}

/**
 * Chamado pode ser atendido por operador da empresa $empresaRaizId.
 */
function repo_chamado_acessivel_operador_empresa(int $chamadoId, int $empresaRaizId): bool
{
    if ($chamadoId <= 0 || $empresaRaizId <= 0) {
        return false;
    }
    $ch = repo_chamado($chamadoId);
    if (!$ch) {
        return false;
    }

    return repo_cliente_pertence_empresa((int) ($ch['cliente_id'] ?? 0), $empresaRaizId);
}

function repo_chamado_acessivel_operador_atribuido(int $chamadoId, int $empresaRaizId, int $operadorUserId): bool
{
    if (!repo_chamado_acessivel_operador_empresa($chamadoId, $empresaRaizId)) {
        return false;
    }
    $ch = repo_chamado($chamadoId);
    if (!$ch) {
        return false;
    }

    return (int) ($ch['tecnico_user_id'] ?? 0) === $operadorUserId;
}

/**
 * Técnicos/operadores vinculados à empresa raiz.
 *
 * @return list<array<string,mixed>>
 */
function repo_operadores_empresa(int $empresaRaizId): array
{
    $pdo = db();
    if (!$pdo || $empresaRaizId <= 0) {
        return [];
    }
    $st = $pdo->prepare("
        SELECT id, nome, email, iniciais
        FROM usuarios
        WHERE perfil = 'operador' AND empresa_id = ?
        ORDER BY nome ASC, id ASC
    ");
    $st->execute([$empresaRaizId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
    }
    unset($r);

    return $rows;
}

/**
 * Lista chamados do portal do cliente com filtro e busca (paginado).
 * Opcional: intervalo de datas de abertura (YYYY-MM-DD), ex. filtro por medição mensal.
 *
 * @return array{rows: list<array<string,mixed>>, total: int}
 */
function repo_chamados_portal_list(
    int $clienteId,
    string $filtro,
    string $q,
    int $page,
    int $perPage,
    ?string $dataAbertaDe = null,
    ?string $dataAbertaAte = null,
    ?int $sqlOffsetOverride = null,
    string $ordem = 'recentes'
): array
{
    $pdo = db();
    if (!$pdo || $clienteId <= 0 || $perPage < 1) {
        return ['rows' => [], 'total' => 0];
    }
    $raizId = repo_cliente_matriz_raiz_id($clienteId);
    if ($raizId <= 0) {
        return ['rows' => [], 'total' => 0];
    }
    $filtro = strtolower(trim($filtro));
    $q      = trim($q);
    $ordem  = strtolower(trim($ordem));

    $where = ['ch.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)'];
    $params = [$raizId, $raizId];

    if ($filtro === 'andamento') {
        $where[] = 'ch.status IN (\'Aberto\',\'Em andamento\')';
    } elseif ($filtro === 'aguardando') {
        $where[] = 'ch.status = ?';
        $params[] = 'Aguardando';
    } elseif ($filtro === 'resolvido') {
        $where[] = 'ch.status IN (\'Resolvido\',\'Fechado\',\'Cancelado\')';
    }

    if ($q !== '') {
        if (ctype_digit($q)) {
            $where[] = 'ch.id = ?';
            $params[] = (int) $q;
        } else {
            $where[] = '(ch.titulo LIKE ? OR ch.descricao LIKE ?)';
            $term      = '%' . $q . '%';
            $params[] = $term;
            $params[] = $term;
        }
    }

    if ($dataAbertaDe !== null && $dataAbertaAte !== null
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAbertaDe) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAbertaAte)
        && $dataAbertaDe <= $dataAbertaAte) {
        $where[] = 'DATE(ch.aberto_em) BETWEEN ? AND ?';
        $params[] = $dataAbertaDe;
        $params[] = $dataAbertaAte;
    }

    switch ($ordem) {
        case 'antigos':
            $orderBy = 'ch.aberto_em ASC, ch.id ASC';
            break;
        case 'status':
            $orderBy = "FIELD(ch.status, 'Aberto', 'Em andamento', 'Aguardando', 'Cancelado', 'Resolvido', 'Fechado'), ch.aberto_em DESC, ch.id DESC";
            break;
        case 'prioridade':
            $orderBy = "FIELD(ch.prioridade, 'Urgente', 'Alta', 'Normal', 'Baixa'), ch.aberto_em DESC, ch.id DESC";
            break;
        default:
            $orderBy = 'ch.aberto_em DESC, ch.id DESC';
            break;
    }

    $sqlWhere = implode(' AND ', $where);
    $stmt     = $pdo->prepare("SELECT COUNT(*) FROM chamados ch WHERE $sqlWhere");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    if ($sqlOffsetOverride !== null) {
        $offset = max(0, $sqlOffsetOverride);
    } else {
        $page       = max(1, $page);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = min($page, $totalPages);
        $offset     = ($page - 1) * $perPage;
    }

    $sql = "
        SELECT
            ch.id, ch.cliente_id, ch.titulo, ch.descricao,
            ch.endereco_completo, ch.latitude, ch.longitude,
            ch.servico_id, ch.finalizado_operador_em, ch.tecnico_user_id, ch.aprovado_gestor_em,
            ch.prioridade, ch.status, ch.responsavel,
            DATE_FORMAT(ch.aberto_em, '%Y-%m-%d %H:%i') AS data,
            c.empresa AS cliente,
            ut.nome AS tecnico_nome,
            s.nome AS servico_nome,
            s.tipo AS servico_tipo,
            s.valor_unitario AS servico_valor_unitario
        FROM chamados ch
        JOIN clientes c ON c.id = ch.cliente_id
        LEFT JOIN usuarios ut ON ut.id = ch.tecnico_user_id
        LEFT JOIN cliente_itens s ON s.id = ch.servico_id
        WHERE $sqlWhere
        ORDER BY $orderBy
        LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id']         = (int) $r['id'];
        $r['cliente_id'] = (int) $r['cliente_id'];
        $r['servico_id'] = isset($r['servico_id']) && $r['servico_id'] !== null ? (int) $r['servico_id'] : null;
        $r['tecnico_user_id'] = isset($r['tecnico_user_id']) && $r['tecnico_user_id'] !== null ? (int) $r['tecnico_user_id'] : null;
        _repo_chamado_row_normalizar_geo($r);
    }
    unset($r);

    return ['rows' => $rows, 'total' => $total];
}

/**
 * Lista chamados no admin com filtro, busca e paginação.
 * Opcional: intervalo de datas de abertura (YYYY-MM-DD), ex. filtro por medição mensal.
 * Opcional: $envolvidoUserId restringe a chamados em que o utilizador intervém (portal por unidade; equipe por colunas técnicas).
 *
 * @return array{rows: list<array<string,mixed>>, total: int}
 */
function repo_chamados_admin_list(
    string $filtro,
    string $q,
    int $page,
    int $perPage,
    ?int $clienteIdEscopo = null,
    ?string $dataAbertaDe = null,
    ?string $dataAbertaAte = null,
    ?int $sqlOffsetOverride = null,
    ?int $envolvidoUserId = null
): array
{
    $pdo = db();
    if (!$pdo || $perPage < 1) {
        return ['rows' => [], 'total' => 0];
    }
    $filtro = strtolower(trim($filtro));
    $q      = trim($q);

    $where = ['1=1'];
    $params = [];

    if ($clienteIdEscopo !== null && $clienteIdEscopo > 0) {
        $where[] = 'ch.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)';
        $params[] = $clienteIdEscopo;
        $params[] = $clienteIdEscopo;
    }

    if ($filtro === 'abertos') {
        $where[] = 'ch.status = ?';
        $params[] = 'Aberto';
    } elseif ($filtro === 'andamento') {
        $where[] = 'ch.status = ?';
        $params[] = 'Em andamento';
    } elseif ($filtro === 'aguardando') {
        $where[] = 'ch.status = ?';
        $params[] = 'Aguardando';
    } elseif ($filtro === 'resolvidos') {
        $where[] = 'ch.status IN (\'Resolvido\',\'Fechado\')';
    } elseif ($filtro === 'cancelados') {
        $where[] = 'ch.status = ?';
        $params[] = 'Cancelado';
    } elseif ($filtro === 'urgentes') {
        $where[] = 'ch.prioridade IN (\'Alta\',\'Urgente\')';
        $where[] = 'ch.status NOT IN (\'Resolvido\',\'Fechado\',\'Cancelado\')';
    }

    /*
     * envolvido_user (ficha do cliente): cliente do portal → chamados da unidade (usuarios.cliente_id);
     * gestor/técnico → papéis técnicos nas colunas do chamado.
     */
    if ($envolvidoUserId !== null && $envolvidoUserId > 0) {
        $where[] = 'EXISTS (
            SELECT 1 FROM usuarios uenv
            WHERE uenv.id = ?
              AND (
                (uenv.perfil = \'cliente\' AND uenv.cliente_id IS NOT NULL AND ch.cliente_id = uenv.cliente_id)
                OR (uenv.perfil IN (\'gestor\',\'operador\') AND (
                    ch.tecnico_user_id = uenv.id
                    OR ch.finalizado_operador_user_id = uenv.id
                    OR ch.aprovado_gestor_user_id = uenv.id
                ))
              )
        )';
        $params[] = $envolvidoUserId;
    }

    if ($q !== '') {
        if (ctype_digit($q)) {
            $where[] = 'ch.id = ?';
            $params[] = (int) $q;
        } else {
            $where[] = '(ch.titulo LIKE ? OR c.empresa LIKE ? OR CAST(ch.id AS CHAR) LIKE ?)';
            $term      = '%' . $q . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = '%' . $q . '%';
        }
    }

    if ($dataAbertaDe !== null && $dataAbertaAte !== null
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAbertaDe) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAbertaAte)
        && $dataAbertaDe <= $dataAbertaAte) {
        $where[] = 'DATE(ch.aberto_em) BETWEEN ? AND ?';
        $params[] = $dataAbertaDe;
        $params[] = $dataAbertaAte;
    }

    $sqlWhere = implode(' AND ', $where);
    $stmt     = $pdo->prepare("
        SELECT COUNT(*) FROM chamados ch
        JOIN clientes c ON c.id = ch.cliente_id
        WHERE $sqlWhere
    ");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    if ($sqlOffsetOverride !== null) {
        $offset = max(0, $sqlOffsetOverride);
    } else {
        $page       = max(1, $page);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = min($page, $totalPages);
        $offset     = ($page - 1) * $perPage;
    }

    $sql = "
        SELECT
            ch.id, ch.cliente_id, ch.titulo, ch.descricao,
            ch.endereco_completo, ch.latitude, ch.longitude,
            ch.tecnico_user_id, ch.finalizado_operador_em, ch.aprovado_gestor_em,
            ch.prioridade, ch.status, ch.responsavel,
            DATE_FORMAT(ch.aberto_em, '%Y-%m-%d %H:%i') AS data,
            c.empresa AS cliente,
            ut.nome AS tecnico_nome
        FROM chamados ch
        JOIN clientes c ON c.id = ch.cliente_id
        LEFT JOIN usuarios ut ON ut.id = ch.tecnico_user_id
        WHERE $sqlWhere
        ORDER BY ch.aberto_em DESC, ch.id DESC
        LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id']         = (int) $r['id'];
        $r['cliente_id'] = (int) $r['cliente_id'];
        $r['tecnico_user_id'] = isset($r['tecnico_user_id']) && $r['tecnico_user_id'] !== null ? (int) $r['tecnico_user_id'] : null;
        _repo_chamado_row_normalizar_geo($r);
    }
    unset($r);

    return ['rows' => $rows, 'total' => $total];
}

/**
 * Boletim de medição: chamados da matriz no período, com totais de itens do catálogo (produto/serviço) em chamado_itens.
 *
 * @return array{rows: list<array<string,mixed>>, totais: array{n_chamados:int, valor_materiais:float, valor_servicos:float, valor_total:float}}
 */
function repo_medicao_chamados_relatorio(int $empresaRaizId, string $dataDe, string $dataAte): array
{
    $ret = [
        'rows'   => [],
        'totais' => [
            'n_chamados'       => 0,
            'valor_materiais'  => 0.0,
            'valor_servicos'   => 0.0,
            'valor_total'      => 0.0,
        ],
    ];
    $pdo = db();
    if (!$pdo || $empresaRaizId <= 0) {
        return $ret;
    }
    $dataDe = trim($dataDe);
    $dataAte = trim($dataAte);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDe) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAte)) {
        return $ret;
    }
    if ($dataDe > $dataAte) {
        return $ret;
    }
    $sql = "
        SELECT
            ch.id,
            ch.cliente_id,
            ch.titulo,
            ch.status,
            ch.prioridade,
            ch.descricao,
            ch.endereco_completo,
            ch.latitude,
            ch.longitude,
            ch.os_bairro,
            ch.os_logradouro,
            ch.os_numero,
            pi.codigo_poste AS ponto_codigo_poste,
            DATE_FORMAT(ch.aberto_em, '%Y-%m-%d %H:%i') AS aberto_em,
            DATE_FORMAT(ch.aberto_em, '%d/%m/%Y') AS aberto_em_br,
            c.empresa AS unidade_nome,
            ut.nome AS tecnico_nome,
            sv.nome AS servico_principal_nome,
            COALESCE(sv.valor_unitario, 0) AS servico_catalogo_valor_unit,
            (
                SELECT COALESCE(SUM(ci.subtotal), 0)
                FROM chamado_itens ci
                INNER JOIN cliente_itens it ON it.id = ci.item_id
                WHERE ci.chamado_id = ch.id AND ci.movimento = 'utilizado' AND it.tipo = 'produto'
            ) AS valor_materiais,
            (
                SELECT COALESCE(SUM(ci.subtotal), 0)
                FROM chamado_itens ci
                INNER JOIN cliente_itens it ON it.id = ci.item_id
                WHERE ci.chamado_id = ch.id AND ci.movimento = 'utilizado' AND it.tipo = 'servico'
            ) AS valor_servicos_itens,
            (
                SELECT COALESCE(SUM(ci.subtotal), 0)
                FROM chamado_itens ci
                WHERE ci.chamado_id = ch.id AND ci.movimento = 'devolvido'
            ) AS valor_devolvidos
        FROM chamados ch
        INNER JOIN clientes c ON c.id = ch.cliente_id
        LEFT JOIN usuarios ut ON ut.id = ch.tecnico_user_id
        LEFT JOIN cliente_itens sv ON sv.id = ch.servico_id
        LEFT JOIN pontos_iluminacao pi ON pi.id = ch.ponto_iluminacao_id
        WHERE ch.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
          AND DATE(ch.aberto_em) BETWEEN ? AND ?
        ORDER BY ch.aberto_em ASC, ch.id ASC
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$empresaRaizId, $empresaRaizId, $dataDe, $dataAte]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $tm = 0.0;
    $ts = 0.0;
    foreach ($rows as &$r) {
        $r['id']                         = (int) $r['id'];
        $r['cliente_id']                 = (int) $r['cliente_id'];
        $r['valor_materiais']            = (float) ($r['valor_materiais'] ?? 0);
        $r['valor_servicos_itens']       = (float) ($r['valor_servicos_itens'] ?? 0);
        $r['valor_devolvidos']           = (float) ($r['valor_devolvidos'] ?? 0);
        $r['servico_catalogo_valor_unit'] = (float) ($r['servico_catalogo_valor_unit'] ?? 0);
        $r['valor_total_linha']          = $r['valor_materiais'] + $r['valor_servicos_itens'];
        $latRaw                          = $r['latitude'] ?? null;
        $lngRaw                          = $r['longitude'] ?? null;
        $r['latitude']                   = ($latRaw !== null && $latRaw !== '') ? (float) $latRaw : null;
        $r['longitude']                  = ($lngRaw !== null && $lngRaw !== '') ? (float) $lngRaw : null;
        $r['ponto_codigo_poste']         = isset($r['ponto_codigo_poste']) && $r['ponto_codigo_poste'] !== null
            ? (string) $r['ponto_codigo_poste'] : '';
        $tm += $r['valor_materiais'];
        $ts += $r['valor_servicos_itens'];
    }
    unset($r);
    $ret['rows']                      = $rows;
    $ret['totais']['n_chamados']      = count($rows);
    $ret['totais']['valor_materiais'] = $tm;
    $ret['totais']['valor_servicos'] = $ts;
    $ret['totais']['valor_total']     = $tm + $ts;

    return $ret;
}

/**
 * Resumo dos itens lançados em chamados de uma medição mensal, separados por movimento.
 *
 * @return array{
 *   rows: list<array<string,mixed>>,
 *   totais: array{
 *     qtd_usada:float,
 *     qtd_devolvida:float,
 *     valor_usado:float,
 *     valor_devolvido:float,
 *     custo_liquido:float,
 *     n_itens_usados:int,
 *     n_itens_devolvidos:int
 *   }
 * }
 */
function repo_medicao_itens_movimento_resumo(int $empresaRaizId, string $dataDe, string $dataAte): array
{
    $ret = [
        'rows'   => [],
        'totais' => [
            'qtd_usada'          => 0.0,
            'qtd_devolvida'      => 0.0,
            'valor_usado'        => 0.0,
            'valor_devolvido'    => 0.0,
            'custo_liquido'      => 0.0,
            'n_itens_usados'     => 0,
            'n_itens_devolvidos' => 0,
        ],
    ];
    $pdo = db();
    if (!$pdo || $empresaRaizId <= 0) {
        return $ret;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDe) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAte) || $dataDe > $dataAte) {
        return $ret;
    }

    $sql = "
        SELECT
            ci.movimento,
            it.tipo AS item_tipo,
            it.codigo AS item_codigo,
            it.nome AS item_nome,
            it.unidade,
            SUM(ci.quantidade) AS quantidade,
            SUM(ci.subtotal) AS valor_total,
            COUNT(*) AS n_lancamentos,
            COUNT(DISTINCT ch.id) AS n_chamados
        FROM chamado_itens ci
        INNER JOIN chamados ch ON ch.id = ci.chamado_id
        INNER JOIN cliente_itens it ON it.id = ci.item_id
        WHERE ch.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
          AND DATE(ch.aberto_em) BETWEEN ? AND ?
        GROUP BY ci.movimento, it.tipo, it.codigo, it.nome, it.unidade
        ORDER BY FIELD(ci.movimento, 'utilizado', 'devolvido'), it.tipo ASC, it.nome ASC
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$empresaRaizId, $empresaRaizId, $dataDe, $dataAte]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$r) {
        $mov = (string) ($r['movimento'] ?? '');
        $qtd = (float) ($r['quantidade'] ?? 0);
        $val = (float) ($r['valor_total'] ?? 0);
        $r['quantidade']    = $qtd;
        $r['valor_total']   = $val;
        $r['n_lancamentos'] = (int) ($r['n_lancamentos'] ?? 0);
        $r['n_chamados']    = (int) ($r['n_chamados'] ?? 0);

        if ($mov === 'devolvido') {
            $ret['totais']['qtd_devolvida'] += $qtd;
            $ret['totais']['valor_devolvido'] += $val;
            $ret['totais']['n_itens_devolvidos']++;
        } else {
            $ret['totais']['qtd_usada'] += $qtd;
            $ret['totais']['valor_usado'] += $val;
            $ret['totais']['n_itens_usados']++;
        }
    }
    unset($r);

    $ret['totais']['custo_liquido'] = $ret['totais']['valor_usado'] - $ret['totais']['valor_devolvido'];
    $ret['rows'] = $rows;

    return $ret;
}

/**
 * Resumo por mês civil na matriz — para listagem de medições mensais.
 * Inclui meses com chamados (data de abertura) e meses que só existem por importação BM.
 *
 * @return list<array{ym:string,data_de:string,data_ate:string,n_chamados:int,valor_materiais:float,valor_servicos:float,valor_total:float}>
 */
function repo_medicao_resumo_mensal_list(int $empresaRaizId, int $limiteLinhas = 60): array
{
    if ($empresaRaizId <= 0) {
        return [];
    }
    $pdo = db();
    if (!$pdo) {
        return [];
    }
    $limiteLinhas = max(1, min(120, $limiteLinhas));
    try {
        $sql = "
            SELECT
                DATE_FORMAT(ch.aberto_em, '%Y-%m') AS ym,
                COUNT(DISTINCT ch.id) AS n_chamados,
                COALESCE(SUM(COALESCE(agg.valor_materiais, 0)), 0) AS valor_materiais,
                COALESCE(SUM(COALESCE(agg.valor_servicos, 0)), 0) AS valor_servicos
            FROM chamados ch
            LEFT JOIN (
                SELECT ci.chamado_id,
                    SUM(CASE WHEN it.tipo = 'produto' AND ci.movimento = 'utilizado' THEN ci.subtotal ELSE 0 END) AS valor_materiais,
                    SUM(CASE WHEN it.tipo = 'servico' AND ci.movimento = 'utilizado' THEN ci.subtotal ELSE 0 END) AS valor_servicos
                FROM chamado_itens ci
                INNER JOIN cliente_itens it ON it.id = ci.item_id
                GROUP BY ci.chamado_id
            ) agg ON agg.chamado_id = ch.id
            WHERE ch.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
            GROUP BY DATE_FORMAT(ch.aberto_em, '%Y-%m')
            ORDER BY ym DESC
            LIMIT 240";
        $st = $pdo->prepare($sql);
        $st->execute([$empresaRaizId, $empresaRaizId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $byYm = [];
        foreach ($rows as $row) {
            $ym = (string) ($row['ym'] ?? '');
            if ($ym === '' || !preg_match('/^\d{4}-\d{2}$/', $ym)) {
                continue;
            }
            $dataDe = $ym . '-01';
            $dataAte = date('Y-m-t', strtotime($dataDe));
            $vm = (float) ($row['valor_materiais'] ?? 0);
            $vs = (float) ($row['valor_servicos'] ?? 0);
            $byYm[$ym] = [
                'ym'               => $ym,
                'data_de'          => $dataDe,
                'data_ate'         => $dataAte,
                'n_chamados'       => (int) ($row['n_chamados'] ?? 0),
                'valor_materiais'  => $vm,
                'valor_servicos'   => $vs,
                'valor_total'      => $vm + $vs,
            ];
        }

        $stImp = $pdo->prepare(
            'SELECT i.ref_ym AS ym, COALESCE(SUM(mil.valor_medido_periodo), 0) AS valor_imp
             FROM medicao_imports i
             LEFT JOIN medicao_import_linhas mil ON mil.import_id = i.id
             WHERE i.cliente_matriz_id = ?
             GROUP BY i.ref_ym'
        );
        $stImp->execute([$empresaRaizId]);
        $impRows = $stImp->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($impRows as $ir) {
            $ym = (string) ($ir['ym'] ?? '');
            if ($ym === '' || !preg_match('/^\d{4}-\d{2}$/', $ym)) {
                continue;
            }
            $vi = (float) ($ir['valor_imp'] ?? 0);
            if (!isset($byYm[$ym])) {
                $dataDe = $ym . '-01';
                $dataAte = date('Y-m-t', strtotime($dataDe));
                $byYm[$ym] = [
                    'ym'               => $ym,
                    'data_de'          => $dataDe,
                    'data_ate'         => $dataAte,
                    'n_chamados'       => 0,
                    'valor_materiais'  => 0.0,
                    'valor_servicos'   => $vi,
                    'valor_total'      => $vi,
                ];
            }
        }

        $merged = array_values($byYm);
        usort($merged, static function (array $a, array $b): int {
            return strcmp((string) ($b['ym'] ?? ''), (string) ($a['ym'] ?? ''));
        });

        return array_slice($merged, 0, $limiteLinhas);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Substitui importação BM do mês (uma por matriz + ref_ym).
 *
 * @param list<array<string,mixed>> $linhas saída de medicao_csv_parse_bm_planilha()['linhas']
 * @return array{ok:bool, erro:string}
 */
function repo_medicao_import_substituir(
    int $clienteMatrizId,
    string $refYm,
    string $nomeArquivo,
    ?string $importadoPor,
    ?int $idxQtdMedido,
    ?int $idxValorMedido,
    array $linhas
): array {
    $ret = ['ok' => false, 'erro' => ''];
    if ($clienteMatrizId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $refYm)) {
        $ret['erro'] = 'Parâmetros inválidos.';

        return $ret;
    }
    $pdo = db();
    if (!$pdo) {
        $ret['erro'] = 'Banco indisponível.';

        return $ret;
    }

    try {
        $pdo->beginTransaction();
        $stDel = $pdo->prepare('DELETE FROM medicao_imports WHERE cliente_matriz_id = ? AND ref_ym = ?');
        $stDel->execute([$clienteMatrizId, $refYm]);

        $stIns = $pdo->prepare(
            'INSERT INTO medicao_imports (cliente_matriz_id, ref_ym, nome_arquivo, importado_por, idx_qtd_medido, idx_valor_medido)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stIns->execute([
            $clienteMatrizId,
            $refYm,
            mb_substr($nomeArquivo, 0, 255),
            $importadoPor !== null && $importadoPor !== '' ? mb_substr($importadoPor, 0, 120) : null,
            $idxQtdMedido,
            $idxValorMedido,
        ]);
        $importId = (int) $pdo->lastInsertId();
        if ($importId <= 0) {
            throw new RuntimeException('Falha ao criar registro de importação.');
        }

        $stLin = $pdo->prepare(
            'INSERT INTO medicao_import_linhas (
                import_id, item_codigo, descricao, unidade,
                qtd_prevista, qtd_total, preco_unitario,
                qtd_medido_periodo, valor_medido_periodo, ordem
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($linhas as $ord => $L) {
            $stLin->execute([
                $importId,
                mb_substr((string) ($L['item_codigo'] ?? ''), 0, 32),
                (string) ($L['descricao'] ?? ''),
                mb_substr((string) ($L['unidade'] ?? ''), 0, 20),
                $L['qtd_prevista'] ?? null,
                $L['qtd_total'] ?? null,
                $L['preco_unitario'] ?? null,
                $L['qtd_medido_periodo'] ?? null,
                $L['valor_medido_periodo'] ?? null,
                (int) $ord,
            ]);
        }

        $pdo->commit();
        $ret['ok'] = true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $ret['erro'] = 'Não foi possível gravar a importação. Rode a migração SQL (medicao_imports) ou verifique o banco.';
    }

    return $ret;
}

/**
 * @return array{cabecalho: array<string,mixed>|null, linhas: list<array<string,mixed>>}
 */
function repo_medicao_import_fetch(int $clienteMatrizId, string $refYm): array
{
    $vazio = ['cabecalho' => null, 'linhas' => []];
    if ($clienteMatrizId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $refYm)) {
        return $vazio;
    }
    $pdo = db();
    if (!$pdo) {
        return $vazio;
    }
    try {
        $st = $pdo->prepare(
            'SELECT id, nome_arquivo, importado_em, importado_por, idx_qtd_medido, idx_valor_medido
             FROM medicao_imports WHERE cliente_matriz_id = ? AND ref_ym = ? LIMIT 1'
        );
        $st->execute([$clienteMatrizId, $refYm]);
        $cab = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cab) {
            return $vazio;
        }
        $importId = (int) $cab['id'];
        $st2 = $pdo->prepare(
            'SELECT mil.id AS linha_id, item_codigo, descricao, unidade, qtd_prevista, qtd_total, preco_unitario,
                    qtd_medido_periodo, valor_medido_periodo, ordem
             FROM medicao_import_linhas mil WHERE mil.import_id = ? ORDER BY mil.ordem ASC, mil.id ASC'
        );
        $st2->execute([$importId]);
        $linhas = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return ['cabecalho' => $cab, 'linhas' => $linhas];
    } catch (Throwable $e) {
        return $vazio;
    }
}

/**
 * Lista linhas importadas da BM para exibição como chamados virtuais no histórico geral.
 *
 * @return list<array<string,mixed>>
 */
function repo_medicao_import_linhas_fetch_all(int $clienteMatrizId, int $limiteImportacoes = 24): array
{
    if ($clienteMatrizId <= 0) {
        return [];
    }
    $pdo = db();
    if (!$pdo) {
        return [];
    }
    $limiteImportacoes = max(1, min(120, $limiteImportacoes));
    try {
        $sql = "
            SELECT
                mil.id AS linha_id,
                mi.ref_ym,
                mil.item_codigo,
                mil.descricao,
                mil.unidade,
                mil.qtd_prevista,
                mil.qtd_total,
                mil.preco_unitario,
                mil.qtd_medido_periodo,
                mil.valor_medido_periodo,
                mil.ordem
            FROM medicao_imports mi
            INNER JOIN medicao_import_linhas mil ON mil.import_id = mi.id
            INNER JOIN (
                SELECT id
                FROM medicao_imports
                WHERE cliente_matriz_id = ?
                ORDER BY ref_ym DESC
                LIMIT " . (int) $limiteImportacoes . "
            ) ultimas ON ultimas.id = mi.id
            WHERE mi.cliente_matriz_id = ?
            ORDER BY mi.ref_ym DESC, mil.ordem ASC, mil.id ASC
        ";
        $st = $pdo->prepare($sql);
        $st->execute([$clienteMatrizId, $clienteMatrizId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['linha_id']             = (int) ($r['linha_id'] ?? 0);
            $r['qtd_prevista']         = $r['qtd_prevista'] !== null ? (float) $r['qtd_prevista'] : null;
            $r['qtd_total']            = $r['qtd_total'] !== null ? (float) $r['qtd_total'] : null;
            $r['preco_unitario']       = $r['preco_unitario'] !== null ? (float) $r['preco_unitario'] : null;
            $r['qtd_medido_periodo']   = $r['qtd_medido_periodo'] !== null ? (float) $r['qtd_medido_periodo'] : null;
            $r['valor_medido_periodo'] = $r['valor_medido_periodo'] !== null ? (float) $r['valor_medido_periodo'] : null;
            $r['ordem']                = (int) ($r['ordem'] ?? 0);
        }
        unset($r);

        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

function repo_delete_chamado(int $id): bool
{
    $pdo = db();
    if (!$pdo || $id <= 0) {
        return false;
    }
    $dir = __DIR__ . '/../uploads/chamados/' . $id;
    if (is_dir($dir)) {
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($dir);
    }
    $stmt = $pdo->prepare('DELETE FROM chamados WHERE id = ?');

    return $stmt->execute([$id]);
}

/**
 * Dúvidas de suporte só do cliente (portal).
 *
 * @return list<array<string,mixed>>
 */
function repo_suporte_por_cliente(int $clienteId): array
{
    $pdo = db();
    if (!$pdo || $clienteId <= 0) {
        return [];
    }
    $stmt = $pdo->prepare('
        SELECT
            s.id, s.cliente_id, s.pergunta, s.detalhe, s.status, s.resposta,
            DATE_FORMAT(s.enviado_em, \'%Y-%m-%d %H:%i\') AS data
        FROM suporte s
        WHERE s.cliente_id = ?
        ORDER BY s.enviado_em DESC, s.id DESC
    ');
    $stmt->execute([$clienteId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id']         = (int) $r['id'];
        $r['cliente_id'] = (int) $r['cliente_id'];
    }
    unset($r);

    return $rows;
}

/**
 * Acessos ligados à ficha do cliente: contas do portal e operadores da empresa
 * gestora. Gestores ficam na gestão da licença e não aparecem na ficha do cliente.
 * Funciona tanto na ficha da matriz quanto de uma filial (sempre resolve a empresa raiz).
 *
 * @return list<array<string,mixed>>
 */
function repo_usuarios_por_cliente(int $clienteId): array
{
    $pdo = db();
    if (!$pdo || $clienteId <= 0) {
        return [];
    }
    try {
        $st = $pdo->prepare('SELECT id, empresa_id FROM clientes WHERE id = ? LIMIT 1');
        $st->execute([$clienteId]);
        $rowCli = $st->fetch(PDO::FETCH_ASSOC);
        if (!$rowCli) {
            return [];
        }
        $empresaPai = isset($rowCli['empresa_id']) && $rowCli['empresa_id'] !== null && $rowCli['empresa_id'] !== ''
            ? (int) $rowCli['empresa_id'] : 0;
        $raizId     = $empresaPai > 0 ? $empresaPai : (int) $rowCli['id'];

        $rows = [];

        $st = $pdo->prepare('
            SELECT id, nome, email, perfil, iniciais, cliente_id,
                   DATE_FORMAT(criado_em, \'%Y-%m-%d %H:%i\') AS criado_em
            FROM usuarios
            WHERE perfil = \'cliente\'
              AND cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
            ORDER BY id ASC
        ');
        $st->execute([$raizId, $raizId]);
        $rows = array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);

        $st = $pdo->prepare('
            SELECT id, nome, email, perfil, iniciais, cliente_id,
                   DATE_FORMAT(criado_em, \'%Y-%m-%d %H:%i\') AS criado_em
            FROM usuarios
            WHERE perfil = \'operador\' AND empresa_id = ?
            ORDER BY nome ASC, id ASC
        ');
        $st->execute([$raizId]);
        $rows = array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);

        $st = $pdo->prepare('
            SELECT id, nome, email, perfil, iniciais, cliente_id,
                   DATE_FORMAT(criado_em, \'%Y-%m-%d %H:%i\') AS criado_em
            FROM usuarios
            WHERE perfil = \'gestor\' AND empresa_id = ?
            ORDER BY nome ASC, id ASC
        ');
        $st->execute([$raizId]);
        $rows = array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);

        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r;
        }
        $rows = array_values($byId);

        usort($rows, static function (array $a, array $b): int {
            $rank = static function (string $p): int {
                if ($p === 'cliente') {
                    return 0;
                }
                if ($p === 'gestor' || $p === 'operador') {
                    return 1;
                }

                return 9;
            };
            $pa = $rank((string) ($a['perfil'] ?? ''));
            $pb = $rank((string) ($b['perfil'] ?? ''));
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }

            return strcasecmp((string) ($a['nome'] ?? ''), (string) ($b['nome'] ?? ''));
        });

        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            if (array_key_exists('cliente_id', $r)) {
                $r['cliente_id'] = isset($r['cliente_id']) && $r['cliente_id'] !== null && $r['cliente_id'] !== ''
                    ? (int) $r['cliente_id'] : null;
            }
        }
        unset($r);

        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Gestores do painel interno vinculados à empresa raiz (`usuarios.empresa_id`).
 *
 * @return list<array{id:int,nome:string}>
 */
function repo_usuarios_gestores_por_empresa_raiz(int $empresaRaizClienteId): array
{
    $pdo = db();
    if (!$pdo || $empresaRaizClienteId <= 0) {
        return [];
    }
    try {
        $st = $pdo->prepare(
            'SELECT id, nome FROM usuarios WHERE perfil = \'gestor\' AND empresa_id = ? ORDER BY nome ASC, id ASC'
        );
        $st->execute([$empresaRaizClienteId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out  = [];
        foreach ($rows as $r) {
            $id = (int) ($r['id'] ?? 0);
            $nome = trim((string) ($r['nome'] ?? ''));
            if ($id > 0 && $nome !== '') {
                $out[] = ['id' => $id, 'nome' => $nome];
            }
        }

        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Métricas agregadas para o dashboard admin (com MySQL).
 *
 * Inclui pontos_total (pontos_iluminacao) e medicao_mes_valor (soma valor_medido_periodo
 * das linhas BM do mês corrente, ref YYYY-MM).
 *
 * @return array<string,int|float|string>|null
 */
function repo_dashboard_admin_stats(?int $clienteIdEscopo = null): ?array
{
    $pdo = db();
    if (!$pdo) {
        return null;
    }
    $cid = ($clienteIdEscopo !== null && $clienteIdEscopo > 0) ? (int) $clienteIdEscopo : null;
    try {
        if ($cid !== null) {
            $chF = 'cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)';
            $st = $pdo->prepare("SELECT COUNT(*) FROM chamados WHERE status = 'Aberto' AND $chF");
            $st->execute([$cid, $cid]);
            $abertos = (int) $st->fetchColumn();
            $st = $pdo->prepare("SELECT COUNT(*) FROM chamados WHERE status = 'Em andamento' AND $chF");
            $st->execute([$cid, $cid]);
            $andamento = (int) $st->fetchColumn();
            $st = $pdo->prepare("SELECT COUNT(*) FROM chamados WHERE prioridade IN ('Alta','Urgente') AND status NOT IN ('Resolvido','Fechado','Cancelado') AND $chF");
            $st->execute([$cid, $cid]);
            $urgentes = (int) $st->fetchColumn();
            $st = $pdo->prepare("
                SELECT COUNT(*) FROM chamados
                WHERE status IN ('Resolvido','Fechado')
                  AND aberto_em >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                  AND $chF
            ");
            $st->execute([$cid, $cid]);
            $res7d = (int) $st->fetchColumn();

            $pendContas = 0;
            $st = $pdo->prepare("
                SELECT COUNT(*) FROM suporte WHERE status IN ('Aberta','Respondendo','Pendente') AND $chF
            ");
            $st->execute([$cid, $cid]);
            $supAbertas = (int) $st->fetchColumn();

            $valorAberto = 0.0;

            $st = $pdo->prepare('SELECT COUNT(*) FROM clientes WHERE id = ? OR empresa_id = ?');
            $st->execute([$cid, $cid]);
            $totalCli = (int) $st->fetchColumn();
            $st = $pdo->prepare("SELECT COUNT(*) FROM clientes WHERE (id = ? OR empresa_id = ?) AND status = 'Ativo'");
            $st->execute([$cid, $cid]);
            $ativos = (int) $st->fetchColumn();
        } else {
            $abertos = (int) $pdo->query("SELECT COUNT(*) FROM chamados WHERE status = 'Aberto'")->fetchColumn();
            $andamento = (int) $pdo->query("SELECT COUNT(*) FROM chamados WHERE status = 'Em andamento'")->fetchColumn();
            $urgentes = (int) $pdo->query("SELECT COUNT(*) FROM chamados WHERE prioridade IN ('Alta','Urgente') AND status NOT IN ('Resolvido','Fechado','Cancelado')")->fetchColumn();
            $res7d = (int) $pdo->query("
                SELECT COUNT(*) FROM chamados
                WHERE status IN ('Resolvido','Fechado')
                  AND aberto_em >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ")->fetchColumn();

            $pendContas = 0;
            $supAbertas = (int) $pdo->query("
                SELECT COUNT(*) FROM suporte WHERE status IN ('Aberta','Respondendo','Pendente')
            ")->fetchColumn();

            $valorAberto = 0.0;

            $totalCli = (int) $pdo->query('SELECT COUNT(*) FROM clientes')->fetchColumn();
            $ativos = (int) $pdo->query("SELECT COUNT(*) FROM clientes WHERE status = 'Ativo'")->fetchColumn();
        }

        $refYmDash = date('Y-m');
        if ($cid !== null) {
            $st = $pdo->prepare('
                SELECT COUNT(*) FROM pontos_iluminacao
                WHERE cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
            ');
            $st->execute([$cid, $cid]);
            $pontosTotal = (int) $st->fetchColumn();

            $midMedicao = repo_cliente_matriz_raiz_id($cid);
            if ($midMedicao <= 0) {
                $midMedicao = $cid;
            }
            $st = $pdo->prepare('
                SELECT COALESCE(SUM(mil.valor_medido_periodo), 0)
                FROM medicao_import_linhas mil
                INNER JOIN medicao_imports mi ON mi.id = mil.import_id
                WHERE mi.ref_ym = ? AND mi.cliente_matriz_id = ?
            ');
            $st->execute([$refYmDash, $midMedicao]);
            $medicaoMesValor = (float) $st->fetchColumn();
        } else {
            $pontosTotal = (int) $pdo->query('SELECT COUNT(*) FROM pontos_iluminacao')->fetchColumn();
            $st = $pdo->prepare('
                SELECT COALESCE(SUM(mil.valor_medido_periodo), 0)
                FROM medicao_import_linhas mil
                INNER JOIN medicao_imports mi ON mi.id = mil.import_id
                WHERE mi.ref_ym = ?
            ');
            $st->execute([$refYmDash]);
            $medicaoMesValor = (float) $st->fetchColumn();
        }

        return [
            'ch_abertos'       => $abertos,
            'ch_andamento'     => $andamento,
            'ch_urgentes'      => $urgentes,
            'ch_resolvidos_7d' => $res7d,
            'contas_pendentes' => $pendContas,
            'valor_em_aberto'  => $valorAberto,
            'suporte_abertas'  => $supAbertas,
            'clientes_total'   => $totalCli,
            'clientes_ativos'  => $ativos,
            'pontos_total'     => $pontosTotal,
            'medicao_mes_valor' => $medicaoMesValor,
        ];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Contagens reais para as tags da sidebar admin (MySQL).
 *
 * @return array<string, string> chaves: clientes, usuarios, chamados, catalogo, suporte
 */
function repo_sidebar_admin_tags(?int $clienteIdEscopo = null): array
{
    $pdo = db();
    if (!$pdo) {
        return [];
    }
    $cid = ($clienteIdEscopo !== null && $clienteIdEscopo > 0) ? (int) $clienteIdEscopo : null;
    try {
        if ($cid !== null) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM clientes WHERE id = ? OR empresa_id = ?');
            $st->execute([$cid, $cid]);
            $nCli = (int) $st->fetchColumn();
            $st = $pdo->prepare('
                SELECT COUNT(*) FROM usuarios WHERE
                    (perfil IN (\'operador\',\'gestor\') AND empresa_id = ?)
                    OR (perfil = \'cliente\' AND (cliente_id = ? OR cliente_id IN (SELECT id FROM clientes WHERE empresa_id = ?)))
            ');
            $st->execute([$cid, $cid, $cid]);
            $nUsu = (int) $st->fetchColumn();
            $chF = 'cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)';
            $st = $pdo->prepare("SELECT COUNT(*) FROM chamados WHERE $chF");
            $st->execute([$cid, $cid]);
            $nCh = (int) $st->fetchColumn();
            $st = $pdo->prepare('SELECT COUNT(*) FROM cliente_itens WHERE cliente_id = ? OR empresa_id = ?');
            $st->execute([$cid, $cid]);
            $nCat = (int) $st->fetchColumn();
            $st = $pdo->prepare('
                SELECT COUNT(*) FROM pontos_iluminacao
                WHERE cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
            ');
            $st->execute([$cid, $cid]);
            $nPontos = (int) $st->fetchColumn();
            $st = $pdo->prepare("
                SELECT COUNT(*) FROM suporte
                WHERE $chF AND status IN ('Aberta','Respondendo','Pendente')
            ");
            $st->execute([$cid, $cid]);
            $nSp = (int) $st->fetchColumn();
        } else {
            $nCli = (int) $pdo->query('SELECT COUNT(*) FROM clientes')->fetchColumn();
            $nUsu = (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
            $nCh  = (int) $pdo->query('SELECT COUNT(*) FROM chamados')->fetchColumn();
            $nCat = (int) $pdo->query('SELECT COUNT(*) FROM cliente_itens')->fetchColumn();
            $nPontos = (int) $pdo->query('SELECT COUNT(*) FROM pontos_iluminacao')->fetchColumn();
            $nSp  = (int) $pdo->query("
                SELECT COUNT(*) FROM suporte
                WHERE status IN ('Aberta','Respondendo','Pendente')
            ")->fetchColumn();
        }

        $nOs = 0;
        try {
            if ($cid !== null) {
                $st = $pdo->prepare('
                    SELECT COUNT(*) FROM os_pedidos
                    WHERE cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
                ');
                $st->execute([$cid, $cid]);
            } else {
                $st = $pdo->query('SELECT COUNT(*) FROM os_pedidos');
            }
            if ($st) {
                $nOs = (int) $st->fetchColumn();
            }
        } catch (Throwable $e) {
            $nOs = 0;
        }

        return [
            'clientes' => (string) $nCli,
            'usuarios' => (string) $nUsu,
            'chamados' => (string) $nCh,
            'medicao'  => (string) $nCh,
            'os'       => (string) $nOs,
            'catalogo' => (string) $nCat,
            'pontos_iluminacao' => (string) $nPontos,
            'suporte'  => (string) $nSp,
        ];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Contagens para a sidebar do cliente (por conta ou por toda a matriz).
 *
 * @return array<string, string> chamados, medicao, documentos, suporte
 */
function repo_sidebar_cliente_tags(int $clienteId, bool $todaMatriz = false): array
{
    if ($clienteId <= 0) {
        return [];
    }
    $pdo = db();
    if (!$pdo) {
        return [];
    }
    try {
        if ($todaMatriz) {
            $st = $pdo->prepare('
                SELECT COUNT(*) FROM chamados
                WHERE cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
            ');
            $st->execute([$clienteId, $clienteId]);
            $nCh = (int) $st->fetchColumn();

            $st = $pdo->prepare('
                SELECT COUNT(*) FROM cliente_anexos
                WHERE cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
            ');
            $st->execute([$clienteId, $clienteId]);
            $nDoc = (int) $st->fetchColumn();

            $st = $pdo->prepare('
                SELECT COUNT(*) FROM pontos_iluminacao
                WHERE cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
            ');
            $st->execute([$clienteId, $clienteId]);
            $nPontos = (int) $st->fetchColumn();

            $st = $pdo->prepare('
                SELECT COUNT(*) FROM suporte
                WHERE cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
                  AND status IN (\'Aberta\',\'Respondendo\',\'Pendente\')
            ');
            $st->execute([$clienteId, $clienteId]);
            $nSp = (int) $st->fetchColumn();

            $nOs = 0;
            try {
                $st = $pdo->prepare('
                    SELECT COUNT(*) FROM os_pedidos
                    WHERE cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
                ');
                $st->execute([$clienteId, $clienteId]);
                $nOs = (int) $st->fetchColumn();
            } catch (Throwable $e) {
                $nOs = 0;
            }
        } else {
            $st = $pdo->prepare('SELECT COUNT(*) FROM chamados WHERE cliente_id = ?');
            $st->execute([$clienteId]);
            $nCh = (int) $st->fetchColumn();

            $st = $pdo->prepare('SELECT COUNT(*) FROM cliente_anexos WHERE cliente_id = ?');
            $st->execute([$clienteId]);
            $nDoc = (int) $st->fetchColumn();

            $st = $pdo->prepare('SELECT COUNT(*) FROM pontos_iluminacao WHERE cliente_id = ?');
            $st->execute([$clienteId]);
            $nPontos = (int) $st->fetchColumn();

            $st = $pdo->prepare('
                SELECT COUNT(*) FROM suporte
                WHERE cliente_id = ?
                  AND status IN (\'Aberta\',\'Respondendo\',\'Pendente\')
            ');
            $st->execute([$clienteId]);
            $nSp = (int) $st->fetchColumn();

            $nOs = 0;
            try {
                $st = $pdo->prepare('SELECT COUNT(*) FROM os_pedidos WHERE cliente_id = ?');
                $st->execute([$clienteId]);
                $nOs = (int) $st->fetchColumn();
            } catch (Throwable $e) {
                $nOs = 0;
            }
        }

        return [
            'chamados'   => (string) $nCh,
            'medicao'    => (string) $nCh,
            'os'         => (string) $nOs,
            'pontos_iluminacao' => (string) $nPontos,
            'documentos' => (string) $nDoc,
            'suporte'    => (string) $nSp,
        ];
    } catch (Throwable $e) {
        return [];
    }
}

/* ---- Usuários (login) ---- */
function repo_create_usuario(array $d): ?int
{
    $pdo = db();
    if (!$pdo) return null;

    $hash = password_hash($d['senha'] ?? '', PASSWORD_BCRYPT);
    $perfil = strtolower(trim((string) ($d['perfil'] ?? 'cliente')));
    if (!in_array($perfil, ['admin', 'cliente', 'operador', 'gestor'], true)) {
        return null;
    }
    $mpf    = null;
    $cid = !empty($d['cliente_id']) ? (int) $d['cliente_id'] : null;
    $eid = !empty($d['empresa_id']) ? (int) $d['empresa_id'] : null;
    if (in_array($perfil, ['operador', 'gestor'], true)) {
        $cid = null;
        if ($eid === null || $eid <= 0) {
            return null;
        }
    } else {
        $eid = null;
    }
    $stmt = $pdo->prepare('
        INSERT INTO usuarios (nome, email, senha_hash, perfil, modulo_perfil, cliente_id, empresa_id, iniciais)
        VALUES (?,?,?,?,?,?,?,?)
    ');
    $stmt->execute([
        $d['nome']       ?? '',
        trim($d['email'] ?? ''),
        $hash,
        $perfil,
        $mpf,
        $cid,
        $eid,
        $d['iniciais']   ?? null,
    ]);
    return (int) $pdo->lastInsertId();
}

function repo_email_existe(string $email): bool
{
    $pdo = db();
    if (!$pdo) return false;
    $stmt = $pdo->prepare('SELECT 1 FROM usuarios WHERE email = ? LIMIT 1');
    $stmt->execute([trim($email)]);
    return (bool) $stmt->fetchColumn();
}

/* ---- Chamados ---- */
function repo_create_chamado(array $d): ?int
{
    $pdo = db();
    if (!$pdo) return null;

    [$la, $lo] = _repo_parse_latlng_pair(
        isset($d['latitude']) ? (string) $d['latitude'] : null,
        isset($d['longitude']) ? (string) $d['longitude'] : null
    );
    $composedAddr = chamado_os_compor_endereco_completo($d);
    $endereco = $composedAddr !== null && $composedAddr !== ''
        ? $composedAddr
        : trim((string) ($d['endereco_completo'] ?? ''));
    $endereco = $endereco !== '' ? $endereco : null;
    $pontoId = !empty($d['ponto_iluminacao_id']) ? (int) $d['ponto_iluminacao_id'] : null;
    if ($pontoId !== null && $pontoId > 0) {
        $ponto = repo_ponto_iluminacao($pontoId);
        if ($ponto && (int) ($ponto['cliente_id'] ?? 0) === (int) ($d['cliente_id'] ?? 0)) {
            if ($endereco === null && !empty($ponto['endereco_completo'])) {
                $endereco = (string) $ponto['endereco_completo'];
            }
            if ($la === null && $lo === null && $ponto['latitude'] !== null && $ponto['longitude'] !== null) {
                $la = (float) $ponto['latitude'];
                $lo = (float) $ponto['longitude'];
            }
        } else {
            $pontoId = null;
        }
    } else {
        $pontoId = null;
    }

    $dab = isset($d['data_abertura_os']) && $d['data_abertura_os'] !== null && $d['data_abertura_os'] !== ''
        ? (string) $d['data_abertura_os']
        : null;

    $stmt = $pdo->prepare('
        INSERT INTO chamados (
            cliente_id, ponto_iluminacao_id, titulo, descricao,
            contribuinte_cpf, contribuinte_nome, contribuinte_telefone, contribuinte_email,
            data_abertura_os, origem_os, problema_os, tipo_os, ponto_referencia,
            os_cep, os_logradouro, os_numero, os_complemento, os_bairro, os_cidade, os_uf,
            endereco_completo, latitude, longitude,
            prioridade, status, responsavel, aberto_em
        )
        VALUES (
            ?,?,?,?,
            ?,?,?,?,
            ?,?,?,?,?,
            ?,?,?,?,?,?,?,
            ?,?,?,?,?,?,NOW()
        )
    ');
    $stmt->execute([
        (int) ($d['cliente_id'] ?? 0),
        $pontoId,
        $d['titulo'] ?? '',
        $d['descricao'] ?? '',
        $d['contribuinte_cpf'] ?? null,
        $d['contribuinte_nome'] ?? null,
        $d['contribuinte_telefone'] ?? null,
        $d['contribuinte_email'] ?? null,
        $dab,
        $d['origem_os'] ?? null,
        $d['problema_os'] ?? null,
        $d['tipo_os'] ?? null,
        $d['ponto_referencia'] ?? null,
        $d['os_cep'] ?? null,
        $d['os_logradouro'] ?? null,
        $d['os_numero'] ?? null,
        $d['os_complemento'] ?? null,
        $d['os_bairro'] ?? null,
        $d['os_cidade'] ?? null,
        $d['os_uf'] ?? null,
        $endereco,
        $la,
        $lo,
        $d['prioridade'] ?? 'Normal',
        $d['status'] ?? 'Aberto',
        $d['responsavel'] ?? null,
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Atualiza ficha estilo OS (contribuinte, endereço estruturado, classificação, texto).
 *
 * @param array<string, mixed> $d
 */
function repo_update_chamado_os_dados(int $id, array $d): bool
{
    $pdo = db();
    if (!$pdo || $id <= 0) {
        return false;
    }

    $ch = repo_chamado($id);
    if (!$ch) {
        return false;
    }
    $clienteId = (int) ($ch['cliente_id'] ?? 0);

    [$la, $lo] = _repo_parse_latlng_pair(
        isset($d['latitude']) ? (string) $d['latitude'] : null,
        isset($d['longitude']) ? (string) $d['longitude'] : null
    );

    $composedAddr = chamado_os_compor_endereco_completo($d);
    $endereco = $composedAddr !== null && $composedAddr !== ''
        ? $composedAddr
        : trim((string) ($d['endereco_completo'] ?? ''));
    $endereco = $endereco !== '' ? $endereco : null;

    $dab = isset($d['data_abertura_os']) && $d['data_abertura_os'] !== null && $d['data_abertura_os'] !== ''
        ? (string) $d['data_abertura_os']
        : null;

    $pontoId = !empty($d['ponto_iluminacao_id']) ? (int) $d['ponto_iluminacao_id'] : null;
    if ($pontoId !== null && $pontoId > 0) {
        $ponto = repo_ponto_iluminacao($pontoId);
        if (!$ponto || (int) ($ponto['cliente_id'] ?? 0) !== $clienteId) {
            $pontoId = null;
        }
    } else {
        $pontoId = null;
    }

    $stmt = $pdo->prepare('
        UPDATE chamados SET
            ponto_iluminacao_id = ?,
            titulo = ?, descricao = ?,
            contribuinte_cpf = ?, contribuinte_nome = ?, contribuinte_telefone = ?, contribuinte_email = ?,
            data_abertura_os = ?, origem_os = ?, problema_os = ?, tipo_os = ?, ponto_referencia = ?,
            os_cep = ?, os_logradouro = ?, os_numero = ?, os_complemento = ?, os_bairro = ?, os_cidade = ?, os_uf = ?,
            endereco_completo = ?, latitude = ?, longitude = ?
        WHERE id = ?
    ');
    return $stmt->execute([
        $pontoId,
        $d['titulo'] ?? '',
        $d['descricao'] ?? '',
        $d['contribuinte_cpf'] ?? null,
        $d['contribuinte_nome'] ?? null,
        $d['contribuinte_telefone'] ?? null,
        $d['contribuinte_email'] ?? null,
        $dab,
        $d['origem_os'] ?? null,
        $d['problema_os'] ?? null,
        $d['tipo_os'] ?? null,
        $d['ponto_referencia'] ?? null,
        $d['os_cep'] ?? null,
        $d['os_logradouro'] ?? null,
        $d['os_numero'] ?? null,
        $d['os_complemento'] ?? null,
        $d['os_bairro'] ?? null,
        $d['os_cidade'] ?? null,
        $d['os_uf'] ?? null,
        $endereco,
        $la,
        $lo,
        $id,
    ]);
}

function repo_update_chamado_localizacao(int $id, ?string $enderecoCompleto, ?float $latitude, ?float $longitude): bool
{
    $pdo = db();
    if (!$pdo || $id <= 0) {
        return false;
    }
    $end = $enderecoCompleto !== null ? trim($enderecoCompleto) : '';
    $end = $end !== '' ? $end : null;
    $stmt = $pdo->prepare('
        UPDATE chamados
        SET endereco_completo = ?, latitude = ?, longitude = ?
        WHERE id = ?
    ');
    return $stmt->execute([$end, $latitude, $longitude, $id]);
}

function repo_update_chamado_status(int $id, string $status): bool
{
    static $allowed = ['Aberto', 'Em andamento', 'Aguardando', 'Resolvido', 'Fechado', 'Cancelado'];
    if (!in_array($status, $allowed, true)) {
        return false;
    }
    $pdo = db();
    if (!$pdo) {
        return false;
    }
    $stmt = $pdo->prepare('UPDATE chamados SET status = ? WHERE id = ?');

    return $stmt->execute([$status, $id]);
}

function repo_update_chamado_responsavel(int $id, string $responsavel): bool
{
    $pdo = db();
    if (!$pdo) return false;
    $stmt = $pdo->prepare('UPDATE chamados SET responsavel = ? WHERE id = ?');
    return $stmt->execute([$responsavel, $id]);
}

/**
 * Dono do catálogo: a empresa raiz. Unidades filhas usam produtos e serviços da matriz.
 */
function repo_cliente_catalogo_dono_id(int $clienteId): int
{
    if ($clienteId <= 0) {
        return 0;
    }
    $cli = repo_cliente($clienteId);
    if (!$cli) {
        return $clienteId;
    }
    $empresaId = isset($cli['empresa_id']) && $cli['empresa_id'] !== null ? (int) $cli['empresa_id'] : 0;

    return $empresaId > 0 ? $empresaId : $clienteId;
}

/**
 * Itens do catálogo da empresa (produtos e serviços com valor unitário).
 *
 * @return list<array<string,mixed>>
 */
function repo_cliente_itens_list(int $clienteId, bool $somenteAtivos = false): array
{
    $pdo = db();
    if (!$pdo || $clienteId <= 0) {
        return [];
    }
    $catalogoClienteId = repo_cliente_catalogo_dono_id($clienteId);
    if ($catalogoClienteId <= 0) {
        return [];
    }
    $sql = 'SELECT id, cliente_id, empresa_id, tipo, nome, codigo, unidade, valor_unitario, descricao, ativo, ordem,
            DATE_FORMAT(criado_em, \'%Y-%m-%d %H:%i\') AS criado_em
            FROM cliente_itens WHERE cliente_id = ? OR empresa_id = ?';
    if ($somenteAtivos) {
        $sql .= ' AND ativo = 1';
    }
    $sql .= ' ORDER BY ordem ASC, tipo ASC, nome ASC';
    $st = $pdo->prepare($sql);
    $st->execute([$catalogoClienteId, $catalogoClienteId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id']              = (int) $r['id'];
        $r['cliente_id']      = (int) $r['cliente_id'];
        $r['empresa_id']      = isset($r['empresa_id']) && $r['empresa_id'] !== null ? (int) $r['empresa_id'] : null;
        $r['valor_unitario']  = (float) $r['valor_unitario'];
        $r['ativo']           = (int) $r['ativo'];
        $r['ordem']           = (int) $r['ordem'];
    }
    unset($r);

    return $rows;
}

/** @see repo_cliente_itens_list */
function repo_cliente_servicos_list(int $clienteId, bool $somenteAtivos = false): array
{
    return repo_cliente_itens_list($clienteId, $somenteAtivos);
}

/**
 * @return array<string,mixed>|null
 */
function repo_cliente_item_por_id(int $itemId, int $clienteId): ?array
{
    $pdo = db();
    if (!$pdo || $itemId <= 0 || $clienteId <= 0) {
        return null;
    }
    $catalogoClienteId = repo_cliente_catalogo_dono_id($clienteId);
    if ($catalogoClienteId <= 0) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM cliente_itens WHERE id = ? AND (cliente_id = ? OR empresa_id = ?) LIMIT 1');
    $st->execute([$itemId, $catalogoClienteId, $catalogoClienteId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        return null;
    }
    $r['id']             = (int) $r['id'];
    $r['cliente_id']     = (int) $r['cliente_id'];
    if (array_key_exists('empresa_id', $r)) {
        $r['empresa_id'] = $r['empresa_id'] !== null && $r['empresa_id'] !== '' ? (int) $r['empresa_id'] : null;
    }
    $r['valor_unitario'] = (float) $r['valor_unitario'];
    $r['ativo']          = (int) $r['ativo'];

    return $r;
}

/**
 * Ajusta strings ao limite das colunas cliente_itens (evita falha em SQL_MODE strict).
 *
 * @return array{0: string, 1: ?string, 2: string, 3: ?string}
 */
function repo_cliente_item_campos_normalizados(
    string $nome,
    ?string $descricao,
    string $unidade,
    ?string $codigo
): array {
    $nome = trim($nome);
    $desc = $descricao !== null ? trim($descricao) : '';
    $unid = trim($unidade) !== '' ? trim($unidade) : 'UN';
    $cod  = $codigo !== null ? trim($codigo) : '';
    $cod  = $cod !== '' ? $cod : null;

    $cut = static function (string $s, int $max): string {
        if ($max <= 0) {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($s, 'UTF-8') <= $max) {
                return $s;
            }

            return mb_substr($s, 0, $max, 'UTF-8');
        }
        if (strlen($s) <= $max) {
            return $s;
        }

        return substr($s, 0, $max);
    };

    $nome = $cut($nome, 160);
    $desc = $desc !== '' ? $cut($desc, 500) : '';
    $unid = $cut($unid, 20);
    if ($unid === '') {
        $unid = 'UN';
    }
    if ($cod !== null) {
        $cod = $cut($cod, 64);
        if ($cod === '') {
            $cod = null;
        }
    }

    return [$nome, $desc !== '' ? $desc : null, $unid, $cod];
}

/**
 * @return array{ok: bool, err: string, id: ?int}
 */
function repo_cliente_item_salvar(
    int $clienteId,
    ?int $id,
    string $tipo,
    string $nome,
    ?string $codigo,
    string $unidade,
    float $valorUnitario,
    ?string $descricao,
    int $ativo
): array {
    $pdo = db();
    if (!$pdo || $clienteId <= 0) {
        return ['ok' => false, 'err' => 'Dados inválidos.', 'id' => null];
    }
    $clienteId = repo_cliente_catalogo_dono_id($clienteId);
    if ($clienteId <= 0) {
        return ['ok' => false, 'err' => 'Empresa inválida para o catálogo.', 'id' => null];
    }
    $tipo = strtolower(trim($tipo));
    if (!in_array($tipo, ['produto', 'servico'], true)) {
        return ['ok' => false, 'err' => 'Tipo inválido.', 'id' => null];
    }
    [$nome, $desc, $unidade, $codigo] = repo_cliente_item_campos_normalizados($nome, $descricao, $unidade, $codigo);
    if ($nome === '') {
        return ['ok' => false, 'err' => 'Informe o nome.', 'id' => null];
    }
    $ativo = $ativo ? 1 : 0;
    try {
        if ($id !== null && $id > 0) {
            $chk = $pdo->prepare('SELECT id FROM cliente_itens WHERE id = ? AND (cliente_id = ? OR empresa_id = ?) LIMIT 1');
            $chk->execute([$id, $clienteId, $clienteId]);
            if (!$chk->fetchColumn()) {
                return ['ok' => false, 'err' => 'Item não encontrado.', 'id' => null];
            }
            $st = $pdo->prepare('
                UPDATE cliente_itens
                SET tipo = ?, nome = ?, codigo = ?, unidade = ?, valor_unitario = ?, descricao = ?, ativo = ?
                WHERE id = ? AND (cliente_id = ? OR empresa_id = ?)
            ');
            $ok = $st->execute([$tipo, $nome, $codigo, $unidade, $valorUnitario, $desc, $ativo, $id, $clienteId, $clienteId]);

            return ['ok' => (bool) $ok, 'err' => $ok ? '' : 'Falha ao atualizar.', 'id' => $ok ? $id : null];
        }
        $st = $pdo->prepare('
            INSERT INTO cliente_itens (cliente_id, empresa_id, tipo, nome, codigo, unidade, valor_unitario, descricao, ativo, ordem)
            VALUES (?,?,?,?,?,?,?,?,?,0)
        ');
        $ok = $st->execute([$clienteId, $clienteId, $tipo, $nome, $codigo, $unidade, $valorUnitario, $desc, $ativo]);
        $nid = (int) $pdo->lastInsertId();

        return ['ok' => (bool) $ok, 'err' => $ok ? '' : 'Falha ao inserir.', 'id' => $ok ? $nid : null];
    } catch (Throwable $e) {
        return ['ok' => false, 'err' => $e->getMessage(), 'id' => null];
    }
}

function repo_cliente_servico_create(int $clienteId, string $nome): ?int
{
    $r = repo_cliente_item_salvar($clienteId, null, 'servico', $nome, null, 'UN', 0.0, null, 1);

    return $r['ok'] ? $r['id'] : null;
}

function repo_cliente_item_excluir(int $clienteId, int $itemId): bool
{
    $pdo = db();
    if (!$pdo || $clienteId <= 0 || $itemId <= 0) {
        return false;
    }
    $clienteId = repo_cliente_catalogo_dono_id($clienteId);
    if ($clienteId <= 0) {
        return false;
    }
    try {
        $stc = $pdo->prepare('SELECT COUNT(*) FROM chamado_itens WHERE item_id = ?');
        $stc->execute([$itemId]);
        if ((int) $stc->fetchColumn() > 0) {
            return false;
        }
    } catch (Throwable $e) {
        return false;
    }
    $st = $pdo->prepare('DELETE FROM cliente_itens WHERE id = ? AND (cliente_id = ? OR empresa_id = ?) LIMIT 1');

    return (bool) $st->execute([$itemId, $clienteId, $clienteId]);
}

function repo_cliente_servico_delete(int $clienteId, int $servicoId): bool
{
    return repo_cliente_item_excluir($clienteId, $servicoId);
}

/**
 * Importa CSV/TSV (primeira linha pode ser cabeçalho: tipo,nome,codigo,unidade,valor_unitario,descricao).
 *
 * @return array{inseridos: int, ignorados: int, erros: list<string>}
 */
function repo_cliente_itens_importar_csv(int $clienteId, string $conteudo): array
{
    $ret = ['inseridos' => 0, 'ignorados' => 0, 'erros' => []];
    $pdo = db();
    if (!$pdo || $clienteId <= 0) {
        $ret['erros'][] = 'Banco ou cliente inválido.';

        return $ret;
    }
    $clienteId = repo_cliente_catalogo_dono_id($clienteId);
    if ($clienteId <= 0) {
        $ret['erros'][] = 'Empresa inválida para o catálogo.';

        return $ret;
    }
    $conteudo = preg_replace('/^\xEF\xBB\xBF/', '', (string) $conteudo);
    $linhas   = preg_split('/\r\n|\r|\n/', trim($conteudo));
    if ($linhas === false || $linhas === []) {
        $ret['erros'][] = 'Arquivo vazio.';

        return $ret;
    }
    $delim = ';';
    $primeira = $linhas[0];
    if (substr_count($primeira, ',') > substr_count($primeira, ';')) {
        $delim = ',';
    }
    $cab = null;
    $p0  = str_getcsv($linhas[0], $delim);
    $h0  = array_map(static fn ($x) => strtolower(trim((string) $x)), $p0);
    if (in_array('tipo', $h0, true) && in_array('nome', $h0, true)) {
        $cab = array_flip($h0);
        array_shift($linhas);
    }
    $nlinha = 0;
    foreach ($linhas as $raw) {
        $nlinha++;
        $raw = trim((string) $raw);
        if ($raw === '') {
            continue;
        }
        $c = str_getcsv($raw, $delim);
        if ($cab !== null) {
            $tipo   = strtolower(trim((string) ($c[$cab['tipo']] ?? '')));
            $nome   = trim((string) ($c[$cab['nome']] ?? ''));
            $codigo = isset($cab['codigo']) ? trim((string) ($c[$cab['codigo']] ?? '')) : '';
            $unid   = isset($cab['unidade']) ? trim((string) ($c[$cab['unidade']] ?? '')) : 'UN';
            $val    = isset($cab['valor_unitario']) ? str_replace(',', '.', trim((string) ($c[$cab['valor_unitario']] ?? '0'))) : '0';
            $desc   = isset($cab['descricao']) ? trim((string) ($c[$cab['descricao']] ?? '')) : '';
        } else {
            $tipo   = strtolower(trim((string) ($c[0] ?? '')));
            $nome   = trim((string) ($c[1] ?? ''));
            $codigo = trim((string) ($c[2] ?? ''));
            $unid   = trim((string) ($c[3] ?? 'UN'));
            $val    = str_replace(',', '.', trim((string) ($c[4] ?? '0')));
            $desc   = trim((string) ($c[5] ?? ''));
        }
        if (!in_array($tipo, ['produto', 'servico'], true)) {
            $ret['ignorados']++;
            $ret['erros'][] = "Linha $nlinha: tipo deve ser produto ou servico.";

            continue;
        }
        if ($nome === '') {
            $ret['ignorados']++;

            continue;
        }
        $vu = (float) $val;
        $r  = repo_cliente_item_salvar($clienteId, null, $tipo, $nome, $codigo !== '' ? $codigo : null, $unid !== '' ? $unid : 'UN', $vu, $desc !== '' ? $desc : null, 1);
        if ($r['ok']) {
            $ret['inseridos']++;
        } else {
            $ret['ignorados']++;
            $ret['erros'][] = "Linha $nlinha: " . ($r['err'] ?: 'falha');
        }
    }

    return $ret;
}

/**
 * Linhas de materiais no chamado (com nome do catálogo).
 *
 * @return list<array<string,mixed>>
 */
function repo_chamado_itens_list(int $chamadoId): array
{
    $pdo = db();
    if (!$pdo || $chamadoId <= 0) {
        return [];
    }
    $st = $pdo->prepare('
        SELECT ci.id, ci.chamado_id, ci.item_id, ci.movimento, ci.quantidade, ci.valor_unitario, ci.subtotal, ci.observacao,
               i.nome AS item_nome, i.tipo AS item_tipo, i.codigo AS item_codigo, i.unidade AS catalogo_unidade
        FROM chamado_itens ci
        INNER JOIN cliente_itens i ON i.id = ci.item_id
        WHERE ci.chamado_id = ?
        ORDER BY ci.id ASC
    ');
    $st->execute([$chamadoId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id']             = (int) $r['id'];
        $r['chamado_id']     = (int) $r['chamado_id'];
        $r['item_id']        = (int) $r['item_id'];
        $r['movimento']      = (string) ($r['movimento'] ?? 'utilizado');
        $r['quantidade']     = (float) $r['quantidade'];
        $r['valor_unitario'] = (float) $r['valor_unitario'];
        $r['subtotal']       = (float) $r['subtotal'];
    }
    unset($r);

    return $rows;
}

function repo_chamado_itens_valor_total(int $chamadoId): float
{
    $pdo = db();
    if (!$pdo || $chamadoId <= 0) {
        return 0.0;
    }
    $st = $pdo->prepare("SELECT COALESCE(SUM(subtotal),0) FROM chamado_itens WHERE chamado_id = ? AND movimento = 'utilizado'");
    $st->execute([$chamadoId]);

    return (float) $st->fetchColumn();
}

/**
 * @return array{ok: bool, err: string}
 */
function repo_chamado_item_adicionar(int $chamadoId, int $itemId, float $quantidade, string $movimento = 'utilizado', ?string $observacao = null): array
{
    $pdo = db();
    if (!$pdo || $chamadoId <= 0 || $itemId <= 0 || $quantidade <= 0) {
        return ['ok' => false, 'err' => 'Dados inválidos.'];
    }
    $movimento = strtolower(trim($movimento));
    if (!in_array($movimento, ['utilizado', 'devolvido'], true)) {
        return ['ok' => false, 'err' => 'Movimento inválido.'];
    }
    $ch = repo_chamado($chamadoId);
    if (!$ch) {
        return ['ok' => false, 'err' => 'Chamado não encontrado.'];
    }
    $cid = (int) $ch['cliente_id'];
    $it  = repo_cliente_item_por_id($itemId, $cid);
    if (!$it || empty($it['ativo'])) {
        return ['ok' => false, 'err' => 'Item não disponível para este cliente.'];
    }
    $vu = (float) ($it['valor_unitario'] ?? 0);
    $sub = round($quantidade * $vu, 4);
    try {
        $st = $pdo->prepare('
            INSERT INTO chamado_itens (chamado_id, item_id, movimento, quantidade, valor_unitario, subtotal, observacao)
            VALUES (?,?,?,?,?,?,?)
        ');
        $obs = trim((string) $observacao);
        $ok = $st->execute([$chamadoId, $itemId, $movimento, $quantidade, $vu, $sub, $obs !== '' ? $obs : null]);

        return ['ok' => (bool) $ok, 'err' => $ok ? '' : 'Não foi possível inserir.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'err' => $e->getMessage()];
    }
}

function repo_chamado_item_atualizar_quantidade(int $linhaId, int $chamadoId, float $quantidade): bool
{
    $pdo = db();
    if (!$pdo || $linhaId <= 0 || $chamadoId <= 0 || $quantidade <= 0) {
        return false;
    }
    $st = $pdo->prepare('SELECT valor_unitario FROM chamado_itens WHERE id = ? AND chamado_id = ? LIMIT 1');
    $st->execute([$linhaId, $chamadoId]);
    $vu = $st->fetchColumn();
    if ($vu === false) {
        return false;
    }
    $vu  = (float) $vu;
    $sub = round($quantidade * $vu, 4);
    $up  = $pdo->prepare('UPDATE chamado_itens SET quantidade = ?, subtotal = ? WHERE id = ? AND chamado_id = ?');

    return (bool) $up->execute([$quantidade, $sub, $linhaId, $chamadoId]);
}

function repo_chamado_item_remover(int $linhaId, int $chamadoId): bool
{
    $pdo = db();
    if (!$pdo || $linhaId <= 0 || $chamadoId <= 0) {
        return false;
    }
    $st = $pdo->prepare('DELETE FROM chamado_itens WHERE id = ? AND chamado_id = ? LIMIT 1');

    return (bool) $st->execute([$linhaId, $chamadoId]);
}

/**
 * Operador vincula um item principal do catálogo ao chamado (catálogo da empresa raiz).
 */
function repo_operador_chamado_set_servico(int $chamadoId, int $empresaRaizId, ?int $servicoId): bool
{
    $pdo = db();
    if (!$pdo || $chamadoId <= 0 || $empresaRaizId <= 0) {
        return false;
    }
    if (!repo_chamado_acessivel_operador_empresa($chamadoId, $empresaRaizId)) {
        return false;
    }
    $ch         = repo_chamado($chamadoId);
    $catCliente = (int) ($ch['cliente_id'] ?? 0);
    if ($catCliente <= 0) {
        return false;
    }
    if ($servicoId === null || $servicoId <= 0) {
        $st = $pdo->prepare('UPDATE chamados SET servico_id = NULL WHERE id = ?');

        return (bool) $st->execute([$chamadoId]);
    }
    $item = repo_cliente_item_por_id($servicoId, $catCliente);
    if (!$item || empty($item['ativo'])) {
        return false;
    }
    $st = $pdo->prepare('UPDATE chamados SET servico_id = ? WHERE id = ?');

    return (bool) $st->execute([$servicoId, $chamadoId]);
}

/**
 * Gestor vincula um chamado a um técnico/operador da mesma empresa.
 *
 * @return array{ok: bool, err: string}
 */
function repo_chamado_atribuir_tecnico(int $chamadoId, ?int $tecnicoUserId, int $empresaRaizId): array
{
    $pdo = db();
    if (!$pdo || $chamadoId <= 0 || $empresaRaizId <= 0) {
        return ['ok' => false, 'err' => 'Dados inválidos.'];
    }
    if (!repo_chamado_acessivel_operador_empresa($chamadoId, $empresaRaizId)) {
        return ['ok' => false, 'err' => 'Chamado fora da empresa.'];
    }
    $tecnicoNome = null;
    if ($tecnicoUserId !== null && $tecnicoUserId > 0) {
        $st = $pdo->prepare("SELECT id, nome FROM usuarios WHERE id = ? AND perfil = 'operador' AND empresa_id = ? LIMIT 1");
        $st->execute([$tecnicoUserId, $empresaRaizId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'err' => 'Técnico inválido para esta empresa.'];
        }
        $tecnicoNome = (string) ($row['nome'] ?? '');
    } else {
        $tecnicoUserId = null;
    }
    $statusSql = $tecnicoUserId !== null ? ", status = IF(status = 'Aberto', 'Em andamento', status)" : '';
    $st = $pdo->prepare("
        UPDATE chamados
        SET tecnico_user_id = ?,
            responsavel = ?,
            finalizado_operador_em = NULL,
            finalizado_operador_user_id = NULL,
            aprovado_gestor_em = NULL,
            aprovado_gestor_user_id = NULL,
            checklist_realizado = NULL
            $statusSql
        WHERE id = ?
    ");
    $ok = $st->execute([$tecnicoUserId, $tecnicoNome, $chamadoId]);

    return ['ok' => (bool) $ok, 'err' => $ok ? '' : 'Não foi possível atribuir técnico.'];
}

/**
 * Gestor aprova a execução feita pelo técnico.
 *
 * @return array{ok: bool, err: string}
 */
function repo_chamado_aprovar_gestor(int $chamadoId, int $gestorUserId, string $gestorNome, string $checklist): array
{
    $pdo = db();
    if (!$pdo || $chamadoId <= 0 || $gestorUserId <= 0) {
        return ['ok' => false, 'err' => 'Dados inválidos.'];
    }
    $ch = repo_chamado($chamadoId);
    if (!$ch) {
        return ['ok' => false, 'err' => 'Chamado não encontrado.'];
    }
    if (empty($ch['finalizado_operador_em'])) {
        return ['ok' => false, 'err' => 'O técnico precisa finalizar o atendimento antes da aprovação.'];
    }
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare("
            UPDATE chamados
            SET status = 'Resolvido',
                aprovado_gestor_em = NOW(),
                aprovado_gestor_user_id = ?,
                checklist_realizado = ?
            WHERE id = ?
        ");
        $st->execute([$gestorUserId, trim($checklist) !== '' ? trim($checklist) : null, $chamadoId]);
        repo_create_chamado_resposta(
            $chamadoId,
            $gestorNome !== '' ? $gestorNome : 'Gestor',
            'admin',
            'Atendimento aprovado pelo gestor.',
            false
        );
        $pdo->commit();

        return ['ok' => true, 'err' => ''];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'err' => $e->getMessage()];
    }
}

/**
 * @return array{ok: bool, err: string}
 */
function repo_operador_chamado_finalizar(int $chamadoId, int $operadorUserId, int $empresaRaizId, string $nomeAutor): array
{
    $pdo = db();
    if (!$pdo || $chamadoId <= 0 || $operadorUserId <= 0 || $empresaRaizId <= 0) {
        return ['ok' => false, 'err' => 'Dados inválidos.'];
    }
    $ch = repo_chamado($chamadoId);
    if (!$ch || !repo_chamado_acessivel_operador_empresa($chamadoId, $empresaRaizId)) {
        return ['ok' => false, 'err' => 'Chamado não encontrado.'];
    }
    if ((int) ($ch['tecnico_user_id'] ?? 0) !== $operadorUserId) {
        return ['ok' => false, 'err' => 'Este chamado não está atribuído ao seu usuário.'];
    }
    $stAtual = (string) ($ch['status'] ?? '');
    if (in_array($stAtual, ['Resolvido', 'Fechado'], true)) {
        return ['ok' => true, 'err' => ''];
    }
    if (!empty($ch['finalizado_operador_em'])) {
        return ['ok' => true, 'err' => ''];
    }
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare('
            UPDATE chamados
            SET status = \'Aguardando\',
                finalizado_operador_em = NOW(),
                finalizado_operador_user_id = ?,
                aprovado_gestor_em = NULL,
                aprovado_gestor_user_id = NULL
            WHERE id = ?
        ');
        $st->execute([$operadorUserId, $chamadoId]);
        repo_create_chamado_resposta(
            $chamadoId,
            $nomeAutor !== '' ? $nomeAutor : 'Operador',
            'operador',
            'Atendimento finalizado pelo técnico e enviado para aprovação do gestor.',
            false
        );
        $pdo->commit();

        return ['ok' => true, 'err' => ''];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'err' => $e->getMessage()];
    }
}

function repo_create_chamado_resposta(int $chamadoId, string $autor, string $tipo, string $texto, bool $interna = false): ?int
{
    $pdo = db();
    if (!$pdo) return null;
    $stmt = $pdo->prepare('
        INSERT INTO chamado_respostas (chamado_id, autor, tipo, texto, interna, enviado_em)
        VALUES (?,?,?,?,?,NOW())
    ');
    $stmt->execute([$chamadoId, $autor, $tipo, $texto, $interna ? 1 : 0]);
    return (int) $pdo->lastInsertId();
}

/* ---- Anexos de chamados ---- */
function repo_create_chamado_anexo(array $d): ?int
{
    $pdo = db();
    if (!$pdo) return null;

    $stmt = $pdo->prepare('
        INSERT INTO chamado_anexos
            (chamado_id, resposta_id, nome_original, nome_arquivo, mime, tamanho,
             enviado_por, enviado_tipo)
        VALUES (:chamado_id, :resposta_id, :nome_original, :nome_arquivo, :mime, :tamanho,
                :enviado_por, :enviado_tipo)
    ');
    $stmt->execute([
        ':chamado_id'    => (int) $d['chamado_id'],
        ':resposta_id'   => !empty($d['resposta_id']) ? (int) $d['resposta_id'] : null,
        ':nome_original' => $d['nome_original'],
        ':nome_arquivo'  => $d['nome_arquivo'],
        ':mime'          => $d['mime']         ?? null,
        ':tamanho'       => (int) ($d['tamanho'] ?? 0),
        ':enviado_por'   => $d['enviado_por']  ?? null,
        ':enviado_tipo'  => $d['enviado_tipo'] ?? 'admin',
    ]);
    return (int) $pdo->lastInsertId();
}

function repo_chamado_anexos(int $chamadoId): array
{
    $pdo = db();
    if (!$pdo) return [];

    $stmt = $pdo->prepare('
        SELECT id, chamado_id, resposta_id, nome_original, nome_arquivo, mime, tamanho,
               enviado_por, enviado_tipo,
               DATE_FORMAT(enviado_em, "%Y-%m-%d %H:%i") AS enviado_em
        FROM chamado_anexos
        WHERE chamado_id = ?
        ORDER BY enviado_em DESC, id DESC
    ');
    $stmt->execute([$chamadoId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['id']          = (int) $r['id'];
        $r['chamado_id']  = (int) $r['chamado_id'];
        $r['resposta_id'] = $r['resposta_id'] !== null ? (int) $r['resposta_id'] : null;
        $r['tamanho']     = (int) $r['tamanho'];
    }
    return $rows;
}

function repo_chamado_anexo(int $id): ?array
{
    $pdo = db();
    if (!$pdo) return null;
    $stmt = $pdo->prepare('SELECT * FROM chamado_anexos WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    if (!$r) return null;
    $r['id']          = (int) $r['id'];
    $r['chamado_id']  = (int) $r['chamado_id'];
    $r['resposta_id'] = $r['resposta_id'] !== null ? (int) $r['resposta_id'] : null;
    $r['tamanho']     = (int) $r['tamanho'];
    return $r;
}

function repo_delete_chamado_anexo(int $id): ?array
{
    $pdo = db();
    if (!$pdo) return null;
    $anexo = repo_chamado_anexo($id);
    if (!$anexo) return null;
    $stmt = $pdo->prepare('DELETE FROM chamado_anexos WHERE id = ?');
    $stmt->execute([$id]);
    return $anexo;
}

/* ---- OS ---- */
function repo_create_os(array $d): ?int
{
    $pdo = db();
    if (!$pdo) return null;

    $stmt = $pdo->prepare('
        INSERT INTO os (chamado_id, cliente_id, titulo, descricao, tipo, horas_previstas,
                        horas_realizadas, valor_hora, status, responsavel, dentro_contrato, data_abertura)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
    ');
    $stmt->execute([
        !empty($d['chamado_id']) ? (int) $d['chamado_id'] : null,
        (int)   ($d['cliente_id'] ?? 0),
        $d['titulo']      ?? '',
        $d['descricao']   ?? '',
        $d['tipo']        ?? 'Corretiva',
        (float) ($d['horas_previstas']  ?? 0),
        (float) ($d['horas_realizadas'] ?? 0),
        (float) ($d['valor_hora']       ?? 0),
        $d['status']      ?? 'Aberta',
        $d['responsavel'] ?? null,
        !empty($d['dentro_contrato']) ? 1 : 0,
        $d['data_abertura'] ?? date('Y-m-d'),
    ]);
    return (int) $pdo->lastInsertId();
}

function repo_update_os(int $id, array $d): bool
{
    $pdo = db();
    if (!$pdo) return false;

    $status = $d['status'] ?? 'Aberta';
    $dataConclusao = null;
    if ($status === 'Concluída') {
        $dc = trim((string) ($d['data_conclusao'] ?? ''));
        $dataConclusao = $dc !== '' ? $dc : date('Y-m-d');
    }

    $desc = trim((string) ($d['descricao'] ?? ''));
    $resp = trim((string) ($d['responsavel'] ?? ''));

    $stmt = $pdo->prepare('
        UPDATE os SET
            chamado_id = ?,
            cliente_id = ?,
            titulo = ?,
            descricao = ?,
            tipo = ?,
            horas_previstas = ?,
            horas_realizadas = ?,
            valor_hora = ?,
            status = ?,
            responsavel = ?,
            dentro_contrato = ?,
            data_abertura = ?,
            data_conclusao = ?
        WHERE id = ?
    ');
    return $stmt->execute([
        !empty($d['chamado_id']) ? (int) $d['chamado_id'] : null,
        (int) ($d['cliente_id'] ?? 0),
        $d['titulo'] ?? '',
        $desc !== '' ? $desc : null,
        $d['tipo'] ?? 'Corretiva',
        (float) ($d['horas_previstas'] ?? 0),
        (float) ($d['horas_realizadas'] ?? 0),
        (float) ($d['valor_hora'] ?? 0),
        $status,
        $resp !== '' ? $resp : null,
        !empty($d['dentro_contrato']) ? 1 : 0,
        !empty($d['data_abertura']) ? $d['data_abertura'] : null,
        $dataConclusao,
        $id,
    ]);
}

/* ---- Contas ---- */
function repo_create_conta(array $d): ?int
{
    $pdo = db();
    if (!$pdo) {
        return null;
    }

    $obs = trim((string) ($d['observacoes'] ?? ''));
    $bl  = trim((string) ($d['boleto_linha'] ?? ''));
    $bu  = trim((string) ($d['boleto_url'] ?? ''));
    $pk  = trim((string) ($d['pix_chave'] ?? ''));
    $pt  = trim((string) ($d['pix_tipo'] ?? ''));
    $tipo = strtolower(trim((string) ($d['tipo_cobranca'] ?? 'mensalidade')));
    if (!in_array($tipo, ['setup', 'mensalidade', 'os'], true)) {
        $tipo = 'mensalidade';
    }
    $osId = !empty($d['os_id']) ? (int) $d['os_id'] : null;
    if ($tipo !== 'os') {
        $osId = null;
    }

    $stmt = $pdo->prepare('
        INSERT INTO contas (cliente_id, tipo_cobranca, os_id, descricao, valor, vencimento, status,
            observacoes, boleto_linha, boleto_url, boleto_arquivo, boleto_original,
            pix_chave, pix_tipo)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ');
    try {
        $stmt->execute([
            (int) ($d['cliente_id'] ?? 0),
            $tipo,
            $osId,
            $d['descricao'] ?? '',
            (float) ($d['valor'] ?? 0),
            $d['vencimento'] ?? date('Y-m-d'),
            $d['status'] ?? 'Pendente',
            $obs !== '' ? $obs : null,
            $bl !== '' ? $bl : null,
            $bu !== '' ? $bu : null,
            !empty($d['boleto_arquivo']) ? $d['boleto_arquivo'] : null,
            !empty($d['boleto_original']) ? $d['boleto_original'] : null,
            $pk !== '' ? $pk : null,
            $pt !== '' ? $pt : null,
        ]);
    } catch (Throwable $e) {
        $stmt2 = $pdo->prepare('
            INSERT INTO contas (cliente_id, descricao, valor, vencimento, status,
                observacoes, boleto_linha, boleto_url, boleto_arquivo, boleto_original,
                pix_chave, pix_tipo)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ');
        $stmt2->execute([
            (int) ($d['cliente_id'] ?? 0),
            $d['descricao'] ?? '',
            (float) ($d['valor'] ?? 0),
            $d['vencimento'] ?? date('Y-m-d'),
            $d['status'] ?? 'Pendente',
            $obs !== '' ? $obs : null,
            $bl !== '' ? $bl : null,
            $bu !== '' ? $bu : null,
            !empty($d['boleto_arquivo']) ? $d['boleto_arquivo'] : null,
            !empty($d['boleto_original']) ? $d['boleto_original'] : null,
            $pk !== '' ? $pk : null,
            $pt !== '' ? $pt : null,
        ]);
    }

    return (int) $pdo->lastInsertId();
}

function repo_conta_set_boleto_arquivo(int $id, ?string $nomeFs, ?string $nomeOriginal): bool
{
    $pdo = db();
    if (!$pdo) {
        return false;
    }
    $stmt = $pdo->prepare('UPDATE contas SET boleto_arquivo = ?, boleto_original = ? WHERE id = ?');
    return $stmt->execute([$nomeFs, $nomeOriginal, $id]);
}

/**
 * Remove PDF do boleto do disco e zera campos no registro.
 */
function repo_conta_remover_boleto_arquivo(int $id): bool
{
    require_once __DIR__ . '/upload.php';
    $c = repo_conta($id);
    if ($c && !empty($c['boleto_arquivo'])) {
        $path = upload_dir_conta($id) . DIRECTORY_SEPARATOR . $c['boleto_arquivo'];
        if (is_file($path)) {
            @unlink($path);
        }
        @rmdir(upload_dir_conta($id));
    }
    return repo_conta_set_boleto_arquivo($id, null, null);
}

/**
 * Atualiza dados de pagamento e observações da cobrança.
 */
function repo_update_conta_cobranca(int $id, array $d): bool
{
    $pdo = db();
    if (!$pdo) return false;

    $obs = trim((string) ($d['observacoes'] ?? ''));
    $bl  = trim((string) ($d['boleto_linha'] ?? ''));
    $bu  = trim((string) ($d['boleto_url'] ?? ''));
    $pk  = trim((string) ($d['pix_chave'] ?? ''));
    $pt  = trim((string) ($d['pix_tipo'] ?? ''));

    try {
        $stmt = $pdo->prepare('
            UPDATE contas SET
                observacoes = ?,
                boleto_linha = ?,
                boleto_url = ?,
                pix_chave = ?,
                pix_tipo = ?
            WHERE id = ?
        ');
        return $stmt->execute([
            $obs !== '' ? $obs : null,
            $bl !== '' ? $bl : null,
            $bu !== '' ? $bu : null,
            $pk !== '' ? $pk : null,
            $pt !== '' ? $pt : null,
            $id,
        ]);
    } catch (Throwable $e) {
        return false;
    }
}

function repo_update_conta_status(int $id, string $status): bool
{
    $pdo = db();
    if (!$pdo) return false;
    $stmt = $pdo->prepare('UPDATE contas SET status = ? WHERE id = ?');
    return $stmt->execute([$status, $id]);
}

function repo_delete_conta(int $id): bool
{
    $pdo = db();
    if (!$pdo) {
        return false;
    }
    require_once __DIR__ . '/upload.php';
    $c = repo_conta($id);
    if ($c && !empty($c['boleto_arquivo'])) {
        $path = upload_dir_conta($id) . DIRECTORY_SEPARATOR . $c['boleto_arquivo'];
        if (is_file($path)) {
            @unlink($path);
        }
        @rmdir(upload_dir_conta($id));
    }
    $stmt = $pdo->prepare('DELETE FROM contas WHERE id = ?');
    return $stmt->execute([$id]);
}

/* ---- Suporte ---- */
function repo_create_suporte(array $d): ?int
{
    $pdo = db();
    if (!$pdo) return null;
    $stmt = $pdo->prepare('
        INSERT INTO suporte (cliente_id, pergunta, detalhe, status, resposta, enviado_em)
        VALUES (?,?,?,?,?,NOW())
    ');
    $stmt->execute([
        (int) ($d['cliente_id'] ?? 0),
        $d['pergunta'] ?? '',
        $d['detalhe']  ?? '',
        $d['status']   ?? 'Aberta',
        $d['resposta'] ?? '',
    ]);
    return (int) $pdo->lastInsertId();
}

function repo_responder_suporte(int $id, string $resposta): bool
{
    $pdo = db();
    if (!$pdo) return false;
    $stmt = $pdo->prepare('UPDATE suporte SET resposta = ?, status = "Respondida" WHERE id = ?');
    return $stmt->execute([$resposta, $id]);
}

/* ---- Kanban ---- */
function repo_create_kanban(array $d): ?int
{
    $pdo = db();
    if (!$pdo) return null;
    $stmt = $pdo->prepare('
        INSERT INTO kanban_cards (coluna, cliente_id, titulo, descricao, prioridade, responsaveis, prazo, chamado_id, ordem)
        VALUES (?,?,?,?,?,?,?,?,?)
    ');
    $stmt->execute([
        $d['coluna']       ?? 'todo',
        !empty($d['cliente_id']) ? (int) $d['cliente_id'] : null,
        $d['titulo']       ?? '',
        $d['descricao']    ?? '',
        $d['prioridade']   ?? 'Normal',
        $d['responsaveis'] ?? '',
        $d['prazo']        ?? '',
        !empty($d['chamado_id']) ? (int) $d['chamado_id'] : null,
        (int) ($d['ordem'] ?? 0),
    ]);
    return (int) $pdo->lastInsertId();
}

function repo_update_kanban_coluna(int $id, string $coluna, int $ordem = 0): bool
{
    $pdo = db();
    if (!$pdo) return false;
    $stmt = $pdo->prepare('UPDATE kanban_cards SET coluna = ?, ordem = ? WHERE id = ?');
    return $stmt->execute([$coluna, $ordem, $id]);
}

function repo_delete_kanban(int $id): bool
{
    $pdo = db();
    if (!$pdo) return false;
    $stmt = $pdo->prepare('DELETE FROM kanban_cards WHERE id = ?');
    return $stmt->execute([$id]);
}

function repo_kanban_card(int $id): ?array
{
    $pdo = db();
    if (!$pdo) return null;
    $stmt = $pdo->prepare('
        SELECT k.*, cli.empresa AS cliente_empresa, cli.nome AS cliente_contato,
               ch.titulo AS chamado_titulo, ch.status AS chamado_status
        FROM kanban_cards k
        LEFT JOIN clientes cli ON cli.id = k.cliente_id
        LEFT JOIN chamados ch  ON ch.id  = k.chamado_id
        WHERE k.id = ? LIMIT 1
    ');
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    return $r ?: null;
}

/**
 * Cliente dono do card (direto no card ou via chamado vinculado).
 */
function repo_kanban_card_cliente_efetivo(?array $card): ?int
{
    if (!$card) {
        return null;
    }
    $cid = isset($card['cliente_id']) && $card['cliente_id'] !== null && $card['cliente_id'] !== ''
        ? (int) $card['cliente_id'] : 0;
    if ($cid > 0) {
        return $cid;
    }
    $chid = isset($card['chamado_id']) && $card['chamado_id'] !== null && $card['chamado_id'] !== ''
        ? (int) $card['chamado_id'] : 0;
    if ($chid <= 0) {
        return null;
    }
    $ch = repo_chamado($chid);
    if (!$ch) {
        return null;
    }
    $out = (int) ($ch['cliente_id'] ?? 0);
    return $out > 0 ? $out : null;
}

function repo_kanban_por_chamado(int $chamadoId): ?array
{
    $pdo = db();
    if (!$pdo) return null;
    $stmt = $pdo->prepare('SELECT id FROM kanban_cards WHERE chamado_id = ? LIMIT 1');
    $stmt->execute([$chamadoId]);
    $r = $stmt->fetch();
    return $r ?: null;
}

/* ================================================================== */
/*  AUTENTICAÇÃO                                                      */
/* ================================================================== */

/**
 * Iniciais a partir do nome (mesma regra do cadastro de cliente/usuário).
 */
function repo_usuario_calcular_iniciais(string $nome): string
{
    $nome = trim($nome);
    if ($nome === '') {
        return '??';
    }
    $parts = preg_split('/\s+/u', $nome, -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) {
        return '??';
    }
    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        $a = mb_strtoupper(mb_substr($parts[0], 0, 1, 'UTF-8'), 'UTF-8');
        $last = $parts[count($parts) - 1];
        $b    = mb_strtoupper(mb_substr($last, 0, 1, 'UTF-8'), 'UTF-8');
        return $a . $b;
    }
    $last = $parts[count($parts) - 1];
    return strtoupper(substr($parts[0], 0, 1) . substr($last, 0, 1));
}

/**
 * @param array<string,mixed> $u  linha de `usuarios` (pode conter senha_hash)
 * @return array<string,mixed>
 */
function _repo_usuario_enriquecer(PDO $pdo, array $u): array
{
    $empresa = '';
    $perfil  = (string) ($u['perfil'] ?? '');
    if ($perfil === 'cliente' && !empty($u['cliente_id'])) {
        $stmt = $pdo->prepare('SELECT empresa FROM clientes WHERE id = ?');
        $stmt->execute([(int) $u['cliente_id']]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($c) {
            $empresa = (string) ($c['empresa'] ?? '');
        }
    } elseif (in_array($perfil, ['operador', 'gestor'], true)) {
        $lookup = 0;
        if (!empty($u['empresa_id'])) {
            $lookup = (int) $u['empresa_id'];
        } elseif (!empty($u['cliente_id'])) {
            $lookup = (int) $u['cliente_id'];
        }
        if ($lookup > 0) {
            $stmt = $pdo->prepare('SELECT empresa FROM clientes WHERE id = ?');
            $stmt->execute([$lookup]);
            $c = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($c) {
                $empresa = (string) ($c['empresa'] ?? '');
            }
        }
    } elseif ($perfil === 'admin') {
        $empresa = 'LightOn';
    }
    $u['empresa'] = $empresa;
    if (($u['perfil'] ?? '') === 'admin') {
        $isa = (int) ($u['is_super_admin'] ?? 0);
        $u['is_super_admin'] = $isa;
        $u['tipo']           = $isa ? 'Super administrador' : 'Administrador';
    } elseif (($u['perfil'] ?? '') === 'gestor') {
        $u['tipo'] = 'Gestor';
    } elseif (($u['perfil'] ?? '') === 'operador') {
        $u['tipo'] = 'Operador';
    } else {
        $u['tipo'] = 'Cliente';
    }
    $u['id']      = (int) ($u['id'] ?? 0);
    if (array_key_exists('cliente_id', $u) && $u['cliente_id'] !== null) {
        $u['cliente_id'] = (int) $u['cliente_id'];
    }
    if (array_key_exists('empresa_id', $u) && $u['empresa_id'] !== null) {
        $u['empresa_id'] = (int) $u['empresa_id'];
    }
    return $u;
}

function repo_user_by_email(string $email): ?array
{
    $pdo = db();
    if (!$pdo) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE email = ? LIMIT 1');
    $stmt->execute([trim($email)]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        return null;
    }

    return _repo_usuario_enriquecer($pdo, $u);
}

/**
 * Usuário por id (sem senha_hash) — para sessão após atualizar perfil.
 *
 * @return array<string,mixed>|null
 */
function repo_user_by_id(int $id): ?array
{
    $pdo = db();
    if (!$pdo || $id <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        return null;
    }
    unset($u['senha_hash']);

    return _repo_usuario_enriquecer($pdo, $u);
}

/**
 * @return array{ok: bool, err: string}
 */
function repo_update_usuario_perfil(int $userId, string $nome, string $email): array
{
    $pdo = db();
    if (!$pdo || $userId <= 0) {
        return ['ok' => false, 'err' => 'Banco indisponível.'];
    }
    $nome  = trim($nome);
    $email = trim($email);
    if ($nome === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'err' => 'Informe nome e um e-mail válido.'];
    }
    try {
        $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE LOWER(email) = LOWER(?) AND id <> ? LIMIT 1');
        $stmt->execute([$email, $userId]);
        if ($stmt->fetchColumn()) {
            return ['ok' => false, 'err' => 'Este e-mail já está em uso por outro usuário.'];
        }
        $iniciais = repo_usuario_calcular_iniciais($nome);
        $stmt     = $pdo->prepare('UPDATE usuarios SET nome = ?, email = ?, iniciais = ? WHERE id = ?');
        $ok       = $stmt->execute([$nome, $email, $iniciais, $userId]);
        return ['ok' => (bool) $ok, 'err' => $ok ? '' : 'Não foi possível salvar.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'err' => 'Erro ao salvar: ' . $e->getMessage()];
    }
}

/**
 * @return array{ok: bool, err: string}
 */
function repo_update_usuario_senha(int $userId, string $senhaAtual, string $senhaNova, string $senhaNova2): array
{
    $pdo = db();
    if (!$pdo || $userId <= 0) {
        return ['ok' => false, 'err' => 'Banco indisponível.'];
    }
    if ($senhaNova !== $senhaNova2) {
        return ['ok' => false, 'err' => 'A nova senha e a confirmação não coincidem.'];
    }
    if (strlen($senhaNova) < 6) {
        return ['ok' => false, 'err' => 'A nova senha deve ter pelo menos 6 caracteres.'];
    }
    try {
        $stmt = $pdo->prepare('SELECT senha_hash FROM usuarios WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['senha_hash'])) {
            return ['ok' => false, 'err' => 'Usuário não encontrado.'];
        }
        if (!password_verify($senhaAtual, (string) $row['senha_hash'])) {
            return ['ok' => false, 'err' => 'Senha atual incorreta.'];
        }
        $hash = password_hash($senhaNova, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?');
        $ok   = $stmt->execute([$hash, $userId]);
        return ['ok' => (bool) $ok, 'err' => $ok ? '' : 'Não foi possível atualizar a senha.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'err' => 'Erro: ' . $e->getMessage()];
    }
}

/**
 * Lista usuários para o painel admin (sem senha).
 *
 * @return array{rows: list<array<string,mixed>>, total: int}
 */
function repo_usuarios_list_for_admin(string $perfilFil, string $q, int $page, int $perPage, ?int $clienteIdEscopo = null, ?int $gestorUserId = null): array
{
    $pdo = db();
    if (!$pdo || $perPage < 1) {
        return ['rows' => [], 'total' => 0];
    }
    $page = max(1, $page);
    $where = ['1=1'];
    $params = [];
    if (in_array($perfilFil, ['admin', 'cliente', 'operador', 'gestor'], true)) {
        $where[] = 'u.perfil = ?';
        $params[] = $perfilFil;
    }
    if ($clienteIdEscopo !== null && $clienteIdEscopo > 0) {
        $gid = (int) ($gestorUserId ?? 0);
        $sub = '(
            (u.empresa_id = ? AND u.perfil IN (\'operador\',\'gestor\'))
            OR (u.perfil = \'cliente\' AND (u.cliente_id = ? OR u.cliente_id IN (SELECT id FROM clientes WHERE empresa_id = ?)))
        )';
        if ($gid > 0) {
            $where[] = '((' . $sub . ') OR u.id = ?)';
            $params[] = $clienteIdEscopo;
            $params[] = $clienteIdEscopo;
            $params[] = $clienteIdEscopo;
            $params[] = $gid;
        } else {
            $where[] = $sub;
            $params[] = $clienteIdEscopo;
            $params[] = $clienteIdEscopo;
            $params[] = $clienteIdEscopo;
        }
    }
    $q = trim($q);
    if ($q !== '') {
        $where[] = '(u.nome LIKE ? OR u.email LIKE ? OR c.empresa LIKE ? OR ce.empresa LIKE ?)';
        $term = '%' . $q . '%';
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
    }
    $sqlWhere = implode(' AND ', $where);

    $sqlCount = "SELECT COUNT(*) FROM usuarios u LEFT JOIN clientes c ON c.id = u.cliente_id LEFT JOIN clientes ce ON ce.id = u.empresa_id WHERE $sqlWhere";
    $st = $pdo->prepare($sqlCount);
    $st->execute($params);
    $total = (int) $st->fetchColumn();

    $offset = ($page - 1) * $perPage;
    $sql = "
        SELECT u.id, u.nome, u.email, u.perfil, u.modulo_perfil, u.cliente_id, u.empresa_id, u.iniciais,
               DATE_FORMAT(u.criado_em, '%Y-%m-%d %H:%i') AS criado_em,
               COALESCE(NULLIF(c.empresa, ''), ce.empresa) AS cliente_empresa
        FROM usuarios u
        LEFT JOIN clientes c ON c.id = u.cliente_id
        LEFT JOIN clientes ce ON ce.id = u.empresa_id
        WHERE $sqlWhere
        ORDER BY u.id ASC
        LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id']         = (int) ($r['id'] ?? 0);
        $r['cliente_id'] = isset($r['cliente_id']) && $r['cliente_id'] !== null ? (int) $r['cliente_id'] : null;
        $r['empresa_id'] = isset($r['empresa_id']) && $r['empresa_id'] !== null ? (int) $r['empresa_id'] : null;
    }
    unset($r);

    return ['rows' => $rows, 'total' => $total];
}

function repo_update_usuario_admin(
    int $targetId,
    string $nome,
    string $email,
    string $perfil,
    ?int $clienteId,
    ?string $moduloPerfil = null,
    ?int $gestorScopeClienteId = null,
    ?int $gestorUserId = null,
    ?int $empresaIdInput = null
): array {
    $pdo = db();
    if (!$pdo || $targetId <= 0) {
        return ['ok' => false, 'err' => 'Banco indisponível ou usuário inválido.'];
    }
    $perfil = strtolower(trim($perfil));
    if (!in_array($perfil, ['admin', 'cliente', 'operador', 'gestor'], true)) {
        return ['ok' => false, 'err' => 'Perfil inválido.'];
    }
    $nome  = trim($nome);
    $email = trim($email);
    if ($nome === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'err' => 'Informe nome e um e-mail válido.'];
    }
    try {
        $st = $pdo->prepare('SELECT id, perfil, is_super_admin, cliente_id, empresa_id FROM usuarios WHERE id = ? LIMIT 1');
        $st->execute([$targetId]);
        $cur = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cur) {
            return ['ok' => false, 'err' => 'Usuário não encontrado.'];
        }
        $curPerfil = (string) ($cur['perfil'] ?? '');
        $curCid    = isset($cur['cliente_id']) && $cur['cliente_id'] !== null ? (int) $cur['cliente_id'] : 0;
        $curEid    = isset($cur['empresa_id']) && $cur['empresa_id'] !== null ? (int) $cur['empresa_id'] : 0;
        $gScope    = ($gestorScopeClienteId !== null && $gestorScopeClienteId > 0) ? (int) $gestorScopeClienteId : 0;
        $gUid      = (int) ($gestorUserId ?? 0);
        $vinculosResolvidos        = false;
        $clienteIdOut              = null;
        $empresaIdOut              = null;

        if ($gScope > 0) {
            $isSelf = ($targetId === $gUid);
            if (!$isSelf) {
                if ($curPerfil === 'operador' || $curPerfil === 'gestor') {
                    if ($curEid !== $gScope) {
                        return ['ok' => false, 'err' => 'Sem permissão para editar este usuário.'];
                    }
                } elseif ($curPerfil === 'cliente') {
                    if (!repo_cliente_pertence_empresa($curCid, $gScope)) {
                        return ['ok' => false, 'err' => 'Sem permissão para editar este usuário.'];
                    }
                } else {
                    return ['ok' => false, 'err' => 'Sem permissão para editar este usuário.'];
                }
                if (!in_array($perfil, ['operador', 'cliente', 'gestor'], true)) {
                    return ['ok' => false, 'err' => 'Gestor só pode definir perfil cliente, operador ou gestor.'];
                }
                if ($perfil === 'operador' || $perfil === 'gestor') {
                    $empresaIdOut = $gScope;
                    $clienteIdOut = null;
                } else {
                    $empresaIdOut = null;
                    $clienteIdOut = (int) ($clienteId ?? 0);
                    if ($clienteIdOut <= 0 || !repo_cliente_pertence_empresa($clienteIdOut, $gScope)) {
                        return ['ok' => false, 'err' => 'Conta cliente inválida para esta empresa.'];
                    }
                }
                $vinculosResolvidos = true;
            } else {
                if ($curPerfil !== 'gestor' || $perfil !== 'gestor') {
                    return ['ok' => false, 'err' => 'Perfil do gestor não pode ser alterado nesta tela.'];
                }
                $empresaIdOut = $gScope;
                $clienteIdOut = null;
                $vinculosResolvidos = true;
            }
        }

        if (($cur['perfil'] ?? '') === 'admin' && $perfil !== 'admin') {
            $cnt = (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE perfil = 'admin'")->fetchColumn();
            if ($cnt < 2) {
                return ['ok' => false, 'err' => 'Cadastre outro administrador antes de alterar o perfil deste usuário.'];
            }
        }

        if (!$vinculosResolvidos) {
            if ($perfil === 'admin') {
                $clienteIdOut = null;
                $empresaIdOut  = null;
            } elseif ($perfil === 'gestor' || $perfil === 'operador') {
                $eid = (int) ($empresaIdInput ?? 0);
                if ($eid <= 0 && $perfil === 'operador') {
                    $eid = (int) ($clienteId ?? 0);
                }
                if ($eid <= 0) {
                    return ['ok' => false, 'err' => 'Selecione a empresa (raiz) para este usuário.'];
                }
                $chk = $pdo->prepare('SELECT id FROM clientes WHERE id = ? AND empresa_id IS NULL LIMIT 1');
                $chk->execute([$eid]);
                if (!$chk->fetchColumn()) {
                    return ['ok' => false, 'err' => 'Empresa inválida (use um cadastro raiz, sem matriz).'];
                }
                $empresaIdOut = $eid;
                $clienteIdOut = null;
            } else {
                $cid = (int) ($clienteId ?? 0);
                if ($cid <= 0) {
                    return ['ok' => false, 'err' => 'Selecione a conta cliente do portal.'];
                }
                $chk = $pdo->prepare('SELECT id FROM clientes WHERE id = ? LIMIT 1');
                $chk->execute([$cid]);
                if (!$chk->fetchColumn()) {
                    return ['ok' => false, 'err' => 'Conta não encontrada.'];
                }
                $clienteIdOut = $cid;
                $empresaIdOut = null;
            }
        }

        $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE LOWER(email) = LOWER(?) AND id <> ? LIMIT 1');
        $stmt->execute([$email, $targetId]);
        if ($stmt->fetchColumn()) {
            return ['ok' => false, 'err' => 'Este e-mail já está em uso por outro usuário.'];
        }

        $iniciais = repo_usuario_calcular_iniciais($nome);
        $mpf      = null;
        $keepSuper = ($perfil === 'admin') ? (int) ($cur['is_super_admin'] ?? 0) : 0;
        $stmt = $pdo->prepare('UPDATE usuarios SET nome = ?, email = ?, perfil = ?, modulo_perfil = ?, cliente_id = ?, empresa_id = ?, iniciais = ?, is_super_admin = ? WHERE id = ?');
        $ok   = $stmt->execute([$nome, $email, $perfil, $mpf, $clienteIdOut, $empresaIdOut, $iniciais, $keepSuper, $targetId]);
        return ['ok' => (bool) $ok, 'err' => $ok ? '' : 'Não foi possível salvar.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'err' => 'Erro ao salvar: ' . $e->getMessage()];
    }
}

/**
 * Define nova senha para um usuário (admin, sem exigir senha atual).
 *
 * @return array{ok: bool, err: string}
 */
function repo_admin_definir_senha_usuario(int $userId, string $senhaNova, string $senhaNova2): array
{
    $pdo = db();
    if (!$pdo || $userId <= 0) {
        return ['ok' => false, 'err' => 'Banco indisponível.'];
    }
    if ($senhaNova !== $senhaNova2) {
        return ['ok' => false, 'err' => 'A nova senha e a confirmação não coincidem.'];
    }
    if (strlen($senhaNova) < 6) {
        return ['ok' => false, 'err' => 'A senha deve ter pelo menos 6 caracteres.'];
    }
    try {
        $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        if (!$stmt->fetchColumn()) {
            return ['ok' => false, 'err' => 'Usuário não encontrado.'];
        }
        $hash = password_hash($senhaNova, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?');
        $ok   = $stmt->execute([$hash, $userId]);
        return ['ok' => (bool) $ok, 'err' => $ok ? '' : 'Não foi possível atualizar a senha.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'err' => 'Erro: ' . $e->getMessage()];
    }
}

/* ================================================================== */
/*  ANEXOS DO CLIENTE                                                 */
/* ================================================================== */
function repo_create_cliente_anexo(array $d): ?int
{
    $pdo = db();
    if (!$pdo) return null;

    $stmt = $pdo->prepare('
        INSERT INTO cliente_anexos
            (cliente_id, nome_original, nome_arquivo, mime, tamanho, tipo, descricao, enviado_por)
        VALUES
            (:cliente_id, :nome_original, :nome_arquivo, :mime, :tamanho, :tipo, :descricao, :enviado_por)
    ');
    $stmt->execute([
        ':cliente_id'    => (int) $d['cliente_id'],
        ':nome_original' => $d['nome_original'],
        ':nome_arquivo'  => $d['nome_arquivo'],
        ':mime'          => $d['mime']      ?? null,
        ':tamanho'       => (int) ($d['tamanho'] ?? 0),
        ':tipo'          => $d['tipo']      ?? 'Outro',
        ':descricao'     => $d['descricao'] ?? null,
        ':enviado_por'   => $d['enviado_por'] ?? null,
    ]);
    return (int) $pdo->lastInsertId();
}

function repo_cliente_anexos(int $clienteId): array
{
    $pdo = db();
    if (!$pdo) return [];

    $stmt = $pdo->prepare('
        SELECT id, cliente_id, nome_original, nome_arquivo, mime, tamanho, tipo, descricao,
               enviado_por, DATE_FORMAT(enviado_em, "%Y-%m-%d %H:%i") AS enviado_em
        FROM cliente_anexos
        WHERE cliente_id = ?
        ORDER BY enviado_em DESC, id DESC
    ');
    $stmt->execute([$clienteId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['id']         = (int) $r['id'];
        $r['cliente_id'] = (int) $r['cliente_id'];
        $r['tamanho']    = (int) $r['tamanho'];
    }
    return $rows;
}

function repo_cliente_anexo(int $id): ?array
{
    $pdo = db();
    if (!$pdo) return null;

    $stmt = $pdo->prepare('SELECT * FROM cliente_anexos WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    if (!$r) return null;
    $r['id']         = (int) $r['id'];
    $r['cliente_id'] = (int) $r['cliente_id'];
    $r['tamanho']    = (int) $r['tamanho'];
    return $r;
}

function repo_delete_cliente_anexo(int $id): ?array
{
    $pdo = db();
    if (!$pdo) return null;

    $anexo = repo_cliente_anexo($id);
    if (!$anexo) return null;

    $stmt = $pdo->prepare('DELETE FROM cliente_anexos WHERE id = ?');
    $stmt->execute([$id]);
    return $anexo;
}

/* ---- SaaS: módulos por instância (sidebar + rotas) ---- */

/**
 * @return list<array{grupo:string,modulo_key:string,label:string,habilitado:int,ordem:int}>
 */
function repo_saas_modulos_list(string $grupo): array
{
    $pdo = db();
    if (!$pdo || !in_array($grupo, ['super_admin', 'gestor', 'cliente', 'operador'], true)) {
        return [];
    }
    try {
        $stmt = $pdo->prepare('
            SELECT grupo, modulo_key, label, habilitado, ordem
            FROM saas_modulos
            WHERE grupo = ?
            ORDER BY ordem ASC, modulo_key ASC
        ');
        $stmt->execute([$grupo]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['habilitado'] = (int) ($r['habilitado'] ?? 0);
            $r['ordem']      = (int) ($r['ordem'] ?? 0);
        }
        unset($r);
        if (function_exists('app_saas_modulos_keys_por_grupo')) {
            $allowed = app_saas_modulos_keys_por_grupo($grupo);
            if ($allowed !== []) {
                $rows = array_values(array_filter(
                    $rows,
                    static function (array $r) use ($allowed): bool {
                        return in_array((string) ($r['modulo_key'] ?? ''), $allowed, true);
                    }
                ));
            }
        }

        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Remove de saas_modulos e perfil_modulos chaves que não existem mais no produto.
 *
 * @return int Linhas apagadas (soma aproximada das duas tabelas)
 */
function repo_saas_modulos_prune_invalidos(): int
{
    if (!function_exists('app_saas_modulos_keys_por_grupo')) {
        return 0;
    }
    $pdo = db();
    if (!$pdo) {
        return 0;
    }
    $total = 0;
    foreach (['super_admin', 'gestor', 'cliente', 'operador'] as $grupo) {
        $allowed = app_saas_modulos_keys_por_grupo($grupo);
        if ($allowed === []) {
            continue;
        }
        $placeholders = implode(',', array_fill(0, count($allowed), '?'));
        $params       = array_merge([$grupo], $allowed);
        try {
            $st = $pdo->prepare(
                "DELETE FROM saas_modulos WHERE grupo = ? AND modulo_key NOT IN ($placeholders)"
            );
            $st->execute($params);
            $total += $st->rowCount();
            $st2 = $pdo->prepare(
                "DELETE FROM perfil_modulos WHERE grupo = ? AND modulo_key NOT IN ($placeholders)"
            );
            $st2->execute($params);
            $total += $st2->rowCount();
        } catch (Throwable $e) {
            // ignora (FK ou versão antiga do schema)
        }
    }

    return $total;
}

function repo_saas_modulo_set_habilitado(string $grupo, string $key, bool $habilitado): bool
{
    $pdo = db();
    if (!$pdo || !in_array($grupo, ['super_admin', 'gestor', 'cliente', 'operador'], true) || $key === '') {
        return false;
    }
    try {
        $stmt = $pdo->prepare('
            UPDATE saas_modulos SET habilitado = ? WHERE grupo = ? AND modulo_key = ?
        ');
        $stmt->execute([$habilitado ? 1 : 0, $grupo, $key]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Chaves presentes na tabela → estado (1/0). Chaves ausentes não entram (tratadas como liberadas no app).
 *
 * @return array<string, bool>
 */
function repo_saas_modulos_habilitados_map(string $grupo): array
{
    $list = repo_saas_modulos_list($grupo);
    $map   = [];
    foreach ($list as $r) {
        $k = (string) ($r['modulo_key'] ?? '');
        if ($k !== '') {
            $map[$k] = !empty($r['habilitado']);
        }
    }
    return $map;
}

require_once __DIR__ . '/os_pedido_repo.php';

