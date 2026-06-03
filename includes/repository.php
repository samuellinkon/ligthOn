<?php
/**
 * Repository: funções que leem dados do banco e devolvem
 * arrays no MESMO formato dos mocks originais. Assim as páginas
 * existentes continuam funcionando sem qualquer alteração.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/upload.php';
require_once __DIR__ . '/chamado_os_fields.php';
require_once __DIR__ . '/chamado_anexo_sync_ponto_foto.php';
require_once __DIR__ . '/audit_log.php';

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
            (SELECT COUNT(*) FROM chamados ch WHERE ch.cliente_id = c.id" . _repo_chamados_sql_apenas_ativos('ch') . ") AS chamados,
            (SELECT COUNT(*) FROM chamados ch
              WHERE ch.cliente_id = c.id
                AND ch.status IN ('Aberto','Em andamento','Aguardando Aprovação')" . _repo_chamados_sql_apenas_ativos('ch') . ") AS pendentes
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

function repo_chamado_tem_coordenadas_validas(array $ch, ?array $ponto = null): bool
{
    if (!function_exists('chamado_coordenadas_efetivas')) {
        require_once __DIR__ . '/chamado_os_fields.php';
    }
    [$la, $lo] = chamado_coordenadas_efetivas($ch, $ponto);
    if ($la !== null && $lo !== null) {
        return true;
    }

    return false;
}

/**
 * Condição SQL: chamados que entram no boletim BM oficial e relatório fotográfico (medição fechada).
 */
function repo_chamados_status_sql_medicao_bm(): string
{
    return "ch.status = 'Validado'";
}

/**
 * Condição SQL legada — preferir {@see repo_chamados_status_sql_medicao_bm()} na medição oficial e no BM completo (detalhe).
 */
function repo_chamados_status_sql_medicao_bm_completo(): string
{
    return repo_chamados_status_sql_medicao_bm();
}

/**
 * Condição SQL: lançamentos que compõem custo de medição/BM (materiais e serviços utilizados).
 * Itens devolvidos/recolhidos (sucata) ficam fora dos totais oficiais.
 */
function repo_chamados_sql_movimento_medicao_custo(string $alias = 'ci'): string
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'ci';

    return $a . ".movimento = 'utilizado'";
}

/**
 * @return array{ok: bool, err: string}
 */
function repo_validar_chamado_resolvido(int $id): array
{
    $ch = repo_chamado($id);
    if (!$ch) {
        return ['ok' => false, 'err' => 'Chamado não encontrado.'];
    }
    $ponto = null;
    $pid = (int) ($ch['ponto_iluminacao_id'] ?? 0);
    if ($pid > 0) {
        $ponto = repo_ponto_iluminacao($pid);
    }
    if (!repo_chamado_tem_coordenadas_validas($ch, $ponto)) {
        return [
            'ok'  => false,
            'err' => 'Cadastre latitude e longitude válidas no chamado antes de finalizá-lo.',
        ];
    }

    return ['ok' => true, 'err' => ''];
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
 * Intervalo de datas normalizado para mapa de chamados.
 *
 * @return array{de: string, ate: string, de_start: string, ate_fim: string}
 */
function _repo_chamados_mapa_periodo_bounds(string $dataDe, string $dataAte): array
{
    $de  = strlen($dataDe) === 10 ? $dataDe : date('Y-m-d', strtotime('-30 days'));
    $ate = strlen($dataAte) === 10 ? $dataAte : date('Y-m-d');
    if (strcmp($de, $ate) > 0) {
        $tmp = $de;
        $de  = $ate;
        $ate = $tmp;
    }

    return [
        'de'        => $de,
        'ate'       => $ate,
        'de_start'  => $de . ' 00:00:00',
        'ate_fim'   => (new DateTimeImmutable($ate))->modify('+1 day')->format('Y-m-d H:i:s'),
    ];
}

/**
 * @param array<string, mixed> $r
 * @return array<string, mixed>
 */
function _repo_chamado_mapa_pin_base(array $r, string $fotoUrl): array
{
    $cid = (int) ($r['id'] ?? 0);

    return [
        'id'                => $cid,
        'titulo'            => (string) ($r['titulo'] ?? ''),
        'status'            => (string) ($r['status'] ?? ''),
        'prioridade'        => (string) ($r['prioridade'] ?? ''),
        'origem_os'         => (string) ($r['origem_os'] ?? ''),
        'problema_os'       => (string) ($r['problema_os'] ?? ''),
        'data'              => (string) ($r['data'] ?? ''),
        'cliente'           => (string) ($r['cliente'] ?? ''),
        'endereco_completo' => isset($r['endereco_completo']) && $r['endereco_completo'] !== ''
            ? (string) $r['endereco_completo'] : null,
        'foto_url'          => $fotoUrl,
    ];
}

/**
 * Chamados do período para mapa: pins com coords (chamado/poste) ou geocode pendente.
 *
 * @return array{pins: list<array<string, mixed>>, stats: array{total_periodo:int,ready:int,pending_geocode:int,sem_localizacao:int}}
 */
function repo_chamados_mapa_data(string $dataDe, string $dataAte, ?int $clienteId = null): array
{
    $emptyStats = [
        'total_periodo'     => 0,
        'ready'             => 0,
        'pending_geocode'   => 0,
        'sem_localizacao'   => 0,
    ];
    $pdo = db();
    if (!$pdo) {
        return ['pins' => [], 'stats' => $emptyStats];
    }

    if (!function_exists('chamado_coordenadas_efetivas')) {
        require_once __DIR__ . '/chamado_os_fields.php';
    }
    if (!function_exists('chamado_map_preview_tier')) {
        require_once __DIR__ . '/chamado_geo.php';
    }

    $bounds = _repo_chamados_mapa_periodo_bounds($dataDe, $dataAte);
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
                ch.prioridade,
                ch.origem_os,
                ch.problema_os,
                ch.ponto_iluminacao_id,
                ch.latitude,
                ch.longitude,
                ch.endereco_completo,
                ch.os_cep,
                ch.os_logradouro,
                ch.os_numero,
                ch.os_complemento,
                ch.os_bairro,
                ch.os_cidade,
                ch.os_uf,
                pi.latitude AS ponto_latitude,
                pi.longitude AS ponto_longitude,
                DATE_FORMAT(ch.aberto_em, \'%Y-%m-%d %H:%i\') AS data,
                c.empresa AS cliente
            FROM chamados ch
            JOIN clientes c ON c.id = ch.cliente_id
            LEFT JOIN pontos_iluminacao pi ON pi.id = ch.ponto_iluminacao_id
            WHERE (
                (ch.aberto_em >= ? AND ch.aberto_em < ?)
                OR (ch.data_abertura_os IS NOT NULL AND ch.data_abertura_os >= ? AND ch.data_abertura_os <= ?)
            )
              ' . _repo_chamados_sql_apenas_ativos('ch') . '
              ' . $scopeSql . '
            ORDER BY ch.aberto_em DESC
        ');
        $params = [$bounds['de_start'], $bounds['ate_fim'], $bounds['de'], $bounds['ate']];
        if ($clienteId !== null && $clienteId > 0) {
            $params[] = $clienteId;
            $params[] = $clienteId;
        }
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $chIds = array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $rows);
        $thumbPorChamado = [];
        if ($chIds !== []) {
            $ph = implode(',', array_fill(0, count($chIds), '?'));
            $stImg = $pdo->prepare("
                SELECT a.chamado_id, MIN(a.id) AS anexo_id
                FROM chamado_anexos a
                WHERE a.chamado_id IN ($ph)
                  AND (a.mime LIKE 'image/%' OR a.nome_original REGEXP '\\\\.(png|jpe?g|gif|webp|bmp)$')
                GROUP BY a.chamado_id
            ");
            $stImg->execute($chIds);
            foreach ($stImg->fetchAll(PDO::FETCH_ASSOC) ?: [] as $im) {
                $cid = (int) ($im['chamado_id'] ?? 0);
                $aid = (int) ($im['anexo_id'] ?? 0);
                if ($cid > 0 && $aid > 0) {
                    $thumbPorChamado[$cid] = 'chamado_download.php?id=' . $aid;
                }
            }
        }

        $pins   = [];
        $stats  = $emptyStats;
        $stats['total_periodo'] = count($rows);

        foreach ($rows as $r) {
            $cid   = (int) ($r['id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            $ponto = null;
            $pid   = (int) ($r['ponto_iluminacao_id'] ?? 0);
            if ($pid > 0) {
                $ponto = [
                    'id'        => $pid,
                    'latitude'  => $r['ponto_latitude'] ?? null,
                    'longitude' => $r['ponto_longitude'] ?? null,
                ];
            }

            [$la, $lo] = chamado_coordenadas_efetivas($r, $ponto);
            $base      = _repo_chamado_mapa_pin_base($r, $thumbPorChamado[$cid] ?? '');

            if ($la !== null && $lo !== null) {
                $stats['ready']++;
                $pins[] = array_merge($base, [
                    'pin_state' => 'ready',
                    'lat'       => $la,
                    'lng'       => $lo,
                ]);
                continue;
            }

            $tier = chamado_map_preview_tier($r, $ponto);
            $attempts = [];
            if ($tier === 2) {
                $attempts = chamado_geocode_attempts_com_cep($r);
            } elseif ($tier >= 3) {
                $attempts = chamado_geocode_attempts($r);
            } elseif ($tier < 0) {
                $attempts = chamado_geocode_attempts($r);
            }
            if ($attempts !== []) {
                $stats['pending_geocode']++;
                $pins[] = array_merge($base, [
                    'pin_state'        => 'pending_geocode',
                    'lat'              => null,
                    'lng'              => null,
                    'geocode_attempts' => $attempts,
                ]);
                continue;
            }

            $stats['sem_localizacao']++;
        }

        return ['pins' => $pins, 'stats' => $stats];
    } catch (Throwable $e) {
        return ['pins' => [], 'stats' => $emptyStats];
    }
}

/**
 * @return list<array<string, mixed>>
 */
function repo_chamados_mapa_pins(string $dataDe, string $dataAte, ?int $clienteId = null): array
{
    return repo_chamados_mapa_data($dataDe, $dataAte, $clienteId)['pins'];
}

/**
 * @return array{total_periodo:int,ready:int,pending_geocode:int,sem_localizacao:int}
 */
function repo_chamados_mapa_pins_stats(string $dataDe, string $dataAte, ?int $clienteId = null): array
{
    return repo_chamados_mapa_data($dataDe, $dataAte, $clienteId)['stats'];
}

/**
 * Grava lat/lng no chamado apenas se ainda não houver coordenadas válidas.
 */
function repo_chamado_persist_geocode_se_vazio(int $chamadoId, float $lat, float $lng): array
{
    if (!function_exists('chamado_geo_coords_validas')) {
        require_once __DIR__ . '/chamado_geo.php';
    }
    if ($chamadoId <= 0 || !chamado_geo_coords_validas($lat, $lng)) {
        return ['ok' => false, 'err' => 'Coordenadas inválidas.'];
    }
    $ch = repo_chamado($chamadoId);
    if (!$ch) {
        return ['ok' => false, 'err' => 'Chamado não encontrado.'];
    }
    [$cla, $clo] = chamado_geo_row_latlng($ch);
    if ($cla !== null && $clo !== null) {
        return ['ok' => true, 'err' => '', 'skipped' => true];
    }
    $end = trim((string) ($ch['endereco_completo'] ?? ''));
    $end = $end !== '' ? $end : null;
    if (!repo_update_chamado_localizacao($chamadoId, $end, $lat, $lng)) {
        return ['ok' => false, 'err' => 'Não foi possível gravar as coordenadas.'];
    }

    return ['ok' => true, 'err' => '', 'skipped' => false];
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
                SUM(CASE WHEN ch.status IN ('Aberto','Em andamento','Aguardando Aprovação') THEN 1 ELSE 0 END) AS chamados_abertos
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
            SELECT id, ponto_iluminacao_id, nome_original, nome_arquivo, `principal`
            FROM ponto_iluminacao_imagens
            WHERE ponto_iluminacao_id IN ($placeholders)
              AND `principal` = 1
            ORDER BY ponto_iluminacao_id ASC, id DESC
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
                'nome_arquivo' => (string) ($r['nome_arquivo'] ?? ''),
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
function repo_pontos_iluminacao_mapa(int $clienteId, bool $escopoEmpresa = false, string $status = 'Ativo'): array
{
    $statusFiltro = in_array($status, ['Ativo', 'Inativo'], true) ? $status : '';
    $rows = repo_pontos_iluminacao_list($clienteId, $escopoEmpresa, '', $statusFiltro);
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
        $fotoUrl = '';
        $fotoNome = '';
        if ($img && $pontoId > 0) {
            $nomeFs = basename((string) ($img['nome_arquivo'] ?? ''));
            $pathFs = $nomeFs !== ''
                ? upload_dir_ponto_iluminacao($pontoId) . DIRECTORY_SEPARATOR . $nomeFs
                : '';
            if ($pathFs !== '' && is_file($pathFs) && is_readable($pathFs)) {
                $fotoUrl = 'ponto_iluminacao_imagem.php?id=' . (int) ($img['id'] ?? 0);
                $fotoNome = (string) ($img['nome_original'] ?? '');
            }
        }
        $ponto['foto_url'] = $fotoUrl;
        $ponto['foto_nome'] = $fotoNome;
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
            ' . (repo_chamados_tem_exclusao_logica() ? ',
            ch.ativo,
            DATE_FORMAT(ch.excluido_em, \'%Y-%m-%d %H:%i\') AS excluido_em,
            ch.excluido_por_user_id,
            uex.nome AS excluido_por_nome' : '') . '
        FROM chamados ch
        JOIN clientes c ON c.id = ch.cliente_id
        LEFT JOIN usuarios ut ON ut.id = ch.tecnico_user_id
        LEFT JOIN usuarios ua ON ua.id = ch.aprovado_gestor_user_id
        LEFT JOIN cliente_itens s ON s.id = ch.servico_id
        LEFT JOIN pontos_iluminacao pi ON pi.id = ch.ponto_iluminacao_id
        ' . (repo_chamados_tem_exclusao_logica() ? 'LEFT JOIN usuarios uex ON uex.id = ch.excluido_por_user_id' : '') . '
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
    if (array_key_exists('ativo', $r)) {
        $r['ativo'] = (int) ($r['ativo'] ?? 1);
    } else {
        $r['ativo'] = 1;
    }
    if (array_key_exists('excluido_por_user_id', $r) && $r['excluido_por_user_id'] !== null) {
        $r['excluido_por_user_id'] = (int) $r['excluido_por_user_id'];
    }
    _repo_chamado_row_normalizar_geo($r);
    _repo_chamado_anexar_tecnicos($r);

    return $r;
}

function repo_chamado_tecnicos_table_exists(): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    $pdo = db();
    if (!$pdo) {
        $exists = false;

        return $exists;
    }
    try {
        $st = $pdo->query("SHOW TABLES LIKE 'chamado_tecnicos'");
        $exists = (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        $exists = false;
    }

    return $exists;
}

function repo_chamados_tem_exclusao_logica(): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    $pdo = db();
    if (!$pdo) {
        $exists = false;

        return $exists;
    }
    try {
        $st = $pdo->query("SHOW COLUMNS FROM chamados LIKE 'ativo'");
        $exists = (bool) $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $exists = false;
    }

    return $exists;
}

/**
 * Filtro de visibilidade nas listagens.
 * - excluidos: só inativos
 * - qualquer outro filtro (incl. Todos vazio): só ativos
 *
 * @param list<string> $where
 */
function _repo_chamados_where_ativo(array &$where, string $filtro = ''): void
{
    if (!repo_chamados_tem_exclusao_logica()) {
        return;
    }
    if (strtolower(trim($filtro)) === 'excluidos') {
        $where[] = 'ch.ativo = 0';
    } else {
        $where[] = 'ch.ativo = 1';
    }
}

/** SQL extra para contagens (dashboard, subqueries). */
function _repo_chamados_sql_apenas_ativos(string $alias = ''): string
{
    if (!repo_chamados_tem_exclusao_logica()) {
        return '';
    }
    $col = ($alias !== '' ? $alias . '.' : '') . 'ativo';

    return " AND {$col} = 1";
}

/**
 * Cláusula SQL para busca textual em listagens de chamados (q na URL).
 *
 * @return array{0: string, 1: list<mixed>}|null null se $q vazio
 */
function _repo_chamados_busca_q_sql(string $q, bool $incluirEmpresa = false, bool $incluirDescricao = false): ?array
{
    $q = trim($q);
    if ($q === '') {
        return null;
    }
    $term   = '%' . $q . '%';
    $parts  = [];
    $params = [];
    if (ctype_digit($q)) {
        $parts[]  = 'ch.id = ?';
        $params[] = (int) $q;
    }
    $parts[]  = 'ch.titulo LIKE ?';
    $params[] = $term;
    if ($incluirDescricao) {
        $parts[]  = 'ch.descricao LIKE ?';
        $params[] = $term;
    }
    if ($incluirEmpresa) {
        $parts[]  = 'c.empresa LIKE ?';
        $params[] = $term;
    }
    $parts[]  = 'CAST(ch.id AS CHAR) LIKE ?';
    $params[] = '%' . $q . '%';
    $parts[]  = 'EXISTS (
        SELECT 1 FROM pontos_iluminacao pi
        WHERE pi.id = ch.ponto_iluminacao_id AND pi.codigo_poste LIKE ?
    )';
    $params[] = $term;
    $parts[]  = 'ch.ponto_referencia LIKE ?';
    $params[] = $term;

    return ['(' . implode(' OR ', $parts) . ')', $params];
}

function repo_chamado_itens_tem_criado_por(): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    $pdo = db();
    if (!$pdo) {
        $exists = false;

        return $exists;
    }
    try {
        $st = $pdo->query("SHOW COLUMNS FROM chamado_itens LIKE 'criado_por_user_id'");
        $exists = (bool) $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $exists = false;
    }

    return $exists;
}

/**
 * ID do utilizador em sessão (admin, gestor ou operador).
 */
function repo_usuario_sessao_id(): ?int
{
    if (!function_exists('current_user')) {
        return null;
    }
    $u = current_user();
    $id  = isset($u['id']) ? (int) $u['id'] : 0;

    return $id > 0 ? $id : null;
}

/**
 * Preenche criado_por_user_id em itens antigos deste chamado (técnico / quem finalizou).
 */
function repo_chamado_itens_backfill_criado_por(int $chamadoId): void
{
    if ($chamadoId <= 0 || !repo_chamado_itens_tem_criado_por()) {
        return;
    }
    $pdo = db();
    if (!$pdo) {
        return;
    }
    try {
        $st = $pdo->prepare('
            UPDATE chamado_itens ci
            INNER JOIN chamados ch ON ch.id = ci.chamado_id
            SET ci.criado_por_user_id = COALESCE(ch.finalizado_operador_user_id, ch.tecnico_user_id)
            WHERE ci.chamado_id = ?
              AND ci.criado_por_user_id IS NULL
              AND COALESCE(ch.finalizado_operador_user_id, ch.tecnico_user_id) IS NOT NULL
        ');
        $st->execute([$chamadoId]);
    } catch (Throwable $e) {
        error_log('[crm_prefeitura] repo_chamado_itens_backfill_criado_por: ' . $e->getMessage());
    }
}

/**
 * @return list<array<string,mixed>>
 */
function repo_chamado_tecnicos_list(int $chamadoId): array
{
    $pdo = db();
    if (!$pdo || $chamadoId <= 0) {
        return [];
    }
    $rows = [];
    if (repo_chamado_tecnicos_table_exists()) {
        $st = $pdo->prepare('
            SELECT u.id, u.nome, u.email, u.iniciais, ct.created_at
            FROM chamado_tecnicos ct
            JOIN usuarios u ON u.id = ct.usuario_id
            JOIN chamados ch ON ch.id = ct.chamado_id
            WHERE ct.chamado_id = ?
            ORDER BY CASE WHEN u.id = ch.tecnico_user_id THEN 0 ELSE 1 END,
                     ct.created_at ASC, u.nome ASC, u.id ASC
        ');
        $st->execute([$chamadoId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    if (empty($rows)) {
        $st = $pdo->prepare('
            SELECT u.id, u.nome, u.email, u.iniciais, NULL AS created_at
            FROM chamados ch
            JOIN usuarios u ON u.id = ch.tecnico_user_id
            WHERE ch.id = ? AND ch.tecnico_user_id IS NOT NULL
            LIMIT 1
        ');
        $st->execute([$chamadoId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
    }
    unset($r);

    return $rows;
}

/**
 * @param array<string,mixed> $row
 */
function _repo_chamado_anexar_tecnicos(array &$row): void
{
    $tecnicos = repo_chamado_tecnicos_list((int) ($row['id'] ?? 0));
    $ids = [];
    $nomes = [];
    foreach ($tecnicos as $tec) {
        $tid = (int) ($tec['id'] ?? 0);
        if ($tid <= 0) {
            continue;
        }
        $ids[] = $tid;
        $nome = trim((string) ($tec['nome'] ?? ''));
        if ($nome !== '') {
            $nomes[] = $nome;
        }
    }
    $row['tecnicos'] = $tecnicos;
    $row['tecnico_ids'] = $ids;
    $row['tecnico_nomes'] = $nomes;
    if (!empty($ids) && empty($row['tecnico_user_id'])) {
        $row['tecnico_user_id'] = $ids[0];
    }
    if (!empty($nomes)) {
        $row['tecnico_nome'] = implode(', ', $nomes);
    }
}

/**
 * @param array<string,mixed> $row
 */
function _repo_chamado_normalizar_tecnico_ids(array &$row): void
{
    $raw = (string) ($row['tecnico_ids'] ?? '');
    $ids = [];
    foreach (explode(',', $raw) as $part) {
        $tid = (int) trim($part);
        if ($tid > 0 && !in_array($tid, $ids, true)) {
            $ids[] = $tid;
        }
    }
    if (empty($ids) && !empty($row['tecnico_user_id'])) {
        $ids[] = (int) $row['tecnico_user_id'];
    }
    $row['tecnico_ids'] = $ids;
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
 * Chamados em que o operador intervém: técnico principal (`tecnico_user_id`) ou linha em `chamado_tecnicos`.
 * Apenas clientes da empresa raiz (matriz e filhos). Sem utilizador válido, lista vazia.
 *
 * @return array{rows: list<array<string,mixed>>, total: int}
 */
function repo_chamados_operador_list(int $empresaRaizId, string $filtro, string $q, int $page, int $perPage, ?int $operadorUserId = null): array
{
    $pdo = db();
    if (!$pdo || $empresaRaizId <= 0 || $perPage < 1 || $operadorUserId === null || $operadorUserId <= 0) {
        return ['rows' => [], 'total' => 0];
    }
    $filtro = strtolower(trim($filtro));
    $q      = trim($q);
    $temTecnicosTabela = repo_chamado_tecnicos_table_exists();

    $where = ['ch.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)'];
    $params = [$empresaRaizId, $empresaRaizId];
    _repo_chamados_where_ativo($where);
    if ($temTecnicosTabela) {
        $where[] = '(ch.tecnico_user_id = ? OR EXISTS (
                SELECT 1 FROM chamado_tecnicos ctf
                WHERE ctf.chamado_id = ch.id AND ctf.usuario_id = ?
            ))';
        $params[] = $operadorUserId;
        $params[] = $operadorUserId;
    } else {
        $where[] = 'ch.tecnico_user_id = ?';
        $params[] = $operadorUserId;
    }

    if ($filtro === 'andamento') {
        $where[] = 'ch.status IN (\'Aberto\',\'Em andamento\')';
    } elseif ($filtro === 'aguardando') {
        $where[] = 'ch.status = ?';
        $params[] = 'Aguardando Aprovação';
    } elseif ($filtro === 'resolvido') {
        $where[] = 'ch.status IN (\'Resolvido\',\'Fechado\',\'Cancelado\')';
    }

    $buscaQ = _repo_chamados_busca_q_sql($q, false, true);
    if ($buscaQ !== null) {
        $where[] = $buscaQ[0];
        foreach ($buscaQ[1] as $p) {
            $params[] = $p;
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

    $tecnicoSelect = $temTecnicosTabela
        ? 'COALESCE(tecs.tecnico_nomes, ut.nome) AS tecnico_nome,
            tecs.tecnico_ids AS tecnico_ids,'
        : 'ut.nome AS tecnico_nome,
            NULL AS tecnico_ids,';
    $tecnicoJoin = $temTecnicosTabela
        ? "
        LEFT JOIN (
            SELECT ct.chamado_id,
                   GROUP_CONCAT(u.nome ORDER BY u.nome SEPARATOR ', ') AS tecnico_nomes,
                   GROUP_CONCAT(u.id ORDER BY u.nome SEPARATOR ',') AS tecnico_ids
            FROM chamado_tecnicos ct
            JOIN usuarios u ON u.id = ct.usuario_id
            GROUP BY ct.chamado_id
        ) tecs ON tecs.chamado_id = ch.id"
        : '';
    $sql = "
        SELECT
            ch.id, ch.cliente_id, ch.titulo, ch.descricao,
            ch.endereco_completo, ch.latitude, ch.longitude,
            ch.servico_id, ch.finalizado_operador_em, ch.tecnico_user_id, ch.aprovado_gestor_em,
            ch.prioridade, ch.status, ch.responsavel,
            ch.origem_os, ch.problema_os,
            DATE_FORMAT(ch.aberto_em, '%Y-%m-%d %H:%i') AS data,
            c.empresa AS cliente,
            $tecnicoSelect
            s.nome AS servico_nome,
            s.tipo AS servico_tipo,
            s.valor_unitario AS servico_valor_unitario
        FROM chamados ch
        JOIN clientes c ON c.id = ch.cliente_id
        LEFT JOIN usuarios ut ON ut.id = ch.tecnico_user_id
        $tecnicoJoin
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
        _repo_chamado_normalizar_tecnico_ids($r);
        _repo_chamado_row_normalizar_geo($r);
    }
    unset($r);

    return ['rows' => $rows, 'total' => $total];
}

/**
 * Métricas do painel do operador (apenas chamados atribuídos ao usuário).
 *
 * @return array{ch_abertos:int,ch_andamento:int,ch_urgentes:int,ch_resolvidos_7d:int}|null
 */
function repo_dashboard_operador_stats(int $empresaRaizId, int $operadorUserId): ?array
{
    if ($empresaRaizId <= 0 || $operadorUserId <= 0 || !db_ok()) {
        return null;
    }
    $res = repo_chamados_operador_list($empresaRaizId, '', '', 1, 5000, $operadorUserId);
    $abertos = $andamento = $urgentes = $res7d = 0;
    foreach ($res['rows'] as $ch) {
        $st = (string) ($ch['status'] ?? '');
        if ($st === 'Aberto') {
            $abertos++;
        } elseif ($st === 'Em andamento') {
            $andamento++;
        }
        if (in_array($st, ['Aberto', 'Em andamento', 'Aguardando Aprovação'], true)
            && in_array((string) ($ch['prioridade'] ?? ''), ['Alta', 'Urgente'], true)) {
            $urgentes++;
        }
        if (in_array($st, ['Resolvido', 'Fechado'], true)) {
            $res7d++;
        }
    }

    return [
        'ch_abertos'       => $abertos,
        'ch_andamento'     => $andamento,
        'ch_urgentes'      => $urgentes,
        'ch_resolvidos_7d' => $res7d,
    ];
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
    if (isset($ch['ativo']) && (int) $ch['ativo'] === 0) {
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

    return repo_chamado_tecnico_vinculado($chamadoId, $operadorUserId);
}

function repo_chamado_tecnico_vinculado(int $chamadoId, int $operadorUserId): bool
{
    $pdo = db();
    if (!$pdo || $chamadoId <= 0 || $operadorUserId <= 0) {
        return false;
    }
    if (repo_chamado_tecnicos_table_exists()) {
        $st = $pdo->prepare('SELECT 1 FROM chamado_tecnicos WHERE chamado_id = ? AND usuario_id = ? LIMIT 1');
        $st->execute([$chamadoId, $operadorUserId]);
        if ($st->fetchColumn()) {
            return true;
        }
    }
    $st = $pdo->prepare('SELECT 1 FROM chamados WHERE id = ? AND tecnico_user_id = ? LIMIT 1');
    $st->execute([$chamadoId, $operadorUserId]);

    return (bool) $st->fetchColumn();
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
    $temTecnicosTabela = repo_chamado_tecnicos_table_exists();

    $where = ['ch.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)'];
    $params = [$raizId, $raizId];
    _repo_chamados_where_ativo($where);

    if ($filtro === 'andamento') {
        $where[] = 'ch.status IN (\'Aberto\',\'Em andamento\')';
    } elseif ($filtro === 'aguardando') {
        $where[] = 'ch.status = ?';
        $params[] = 'Aguardando Aprovação';
    } elseif ($filtro === 'resolvido') {
        $where[] = 'ch.status IN (\'Resolvido\',\'Fechado\',\'Cancelado\')';
    }

    $buscaQ = _repo_chamados_busca_q_sql($q, false, true);
    if ($buscaQ !== null) {
        $where[] = $buscaQ[0];
        foreach ($buscaQ[1] as $p) {
            $params[] = $p;
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
            $orderBy = "FIELD(ch.status, 'Aberto', 'Em andamento', 'Aguardando Aprovação', 'Cancelado', 'Resolvido', 'Fechado'), ch.aberto_em DESC, ch.id DESC";
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

    $tecnicoSelect = $temTecnicosTabela
        ? 'COALESCE(tecs.tecnico_nomes, ut.nome) AS tecnico_nome,
            tecs.tecnico_ids AS tecnico_ids,'
        : 'ut.nome AS tecnico_nome,
            NULL AS tecnico_ids,';
    $tecnicoJoin = $temTecnicosTabela
        ? "
        LEFT JOIN (
            SELECT ct.chamado_id,
                   GROUP_CONCAT(u.nome ORDER BY u.nome SEPARATOR ', ') AS tecnico_nomes,
                   GROUP_CONCAT(u.id ORDER BY u.nome SEPARATOR ',') AS tecnico_ids
            FROM chamado_tecnicos ct
            JOIN usuarios u ON u.id = ct.usuario_id
            GROUP BY ct.chamado_id
        ) tecs ON tecs.chamado_id = ch.id"
        : '';
    $sql = "
        SELECT
            ch.id, ch.cliente_id, ch.titulo, ch.descricao,
            ch.endereco_completo, ch.latitude, ch.longitude,
            ch.servico_id, ch.finalizado_operador_em, ch.tecnico_user_id, ch.aprovado_gestor_em,
            ch.prioridade, ch.status, ch.responsavel,
            DATE_FORMAT(ch.aberto_em, '%Y-%m-%d %H:%i') AS data,
            c.empresa AS cliente,
            $tecnicoSelect
            s.nome AS servico_nome,
            s.tipo AS servico_tipo,
            s.valor_unitario AS servico_valor_unitario
        FROM chamados ch
        JOIN clientes c ON c.id = ch.cliente_id
        LEFT JOIN usuarios ut ON ut.id = ch.tecnico_user_id
        $tecnicoJoin
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
        _repo_chamado_normalizar_tecnico_ids($r);
        _repo_chamado_row_normalizar_geo($r);
    }
    unset($r);

    return ['rows' => $rows, 'total' => $total];
}

/**
 * WHERE + parâmetros reutilizados em listagens admin, PDF e resumos.
 *
 * @return array{0: string, 1: array<int, mixed>}
 */
function _repo_chamados_admin_sql_where(
    string $filtro,
    string $q,
    ?int $clienteIdEscopo,
    ?string $dataAbertaDe,
    ?string $dataAbertaAte,
    ?int $envolvidoUserId,
    ?int $tecnicoUserId,
    ?string $localQ,
    bool $excluirCancelados
): array {
    $filtro = strtolower(trim($filtro));
    $q      = trim($q);
    $temTecnicosTabela = repo_chamado_tecnicos_table_exists();

    $where  = ['1=1'];
    $params = [];

    _repo_chamados_where_ativo($where, $filtro);

    if ($clienteIdEscopo !== null && $clienteIdEscopo > 0) {
        $where[]  = 'ch.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)';
        $params[] = $clienteIdEscopo;
        $params[] = $clienteIdEscopo;
    }

    if ($filtro === 'abertos') {
        $where[]  = 'ch.status = ?';
        $params[] = 'Aberto';
    } elseif ($filtro === 'andamento') {
        $where[]  = 'ch.status = ?';
        $params[] = 'Em andamento';
    } elseif ($filtro === 'aguardando') {
        $where[]  = 'ch.status = ?';
        $params[] = 'Aguardando Aprovação';
    } elseif ($filtro === 'resolvidos') {
        $where[] = 'ch.status IN (\'Resolvido\',\'Fechado\')';
    } elseif ($filtro === 'resolvido_bm') {
        $where[] = repo_chamados_status_sql_medicao_bm();
    } elseif ($filtro === 'cancelados') {
        $where[]  = 'ch.status = ?';
        $params[] = 'Cancelado';
    } elseif ($filtro === 'ativos') {
        $where[] = 'ch.status NOT IN (\'Validado\',\'Cancelado\')';
    } elseif ($filtro === 'excluidos') {
        // visibilidade: ch.ativo = 0 (já aplicado em _repo_chamados_where_ativo)
    } elseif ($filtro === 'urgentes') {
        $where[] = 'ch.prioridade IN (\'Alta\',\'Urgente\')';
        $where[] = 'ch.status NOT IN (\'Resolvido\',\'Fechado\',\'Cancelado\')';
    }

    if ($envolvidoUserId !== null && $envolvidoUserId > 0) {
        $where[] = 'EXISTS (
            SELECT 1 FROM usuarios uenv
            WHERE uenv.id = ?
              AND (
                (uenv.perfil = \'cliente\' AND uenv.cliente_id IS NOT NULL AND ch.cliente_id = uenv.cliente_id)
                OR (uenv.perfil IN (\'gestor\',\'operador\') AND (
                    ch.tecnico_user_id = uenv.id
                    OR ' . ($temTecnicosTabela ? 'EXISTS (
                        SELECT 1 FROM chamado_tecnicos cte
                        WHERE cte.chamado_id = ch.id AND cte.usuario_id = uenv.id
                    )
                    OR ' : '') . 'ch.finalizado_operador_user_id = uenv.id
                    OR ch.aprovado_gestor_user_id = uenv.id
                ))
              )
        )';
        $params[] = $envolvidoUserId;
    }

    $buscaQ = _repo_chamados_busca_q_sql($q, true, false);
    if ($buscaQ !== null) {
        $where[] = $buscaQ[0];
        foreach ($buscaQ[1] as $p) {
            $params[] = $p;
        }
    }

    if ($tecnicoUserId !== null && $tecnicoUserId > 0) {
        if ($temTecnicosTabela) {
            $where[] = '(ch.tecnico_user_id = ? OR EXISTS (
                SELECT 1 FROM chamado_tecnicos ctf
                WHERE ctf.chamado_id = ch.id AND ctf.usuario_id = ?
            ))';
            $params[] = $tecnicoUserId;
            $params[] = $tecnicoUserId;
        } else {
            $where[]  = 'ch.tecnico_user_id = ?';
            $params[] = $tecnicoUserId;
        }
    }

    $localTrim = $localQ !== null ? trim((string) $localQ) : '';
    if ($localTrim !== '') {
        $term = '%' . $localTrim . '%';
        $where[] = '(ch.endereco_completo LIKE ? OR ch.os_bairro LIKE ? OR ch.os_logradouro LIKE ? OR ch.os_numero LIKE ? OR ch.os_complemento LIKE ? OR ch.os_cidade LIKE ? OR ch.os_cep LIKE ?)';
        for ($i = 0; $i < 7; $i++) {
            $params[] = $term;
        }
    }

    if ($excluirCancelados && $filtro !== 'cancelados') {
        $where[]  = 'ch.status <> ?';
        $params[] = 'Cancelado';
    }

    if ($dataAbertaDe !== null && $dataAbertaAte !== null
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAbertaDe) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAbertaAte)
        && $dataAbertaDe <= $dataAbertaAte) {
        if ($filtro === 'excluidos' && repo_chamados_tem_exclusao_logica()) {
            $where[]  = 'DATE(COALESCE(ch.excluido_em, ch.aberto_em)) BETWEEN ? AND ?';
            $params[] = $dataAbertaDe;
            $params[] = $dataAbertaAte;
        } else {
            $where[]  = 'DATE(ch.aberto_em) BETWEEN ? AND ?';
            $params[] = $dataAbertaDe;
            $params[] = $dataAbertaAte;
        }
    }

    return [implode(' AND ', $where), $params];
}

/**
 * Lista chamados no admin com filtro, busca e paginação.
 * Opcional: intervalo de datas de abertura (YYYY-MM-DD), ex. filtro por medição mensal.
 * Opcional: $envolvidoUserId restringe a chamados em que o utilizador intervém (portal por unidade; equipe por colunas técnicas).
 * Opcional: $tecnicoUserId, $localQ (endereço), $excluirCancelados.
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
    ?int $envolvidoUserId = null,
    ?int $tecnicoUserId = null,
    ?string $localQ = null,
    bool $excluirCancelados = false
): array
{
    $pdo = db();
    if (!$pdo || $perPage < 1) {
        return ['rows' => [], 'total' => 0];
    }
    $filtro = strtolower(trim($filtro));
    $q      = trim($q);
    $temTecnicosTabela = repo_chamado_tecnicos_table_exists();

    [$sqlWhere, $params] = _repo_chamados_admin_sql_where(
        $filtro,
        $q,
        $clienteIdEscopo,
        $dataAbertaDe,
        $dataAbertaAte,
        $envolvidoUserId,
        $tecnicoUserId,
        $localQ,
        $excluirCancelados
    );
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

    $tecnicoSelect = $temTecnicosTabela
        ? 'COALESCE(tecs.tecnico_nomes, ut.nome) AS tecnico_nome,
            tecs.tecnico_ids AS tecnico_ids'
        : 'ut.nome AS tecnico_nome,
            NULL AS tecnico_ids';
    $tecnicoJoin = $temTecnicosTabela
        ? "
        LEFT JOIN (
            SELECT ct.chamado_id,
                   GROUP_CONCAT(u.nome ORDER BY u.nome SEPARATOR ', ') AS tecnico_nomes,
                   GROUP_CONCAT(u.id ORDER BY u.nome SEPARATOR ',') AS tecnico_ids
            FROM chamado_tecnicos ct
            JOIN usuarios u ON u.id = ct.usuario_id
            GROUP BY ct.chamado_id
        ) tecs ON tecs.chamado_id = ch.id"
        : '';
    $sql = "
        SELECT
            ch.id, ch.cliente_id, ch.titulo, ch.descricao,
            ch.endereco_completo, ch.latitude, ch.longitude,
            ch.tecnico_user_id, ch.finalizado_operador_em, ch.aprovado_gestor_em,
            ch.prioridade, ch.status, ch.responsavel,
            ch.ponto_iluminacao_id,
            DATE_FORMAT(ch.aberto_em, '%Y-%m-%d %H:%i') AS data,
            c.empresa AS cliente,
            $tecnicoSelect
            " . (repo_chamados_tem_exclusao_logica() ? ",
            ch.ativo,
            DATE_FORMAT(ch.excluido_em, '%Y-%m-%d %H:%i') AS excluido_em,
            uex.nome AS excluido_por_nome" : '') . "
        FROM chamados ch
        JOIN clientes c ON c.id = ch.cliente_id
        LEFT JOIN usuarios ut ON ut.id = ch.tecnico_user_id
        " . (repo_chamados_tem_exclusao_logica() ? 'LEFT JOIN usuarios uex ON uex.id = ch.excluido_por_user_id' : '') . "
        $tecnicoJoin
        WHERE $sqlWhere
        ORDER BY " . (strtolower(trim($filtro)) === 'excluidos' ? 'ch.excluido_em DESC, ch.id DESC' : 'ch.aberto_em DESC, ch.id DESC') . "
        LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id']         = (int) $r['id'];
        $r['cliente_id'] = (int) $r['cliente_id'];
        $r['ponto_iluminacao_id'] = isset($r['ponto_iluminacao_id']) && $r['ponto_iluminacao_id'] !== null && $r['ponto_iluminacao_id'] !== ''
            ? (int) $r['ponto_iluminacao_id'] : null;
        $r['tecnico_user_id'] = isset($r['tecnico_user_id']) && $r['tecnico_user_id'] !== null ? (int) $r['tecnico_user_id'] : null;
        _repo_chamado_normalizar_tecnico_ids($r);
        _repo_chamado_row_normalizar_geo($r);
        if (array_key_exists('ativo', $r)) {
            $r['ativo'] = (int) ($r['ativo'] ?? 1);
        }
    }
    unset($r);

    return ['rows' => $rows, 'total' => $total];
}

/**
 * Agregados para capa / resumo executivo do PDF de chamados (mesmos filtros que a listagem admin).
 *
 * @return array{
 *   total: int,
 *   por_status: array<string,int>,
 *   por_prioridade: array<string,int>,
 *   com_anexo: int,
 *   urgentes_abertos: int
 * }
 */
function repo_chamados_admin_relatorio_resumo(
    string $filtro,
    string $q,
    ?int $clienteIdEscopo = null,
    ?string $dataAbertaDe = null,
    ?string $dataAbertaAte = null,
    ?int $envolvidoUserId = null,
    ?int $tecnicoUserId = null,
    ?string $localQ = null,
    bool $excluirCancelados = false
): array {
    $empty = [
        'total'            => 0,
        'por_status'       => [],
        'por_prioridade'   => [],
        'com_anexo'        => 0,
        'urgentes_abertos' => 0,
    ];
    $pdo = db();
    if (!$pdo) {
        return $empty;
    }
    [$sqlWhere, $params] = _repo_chamados_admin_sql_where(
        $filtro,
        $q,
        $clienteIdEscopo,
        $dataAbertaDe,
        $dataAbertaAte,
        $envolvidoUserId,
        $tecnicoUserId,
        $localQ,
        $excluirCancelados
    );
    $base = 'FROM chamados ch JOIN clientes c ON c.id = ch.cliente_id WHERE ' . $sqlWhere;

    $stmt = $pdo->prepare('SELECT COUNT(*) ' . $base);
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    $porStatus = [];
    $stmt = $pdo->prepare('SELECT ch.status, COUNT(*) AS n ' . $base . ' GROUP BY ch.status');
    $stmt->execute($params);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $porStatus[(string) $r['status']] = (int) $r['n'];
    }

    $porPrioridade = [];
    $stmt = $pdo->prepare('SELECT ch.prioridade, COUNT(*) AS n ' . $base . ' GROUP BY ch.prioridade');
    $stmt->execute($params);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $porPrioridade[(string) $r['prioridade']] = (int) $r['n'];
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT ch.id) ' . $base
        . ' AND EXISTS (SELECT 1 FROM chamado_anexos ca WHERE ca.chamado_id = ch.id)'
    );
    $stmt->execute($params);
    $comAnexo = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) ' . $base
        . ' AND ch.prioridade IN (\'Alta\',\'Urgente\')'
        . ' AND ch.status NOT IN (\'Resolvido\',\'Fechado\',\'Cancelado\')'
    );
    $stmt->execute($params);
    $urgentes = (int) $stmt->fetchColumn();

    return [
        'total'            => $total,
        'por_status'       => $porStatus,
        'por_prioridade'   => $porPrioridade,
        'com_anexo'        => $comAnexo,
        'urgentes_abertos' => $urgentes,
    ];
}

/**
 * KPIs CRM para exportação XLSX «Medição / listagem chamados» (mesmos filtros que o admin).
 *
 * @return array{
 *     total_chamados_crm: int,
 *     em_andamento: int,
 *     resolvidos: int,
 *     urgentes_abertos: int,
 *     valor_itens_utilizados: float,
 *     quantidade_anexos: int,
 *     resumo_admin: array
 * }
 */
function repo_chamados_medicao_export_kpis(
    string $filtro,
    string $q,
    ?int $clienteIdEscopo,
    ?string $dataAbertaDe,
    ?string $dataAbertaAte,
    ?int $envolvidoUserId,
    ?int $tecnicoUserId,
    ?string $localQ,
    bool $excluirCancelados
): array {
    $vazio = [
        'total_chamados_crm'     => 0,
        'em_andamento'           => 0,
        'resolvidos'             => 0,
        'urgentes_abertos'       => 0,
        'valor_itens_utilizados' => 0.0,
        'quantidade_anexos'      => 0,
        'resumo_admin'           => [],
    ];
    $pdo = db();
    if (!$pdo) {
        return $vazio;
    }
    $resumo = repo_chamados_admin_relatorio_resumo(
        $filtro,
        $q,
        $clienteIdEscopo,
        $dataAbertaDe,
        $dataAbertaAte,
        $envolvidoUserId,
        $tecnicoUserId,
        $localQ,
        $excluirCancelados
    );
    $ps               = $resumo['por_status'] ?? [];
    $emAnd            = (int) ($ps['Em andamento'] ?? 0);
    $resolvidos       = (int) ($ps['Resolvido'] ?? 0) + (int) ($ps['Fechado'] ?? 0);
    $urgentesAbertos  = (int) ($resumo['urgentes_abertos'] ?? 0);
    $totalCrm         = (int) ($resumo['total'] ?? 0);

    [$sqlWhere, $params] = _repo_chamados_admin_sql_where(
        $filtro,
        $q,
        $clienteIdEscopo,
        $dataAbertaDe,
        $dataAbertaAte,
        $envolvidoUserId,
        $tecnicoUserId,
        $localQ,
        $excluirCancelados
    );
    $baseFrom = '
        FROM chamado_itens ci
        INNER JOIN chamados ch ON ch.id = ci.chamado_id
        JOIN clientes c ON c.id = ch.cliente_id
        WHERE ci.movimento = \'utilizado\'
          AND ' . $sqlWhere;
    try {
        $st = $pdo->prepare('SELECT COALESCE(SUM(ci.subtotal), 0) ' . $baseFrom);
        $st->execute($params);
        $valorItens = (float) $st->fetchColumn();
    } catch (Throwable $e) {
        $valorItens = 0.0;
    }

    try {
        $st2 = $pdo->prepare('
            SELECT COUNT(*)
            FROM chamado_anexos ca
            INNER JOIN chamados ch ON ch.id = ca.chamado_id
            JOIN clientes c ON c.id = ch.cliente_id
            WHERE ' . $sqlWhere);
        $st2->execute($params);
        $qAnexos = (int) $st2->fetchColumn();
    } catch (Throwable $e) {
        $qAnexos = 0;
    }

    return [
        'total_chamados_crm'     => $totalCrm,
        'em_andamento'           => $emAnd,
        'resolvidos'             => $resolvidos,
        'urgentes_abertos'       => $urgentesAbertos,
        'valor_itens_utilizados' => $valorItens,
        'quantidade_anexos'      => $qAnexos,
        'resumo_admin'           => $resumo,
    ];
}

/**
 * Colunas extras + métricas por chamado para exportação XLSX tabular (lotes de IDs).
 *
 * @param list<int> $chamadoIds
 *
 * @return array<int, array<string,mixed>>
 */
function repo_chamados_export_metricas_por_ids(array $chamadoIds): array
{
    $out = [];
    $pdo = db();
    if (!$pdo || $chamadoIds === []) {
        return $out;
    }
    $ids = array_values(array_unique(array_filter(array_map(static fn ($v) => (int) $v, $chamadoIds), static fn ($v) => $v > 0)));
    if ($ids === []) {
        return $out;
    }

    foreach (array_chunk($ids, 400) as $chunk) {
        $ph         = implode(',', array_fill(0, count($chunk), '?'));
        $sqlUltima = 'GREATEST(
            COALESCE(ch.aberto_em, \'1970-01-01 00:00:00\'),
            COALESCE((SELECT MAX(enviado_em) FROM chamado_respostas WHERE chamado_id = ch.id), ch.aberto_em),
            COALESCE((SELECT MAX(enviado_em) FROM chamado_anexos WHERE chamado_id = ch.id), ch.aberto_em),
            COALESCE(ch.finalizado_operador_em, ch.aberto_em),
            COALESCE(ch.aprovado_gestor_em, ch.aberto_em)
        )';
        $sql = '
            SELECT
                ch.id,
                ch.problema_os,
                ch.tipo_os,
                ch.origem_os,
                sv.tipo AS servico_categoria_tipo,
                ch.os_bairro,
                pi.codigo_poste AS ponto_codigo_poste,
                CASE
                    WHEN ch.status IN (\'Resolvido\',\'Fechado\')
                    THEN COALESCE(ch.finalizado_operador_em, ch.aprovado_gestor_em)
                    ELSE NULL
                END AS data_resolucao_raw,
                (SELECT COUNT(*) FROM chamado_itens ci WHERE ci.chamado_id = ch.id AND ci.movimento = \'utilizado\') AS qtd_itens,
                COALESCE((SELECT SUM(ci.subtotal) FROM chamado_itens ci WHERE ci.chamado_id = ch.id AND ci.movimento = \'utilizado\'), 0) AS total_utilizado,
                (SELECT COUNT(*) FROM chamado_anexos ca WHERE ca.chamado_id = ch.id) AS qtd_anexos,
                (SELECT COUNT(*) FROM chamado_respostas cr WHERE cr.chamado_id = ch.id) AS qtd_mensagens,
                ' . $sqlUltima . ' AS ultima_atual_raw
            FROM chamados ch
            LEFT JOIN cliente_itens sv ON sv.id = ch.servico_id
            LEFT JOIN pontos_iluminacao pi ON pi.id = ch.ponto_iluminacao_id
            WHERE ch.id IN (' . $ph . ')
        ';
        $st = $pdo->prepare($sql);
        $st->execute($chunk);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $id = (int) ($r['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $tipoTxt = trim((string) ($r['problema_os'] ?? ''));
            if ($tipoTxt === '') {
                $tipoTxt = trim((string) ($r['tipo_os'] ?? ''));
            }
            $r['tipo_export']     = $tipoTxt;
            $r['categoria_export'] = trim((string) ($r['servico_categoria_tipo'] ?? ''));
            $r['canal_export']    = trim((string) ($r['origem_os'] ?? ''));
            $out[$id] = $r;
        }
    }

    return $out;
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
    $temTecnicosTabela = repo_chamado_tecnicos_table_exists();
    $tecnicoSelect = $temTecnicosTabela
        ? 'COALESCE(tecs.tecnico_nomes, ut.nome) AS tecnico_nome,
            tecs.tecnico_ids AS tecnico_ids,'
        : 'ut.nome AS tecnico_nome,
            NULL AS tecnico_ids,';
    $tecnicoJoin = $temTecnicosTabela
        ? "
        LEFT JOIN (
            SELECT ct.chamado_id,
                   GROUP_CONCAT(u.nome ORDER BY u.nome SEPARATOR ', ') AS tecnico_nomes,
                   GROUP_CONCAT(u.id ORDER BY u.nome SEPARATOR ',') AS tecnico_ids
            FROM chamado_tecnicos ct
            JOIN usuarios u ON u.id = ct.usuario_id
            GROUP BY ct.chamado_id
        ) tecs ON tecs.chamado_id = ch.id"
        : '';
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
            $tecnicoSelect
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
        $tecnicoJoin
        LEFT JOIN cliente_itens sv ON sv.id = ch.servico_id
        LEFT JOIN pontos_iluminacao pi ON pi.id = ch.ponto_iluminacao_id
        WHERE ch.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
          AND DATE(ch.aberto_em) BETWEEN ? AND ?
          AND " . repo_chamados_status_sql_medicao_bm() . "
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
        _repo_chamado_normalizar_tecnico_ids($r);
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
 * Itens com movimento «utilizado» em chamados da matriz no período (uma linha por lançamento).
 *
 * @return list<array<string,mixed>>
 */
function repo_chamados_itens_utilizados_periodo_linhas(int $empresaRaizId, string $dataDe, string $dataAte): array
{
    $pdo = db();
    if (!$pdo || $empresaRaizId <= 0) {
        return [];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDe) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAte) || $dataDe > $dataAte) {
        return [];
    }
    $sql = '
        SELECT
            DATE_FORMAT(ch.aberto_em, "%Y-%m") AS ref_mes,
            ch.id AS chamado_id,
            c.empresa AS unidade_nome,
            it.nome AS produto_nome,
            it.codigo AS produto_codigo,
            it.tipo AS produto_tipo,
            it.unidade AS catalogo_unidade,
            ci.quantidade,
            ci.valor_unitario,
            ci.subtotal,
            ci.observacao
        FROM chamado_itens ci
        INNER JOIN chamados ch ON ch.id = ci.chamado_id
        INNER JOIN clientes c ON c.id = ch.cliente_id
        INNER JOIN cliente_itens it ON it.id = ci.item_id
        WHERE ch.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
          AND ci.movimento = \'utilizado\'
          AND DATE(ch.aberto_em) BETWEEN ? AND ?
          AND ' . repo_chamados_status_sql_medicao_bm() . '
        ORDER BY ref_mes ASC, ch.id ASC, ci.id ASC
    ';
    $st = $pdo->prepare($sql);
    $st->execute([$empresaRaizId, $empresaRaizId, $dataDe, $dataAte]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['chamado_id']    = (int) ($r['chamado_id'] ?? 0);
        $r['quantidade']    = (float) ($r['quantidade'] ?? 0);
        $r['valor_unitario'] = (float) ($r['valor_unitario'] ?? 0);
        $r['subtotal']      = (float) ($r['subtotal'] ?? 0);
    }
    unset($r);

    return $rows;
}

/**
 * Lançamentos de itens do catálogo em chamados no período (uso e devolução), uma linha por chamado_itens.
 *
 * @return list<array<string,mixed>>
 */
function repo_catalogo_chamados_itens_periodo(int $empresaRaizId, string $dataDe, string $dataAte, ?string $movimentoFiltro = null): array
{
    $pdo = db();
    if (!$pdo || $empresaRaizId <= 0) {
        return [];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDe) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAte) || $dataDe > $dataAte) {
        return [];
    }
    $movimentoFiltro = $movimentoFiltro !== null ? strtolower(trim($movimentoFiltro)) : '';
    $movimentoSql    = '';
    $params          = [$empresaRaizId, $empresaRaizId, $dataDe, $dataAte];
    if ($movimentoFiltro === 'utilizado' || $movimentoFiltro === 'devolvido') {
        $movimentoSql = ' AND ci.movimento = ? ';
        $params[]     = $movimentoFiltro;
    }

    $temTecnicosTabela = repo_chamado_tecnicos_table_exists();
    $tecSelect         = $temTecnicosTabela
        ? 'COALESCE(tecs.tecnico_nomes, ut.nome, \'\') AS tecnico_nomes'
        : 'COALESCE(ut.nome, \'\') AS tecnico_nomes';
    $tecJoin = $temTecnicosTabela
        ? "
        LEFT JOIN (
            SELECT ct.chamado_id,
                   GROUP_CONCAT(u.nome ORDER BY u.nome SEPARATOR ', ') AS tecnico_nomes
            FROM chamado_tecnicos ct
            JOIN usuarios u ON u.id = ct.usuario_id
            GROUP BY ct.chamado_id
        ) tecs ON tecs.chamado_id = ch.id"
        : '';

    $sql = '
        SELECT
            ch.id AS chamado_id,
            ch.titulo AS chamado_titulo,
            ch.status AS chamado_status,
            DATE_FORMAT(ch.aberto_em, \'%Y-%m-%d %H:%i\') AS chamado_aberto_em,
            c.empresa AS unidade_nome,
            ci.id AS linha_id,
            ci.movimento,
            it.nome AS item_nome,
            it.tipo AS item_tipo,
            it.codigo AS item_codigo,
            it.unidade AS catalogo_unidade,
            ci.quantidade,
            ci.valor_unitario,
            ci.subtotal,
            ci.observacao,
            DATE_FORMAT(ci.criado_em, \'%Y-%m-%d %H:%i\') AS lancamento_em,
            ' . $tecSelect . '
        FROM chamado_itens ci
        INNER JOIN chamados ch ON ch.id = ci.chamado_id
        INNER JOIN clientes c ON c.id = ch.cliente_id
        INNER JOIN cliente_itens it ON it.id = ci.item_id
        LEFT JOIN usuarios ut ON ut.id = ch.tecnico_user_id
        ' . $tecJoin . '
        WHERE ch.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
          AND DATE(ch.aberto_em) BETWEEN ? AND ?
          AND ' . repo_chamados_status_sql_medicao_bm() . '
        ' . $movimentoSql . '
        ORDER BY ch.aberto_em DESC, ch.id DESC, FIELD(ci.movimento, \'utilizado\', \'devolvido\'), ci.id ASC
    ';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['chamado_id']     = (int) ($r['chamado_id'] ?? 0);
        $r['linha_id']       = (int) ($r['linha_id'] ?? 0);
        $r['quantidade']     = (float) ($r['quantidade'] ?? 0);
        $r['valor_unitario'] = (float) ($r['valor_unitario'] ?? 0);
        $r['subtotal']       = (float) ($r['subtotal'] ?? 0);
        $r['movimento']      = (string) ($r['movimento'] ?? 'utilizado');
        $r['tecnico_nomes']  = (string) ($r['tecnico_nomes'] ?? '');
    }
    unset($r);

    return $rows;
}

/**
 * Itens de catálogo no período (uma linha por chamado_itens).
 * Padrão: Validado + data do lançamento (criado_em).
 * BM completo ($bmCompleto): Validado + abertura (aberto_em); nas exportações XLSX só entram linhas
 * com movimento «utilizado» (devolvido/sucata não são exportados).
 *
 * @return list<array<string,mixed>>
 */
function repo_catalogo_chamados_itens_periodo_por_data_lancamento(
    int $empresaRaizId,
    string $dataDe,
    string $dataAte,
    string $movimento = 'utilizado',
    bool $bmCompleto = false
): array {
    $pdo = db();
    if (!$pdo || $empresaRaizId <= 0) {
        return [];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDe) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAte) || $dataDe > $dataAte) {
        return [];
    }
    $mov = strtolower(trim($movimento));
    if ($mov !== '' && $mov !== 'utilizado' && $mov !== 'devolvido') {
        return [];
    }
    $temTecnicosTabela = repo_chamado_tecnicos_table_exists();
    $tecSelect         = $temTecnicosTabela
        ? 'COALESCE(tecs.tecnico_nomes, ut.nome, \'\') AS tecnico_nomes'
        : 'COALESCE(ut.nome, \'\') AS tecnico_nomes';
    $tecJoin = $temTecnicosTabela
        ? '
        LEFT JOIN (
            SELECT ct.chamado_id,
                   GROUP_CONCAT(u.nome ORDER BY u.nome SEPARATOR \', \') AS tecnico_nomes
            FROM chamado_tecnicos ct
            JOIN usuarios u ON u.id = ct.usuario_id
            GROUP BY ct.chamado_id
        ) tecs ON tecs.chamado_id = ch.id'
        : '';
    $sql = '
        SELECT
            ci.id AS chamado_item_id,
            ch.id AS chamado_id,
            DATE_FORMAT(ch.aberto_em, \'%Y-%m-%d %H:%i\') AS chamado_aberto_em,
            DATE_FORMAT(ci.criado_em, \'%Y-%m-%d %H:%i\') AS item_criado_em,
            ch.status AS chamado_status,
            ch.prioridade AS chamado_prioridade,
            TRIM(COALESCE(NULLIF(TRIM(ch.problema_os), \'\'), ch.tipo_os, \'\')) AS chamado_problema_tipo,
            TRIM(COALESCE(ch.origem_os, \'\')) AS chamado_canal,
            TRIM(COALESCE(ch.responsavel, \'\')) AS chamado_responsavel,
            ch.endereco_completo,
            ch.os_bairro AS ch_os_bairro,
            ch.os_logradouro AS ch_os_logradouro,
            ch.os_numero AS ch_os_numero,
            ch.latitude AS ch_latitude,
            ch.longitude AS ch_longitude,
            pi.bairro AS pi_bairro,
            pi.latitude AS pi_latitude,
            pi.longitude AS pi_longitude,
            svc.tipo AS servico_categoria_tipo,
            pi.codigo_poste AS ponto_codigo_poste,
            it.id AS item_id_catalogo,
            it.nome AS item_nome,
            it.tipo AS item_tipo,
            it.codigo AS item_codigo,
            it.unidade AS catalogo_unidade,
            ci.movimento,
            ci.quantidade,
            ci.valor_unitario,
            ci.subtotal,
            ci.observacao,
            ' . $tecSelect . '
        FROM chamado_itens ci
        INNER JOIN chamados ch ON ch.id = ci.chamado_id
        INNER JOIN clientes c ON c.id = ch.cliente_id
        INNER JOIN cliente_itens it ON it.id = ci.item_id
        LEFT JOIN cliente_itens svc ON svc.id = ch.servico_id
        LEFT JOIN pontos_iluminacao pi ON pi.id = ch.ponto_iluminacao_id
        LEFT JOIN usuarios ut ON ut.id = ch.tecnico_user_id
        ' . $tecJoin . '
        WHERE ch.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
          AND ' . ($bmCompleto
        ? 'DATE(ch.aberto_em) BETWEEN ? AND ?'
        : 'DATE(COALESCE(ci.criado_em, ch.aberto_em)) BETWEEN ? AND ?') . '
          AND ' . repo_chamados_status_sql_medicao_bm() . '
          ' . _repo_chamados_sql_apenas_ativos('ch') . '
          ' . ($mov === '' ? "AND ci.movimento IN ('utilizado', 'devolvido')" : 'AND ci.movimento = ?') . '
        ORDER BY ch.aberto_em DESC, ch.id DESC, ci.id ASC
    ';
    $st = $pdo->prepare($sql);
    $params = [$empresaRaizId, $empresaRaizId, $dataDe, $dataAte];
    if ($mov !== '') {
        $params[] = $mov;
    }
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['chamado_item_id'] = (int) ($r['chamado_item_id'] ?? 0);
        $r['chamado_id']     = (int) ($r['chamado_id'] ?? 0);
        $r['quantidade']     = (float) ($r['quantidade'] ?? 0);
        $r['valor_unitario'] = (float) ($r['valor_unitario'] ?? 0);
        $r['subtotal']       = (float) ($r['subtotal'] ?? 0);
        $r['item_id_catalogo'] = (int) ($r['item_id_catalogo'] ?? 0);
        $r['tecnico_nomes']  = (string) ($r['tecnico_nomes'] ?? '');
        $r['movimento']      = (string) ($r['movimento'] ?? '');
        $r['observacao']    = isset($r['observacao']) ? (string) $r['observacao'] : '';
        $r['item_criado_em'] = isset($r['item_criado_em']) && $r['item_criado_em'] !== null ? (string) $r['item_criado_em'] : '';
        $r['ch_os_bairro']   = isset($r['ch_os_bairro']) ? (string) $r['ch_os_bairro'] : '';
        $r['ch_os_logradouro'] = isset($r['ch_os_logradouro']) ? (string) $r['ch_os_logradouro'] : '';
        $r['ch_os_numero']   = isset($r['ch_os_numero']) ? (string) $r['ch_os_numero'] : '';
        $r['ch_latitude']    = isset($r['ch_latitude']) && $r['ch_latitude'] !== null && $r['ch_latitude'] !== '' ? (string) $r['ch_latitude'] : '';
        $r['ch_longitude']   = isset($r['ch_longitude']) && $r['ch_longitude'] !== null && $r['ch_longitude'] !== '' ? (string) $r['ch_longitude'] : '';
        $r['pi_bairro']      = isset($r['pi_bairro']) ? (string) $r['pi_bairro'] : '';
        $r['pi_latitude']    = isset($r['pi_latitude']) && $r['pi_latitude'] !== null && $r['pi_latitude'] !== '' ? (string) $r['pi_latitude'] : '';
        $r['pi_longitude']   = isset($r['pi_longitude']) && $r['pi_longitude'] !== null && $r['pi_longitude'] !== '' ? (string) $r['pi_longitude'] : '';
    }
    unset($r);

    return $rows;
}

/**
 * Lançamentos planos (uma linha por lançamento) com filtros — relatório «catálogo em chamados».
 *
 * @param array{
 *   movimento?:''|'utilizado'|'devolvido',
 *   chamado_id?:int,
 *   item_id?:int,
 *   tecnico_user_id?:int
 * } $filtros
 *
 * @return list<array<string,mixed>>
 */
function repo_catalogo_chamados_itens_linhas_filtradas(int $empresaRaizId, string $dataDe, string $dataAte, array $filtros = []): array
{
    $pdo = db();
    if (!$pdo || $empresaRaizId <= 0) {
        return [];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDe) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAte) || $dataDe > $dataAte) {
        return [];
    }
    $mov = isset($filtros['movimento']) ? strtolower(trim((string) $filtros['movimento'])) : '';
    if ($mov !== '' && !in_array($mov, ['utilizado', 'devolvido'], true)) {
        $mov = '';
    }
    $tipo = isset($filtros['tipo']) ? strtolower(trim((string) $filtros['tipo'])) : '';
    if ($tipo !== '' && !in_array($tipo, ['produto', 'servico'], true)) {
        $tipo = '';
    }
    $fidChamado = isset($filtros['chamado_id']) ? (int) $filtros['chamado_id'] : 0;
    $fidItem    = isset($filtros['item_id']) ? (int) $filtros['item_id'] : 0;
    $fidTec     = isset($filtros['tecnico_user_id']) ? (int) $filtros['tecnico_user_id'] : 0;

    $params = [$empresaRaizId, $empresaRaizId, $dataDe, $dataAte];
    $extra  = '';
    if ($mov !== '') {
        $extra .= ' AND ci.movimento = ? ';
        $params[] = $mov;
    }
    if ($tipo !== '') {
        $extra .= ' AND it.tipo = ? ';
        $params[] = $tipo;
    }
    if ($fidChamado > 0) {
        $extra .= ' AND ch.id = ? ';
        $params[] = $fidChamado;
    }
    if ($fidItem > 0) {
        $extra .= ' AND ci.item_id = ? ';
        $params[] = $fidItem;
    }
    if ($fidTec > 0) {
        $extra .= ' AND ch.tecnico_user_id = ? ';
        $params[] = $fidTec;
    }

    $sql = '
        SELECT
            ch.id AS chamado_id,
            ch.titulo AS chamado_titulo,
            DATE_FORMAT(ch.aberto_em, \'%Y-%m-%d %H:%i\') AS chamado_aberto_em,
            ch.status AS chamado_status,
            ch.endereco_completo,
            ch.os_logradouro,
            ch.os_numero,
            ch.os_complemento,
            ch.os_bairro,
            ch.os_cidade,
            ch.os_uf,
            ch.os_cep,
            pi.endereco_completo AS ponto_endereco_completo,
            ci.id AS linha_id,
            ci.movimento,
            it.nome AS item_nome,
            it.tipo AS item_tipo,
            it.codigo AS item_codigo,
            it.unidade AS catalogo_unidade,
            ci.quantidade,
            ci.valor_unitario,
            ci.subtotal
        FROM chamado_itens ci
        INNER JOIN chamados ch ON ch.id = ci.chamado_id
        INNER JOIN cliente_itens it ON it.id = ci.item_id
        LEFT JOIN pontos_iluminacao pi ON pi.id = ch.ponto_iluminacao_id
        LEFT JOIN usuarios u ON u.id = ch.tecnico_user_id
        WHERE ch.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
          AND DATE(ch.aberto_em) BETWEEN ? AND ?
          AND ch.status <> \'Cancelado\'
        ' . $extra . '
        ORDER BY ch.aberto_em DESC, ch.id DESC, ci.id ASC
    ';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['chamado_id']     = (int) ($r['chamado_id'] ?? 0);
        $r['linha_id']       = (int) ($r['linha_id'] ?? 0);
        $r['quantidade']     = (float) ($r['quantidade'] ?? 0);
        $r['valor_unitario'] = (float) ($r['valor_unitario'] ?? 0);
        $r['subtotal']       = (float) ($r['subtotal'] ?? 0);
        $r['movimento']      = (string) ($r['movimento'] ?? 'utilizado');
        $pontoEnd = trim((string) ($r['ponto_endereco_completo'] ?? ''));
        $r['chamado_endereco'] = chamado_endereco_efetivo(
            [
                'endereco_completo' => (string) ($r['endereco_completo'] ?? ''),
                'os_logradouro'     => (string) ($r['os_logradouro'] ?? ''),
                'os_numero'         => (string) ($r['os_numero'] ?? ''),
                'os_complemento'    => (string) ($r['os_complemento'] ?? ''),
                'os_bairro'         => (string) ($r['os_bairro'] ?? ''),
                'os_cidade'         => (string) ($r['os_cidade'] ?? ''),
                'os_uf'             => (string) ($r['os_uf'] ?? ''),
                'os_cep'            => (string) ($r['os_cep'] ?? ''),
            ],
            $pontoEnd !== '' ? ['endereco_completo' => $pontoEnd] : null
        );
    }
    unset($r);

    return $rows;
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
 * custo_liquido = valor_usado (devolvido é informativo/sucata e não abate medição).
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
            it.id AS item_id,
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
          AND " . repo_chamados_status_sql_medicao_bm() . "
        GROUP BY ci.movimento, it.tipo, it.codigo, it.nome, it.unidade, it.id
        ORDER BY FIELD(ci.movimento, 'utilizado', 'devolvido'), it.tipo ASC, it.nome ASC
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$empresaRaizId, $empresaRaizId, $dataDe, $dataAte]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$r) {
        $mov = (string) ($r['movimento'] ?? '');
        $qtd = (float) ($r['quantidade'] ?? 0);
        $val = (float) ($r['valor_total'] ?? 0);
        $r['quantidade']     = $qtd;
        $r['valor_total']    = $val;
        $r['valor_unitario'] = $qtd > 0 ? round($val / $qtd, 4) : 0.0;
        $r['item_id']        = (int) ($r['item_id'] ?? 0);
        $r['n_lancamentos']  = (int) ($r['n_lancamentos'] ?? 0);
        $r['n_chamados']     = (int) ($r['n_chamados'] ?? 0);

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

    $ret['totais']['custo_liquido'] = $ret['totais']['valor_usado'];
    $ret['rows'] = $rows;

    return $ret;
}

/**
 * Quantidades utilizadas em chamados no período, agrupadas por item do catálogo (uma linha por item).
 * Usado no boletim BM: evita duplicar o mesmo código por tipo/movimento e permite cruzar com a planilha modelo.
 *
 * @return list<array{item_id:int, item_codigo:string, item_nome:string, unidade:string, valor_unitario:float, quantidade:float}>
 */
function repo_medicao_bm_utilizado_quantidades_por_item(int $empresaRaizId, string $dataDe, string $dataAte): array
{
    if ($empresaRaizId <= 0) {
        return [];
    }
    $pdo = db();
    if (!$pdo) {
        return [];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDe) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAte) || $dataDe > $dataAte) {
        return [];
    }

    $sql = "
        SELECT
            it.id AS item_id,
            MAX(COALESCE(NULLIF(TRIM(it.codigo), ''), '')) AS item_codigo,
            MAX(it.nome) AS item_nome,
            MAX(it.unidade) AS unidade,
            MAX(it.valor_unitario) AS valor_unitario,
            SUM(ci.quantidade) AS quantidade
        FROM chamado_itens ci
        INNER JOIN chamados ch ON ch.id = ci.chamado_id
        INNER JOIN cliente_itens it ON it.id = ci.item_id
        WHERE ch.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
          AND DATE(ch.aberto_em) BETWEEN ? AND ?
          AND " . repo_chamados_status_sql_medicao_bm() . "
          AND " . repo_chamados_sql_movimento_medicao_custo('ci') . "
        GROUP BY it.id
        HAVING SUM(ci.quantidade) <> 0
        ORDER BY MAX(it.codigo) ASC, MAX(it.nome) ASC
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$empresaRaizId, $empresaRaizId, $dataDe, $dataAte]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'item_id'          => (int) ($r['item_id'] ?? 0),
            'item_codigo'      => (string) ($r['item_codigo'] ?? ''),
            'item_nome'        => (string) ($r['item_nome'] ?? ''),
            'unidade'          => (string) ($r['unidade'] ?? 'UN'),
            'valor_unitario'   => (float) ($r['valor_unitario'] ?? 0),
            'quantidade'       => (float) ($r['quantidade'] ?? 0),
        ];
    }

    return $out;
}

/**
 * Agrupa itens utilizados no CRM por mês de abertura do chamado Validado (aberto_em).
 * Alinhado ao export «Completo» e à importação BM (itens com criado_em na data da importação).
 * Usado na exportação boletim BM v2 — «medido no período» físico/financeiro.
 *
 * @return list<array{item_id:int, item_codigo:string, item_nome:string, unidade:string, valor_unitario:float, quantidade:float, valor_subtotal:float, estoque_saldo:float}>
 */
function repo_medicao_bm_utilizado_por_item_periodo_lancamento(int $empresaRaizId, string $dataDe, string $dataAte): array
{
    if ($empresaRaizId <= 0) {
        return [];
    }
    $pdo = db();
    if (!$pdo) {
        return [];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDe) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAte) || $dataDe > $dataAte) {
        return [];
    }

    $saldoExpr = repo_cliente_itens_estoque_saldo_column_exists()
        ? 'MAX(it.estoque_saldo)'
        : 'CAST(0 AS DECIMAL(14,4))';
    $sql = "
        SELECT
            it.id AS item_id,
            MAX(COALESCE(NULLIF(TRIM(it.codigo), ''), '')) AS item_codigo,
            MAX(it.nome) AS item_nome,
            MAX(it.unidade) AS unidade,
            MAX(it.valor_unitario) AS valor_unitario,
            {$saldoExpr} AS estoque_saldo,
            SUM(ci.quantidade) AS quantidade,
            SUM(ci.subtotal) AS valor_subtotal
        FROM chamado_itens ci
        INNER JOIN chamados ch ON ch.id = ci.chamado_id
        INNER JOIN cliente_itens it ON it.id = ci.item_id
        WHERE ch.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
          AND DATE(ch.aberto_em) BETWEEN ? AND ?
          AND " . repo_chamados_status_sql_medicao_bm() . "
          AND " . repo_chamados_sql_movimento_medicao_custo('ci') . "
        GROUP BY it.id
        HAVING SUM(ci.quantidade) <> 0 OR SUM(ci.subtotal) <> 0
        ORDER BY MAX(it.codigo) ASC, MAX(it.nome) ASC
    ";

    $st = $pdo->prepare($sql);
    $st->execute([$empresaRaizId, $empresaRaizId, $dataDe, $dataAte]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'item_id'         => (int) ($r['item_id'] ?? 0),
            'item_codigo'     => (string) ($r['item_codigo'] ?? ''),
            'item_nome'       => (string) ($r['item_nome'] ?? ''),
            'unidade'         => (string) ($r['unidade'] ?? 'UN'),
            'valor_unitario'  => (float) ($r['valor_unitario'] ?? 0),
            'estoque_saldo'   => (float) ($r['estoque_saldo'] ?? 0),
            'quantidade'      => (float) ($r['quantidade'] ?? 0),
            'valor_subtotal'  => (float) ($r['valor_subtotal'] ?? 0),
        ];
    }

    return $out;
}

/**
 * Somas de qty/valor já importadas em BMs referentes a meses estritamente anteriores ao mês de exportação,
 * agrupadas por código de item na importação (trimmed).
 *
 * @return array<string, array{qtd:float,valor:float}>
 */
function repo_medicao_bm_imports_totals_before_ym(int $clienteMatrizId, string $refYm): array
{
    if ($clienteMatrizId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $refYm)) {
        return [];
    }
    $pdo = db();
    if (!$pdo) {
        return [];
    }
    try {
        $sql = '
            SELECT
                TRIM(mil.item_codigo) AS item_codigo,
                COALESCE(SUM(mil.qtd_medido_periodo), 0) AS qtd_bm,
                COALESCE(SUM(mil.valor_medido_periodo), 0) AS valor_bm
            FROM medicao_import_linhas mil
            INNER JOIN medicao_imports mi ON mi.id = mil.import_id
            WHERE mi.cliente_matriz_id = ?
              AND mi.ref_ym < ?
              AND TRIM(mil.item_codigo) <> \'\'
            GROUP BY TRIM(mil.item_codigo)
        ';
        $st = $pdo->prepare($sql);
        $st->execute([$clienteMatrizId, $refYm]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $r) {
        $c = trim((string) ($r['item_codigo'] ?? ''));
        if ($c === '') {
            continue;
        }
        $key = medicao_bm_boletim_key_from_cod($c);
        if ($key === '') {
            $key = 'RAW:' . $c;
        }
        $out[$key] = [
            'qtd'   => (float) ($r['qtd_bm'] ?? 0),
            'valor' => (float) ($r['valor_bm'] ?? 0),
        ];
    }

    return $out;
}

/**
 * Dados consolidados por item para o boletim BM v2: saldo + unitário (catálogo) por código normalizado.
 *
 * @return array<string, array{item_id:int, item_codigo:string, item_nome:string, unidade:string, valor_unitario:float, estoque_saldo:float, estoque_capacidade:float}>
 */
function repo_medicao_bm_catalogo_por_codigo_matriz(int $empresaRaizId): array
{
    if ($empresaRaizId <= 0) {
        return [];
    }
    $pdo = db();
    if (!$pdo) {
        return [];
    }
    $temEstoque = repo_cliente_itens_estoque_saldo_column_exists();
    $colEst     = $temEstoque ? 'it.estoque_saldo' : 'CAST(0 AS DECIMAL(14,4))';
    $temCap     = repo_cliente_itens_estoque_capacidade_column_exists();
    $colCap     = $temCap ? 'it.estoque_capacidade' : 'CAST(0 AS DECIMAL(14,4))';

    try {
        $sql = "
            SELECT
                it.id AS item_id,
                COALESCE(NULLIF(TRIM(it.codigo), ''), '') AS item_codigo,
                it.nome AS item_nome,
                it.unidade AS unidade,
                it.valor_unitario AS valor_unitario,
                {$colEst} AS estoque_saldo,
                {$colCap} AS estoque_capacidade
            FROM cliente_itens it
            WHERE (it.cliente_id = ? OR it.empresa_id = ?)
        ";
        $st = $pdo->prepare($sql);
        $st->execute([$empresaRaizId, $empresaRaizId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $sql = "
            SELECT
                it.id AS item_id,
                COALESCE(NULLIF(TRIM(it.codigo), ''), '') AS item_codigo,
                it.nome AS item_nome,
                it.unidade AS unidade,
                it.valor_unitario AS valor_unitario,
                CAST(0 AS DECIMAL(14,4)) AS estoque_saldo,
                CAST(0 AS DECIMAL(14,4)) AS estoque_capacidade
            FROM cliente_itens it
            WHERE (it.cliente_id = ? OR it.empresa_id = ?)
        ";
        try {
            $st = $pdo->prepare($sql);
            $st->execute([$empresaRaizId, $empresaRaizId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e2) {
            return [];
        }
    }

    $map = [];
    foreach ($rows as $r) {
        $codRaw = trim((string) ($r['item_codigo'] ?? ''));
        $cod    = mb_strtoupper($codRaw, 'UTF-8');
        if ($cod === '') {
            continue;
        }
        if (isset($map[$cod])) {
            continue;
        }
        $map[$cod] = [
            'item_id'             => (int) ($r['item_id'] ?? 0),
            'item_codigo'         => $codRaw,
            'item_nome'           => (string) ($r['item_nome'] ?? ''),
            'unidade'             => (string) ($r['unidade'] ?? 'UN'),
            'valor_unitario'      => (float) ($r['valor_unitario'] ?? 0),
            'estoque_saldo'       => (float) ($r['estoque_saldo'] ?? 0),
            'estoque_capacidade'  => (float) ($r['estoque_capacidade'] ?? 0),
        ];
    }

    return $map;
}

/**
 * Total de chamados Validado (ativos) no escopo da matriz — diagnóstico da listagem de medição.
 */
function repo_medicao_count_validado_escopo(int $empresaRaizId): int
{
    if ($empresaRaizId <= 0) {
        return 0;
    }
    $pdo = db();
    if (!$pdo) {
        return 0;
    }
    try {
        $sql = '
            SELECT COUNT(*)
            FROM chamados ch
            WHERE ch.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
              AND ' . repo_chamados_status_sql_medicao_bm() . '
              ' . _repo_chamados_sql_apenas_ativos('ch');
        $st = $pdo->prepare($sql);
        $st->execute([$empresaRaizId, $empresaRaizId]);

        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        if (function_exists('app_debug_mode') && app_debug_mode()) {
            error_log('repo_medicao_count_validado_escopo: ' . $e->getMessage());
        }

        return 0;
    }
}

/**
 * Chamados ativos (não Validado/Cancelado) com abertura no mês civil corrente — para aviso na listagem de medição.
 */
function repo_medicao_count_nao_validados_mes_corrente(int $empresaRaizId): int
{
    if ($empresaRaizId <= 0) {
        return 0;
    }
    $pdo = db();
    if (!$pdo) {
        return 0;
    }
    try {
        $sql = '
            SELECT COUNT(DISTINCT ch.id)
            FROM chamados ch
            WHERE ch.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
              AND ch.status NOT IN (\'Validado\', \'Cancelado\')
              AND DATE_FORMAT(ch.aberto_em, \'%Y-%m\') = DATE_FORMAT(CURDATE(), \'%Y-%m\')
              ' . _repo_chamados_sql_apenas_ativos('ch') . '
        ';
        $st = $pdo->prepare($sql);
        $st->execute([$empresaRaizId, $empresaRaizId]);

        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        if (function_exists('app_debug_mode') && app_debug_mode()) {
            error_log('repo_medicao_count_nao_validados_mes_corrente: ' . $e->getMessage());
        }

        return 0;
    }
}

/**
 * Resumo por mês civil na matriz — para listagem de medições mensais.
 * Inclui meses com chamados Validado (data de abertura) e meses que só existem por importação BM.
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
        $sql = '
            SELECT
                DATE_FORMAT(ch.aberto_em, \'%Y-%m\') AS ym,
                COUNT(DISTINCT ch.id) AS n_chamados,
                COALESCE(SUM(COALESCE(agg.valor_materiais, 0)), 0) AS valor_materiais,
                COALESCE(SUM(COALESCE(agg.valor_servicos, 0)), 0) AS valor_servicos
            FROM chamados ch
            LEFT JOIN (
                SELECT ci.chamado_id,
                    SUM(CASE WHEN it.tipo = \'produto\' AND ci.movimento = \'utilizado\' THEN ci.subtotal ELSE 0 END) AS valor_materiais,
                    SUM(CASE WHEN it.tipo = \'servico\' AND ci.movimento = \'utilizado\' THEN ci.subtotal ELSE 0 END) AS valor_servicos
                FROM chamado_itens ci
                INNER JOIN cliente_itens it ON it.id = ci.item_id
                GROUP BY ci.chamado_id
            ) agg ON agg.chamado_id = ch.id
            WHERE ch.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
              AND ' . repo_chamados_status_sql_medicao_bm() . '
              ' . _repo_chamados_sql_apenas_ativos('ch') . '
            GROUP BY DATE_FORMAT(ch.aberto_em, \'%Y-%m\')
            ORDER BY ym DESC
            LIMIT 240';
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

        if (function_exists('repo_medicao_custos_table_exists') && repo_medicao_custos_table_exists()) {
            $stCustos = $pdo->prepare(
                'SELECT ref_ym AS ym, COALESCE(SUM(valor_total), 0) AS valor_custos
                 FROM medicao_custos
                 WHERE cliente_matriz_id = ? AND status = \'Aprovado\'
                 GROUP BY ref_ym'
            );
            $stCustos->execute([$empresaRaizId]);
            foreach ($stCustos->fetchAll(PDO::FETCH_ASSOC) ?: [] as $cr) {
                $ym = (string) ($cr['ym'] ?? '');
                if ($ym === '' || !preg_match('/^\d{4}-\d{2}$/', $ym)) {
                    continue;
                }
                $vc = round((float) ($cr['valor_custos'] ?? 0), 2);
                if ($vc <= 0) {
                    continue;
                }
                if (!isset($byYm[$ym])) {
                    $dataDe = $ym . '-01';
                    $byYm[$ym] = [
                        'ym'               => $ym,
                        'data_de'          => $dataDe,
                        'data_ate'         => date('Y-m-t', strtotime($dataDe)),
                        'n_chamados'       => 0,
                        'valor_materiais'  => 0.0,
                        'valor_servicos'   => 0.0,
                        'valor_total'      => 0.0,
                    ];
                }
                $byYm[$ym]['valor_custos_adicionais'] = $vc;
                $byYm[$ym]['valor_total'] = round((float) ($byYm[$ym]['valor_total'] ?? 0) + $vc, 2);
            }
        }

        $merged = array_values($byYm);
        usort($merged, static function (array $a, array $b): int {
            return strcmp((string) ($b['ym'] ?? ''), (string) ($a['ym'] ?? ''));
        });

        return array_slice($merged, 0, $limiteLinhas);
    } catch (Throwable $e) {
        if (function_exists('app_debug_mode') && app_debug_mode()) {
            error_log('repo_medicao_resumo_mensal_list: ' . $e->getMessage());
        }

        return [];
    }
}

/**
 * Substitui importação BM do mês (uma por matriz + ref_ym).
 *
 * @param list<array<string,mixed>> $linhas saída de medicao_csv_parse_bm_planilha()['linhas']
 * @return array{ok:bool, erro:string, estoque_sync?: array<string,mixed>}
 */
function repo_medicao_import_substituir(
    int $clienteMatrizId,
    string $refYm,
    string $nomeArquivo,
    ?string $importadoPor,
    ?int $idxQtdMedido,
    ?int $idxValorMedido,
    array $linhas,
    int $importadorUserId = 0,
    bool $notificarDestinatarios = true,
    bool $sincronizarSaldoBm = true
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

        if ($sincronizarSaldoBm && $linhas !== []) {
            require_once __DIR__ . '/medicao_relatorio_import.php';
            medicao_import_sync_catalogo_from_bm_linhas($clienteMatrizId, $linhas);
            $syncEst = repo_catalogo_sync_saldo_from_bm_linhas($clienteMatrizId, $linhas);
            $ret['estoque_sync'] = $syncEst;
        }

        audit_log_registar('medicao.importar', 'medicao', null, $clienteMatrizId > 0 ? $clienteMatrizId : null, [
            'ref_ym'        => $refYm,
            'nome_arquivo'  => function_exists('mb_substr') ? mb_substr($nomeArquivo, 0, 200, 'UTF-8') : substr($nomeArquivo, 0, 200),
            'n_linhas'      => count($linhas),
            'importado_por' => $importadoPor !== null && $importadoPor !== ''
                ? (function_exists('mb_substr') ? mb_substr($importadoPor, 0, 80, 'UTF-8') : substr($importadoPor, 0, 80))
                : '',
        ]);
        if ($notificarDestinatarios) {
            require_once __DIR__ . '/notificacoes.php';
            notificar_medicao_bm_importado(
                $clienteMatrizId,
                $refYm,
                $importadorUserId,
                'planilha',
                count($linhas),
                $nomeArquivo
            );
        }
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
 * Soma de valor_medido_periodo em todas as importações BM da matriz (histórico completo).
 */
function repo_medicao_bm_valor_acumulado(int $clienteMatrizId): float
{
    if ($clienteMatrizId <= 0) {
        return 0.0;
    }
    $pdo = db();
    if (!$pdo) {
        return 0.0;
    }
    try {
        $st = $pdo->prepare('
            SELECT COALESCE(SUM(mil.valor_medido_periodo), 0)
            FROM medicao_import_linhas mil
            INNER JOIN medicao_imports mi ON mi.id = mil.import_id
            WHERE mi.cliente_matriz_id = ?
        ');
        $st->execute([$clienteMatrizId]);

        return (float) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
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
    $ch = repo_chamado($id);
    if (!$ch) {
        return false;
    }
    $cidLog = (int) ($ch['cliente_id'] ?? 0);

    if (repo_chamados_tem_exclusao_logica()) {
        if (isset($ch['ativo']) && (int) $ch['ativo'] === 0) {
            return true;
        }
        $uid = repo_usuario_sessao_id();
        $st  = $pdo->prepare('
            UPDATE chamados
            SET ativo = 0,
                excluido_em = NOW(),
                excluido_por_user_id = ?
            WHERE id = ? AND ativo = 1
        ');
        $ok = $st->execute([$uid, $id]);
        if ($ok && $st->rowCount() > 0) {
            audit_log_registar('chamado.inativar', 'chamado', $id, $cidLog > 0 ? $cidLog : null, [
                'titulo'        => function_exists('mb_substr') ? mb_substr((string) ($ch['titulo'] ?? ''), 0, 200, 'UTF-8') : substr((string) ($ch['titulo'] ?? ''), 0, 200),
                'status'        => (string) ($ch['status'] ?? ''),
                'exclusao_tipo' => 'logica',
            ]);

            return true;
        }

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
    $ok   = $stmt->execute([$id]);
    if ($ok && $stmt->rowCount() > 0) {
        audit_log_registar('chamado.excluir', 'chamado', $id, $cidLog > 0 ? $cidLog : null, [
            'titulo' => function_exists('mb_substr') ? mb_substr((string) ($ch['titulo'] ?? ''), 0, 200, 'UTF-8') : substr((string) ($ch['titulo'] ?? ''), 0, 200),
            'status' => (string) ($ch['status'] ?? ''),
        ]);
    }

    return $ok;
}

/**
 * Conta chamados ativos no escopo (matriz + filiais). null = todo o sistema.
 */
function repo_chamados_contar_ativos(?int $clienteIdEscopo = null): int
{
    $pdo = db();
    if (!$pdo) {
        return 0;
    }
    $where  = ['1=1'];
    $params = [];
    _repo_chamados_where_ativo($where, '');
    if ($clienteIdEscopo !== null && $clienteIdEscopo > 0) {
        $where[]  = 'ch.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)';
        $params[] = $clienteIdEscopo;
        $params[] = $clienteIdEscopo;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM chamados ch WHERE ' . implode(' AND ', $where));
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

/**
 * Inativa ou remove todos os chamados ativos no escopo. Retorna quantidade afetada.
 */
function repo_chamados_excluir_todos_ativos(?int $clienteIdEscopo = null): int
{
    $pdo = db();
    if (!$pdo) {
        return 0;
    }
    $where  = ['1=1'];
    $params = [];
    _repo_chamados_where_ativo($where, '');
    if ($clienteIdEscopo !== null && $clienteIdEscopo > 0) {
        $where[]  = 'ch.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)';
        $params[] = $clienteIdEscopo;
        $params[] = $clienteIdEscopo;
    }
    $sqlWhere = implode(' AND ', $where);

    if (repo_chamados_tem_exclusao_logica()) {
        $uid = repo_usuario_sessao_id();
        $st  = $pdo->prepare("
            UPDATE chamados ch
            SET ativo = 0,
                excluido_em = NOW(),
                excluido_por_user_id = ?
            WHERE $sqlWhere
        ");
        $st->execute(array_merge([$uid], $params));
        $n = $st->rowCount();
        if ($n > 0) {
            audit_log_registar('chamados.excluir_todos', 'chamado', null, $clienteIdEscopo > 0 ? $clienteIdEscopo : null, [
                'total'           => $n,
                'exclusao_tipo'   => 'logica',
                'cliente_escopo'  => $clienteIdEscopo,
            ]);
        }

        return $n;
    }

    $st = $pdo->prepare("SELECT ch.id FROM chamados ch WHERE $sqlWhere");
    $st->execute($params);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $n   = 0;
    foreach ($ids as $id) {
        if (repo_delete_chamado($id)) {
            $n++;
        }
    }
    if ($n > 0) {
        audit_log_registar('chamados.excluir_todos', 'chamado', null, $clienteIdEscopo > 0 ? $clienteIdEscopo : null, [
            'total'          => $n,
            'exclusao_tipo'  => 'fisica',
            'cliente_escopo' => $clienteIdEscopo,
        ]);
    }

    return $n;
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
            $chAtivo = _repo_chamados_sql_apenas_ativos();
            $st = $pdo->prepare("SELECT COUNT(*) FROM chamados WHERE 1=1 AND $chF" . $chAtivo);
            $st->execute([$cid, $cid]);
            $chTotal = (int) $st->fetchColumn();
            $st = $pdo->prepare("SELECT COUNT(*) FROM chamados WHERE status = 'Aberto' AND $chF" . $chAtivo);
            $st->execute([$cid, $cid]);
            $abertos = (int) $st->fetchColumn();
            $st = $pdo->prepare("SELECT COUNT(*) FROM chamados WHERE status = 'Em andamento' AND $chF" . $chAtivo);
            $st->execute([$cid, $cid]);
            $andamento = (int) $st->fetchColumn();
            $st = $pdo->prepare("SELECT COUNT(*) FROM chamados WHERE status NOT IN ('Validado','Cancelado') AND $chF" . $chAtivo);
            $st->execute([$cid, $cid]);
            $semValidadoCancelado = (int) $st->fetchColumn();
            $st = $pdo->prepare("SELECT COUNT(*) FROM chamados WHERE prioridade IN ('Alta','Urgente') AND status NOT IN ('Resolvido','Fechado','Cancelado') AND $chF" . $chAtivo);
            $st->execute([$cid, $cid]);
            $urgentes = (int) $st->fetchColumn();
            $st = $pdo->prepare("
                SELECT COUNT(*) FROM chamados
                WHERE status IN ('Resolvido','Fechado')
                  AND aberto_em >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                  AND $chF" . $chAtivo . '
            ');
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
            $chAtivo = _repo_chamados_sql_apenas_ativos();
            $chTotal = (int) $pdo->query('SELECT COUNT(*) FROM chamados WHERE 1=1' . $chAtivo)->fetchColumn();
            $abertos = (int) $pdo->query("SELECT COUNT(*) FROM chamados WHERE status = 'Aberto'" . $chAtivo)->fetchColumn();
            $andamento = (int) $pdo->query("SELECT COUNT(*) FROM chamados WHERE status = 'Em andamento'" . $chAtivo)->fetchColumn();
            $semValidadoCancelado = (int) $pdo->query("SELECT COUNT(*) FROM chamados WHERE status NOT IN ('Validado','Cancelado')" . $chAtivo)->fetchColumn();
            $urgentes = (int) $pdo->query("SELECT COUNT(*) FROM chamados WHERE prioridade IN ('Alta','Urgente') AND status NOT IN ('Resolvido','Fechado','Cancelado')" . $chAtivo)->fetchColumn();
            $res7d = (int) $pdo->query("
                SELECT COUNT(*) FROM chamados
                WHERE status IN ('Resolvido','Fechado')
                  AND aberto_em >= DATE_SUB(NOW(), INTERVAL 7 DAY)" . $chAtivo . '
            ')->fetchColumn();

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
            $st = $pdo->prepare("
                SELECT COALESCE(SUM(ci.subtotal), 0)
                FROM chamado_itens ci
                INNER JOIN chamados ch ON ch.id = ci.chamado_id
                WHERE ci.movimento = 'utilizado'
                  AND ch.status = 'Validado'
                  AND DATE(ch.aberto_em) >= ?
                  AND DATE(ch.aberto_em) <= ?
                  AND ch.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
                  " . _repo_chamados_sql_apenas_ativos('ch') . '
            ');
            $mesIni = $refYmDash . '-01';
            $mesFim = date('Y-m-t', strtotime($mesIni));
            $st->execute([$mesIni, $mesFim, $cid, $cid]);
            $medicaoMesValor = (float) $st->fetchColumn();
        } else {
            $pontosTotal = (int) $pdo->query('SELECT COUNT(*) FROM pontos_iluminacao')->fetchColumn();
            $st = $pdo->prepare("
                SELECT COALESCE(SUM(ci.subtotal), 0)
                FROM chamado_itens ci
                INNER JOIN chamados ch ON ch.id = ci.chamado_id
                WHERE ci.movimento = 'utilizado'
                  AND ch.status = 'Validado'
                  AND DATE(ch.aberto_em) >= ?
                  AND DATE(ch.aberto_em) <= ?
                  " . _repo_chamados_sql_apenas_ativos('ch') . '
            ');
            $mesIni = $refYmDash . '-01';
            $mesFim = date('Y-m-t', strtotime($mesIni));
            $st->execute([$mesIni, $mesFim]);
            $medicaoMesValor = (float) $st->fetchColumn();
        }

        return [
            'ch_total'                    => $chTotal ?? 0,
            'ch_sem_validado_cancelado'   => $semValidadoCancelado ?? 0,
            'ch_abertos'                  => $abertos,
            'ch_andamento'                => $andamento,
            'ch_urgentes'                 => $urgentes,
            'ch_resolvidos_7d'            => $res7d,
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

            $st = $pdo->prepare('
                SELECT COUNT(*) FROM cliente_itens
                WHERE ativo = 1 AND cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
            ');
            $st->execute([$clienteId, $clienteId]);
            $nCat = (int) $st->fetchColumn();
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

            $st = $pdo->prepare('SELECT COUNT(*) FROM cliente_itens WHERE ativo = 1 AND cliente_id = ?');
            $st->execute([$clienteId]);
            $nCat = (int) $st->fetchColumn();
        }

        return [
            'chamados'           => (string) $nCh,
            'medicao'            => (string) $nCh,
            'catalogo'           => (string) $nCat,
            'auditoria'          => '0',
            'pontos_iluminacao'  => (string) $nPontos,
            'documentos'         => (string) $nDoc,
            'suporte'            => (string) $nSp,
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

    $senhaPlana = (string) ($d['senha'] ?? '');
    if (strlen($senhaPlana) < 6) {
        return null;
    }
    $hash = password_hash($senhaPlana, PASSWORD_BCRYPT);
    $perfil = strtolower(trim((string) ($d['perfil'] ?? 'cliente')));
    if (!in_array($perfil, ['admin', 'cliente', 'operador', 'gestor'], true)) {
        return null;
    }
    $nome = trim((string) ($d['nome'] ?? ''));
    if ($nome === '') {
        return null;
    }
    $email = trim((string) ($d['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    if (repo_email_existe($email)) {
        return null;
    }
    $iniciais = trim((string) ($d['iniciais'] ?? ''));
    if ($iniciais === '') {
        $iniciais = repo_usuario_calcular_iniciais($nome);
    }
    $mpf    = null;
    $cid = !empty($d['cliente_id']) ? (int) $d['cliente_id'] : null;
    $eid = !empty($d['empresa_id']) ? (int) $d['empresa_id'] : null;
    if (in_array($perfil, ['operador', 'gestor'], true)) {
        $cid = null;
        if ($eid === null || $eid <= 0) {
            return null;
        }
    } elseif ($perfil === 'cliente') {
        $eid = null;
        if ($cid === null || $cid <= 0) {
            return null;
        }
        $chk = $pdo->prepare('SELECT id FROM clientes WHERE id = ? LIMIT 1');
        $chk->execute([$cid]);
        if (!$chk->fetchColumn()) {
            return null;
        }
    } else {
        $eid = null;
    }
    if (repo_usuarios_ativo_column_exists()) {
        $stmt = $pdo->prepare('
            INSERT INTO usuarios (nome, email, senha_hash, perfil, modulo_perfil, cliente_id, empresa_id, iniciais, ativo)
            VALUES (?,?,?,?,?,?,?,?,1)
        ');
        $stmt->execute([
            $nome,
            $email,
            $hash,
            $perfil,
            $mpf,
            $cid,
            $eid,
            $iniciais,
        ]);
    } else {
        $stmt = $pdo->prepare('
            INSERT INTO usuarios (nome, email, senha_hash, perfil, modulo_perfil, cliente_id, empresa_id, iniciais)
            VALUES (?,?,?,?,?,?,?,?)
        ');
        $stmt->execute([
            $nome,
            $email,
            $hash,
            $perfil,
            $mpf,
            $cid,
            $eid,
            $iniciais,
        ]);
    }
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
    $ponto   = null;
    if ($pontoId !== null && $pontoId > 0) {
        $ponto = repo_ponto_iluminacao($pontoId);
        if ($ponto && (int) ($ponto['cliente_id'] ?? 0) === (int) ($d['cliente_id'] ?? 0)) {
            $d = chamado_os_aplicar_dados_ponto($d, $ponto, true);
            $composedAddr = chamado_os_compor_endereco_completo($d);
            $endereco = $composedAddr !== null && $composedAddr !== ''
                ? $composedAddr
                : trim((string) ($d['endereco_completo'] ?? ''));
            $endereco = $endereco !== '' ? $endereco : null;
            [$la, $lo] = _repo_parse_latlng_pair(
                isset($d['latitude']) ? (string) $d['latitude'] : null,
                isset($d['longitude']) ? (string) $d['longitude'] : null
            );
        } else {
            $pontoId = null;
            $ponto   = null;
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
    $nid = (int) $pdo->lastInsertId();
    if ($nid > 0) {
        $cidLog = (int) ($d['cliente_id'] ?? 0);
        audit_log_registar('chamado.criar', 'chamado', $nid, $cidLog > 0 ? $cidLog : null, [
            'titulo'               => function_exists('mb_substr') ? mb_substr((string) ($d['titulo'] ?? ''), 0, 200, 'UTF-8') : substr((string) ($d['titulo'] ?? ''), 0, 200),
            'descricao'            => function_exists('mb_substr') ? mb_substr(trim((string) ($d['descricao'] ?? '')), 0, 400, 'UTF-8') : substr(trim((string) ($d['descricao'] ?? '')), 0, 400),
            'prioridade'           => (string) ($d['prioridade'] ?? ''),
            'status'               => (string) ($d['status'] ?? 'Aberto'),
            'ponto_iluminacao_id'  => $pontoId,
            'contribuinte_nome'    => function_exists('mb_substr') ? mb_substr(trim((string) ($d['contribuinte_nome'] ?? '')), 0, 120, 'UTF-8') : substr(trim((string) ($d['contribuinte_nome'] ?? '')), 0, 120),
        ]);
        require_once __DIR__ . '/notificacoes.php';
        $autorNotif = (int) ($d['criado_por_user_id'] ?? 0);
        notificar_chamado_criado($nid, $autorNotif);
    }

    return $nid;
}

/**
 * Grava no chamado o endereço/coordenadas do poste quando o chamado ainda não tem endereço.
 */
function repo_chamado_sincronizar_endereco_do_ponto(int $chamadoId): bool
{
    if ($chamadoId <= 0) {
        return false;
    }
    $ch = repo_chamado($chamadoId);
    if (!$ch) {
        return false;
    }
    $pid = (int) ($ch['ponto_iluminacao_id'] ?? 0);
    if ($pid <= 0) {
        return false;
    }
    $ponto = repo_ponto_iluminacao($pid);
    if (!$ponto || (int) ($ponto['cliente_id'] ?? 0) !== (int) ($ch['cliente_id'] ?? 0)) {
        return false;
    }
    if (chamado_tem_endereco_cadastrado($ch, null)) {
        return true;
    }
    $d = chamado_os_aplicar_dados_ponto($ch, $ponto, false);

    return repo_update_chamado_os_dados($chamadoId, $d);
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

    $keysAuditOs = [
        'ponto_iluminacao_id', 'titulo', 'descricao', 'contribuinte_cpf', 'contribuinte_nome', 'contribuinte_telefone', 'contribuinte_email',
        'data_abertura_os', 'origem_os', 'problema_os', 'tipo_os', 'ponto_referencia',
        'os_cep', 'os_logradouro', 'os_numero', 'os_complemento', 'os_bairro', 'os_cidade', 'os_uf',
        'endereco_completo', 'latitude', 'longitude',
    ];

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
    $ponto   = null;
    if ($pontoId !== null && $pontoId > 0) {
        $ponto = repo_ponto_iluminacao($pontoId);
        if (!$ponto || (int) ($ponto['cliente_id'] ?? 0) !== $clienteId) {
            $pontoId = null;
            $ponto   = null;
        } else {
            $d = chamado_os_aplicar_dados_ponto($d, $ponto, true);
            $composedAddr = chamado_os_compor_endereco_completo($d);
            $endereco = $composedAddr !== null && $composedAddr !== ''
                ? $composedAddr
                : trim((string) ($d['endereco_completo'] ?? ''));
            $endereco = $endereco !== '' ? $endereco : null;
            [$la, $lo] = _repo_parse_latlng_pair(
                isset($d['latitude']) ? (string) $d['latitude'] : null,
                isset($d['longitude']) ? (string) $d['longitude'] : null
            );
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
    $ok = $stmt->execute([
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
    if ($ok) {
        $depoisAudit = [
            'ponto_iluminacao_id'     => $pontoId,
            'titulo'                  => (string) ($d['titulo'] ?? ''),
            'descricao'               => (string) ($d['descricao'] ?? ''),
            'contribuinte_cpf'        => $d['contribuinte_cpf'] ?? null,
            'contribuinte_nome'       => $d['contribuinte_nome'] ?? null,
            'contribuinte_telefone'   => $d['contribuinte_telefone'] ?? null,
            'contribuinte_email'      => $d['contribuinte_email'] ?? null,
            'data_abertura_os'        => $dab,
            'origem_os'               => $d['origem_os'] ?? null,
            'problema_os'             => $d['problema_os'] ?? null,
            'tipo_os'                 => $d['tipo_os'] ?? null,
            'ponto_referencia'        => $d['ponto_referencia'] ?? null,
            'os_cep'                  => $d['os_cep'] ?? null,
            'os_logradouro'           => $d['os_logradouro'] ?? null,
            'os_numero'               => $d['os_numero'] ?? null,
            'os_complemento'          => $d['os_complemento'] ?? null,
            'os_bairro'               => $d['os_bairro'] ?? null,
            'os_cidade'               => $d['os_cidade'] ?? null,
            'os_uf'                   => $d['os_uf'] ?? null,
            'endereco_completo'       => $endereco,
            'latitude'                => $la,
            'longitude'               => $lo,
        ];
        $diffOs = audit_log_diff_campos($ch, $depoisAudit, $keysAuditOs);
        audit_log_registar('chamado.alterar', 'chamado', $id, $clienteId > 0 ? $clienteId : null, [
            'campos'       => 'ficha_os',
            'titulo'       => function_exists('mb_substr') ? mb_substr((string) ($d['titulo'] ?? ''), 0, 160, 'UTF-8') : substr((string) ($d['titulo'] ?? ''), 0, 160),
            'alteracoes'   => $diffOs,
            'n_alteracoes' => count($diffOs),
        ]);
        if ($pontoId !== null && $pontoId > 0) {
            chamado_sync_primeira_imagem_chamado_para_ponto($id, $pontoId);
        }
    }

    return $ok;
}

function repo_update_chamado_prioridade(int $id, string $prioridade): bool
{
    static $allowed = ['Baixa', 'Normal', 'Alta', 'Urgente'];
    if (!in_array($prioridade, $allowed, true)) {
        return false;
    }
    $pdo = db();
    if (!$pdo) {
        return false;
    }
    $ch = repo_chamado($id);
    if (!$ch) {
        return false;
    }
    $clienteId = (int) ($ch['cliente_id'] ?? 0);
    $antesPrio  = (string) ($ch['prioridade'] ?? '');
    $stmt       = $pdo->prepare('UPDATE chamados SET prioridade = ? WHERE id = ?');
    $ok         = $stmt->execute([$prioridade, $id]);
    if ($ok && $antesPrio !== $prioridade) {
        audit_log_registar('chamado.alterar_prioridade', 'chamado', $id, $clienteId > 0 ? $clienteId : null, [
            'antes'  => $antesPrio,
            'depois' => $prioridade,
        ]);
    }

    return $ok;
}

function repo_update_chamado_localizacao(int $id, ?string $enderecoCompleto, ?float $latitude, ?float $longitude): bool
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
    $end = $enderecoCompleto !== null ? trim($enderecoCompleto) : '';
    $end = $end !== '' ? $end : null;
    $stmt = $pdo->prepare('
        UPDATE chamados
        SET endereco_completo = ?, latitude = ?, longitude = ?
        WHERE id = ?
    ');
    $ok = $stmt->execute([$end, $latitude, $longitude, $id]);
    if ($ok) {
        $antesLoc = [
            'endereco_completo' => $ch['endereco_completo'] ?? null,
            'latitude'          => $ch['latitude'] ?? null,
            'longitude'         => $ch['longitude'] ?? null,
        ];
        $depoisLoc = [
            'endereco_completo' => $end,
            'latitude'          => $latitude,
            'longitude'         => $longitude,
        ];
        $diffLoc = audit_log_diff_campos($antesLoc, $depoisLoc, ['endereco_completo', 'latitude', 'longitude']);
        if ($diffLoc !== []) {
            audit_log_registar('chamado.alterar_localizacao', 'chamado', $id, $clienteId > 0 ? $clienteId : null, [
                'alteracoes'   => $diffLoc,
                'n_alteracoes' => count($diffLoc),
            ]);
        }
    }

    return $ok;
}

/**
 * Portal cliente: «Cancelar» reabre o chamado (status Aberto) para novo fluxo com a gestão.
 */
function repo_chamado_cliente_reabrir(int $id, int $matrizId): bool
{
    if ($id <= 0 || $matrizId <= 0) {
        return false;
    }
    $pdo = db();
    if (!$pdo) {
        return false;
    }
    $antes = repo_chamado($id);
    if (!$antes || !repo_cliente_pertence_empresa((int) ($antes['cliente_id'] ?? 0), $matrizId)) {
        return false;
    }
    if (array_key_exists('ativo', $antes) && (int) ($antes['ativo'] ?? 1) === 0) {
        return false;
    }
    $stAnt = trim((string) ($antes['status'] ?? ''));
    if ($stAnt === 'Aberto') {
        return false;
    }
    $stmt = $pdo->prepare('UPDATE chamados SET status = ? WHERE id = ?');
    $ok   = $stmt->execute(['Aberto', $id]);
    if (!$ok) {
        return false;
    }
    $cidLog = (int) ($antes['cliente_id'] ?? 0);
    audit_log_registar('chamado.cliente_reabrir', 'chamado', $id, $cidLog > 0 ? $cidLog : null, [
        'status_anterior' => $stAnt,
        'status_novo'     => 'Aberto',
    ]);
    if (function_exists('repo_notificacao_insert')) {
        require_once __DIR__ . '/notificacoes.php';
        $tituloReab = sprintf('Chamado #%d reaberto pelo cliente', $id);
        $descReab   = 'O chamado voltou ao status Aberto para novo atendimento.';
        $dest       = repo_notificacao_destinatarios_chamado($id, true);
        foreach (array_unique($dest) as $uidDest) {
            if ($uidDest > 0) {
                repo_notificacao_insert((int) $uidDest, $id, null, $tituloReab, $descReab, 'chamado_status');
            }
        }
    }

    return true;
}

function repo_update_chamado_status(int $id, string $status, ?string $perfilActor = null): bool
{
    static $allowed = ['Aberto', 'Em andamento', 'Aguardando Aprovação', 'Resolvido', 'Validado', 'Fechado', 'Cancelado'];
    if (!in_array($status, $allowed, true)) {
        return false;
    }
    $p = strtolower(trim((string) $perfilActor));
    /** Admin: validar, fechar e cancelar. Gestor: até Resolvido (e fluxos anteriores). */
    $gestaoPlena = ($p === 'admin');
    $gestaoOperacional = in_array($p, ['admin', 'gestor'], true);
    if ($p === 'gestor' && in_array($status, ['Validado', 'Fechado', 'Cancelado'], true)) {
        return false;
    }
    if ($status === 'Cancelado' && !$gestaoPlena) {
        return false;
    }
    if ($status === 'Validado' && !$gestaoPlena && $p !== 'cliente') {
        return false;
    }
    $pdo = db();
    if (!$pdo) {
        return false;
    }
    $antes = repo_chamado($id);
    if (!$antes) {
        return false;
    }
    if ($status === 'Resolvido' && !$gestaoOperacional) {
        $pontoSt = null;
        $pidSt = (int) ($antes['ponto_iluminacao_id'] ?? 0);
        if ($pidSt > 0) {
            $pontoSt = repo_ponto_iluminacao($pidSt);
        }
        if (!repo_chamado_tem_coordenadas_validas($antes, $pontoSt)) {
            return false;
        }
    }
    if ($status === 'Validado' && !$gestaoPlena) {
        $stAnt = trim((string) ($antes['status'] ?? ''));
        if (!in_array($stAnt, ['Resolvido', 'Fechado'], true)) {
            return false;
        }
    }
    $stmt  = $pdo->prepare('UPDATE chamados SET status = ? WHERE id = ?');
    $ok    = $stmt->execute([$status, $id]);
    if ($ok && $antes) {
        $cidLog = (int) ($antes['cliente_id'] ?? 0);
        audit_log_registar('chamado.status', 'chamado', $id, $cidLog > 0 ? $cidLog : null, [
            'status_anterior' => (string) ($antes['status'] ?? ''),
            'status_novo'     => $status,
        ]);
        if ((string) ($antes['status'] ?? '') !== $status && function_exists('repo_notificacao_insert')) {
            require_once __DIR__ . '/notificacoes.php';
            $tipoNot = 'chamado_status';
            if ($status === 'Resolvido') {
                $tituloSt = sprintf('Chamado #%d foi resolvido', $id);
                $descSt   = 'O atendimento foi marcado como resolvido e aguarda validação, se aplicável.';
            } elseif ($status === 'Validado') {
                $tituloSt = sprintf('Chamado #%d validado', $id);
                $descSt   = 'O chamado foi conferido e validado pela gestão.';
            } elseif ($status === 'Em andamento') {
                $tituloSt = sprintf('Chamado #%d em atendimento', $id);
                $descSt   = 'O chamado entrou em atendimento. Acompanhe o progresso.';
            } elseif ($status === 'Aguardando Aprovação') {
                $tituloSt = sprintf('Chamado #%d aguarda aprovação', $id);
                $descSt   = 'O chamado aguarda aprovação da gestão.';
            } else {
                $tituloSt = sprintf('Chamado #%d: %s', $id, $status);
                $descSt   = 'Status alterado de "' . (string) ($antes['status'] ?? '—') . '" para "' . $status . '".';
            }
            if ($status === 'Aguardando Aprovação') {
                $dest = repo_notificacao_destinatarios_chamado($id, true);
            } elseif ($status === 'Resolvido' || $status === 'Validado') {
                $dest = repo_notificacao_destinatarios_chamado($id, false);
            } else {
                $dest = repo_notificacao_destinatarios_chamado($id, true);
            }
            foreach (array_unique($dest) as $uidDest) {
                if ($uidDest > 0) {
                    repo_notificacao_insert((int) $uidDest, $id, null, $tituloSt, $descSt, $tipoNot);
                }
            }
        }
    }

    return $ok;
}

function repo_update_chamado_responsavel(int $id, string $responsavel): bool
{
    $pdo = db();
    if (!$pdo) {
        return false;
    }
    $ch = repo_chamado($id);
    if (!$ch) {
        return false;
    }
    $clienteId = (int) ($ch['cliente_id'] ?? 0);
    $antesResp  = trim((string) ($ch['responsavel'] ?? ''));
    $stmt       = $pdo->prepare('UPDATE chamados SET responsavel = ? WHERE id = ?');
    $ok         = $stmt->execute([$responsavel, $id]);
    if ($ok && $antesResp !== $responsavel) {
        audit_log_registar('chamado.alterar_responsavel', 'chamado', $id, $clienteId > 0 ? $clienteId : null, [
            'antes'  => function_exists('mb_substr') ? mb_substr($antesResp, 0, 120, 'UTF-8') : substr($antesResp, 0, 120),
            'depois' => function_exists('mb_substr') ? mb_substr($responsavel, 0, 120, 'UTF-8') : substr($responsavel, 0, 120),
        ]);
    }

    return $ok;
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

function repo_cliente_itens_estoque_capacidade_column_exists(): bool
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
        $st    = $pdo->query("SHOW COLUMNS FROM cliente_itens LIKE 'estoque_capacidade'");
        $row   = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
        $cache = $row !== false;
    } catch (Throwable $e) {
        $cache = false;
    }

    return $cache;
}

/**
 * Estoque de referência (estoque_capacidade) para alerta de estoque baixo.
 */
function catalogo_estoque_referencia(array $it): float
{
    if (!repo_cliente_itens_estoque_capacidade_column_exists()) {
        return 0.0;
    }
    $cap = (float) ($it['estoque_capacidade'] ?? 0);

    return $cap > 0 ? $cap : 0.0;
}

/** Limiar = 10% do estoque (capacidade), não do saldo. */
function catalogo_estoque_limiar_baixo(array $it): float
{
    $cap = catalogo_estoque_referencia($it);
    if ($cap <= 0) {
        return 0.0;
    }

    return $cap * 0.10;
}

/** Estoque baixo quando saldo atual ≤ 10% do estoque cadastrado. */
function catalogo_item_estoque_baixo(array $it): bool
{
    if (empty($it['ativo']) || !repo_cliente_itens_estoque_saldo_column_exists()) {
        return false;
    }
    $cap = catalogo_estoque_referencia($it);
    if ($cap <= 0) {
        return false;
    }
    $saldo = (float) ($it['estoque_saldo'] ?? 0);
    $limiar = $cap * 0.10;

    return $saldo <= $limiar + 1e-9;
}

function repo_cliente_itens_estoque_saldo_column_exists(): bool
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
        $st    = $pdo->query("SHOW COLUMNS FROM cliente_itens LIKE 'estoque_saldo'");
        $row   = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
        $cache = $row !== false;
    } catch (Throwable $e) {
        $cache = false;
    }

    return $cache;
}

function repo_cliente_itens_catalogo_fluxo_status_column_exists(): bool
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
        $st    = $pdo->query("SHOW COLUMNS FROM cliente_itens LIKE 'catalogo_fluxo_status'");
        $row   = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
        $cache = $row !== false;
    } catch (Throwable $e) {
        $cache = false;
    }

    return $cache;
}

function repo_cliente_item_set_catalogo_fluxo_status(int $itemId, ?string $status): void
{
    if ($itemId <= 0 || !repo_cliente_itens_catalogo_fluxo_status_column_exists()) {
        return;
    }
    $status = trim((string) $status);
    $pdo    = db();
    if (!$pdo) {
        return;
    }
    try {
        $st = $pdo->prepare('UPDATE cliente_itens SET catalogo_fluxo_status = ? WHERE id = ? LIMIT 1');
        $st->execute([$status !== '' ? $status : null, $itemId]);
    } catch (Throwable $e) {
        error_log('[crm_prefeitura] repo_cliente_item_set_catalogo_fluxo_status: ' . $e->getMessage());
    }
}

/**
 * Variação do estoque saldo conforme movimento no chamado (utilizado = saída, devolvido = entrada).
 */
function repo_cliente_item_estoque_delta_por_movimento(string $movimento, float $quantidade): float
{
    if ($quantidade <= 0) {
        return 0.0;
    }
    $movimento = strtolower(trim($movimento));
    if ($movimento === 'utilizado') {
        return -$quantidade;
    }
    if ($movimento === 'devolvido') {
        return $quantidade;
    }

    return 0.0;
}

function repo_cliente_item_aplicar_estoque_delta(PDO $pdo, int $itemId, float $delta): void
{
    if (!repo_cliente_itens_estoque_saldo_column_exists() || $itemId <= 0 || abs($delta) < 1e-9) {
        return;
    }
    $st = $pdo->prepare('UPDATE cliente_itens SET estoque_saldo = estoque_saldo + ? WHERE id = ? LIMIT 1');
    $st->execute([$delta, $itemId]);
}

/**
 * Sincroniza estoque_saldo do catálogo a partir das linhas BM (Código = Item, formato X.Y).
 *
 * @param list<array<string,mixed>> $linhas saída do parse BM com saldo_restante
 * @return array{ok: bool, err: string, itens_processados: int, itens_alterados: int, codigos_sem_catalogo: list<string>}
 */
function repo_catalogo_sync_saldo_from_bm_linhas(int $clienteId, array $linhas): array
{
    $ret = [
        'ok'                   => false,
        'err'                  => '',
        'itens_processados'    => 0,
        'itens_alterados'      => 0,
        'codigos_sem_catalogo' => [],
    ];
    if (!repo_cliente_itens_estoque_saldo_column_exists()) {
        $ret['err'] = 'Controle de estoque não habilitado (migração 045).';

        return $ret;
    }
    $pdo = db();
    if (!$pdo || $clienteId <= 0 || $linhas === []) {
        $ret['ok'] = $linhas === [];

        return $ret;
    }
    $catalogoClienteId = repo_cliente_catalogo_dono_id($clienteId);
    if ($catalogoClienteId <= 0) {
        $ret['err'] = 'Catálogo indisponível para esta empresa.';

        return $ret;
    }

    $seen = [];
    try {
        $pdo->beginTransaction();
        $up = $pdo->prepare(
            'UPDATE cliente_itens SET estoque_saldo = ? WHERE id = ? AND (cliente_id = ? OR empresa_id = ?) LIMIT 1'
        );
        foreach ($linhas as $L) {
            if (!is_array($L)) {
                continue;
            }
            $cod = trim((string) ($L['item_codigo'] ?? ''));
            if ($cod === '' || !preg_match('/^\d+(\.\d+)+$/', $cod)) {
                continue;
            }
            $key = strtoupper(trim($cod));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $saldoNovo = null;
            if (isset($L['saldo_restante']) && $L['saldo_restante'] !== null && $L['saldo_restante'] !== '') {
                $saldoNovo = (float) $L['saldo_restante'];
            } else {
                $cap = isset($L['qtd_total']) && $L['qtd_total'] !== null && $L['qtd_total'] !== ''
                    ? (float) $L['qtd_total']
                    : null;
                $qMed = isset($L['qtd_medido_periodo']) && $L['qtd_medido_periodo'] !== null && $L['qtd_medido_periodo'] !== ''
                    ? (float) $L['qtd_medido_periodo']
                    : null;
                if ($cap !== null && $qMed !== null) {
                    $saldoNovo = max(0.0, $cap - $qMed);
                }
            }
            if ($saldoNovo === null) {
                continue;
            }
            if ($saldoNovo < 0) {
                $saldoNovo = 0.0;
            }

            [, , , $codigoNorm] = repo_cliente_item_campos_normalizados('', null, 'UN', $cod);
            if ($codigoNorm === null || $codigoNorm === '') {
                continue;
            }
            $stFind = $pdo->prepare(
                'SELECT id, estoque_saldo FROM cliente_itens
                 WHERE (cliente_id = ? OR empresa_id = ?) AND codigo = ? AND ativo = 1
                 ORDER BY id ASC LIMIT 1'
            );
            $stFind->execute([$catalogoClienteId, $catalogoClienteId, $codigoNorm]);
            $row = $stFind->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $ret['codigos_sem_catalogo'][] = $cod;
                continue;
            }
            $iid     = (int) ($row['id'] ?? 0);
            $saldoAt = (float) ($row['estoque_saldo'] ?? 0);
            $ret['itens_processados']++;
            if ($iid > 0 && abs($saldoAt - $saldoNovo) > 1e-9) {
                $up->execute([$saldoNovo, $iid, $catalogoClienteId, $catalogoClienteId]);
                $ret['itens_alterados']++;
            }
        }
        $pdo->commit();
        $ret['ok'] = true;
        if ($ret['itens_alterados'] > 0) {
            audit_log_registar('catalogo.estoque.sync_bm', 'cliente', $catalogoClienteId, $catalogoClienteId, [
                'itens_processados' => $ret['itens_processados'],
                'itens_alterados'   => $ret['itens_alterados'],
            ]);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $ret['err'] = $e->getMessage();
    }

    return $ret;
}

/**
 * Recalcula estoque_saldo a partir de estoque_capacidade (referência) e lançamentos em chamados.
 *
 * @param list<int>|null $itemIds se informado, limita aos IDs; senão todo o catálogo da matriz
 * @return array{ok: bool, err: string, itens_processados: int, itens_alterados: int}
 */
function repo_catalogo_recalcular_estoque_saldo(int $clienteId, ?array $itemIds = null): array
{
    $ret = ['ok' => false, 'err' => '', 'itens_processados' => 0, 'itens_alterados' => 0];
    if (!repo_cliente_itens_estoque_saldo_column_exists()) {
        $ret['err'] = 'Controle de estoque não habilitado (migração 045).';

        return $ret;
    }
    $pdo = db();
    if (!$pdo || $clienteId <= 0) {
        $ret['err'] = 'Dados inválidos.';

        return $ret;
    }
    $catalogoClienteId = repo_cliente_catalogo_dono_id($clienteId);
    if ($catalogoClienteId <= 0) {
        $ret['err'] = 'Catálogo indisponível para esta empresa.';

        return $ret;
    }

    $temCap      = repo_cliente_itens_estoque_capacidade_column_exists();
    $temFluxo    = repo_cliente_itens_catalogo_fluxo_status_column_exists();
    $colCap      = $temCap ? 'it.estoque_capacidade' : 'CAST(0 AS DECIMAL(14,4))';
    $utilExpr    = $temFluxo
        ? "SUM(CASE WHEN ci.movimento = 'utilizado' AND (i.catalogo_fluxo_status IS NULL OR TRIM(i.catalogo_fluxo_status) <> 'Criado') THEN ci.quantidade ELSE 0 END)"
        : "SUM(CASE WHEN ci.movimento = 'utilizado' THEN ci.quantidade ELSE 0 END)";
    $devolvExpr  = $temFluxo
        ? "SUM(CASE WHEN ci.movimento = 'devolvido' OR (ci.movimento = 'utilizado' AND TRIM(i.catalogo_fluxo_status) = 'Criado') THEN ci.quantidade ELSE 0 END)"
        : "SUM(CASE WHEN ci.movimento = 'devolvido' THEN ci.quantidade ELSE 0 END)";

    $itemFilterIt = '';
    $itemFilterI  = '';
    $params       = [$catalogoClienteId, $catalogoClienteId];
    if ($itemIds !== null && $itemIds !== []) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            $ret['ok'] = true;

            return $ret;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $itemFilterIt = " AND it.id IN ($ph)";
        $itemFilterI  = " AND i.id IN ($ph)";
        $params       = array_merge($params, $ids);
    }

    try {
        $sqlItens = "
            SELECT it.id, it.estoque_saldo, {$colCap} AS estoque_capacidade
            FROM cliente_itens it
            WHERE (it.cliente_id = ? OR it.empresa_id = ?)
            {$itemFilterIt}
        ";
        $stItens = $pdo->prepare($sqlItens);
        $stItens->execute($params);
        $itens = $stItens->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($itens === []) {
            $ret['ok'] = true;

            return $ret;
        }

        $sqlAgg = "
            SELECT
                ci.item_id,
                {$utilExpr} AS qtd_utilizado,
                {$devolvExpr} AS qtd_devolvido
            FROM chamado_itens ci
            INNER JOIN cliente_itens i ON i.id = ci.item_id
            WHERE (i.cliente_id = ? OR i.empresa_id = ?)
            {$itemFilterI}
            GROUP BY ci.item_id
        ";
        $stAgg = $pdo->prepare($sqlAgg);
        $stAgg->execute($params);
        $aggPorItem = [];
        foreach ($stAgg->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $iid = (int) ($row['item_id'] ?? 0);
            if ($iid > 0) {
                $aggPorItem[$iid] = [
                    'util' => (float) ($row['qtd_utilizado'] ?? 0),
                    'dev'  => (float) ($row['qtd_devolvido'] ?? 0),
                ];
            }
        }

        $pdo->beginTransaction();
        $up = $pdo->prepare('UPDATE cliente_itens SET estoque_saldo = ? WHERE id = ? AND (cliente_id = ? OR empresa_id = ?) LIMIT 1');
        foreach ($itens as $it) {
            $iid      = (int) ($it['id'] ?? 0);
            $saldoAt  = (float) ($it['estoque_saldo'] ?? 0);
            $cap      = $temCap ? (float) ($it['estoque_capacidade'] ?? 0) : 0.0;
            $util     = (float) ($aggPorItem[$iid]['util'] ?? 0);
            $dev      = (float) ($aggPorItem[$iid]['dev'] ?? 0);
            $ret['itens_processados']++;

            if ($cap > 0) {
                $baseRef = $cap;
            } else {
                $baseRef = $saldoAt + $util - $dev;
                if ($baseRef < 0) {
                    $baseRef = 0.0;
                }
            }
            $saldoNovo = $baseRef - $util + $dev;
            if ($saldoNovo < 0) {
                $saldoNovo = 0.0;
            }

            if (abs($saldoAt - $saldoNovo) > 1e-9) {
                $up->execute([$saldoNovo, $iid, $catalogoClienteId, $catalogoClienteId]);
                $ret['itens_alterados']++;
            }
        }
        $pdo->commit();
        $ret['ok'] = true;
        if ($ret['itens_alterados'] > 0) {
            audit_log_registar('catalogo.estoque.recalcular', 'cliente', $catalogoClienteId, $catalogoClienteId, [
                'itens_processados' => $ret['itens_processados'],
                'itens_alterados'  => $ret['itens_alterados'],
                'filtro_item_ids'   => $itemIds !== null && $itemIds !== [],
            ]);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $ret['err'] = $e->getMessage();
    }

    return $ret;
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
    $colEstoque = repo_cliente_itens_estoque_saldo_column_exists() ? 'estoque_saldo' : '0 AS estoque_saldo';
    $colCap     = repo_cliente_itens_estoque_capacidade_column_exists()
        ? 'estoque_capacidade'
        : 'NULL AS estoque_capacidade';
    $colFluxo   = repo_cliente_itens_catalogo_fluxo_status_column_exists()
        ? 'catalogo_fluxo_status'
        : 'NULL AS catalogo_fluxo_status';
    $sql = 'SELECT id, cliente_id, empresa_id, tipo, nome, codigo, unidade, valor_unitario, ' . $colEstoque . ', ' . $colCap . ', descricao, ativo, ' . $colFluxo . ', ordem,
            DATE_FORMAT(criado_em, \'%Y-%m-%d %H:%i\') AS criado_em
            FROM cliente_itens WHERE cliente_id = ? OR empresa_id = ? OR cliente_id = ?';
    if ($somenteAtivos) {
        $sql .= ' AND ativo = 1';
    }
    $sql .= ' ORDER BY ordem ASC, tipo ASC, nome ASC';
    $st = $pdo->prepare($sql);
    $st->execute([$catalogoClienteId, $catalogoClienteId, $clienteId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id']              = (int) $r['id'];
        $r['cliente_id']      = (int) $r['cliente_id'];
        $r['empresa_id']      = isset($r['empresa_id']) && $r['empresa_id'] !== null ? (int) $r['empresa_id'] : null;
        $r['valor_unitario']      = (float) $r['valor_unitario'];
        $r['estoque_saldo']       = (float) ($r['estoque_saldo'] ?? 0);
        $r['estoque_capacidade']  = isset($r['estoque_capacidade']) && $r['estoque_capacidade'] !== null
            ? (float) $r['estoque_capacidade']
            : null;
        $r['ativo']               = (int) $r['ativo'];
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
    $st = $pdo->prepare(
        'SELECT * FROM cliente_itens WHERE id = ? AND (cliente_id = ? OR empresa_id = ? OR cliente_id = ?) LIMIT 1'
    );
    $st->execute([$itemId, $catalogoClienteId, $catalogoClienteId, $clienteId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        return null;
    }
    $r['id']             = (int) $r['id'];
    $r['cliente_id']     = (int) $r['cliente_id'];
    if (array_key_exists('empresa_id', $r)) {
        $r['empresa_id'] = $r['empresa_id'] !== null && $r['empresa_id'] !== '' ? (int) $r['empresa_id'] : null;
    }
    $r['valor_unitario']     = (float) $r['valor_unitario'];
    $r['estoque_saldo']      = (float) ($r['estoque_saldo'] ?? 0);
    $r['estoque_capacidade'] = isset($r['estoque_capacidade']) && $r['estoque_capacidade'] !== null
        ? (float) $r['estoque_capacidade']
        : null;
    $r['ativo']              = (int) $r['ativo'];

    return $r;
}

/**
 * Localiza item do catálogo pelo código (trim + comparação exata, depois UPPER).
 *
 * @return array{id: int, valor_unitario: float}|null
 */
function repo_cliente_item_row_por_codigo(int $clienteId, string $codigo, bool $apenasAtivos = true): ?array
{
    $pdo = db();
    if (!$pdo || $clienteId <= 0) {
        return null;
    }
    $donorId = repo_cliente_catalogo_dono_id($clienteId);
    if ($donorId <= 0) {
        return null;
    }
    [, , , $codigoNorm] = repo_cliente_item_campos_normalizados('', null, 'UN', $codigo);
    if ($codigoNorm === null || $codigoNorm === '') {
        return null;
    }

    $ativoSql = $apenasAtivos ? ' AND ativo = 1' : '';
    $params   = [$donorId, $donorId, $codigoNorm];

    $fetch = static function (PDO $pdo, string $sql, array $params): ?array {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    };

    $row = $fetch(
        $pdo,
        'SELECT id, valor_unitario FROM cliente_itens
         WHERE (cliente_id = ? OR empresa_id = ?) AND codigo = ?' . $ativoSql . '
         ORDER BY id ASC LIMIT 1',
        $params
    );
    if ($row === null) {
        $row = $fetch(
            $pdo,
            'SELECT id, valor_unitario FROM cliente_itens
             WHERE (cliente_id = ? OR empresa_id = ?) AND codigo IS NOT NULL
               AND UPPER(TRIM(codigo)) = UPPER(?)' . $ativoSql . '
             ORDER BY id ASC LIMIT 1',
            $params
        );
    }
    if ($row === null) {
        return null;
    }

    return [
        'id'              => (int) ($row['id'] ?? 0),
        'valor_unitario'  => (float) ($row['valor_unitario'] ?? 0),
    ];
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
    int $ativo,
    float $estoqueCapacidade = 0.0,
    ?float $estoqueSaldoInicial = null
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
    if ($codigo !== null && $codigo !== '') {
        $dupRow = repo_cliente_item_row_por_codigo($clienteId, $codigo, false);
        if ($dupRow !== null) {
            $dupId = (int) ($dupRow['id'] ?? 0);
            if ($id === null || $id <= 0 || $dupId !== $id) {
                return ['ok' => false, 'err' => 'Já existe um item com este código (ID) no catálogo.', 'id' => null];
            }
        }
    }
    $ativo = 1;
    $temEstoque = repo_cliente_itens_estoque_saldo_column_exists();
    $temCap     = repo_cliente_itens_estoque_capacidade_column_exists();
    try {
        if ($id !== null && $id > 0) {
            $chk = $pdo->prepare(
                'SELECT id, estoque_capacidade, estoque_saldo FROM cliente_itens
                 WHERE id = ? AND (cliente_id = ? OR empresa_id = ?) LIMIT 1'
            );
            $chk->execute([$id, $clienteId, $clienteId]);
            $rowAntes = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$rowAntes) {
                return ['ok' => false, 'err' => 'Item não encontrado.', 'id' => null];
            }
            $saldoAnt = (float) ($rowAntes['estoque_saldo'] ?? 0);
            $capAnt   = (float) ($rowAntes['estoque_capacidade'] ?? 0);
            $capMudou = abs($capAnt - $estoqueCapacidade) > 1e-9;

            $saldoParaGravar = null;
            if ($temEstoque) {
                if ($estoqueSaldoInicial !== null) {
                    if ($capMudou && abs($estoqueSaldoInicial - $saldoAnt) < 1e-9) {
                        $saldoParaGravar = $saldoAnt + ($estoqueCapacidade - $capAnt);
                    } else {
                        $saldoParaGravar = $estoqueSaldoInicial;
                    }
                } elseif ($capMudou) {
                    $saldoParaGravar = $saldoAnt + ($estoqueCapacidade - $capAnt);
                }
            }
            $saldoDeltaPorEstoque = $saldoParaGravar !== null
                && $capMudou
                && ($estoqueSaldoInicial === null || abs($estoqueSaldoInicial - $saldoAnt) < 1e-9);

            if ($temEstoque && $temCap && $saldoParaGravar !== null) {
                $st = $pdo->prepare('
                    UPDATE cliente_itens
                    SET tipo = ?, nome = ?, codigo = ?, unidade = ?, valor_unitario = ?, estoque_capacidade = ?, estoque_saldo = ?, descricao = ?, ativo = ?
                    WHERE id = ? AND (cliente_id = ? OR empresa_id = ?)
                ');
                $ok = $st->execute([$tipo, $nome, $codigo, $unidade, $valorUnitario, $estoqueCapacidade, $saldoParaGravar, $desc, $ativo, $id, $clienteId, $clienteId]);
            } elseif ($temEstoque && $temCap) {
                $st = $pdo->prepare('
                    UPDATE cliente_itens
                    SET tipo = ?, nome = ?, codigo = ?, unidade = ?, valor_unitario = ?, estoque_capacidade = ?, descricao = ?, ativo = ?
                    WHERE id = ? AND (cliente_id = ? OR empresa_id = ?)
                ');
                $ok = $st->execute([$tipo, $nome, $codigo, $unidade, $valorUnitario, $estoqueCapacidade, $desc, $ativo, $id, $clienteId, $clienteId]);
            } elseif ($temEstoque && $saldoParaGravar !== null) {
                $st = $pdo->prepare('
                    UPDATE cliente_itens
                    SET tipo = ?, nome = ?, codigo = ?, unidade = ?, valor_unitario = ?, estoque_saldo = ?, descricao = ?, ativo = ?
                    WHERE id = ? AND (cliente_id = ? OR empresa_id = ?)
                ');
                $ok = $st->execute([$tipo, $nome, $codigo, $unidade, $valorUnitario, $saldoParaGravar, $desc, $ativo, $id, $clienteId, $clienteId]);
            } elseif ($temEstoque) {
                $st = $pdo->prepare('
                    UPDATE cliente_itens
                    SET tipo = ?, nome = ?, codigo = ?, unidade = ?, valor_unitario = ?, descricao = ?, ativo = ?
                    WHERE id = ? AND (cliente_id = ? OR empresa_id = ?)
                ');
                $ok = $st->execute([$tipo, $nome, $codigo, $unidade, $valorUnitario, $desc, $ativo, $id, $clienteId, $clienteId]);
            } else {
                $st = $pdo->prepare('
                    UPDATE cliente_itens
                    SET tipo = ?, nome = ?, codigo = ?, unidade = ?, valor_unitario = ?, descricao = ?, ativo = ?
                    WHERE id = ? AND (cliente_id = ? OR empresa_id = ?)
                ');
                $ok = $st->execute([$tipo, $nome, $codigo, $unidade, $valorUnitario, $desc, $ativo, $id, $clienteId, $clienteId]);
            }

            if ($ok && $temCap && $capMudou) {
                audit_log_registar('catalogo.item.estoque_alterar', 'cliente_item', $id, $clienteId, [
                    'item_id'          => $id,
                    'nome'             => $nome,
                    'codigo'           => $codigo,
                    'estoque_anterior' => $capAnt,
                    'estoque_novo'     => $estoqueCapacidade,
                ]);
            }
            if ($ok && $temEstoque && $saldoParaGravar !== null && abs($saldoAnt - $saldoParaGravar) > 1e-9) {
                $auditSaldo = [
                    'item_id'        => $id,
                    'nome'           => $nome,
                    'codigo'         => $codigo,
                    'saldo_anterior' => $saldoAnt,
                    'saldo_novo'     => $saldoParaGravar,
                ];
                if ($saldoDeltaPorEstoque) {
                    $auditSaldo['motivo']            = 'ajuste_delta_estoque';
                    $auditSaldo['estoque_anterior'] = $capAnt;
                    $auditSaldo['estoque_novo']     = $estoqueCapacidade;
                }
                audit_log_registar('catalogo.item.saldo_alterar', 'cliente_item', $id, $clienteId, $auditSaldo);
            }

            return ['ok' => (bool) $ok, 'err' => $ok ? '' : 'Falha ao atualizar.', 'id' => $ok ? $id : null];
        }
        if ($temEstoque) {
            $saldoIni = $estoqueSaldoInicial ?? $estoqueCapacidade;
            if ($temCap) {
                $st = $pdo->prepare('
                    INSERT INTO cliente_itens (cliente_id, empresa_id, tipo, nome, codigo, unidade, valor_unitario, estoque_saldo, estoque_capacidade, descricao, ativo, ordem)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,0)
                ');
                $ok = $st->execute([$clienteId, $clienteId, $tipo, $nome, $codigo, $unidade, $valorUnitario, $saldoIni, $estoqueCapacidade, $desc, $ativo]);
            } else {
                $st = $pdo->prepare('
                    INSERT INTO cliente_itens (cliente_id, empresa_id, tipo, nome, codigo, unidade, valor_unitario, estoque_saldo, descricao, ativo, ordem)
                    VALUES (?,?,?,?,?,?,?,?,?,?,0)
                ');
                $ok = $st->execute([$clienteId, $clienteId, $tipo, $nome, $codigo, $unidade, $valorUnitario, $saldoIni, $desc, $ativo]);
            }
            $nid = (int) $pdo->lastInsertId();
            if ($ok && $nid > 0) {
                audit_log_registar('catalogo.item.criar', 'cliente_item', $nid, $clienteId, [
                    'item_id'       => $nid,
                    'nome'          => $nome,
                    'codigo'        => $codigo,
                    'tipo'          => $tipo,
                    'estoque'       => $estoqueCapacidade,
                    'saldo_inicial' => $saldoIni,
                ]);
            }

            return ['ok' => (bool) $ok, 'err' => $ok ? '' : 'Falha ao inserir.', 'id' => $ok ? $nid : null];
        } else {
            $st = $pdo->prepare('
                INSERT INTO cliente_itens (cliente_id, empresa_id, tipo, nome, codigo, unidade, valor_unitario, descricao, ativo, ordem)
                VALUES (?,?,?,?,?,?,?,?,?,0)
            ');
            $ok = $st->execute([$clienteId, $clienteId, $tipo, $nome, $codigo, $unidade, $valorUnitario, $desc, $ativo]);
        }
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

/**
 * Gestor/admin: cria produto no catálogo (status de fluxo «Criado») e lança recolhimento no chamado.
 *
 * @return array{ok: bool, err: string, item_id: ?int, linha_id: ?int}
 */
function repo_chamado_item_devolutivo_criar_direto(
    int $chamadoId,
    string $nome,
    ?string $codigo,
    float $quantidade,
    ?string $observacao,
    string $fluxoStatus = 'Criado'
): array {
    $nome = trim($nome);
    if ($chamadoId <= 0 || $nome === '') {
        return ['ok' => false, 'err' => 'Informe o nome do item devolutivo.', 'item_id' => null, 'linha_id' => null];
    }
    if ($quantidade <= 0) {
        return ['ok' => false, 'err' => 'Informe uma quantidade válida.', 'item_id' => null, 'linha_id' => null];
    }
    $ch = repo_chamado($chamadoId);
    if (!$ch) {
        return ['ok' => false, 'err' => 'Chamado não encontrado.', 'item_id' => null, 'linha_id' => null];
    }
    $clienteId = (int) ($ch['cliente_id'] ?? 0);
    if ($clienteId <= 0) {
        return ['ok' => false, 'err' => 'Cliente do chamado inválido.', 'item_id' => null, 'linha_id' => null];
    }
    $codigo = trim((string) $codigo);
    $codigo = $codigo !== '' ? $codigo : null;
    $obs    = trim((string) $observacao);
    $obsVal = $obs !== '' ? $obs : null;

    $rCat = repo_cliente_item_salvar(
        $clienteId,
        null,
        'produto',
        $nome,
        $codigo,
        'UN',
        0.0,
        null,
        1,
        0.0
    );
    if (!$rCat['ok'] || empty($rCat['id'])) {
        return ['ok' => false, 'err' => (string) ($rCat['err'] ?? 'Não foi possível criar o item no catálogo.'), 'item_id' => null, 'linha_id' => null];
    }
    $itemId = (int) $rCat['id'];
    repo_cliente_item_set_catalogo_fluxo_status($itemId, $fluxoStatus);

    $rLinha = repo_chamado_item_adicionar($chamadoId, $itemId, $quantidade, 'devolvido', $obsVal);
    if (!$rLinha['ok']) {
        return ['ok' => false, 'err' => (string) ($rLinha['err'] ?? 'Item criado no catálogo, mas não foi possível lançar no chamado.'), 'item_id' => $itemId, 'linha_id' => null];
    }

    audit_log_registar('chamado.item_devolutivo.criar', 'chamado', $chamadoId, $clienteId > 0 ? $clienteId : null, [
        'item_id'    => $itemId,
        'nome'       => $nome,
        'codigo'     => $codigo,
        'qtd'        => $quantidade,
        'obs'        => $obs,
        'fluxo_status' => $fluxoStatus,
    ]);

    return ['ok' => true, 'err' => '', 'item_id' => $itemId, 'linha_id' => null];
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
 * Grava linhas normalizadas do catálogo (importação CSV/XLSX).
 *
 * @param list<array{tipo?: string, nome?: string, codigo?: string, unidade?: string, valor_unitario?: float|int|string, estoque_capacidade?: float|int|string, estoque_saldo?: float|int|string, saldo_informado?: bool, descricao?: string, _linha_plan?: int}> $linhas
 * @return array{inseridos: int, ignorados: int, erros: list<string>}
 */
function repo_cliente_itens_importar_linhas(int $clienteId, array $linhas): array
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

    foreach ($linhas as $lin) {
        $nlinha = (int) ($lin['_linha_plan'] ?? 0);
        $label  = $nlinha > 0 ? "Linha $nlinha" : 'Linha';

        $tipo = strtolower(trim((string) ($lin['tipo'] ?? '')));
        $nome = trim((string) ($lin['nome'] ?? ''));
        if (!in_array($tipo, ['produto', 'servico'], true)) {
            $ret['ignorados']++;
            $ret['erros'][] = "$label: tipo deve ser produto ou servico.";

            continue;
        }
        if ($nome === '') {
            $ret['ignorados']++;

            continue;
        }

        $codigo = trim((string) ($lin['codigo'] ?? ''));
        $unid   = trim((string) ($lin['unidade'] ?? 'UN'));
        $vu      = (float) ($lin['valor_unitario'] ?? 0);
        $estCap  = (float) ($lin['estoque_capacidade'] ?? $lin['estoque_saldo'] ?? 0);
        $estSaldo = !empty($lin['saldo_informado'])
            ? (float) ($lin['estoque_saldo'] ?? 0)
            : $estCap;
        $desc    = trim((string) ($lin['descricao'] ?? ''));

        $r = repo_cliente_item_salvar(
            $clienteId,
            null,
            $tipo,
            $nome,
            $codigo !== '' ? $codigo : null,
            $unid !== '' ? $unid : 'UN',
            $vu,
            $desc !== '' ? $desc : null,
            1,
            $estCap,
            $estSaldo
        );
        if ($r['ok']) {
            $ret['inseridos']++;
        } else {
            $ret['ignorados']++;
            $ret['erros'][] = "$label: " . ($r['err'] ?: 'falha');
        }
    }

    return $ret;
}

/**
 * Importa CSV/TSV (cabeçalho: tipo,nome,codigo,unidade,valor_unitario,estoque_saldo,descricao).
 *
 * @return array{inseridos: int, ignorados: int, erros: list<string>}
 */
function repo_cliente_itens_importar_csv(int $clienteId, string $conteudo): array
{
    require_once __DIR__ . '/catalogo_import.php';
    $parse = catalogo_import_parse_csv_content($conteudo);
    if (!$parse['ok']) {
        return [
            'inseridos' => 0,
            'ignorados' => 0,
            'erros'     => array_filter(array_merge(
                [$parse['erro'] !== '' ? $parse['erro'] : 'Falha ao interpretar CSV.'],
                $parse['avisos']
            )),
        ];
    }

    $ret = repo_cliente_itens_importar_linhas($clienteId, $parse['linhas']);
    if ($parse['avisos'] !== []) {
        $ret['erros'] = array_merge($parse['avisos'], $ret['erros']);
    }

    return $ret;
}

/**
 * Valor unitário e subtotal efetivos da linha (fallback ao catálogo quando gravado zerado).
 *
 * @return array{0: float, 1: float} [valor_unitario, subtotal]
 */
function repo_chamado_item_valores_resolvidos(float $vuLinha, float $subLinha, float $qtd, float $vuCatalogo): array
{
    $vu = $vuLinha > 0 ? $vuLinha : ($vuCatalogo > 0 ? $vuCatalogo : 0.0);
    if ($vu > 0 && $qtd > 0) {
        return [$vu, round($qtd * $vu, 4)];
    }
    $sub = $subLinha > 0 ? $subLinha : 0.0;

    return [$vu, $sub];
}

/**
 * Movimento efetivo para listagem (corrige linhas de catálogo «Criado» gravadas como utilizado).
 */
function repo_chamado_item_movimento_efetivo(array $row): string
{
    $m = strtolower(trim((string) ($row['movimento'] ?? 'utilizado')));
    if ($m === 'devolvido') {
        return 'devolvido';
    }
    if (repo_cliente_itens_catalogo_fluxo_status_column_exists()
        && trim((string) ($row['catalogo_fluxo_status'] ?? '')) === 'Criado') {
        return 'devolvido';
    }

    return 'utilizado';
}

/**
 * Persiste movimento devolvido em linhas ligadas a itens de catálogo criados como devolutivo.
 */
function repo_chamado_itens_sync_movimento_devolutivo_catalogo(int $chamadoId): void
{
    if ($chamadoId <= 0 || !repo_cliente_itens_catalogo_fluxo_status_column_exists()) {
        return;
    }
    $pdo = db();
    if (!$pdo) {
        return;
    }
    try {
        $st = $pdo->prepare("
            SELECT ci.id, ci.item_id, ci.quantidade
            FROM chamado_itens ci
            INNER JOIN cliente_itens i ON i.id = ci.item_id
            WHERE ci.chamado_id = ?
              AND i.catalogo_fluxo_status = 'Criado'
              AND ci.movimento = 'utilizado'
        ");
        $st->execute([$chamadoId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return;
        }
        $pdo->beginTransaction();
        $up = $pdo->prepare("UPDATE chamado_itens SET movimento = 'devolvido' WHERE id = ? AND chamado_id = ? LIMIT 1");
        foreach ($rows as $row) {
            $lid = (int) ($row['id'] ?? 0);
            $iid = (int) ($row['item_id'] ?? 0);
            $qtd = (float) ($row['quantidade'] ?? 0);
            if ($lid <= 0 || $iid <= 0 || $qtd <= 0) {
                continue;
            }
            $up->execute([$lid, $chamadoId]);
            $deltaCorrecao = repo_cliente_item_estoque_delta_por_movimento('devolvido', $qtd)
                - repo_cliente_item_estoque_delta_por_movimento('utilizado', $qtd);
            repo_cliente_item_aplicar_estoque_delta($pdo, $iid, $deltaCorrecao);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[crm_prefeitura] repo_chamado_itens_sync_movimento_devolutivo_catalogo: ' . $e->getMessage());
    }
}

/**
 * Preenche valor_unitario/subtotal zerados a partir do catálogo (linhas antigas ou devolutivo sem preço).
 */
function repo_chamado_itens_backfill_valores_catalogo(int $chamadoId): void
{
    $pdo = db();
    if (!$pdo || $chamadoId <= 0) {
        return;
    }
    try {
        $st = $pdo->prepare('
            UPDATE chamado_itens ci
            INNER JOIN cliente_itens i ON i.id = ci.item_id
            SET ci.valor_unitario = i.valor_unitario,
                ci.subtotal = ROUND(ci.quantidade * i.valor_unitario, 4)
            WHERE ci.chamado_id = ?
              AND i.valor_unitario > 0
              AND (ci.valor_unitario IS NULL OR ci.valor_unitario <= 0 OR ci.subtotal IS NULL OR ci.subtotal <= 0)
        ');
        $st->execute([$chamadoId]);
    } catch (Throwable $e) {
        error_log('[crm_prefeitura] repo_chamado_itens_backfill_valores_catalogo: ' . $e->getMessage());
    }
}

/** Recalcula subtotal = quantidade × valor unitário (linhas com preço definido). */
function repo_chamado_itens_sync_subtotais(int $chamadoId): void
{
    $pdo = db();
    if (!$pdo || $chamadoId <= 0) {
        return;
    }
    try {
        $st = $pdo->prepare('
            UPDATE chamado_itens ci
            INNER JOIN cliente_itens i ON i.id = ci.item_id
            SET ci.valor_unitario = IF(ci.valor_unitario > 0, ci.valor_unitario, i.valor_unitario),
                ci.subtotal = ROUND(
                    ci.quantidade * IF(ci.valor_unitario > 0, ci.valor_unitario, i.valor_unitario),
                    4
                )
            WHERE ci.chamado_id = ?
              AND IF(ci.valor_unitario > 0, ci.valor_unitario, i.valor_unitario) > 0
        ');
        $st->execute([$chamadoId]);
    } catch (Throwable $e) {
        error_log('[crm_prefeitura] repo_chamado_itens_sync_subtotais: ' . $e->getMessage());
    }
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
    repo_chamado_itens_backfill_valores_catalogo($chamadoId);
    repo_chamado_itens_sync_subtotais($chamadoId);
    repo_chamado_itens_sync_movimento_devolutivo_catalogo($chamadoId);
    $colFluxo = repo_cliente_itens_catalogo_fluxo_status_column_exists()
        ? 'i.catalogo_fluxo_status'
        : "NULL AS catalogo_fluxo_status";
    $st = $pdo->prepare("
        SELECT ci.id, ci.chamado_id, ci.item_id, ci.movimento, ci.quantidade, ci.valor_unitario, ci.subtotal, ci.observacao,
               i.nome AS item_nome, i.tipo AS item_tipo, i.codigo AS item_codigo, i.unidade AS catalogo_unidade,
               i.descricao AS item_descricao, i.valor_unitario AS catalogo_valor_unitario,
               {$colFluxo}
        FROM chamado_itens ci
        INNER JOIN cliente_itens i ON i.id = ci.item_id
        WHERE ci.chamado_id = ?
        ORDER BY ci.id ASC
    ");
    $st->execute([$chamadoId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id']             = (int) $r['id'];
        $r['chamado_id']     = (int) $r['chamado_id'];
        $r['item_id']        = (int) $r['item_id'];
        $r['movimento']             = repo_chamado_item_movimento_efetivo($r);
        $r['catalogo_fluxo_status'] = trim((string) ($r['catalogo_fluxo_status'] ?? ''));
        $r['quantidade']            = (float) $r['quantidade'];
        $catVu               = (float) ($r['catalogo_valor_unitario'] ?? 0);
        [$vu, $sub]          = repo_chamado_item_valores_resolvidos(
            (float) ($r['valor_unitario'] ?? 0),
            (float) ($r['subtotal'] ?? 0),
            (float) ($r['quantidade'] ?? 0),
            $catVu
        );
        $r['valor_unitario'] = $vu;
        $r['subtotal']       = $sub;
        unset($r['catalogo_valor_unitario']);
    }
    unset($r);

    return $rows;
}

function repo_chamado_itens_valor_total(int $chamadoId): float
{
    if ($chamadoId <= 0) {
        return 0.0;
    }
    $total = 0.0;
    foreach (repo_chamado_itens_list($chamadoId) as $row) {
        if (repo_chamado_item_movimento_efetivo($row) === 'devolvido') {
            continue;
        }
        $total += (float) ($row['subtotal'] ?? 0);
    }

    return $total;
}

/**
 * @return array{ok: bool, err: string}
 */
function repo_chamado_item_adicionar(
    int $chamadoId,
    int $itemId,
    float $quantidade,
    string $movimento = 'utilizado',
    ?string $observacao = null,
    ?float $valorUnitarioInformado = null,
    ?float $subtotalInformado = null
): array
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
    $ativoOk = $it && (int) ($it['ativo'] ?? 0) === 1;
    if (!$ativoOk) {
        return ['ok' => false, 'err' => 'Item não disponível para este cliente.'];
    }
    $tipoItem = strtolower(trim((string) ($it['tipo'] ?? '')));
    if ($movimento === 'devolvido' && $tipoItem !== 'produto') {
        return ['ok' => false, 'err' => 'Recolhimentos aceitam apenas produtos (materiais) do catálogo.'];
    }
    if (repo_cliente_itens_catalogo_fluxo_status_column_exists()
        && trim((string) ($it['catalogo_fluxo_status'] ?? '')) === 'Criado') {
        $movimento = 'devolvido';
    }
    $vuCat = (float) ($it['valor_unitario'] ?? 0);
    $vuInf = $valorUnitarioInformado !== null ? (float) $valorUnitarioInformado : 0.0;
    $subInf = $subtotalInformado !== null ? (float) $subtotalInformado : 0.0;
    [$vu, $sub] = repo_chamado_item_valores_resolvidos($vuInf, $subInf, $quantidade, $vuCat);
    $criadoPor = repo_usuario_sessao_id();
    $obs = trim((string) $observacao);
    $obsVal = $obs !== '' ? $obs : null;
    try {
        $pdo->beginTransaction();
        if (repo_chamado_itens_tem_criado_por()) {
            $st = $pdo->prepare('
                INSERT INTO chamado_itens
                    (chamado_id, item_id, movimento, quantidade, valor_unitario, subtotal, observacao, criado_por_user_id)
                VALUES (?,?,?,?,?,?,?,?)
            ');
            $ok = $st->execute([
                $chamadoId, $itemId, $movimento, $quantidade, $vu, $sub,
                $obsVal,
                $criadoPor,
            ]);
        } else {
            $st = $pdo->prepare('
                INSERT INTO chamado_itens (chamado_id, item_id, movimento, quantidade, valor_unitario, subtotal, observacao)
                VALUES (?,?,?,?,?,?,?)
            ');
            $ok = $st->execute([$chamadoId, $itemId, $movimento, $quantidade, $vu, $sub, $obsVal]);
        }
        if (!$ok) {
            $pdo->rollBack();

            return ['ok' => false, 'err' => 'Não foi possível inserir.'];
        }
        $linhaId = (int) $pdo->lastInsertId();
        repo_cliente_item_aplicar_estoque_delta(
            $pdo,
            $itemId,
            repo_cliente_item_estoque_delta_por_movimento($movimento, $quantidade)
        );
        $pdo->commit();
        audit_log_registar('chamado.item.adicionar', 'chamado', $chamadoId, $cid > 0 ? $cid : null, [
            'linha_id'   => $linhaId,
            'item_id'    => $itemId,
            'item_nome'  => (string) ($it['nome'] ?? ''),
            'movimento'  => $movimento,
            'quantidade' => rtrim(rtrim(sprintf('%.4f', $quantidade), '0'), '.'),
        ]);

        return ['ok' => true, 'err' => ''];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'err' => $e->getMessage()];
    }
}

function repo_chamado_item_atualizar_quantidade(int $linhaId, int $chamadoId, float $quantidade): bool
{
    $pdo = db();
    if (!$pdo || $linhaId <= 0 || $chamadoId <= 0 || $quantidade <= 0) {
        return false;
    }
    $st = $pdo->prepare('
        SELECT ci.item_id, ci.quantidade, ci.valor_unitario, ci.subtotal, ci.movimento,
               i.nome AS item_nome, i.valor_unitario AS catalogo_valor_unitario, ch.cliente_id
        FROM chamado_itens ci
        INNER JOIN cliente_itens i ON i.id = ci.item_id
        INNER JOIN chamados ch ON ch.id = ci.chamado_id
        WHERE ci.id = ? AND ci.chamado_id = ?
        LIMIT 1
    ');
    $st->execute([$linhaId, $chamadoId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    $itemId   = (int) ($row['item_id'] ?? 0);
    $qtdAnt   = (float) ($row['quantidade'] ?? 0);
    $mov      = (string) ($row['movimento'] ?? 'utilizado');
    [$vu, $sub] = repo_chamado_item_valores_resolvidos(
        (float) ($row['valor_unitario'] ?? 0),
        (float) ($row['subtotal'] ?? 0),
        $quantidade,
        (float) ($row['catalogo_valor_unitario'] ?? 0)
    );
    $deltaEst = repo_cliente_item_estoque_delta_por_movimento($mov, $quantidade - $qtdAnt);
    try {
        $pdo->beginTransaction();
        $up = $pdo->prepare('UPDATE chamado_itens SET quantidade = ?, valor_unitario = ?, subtotal = ? WHERE id = ? AND chamado_id = ?');
        $ok = (bool) $up->execute([$quantidade, $vu, $sub, $linhaId, $chamadoId]);
        if (!$ok) {
            $pdo->rollBack();

            return false;
        }
        repo_cliente_item_aplicar_estoque_delta($pdo, $itemId, $deltaEst);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return false;
    }
    $cid = (int) ($row['cliente_id'] ?? 0);
    audit_log_registar('chamado.item.alterar', 'chamado', $chamadoId, $cid > 0 ? $cid : null, [
        'linha_id'   => $linhaId,
        'item_nome'  => (string) ($row['item_nome'] ?? ''),
        'movimento'  => $mov,
        'quantidade' => rtrim(rtrim(sprintf('%.4f', $quantidade), '0'), '.'),
    ]);

    return true;
}

/**
 * Atualiza quantidade e observação de uma linha (mantém valor unitário gravado).
 */
function repo_chamado_item_atualizar_linha(int $linhaId, int $chamadoId, float $quantidade, ?string $observacao): bool
{
    $pdo = db();
    if (!$pdo || $linhaId <= 0 || $chamadoId <= 0 || $quantidade <= 0) {
        return false;
    }
    $st = $pdo->prepare('
        SELECT ci.item_id, ci.quantidade, ci.valor_unitario, ci.subtotal, ci.movimento,
               i.nome AS item_nome, i.valor_unitario AS catalogo_valor_unitario, ch.cliente_id
        FROM chamado_itens ci
        INNER JOIN cliente_itens i ON i.id = ci.item_id
        INNER JOIN chamados ch ON ch.id = ci.chamado_id
        WHERE ci.id = ? AND ci.chamado_id = ?
        LIMIT 1
    ');
    $st->execute([$linhaId, $chamadoId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    $itemId   = (int) ($row['item_id'] ?? 0);
    $qtdAnt   = (float) ($row['quantidade'] ?? 0);
    $mov      = (string) ($row['movimento'] ?? 'utilizado');
    [$vu, $sub] = repo_chamado_item_valores_resolvidos(
        (float) ($row['valor_unitario'] ?? 0),
        (float) ($row['subtotal'] ?? 0),
        $quantidade,
        (float) ($row['catalogo_valor_unitario'] ?? 0)
    );
    $obs      = trim((string) $observacao);
    $deltaEst = repo_cliente_item_estoque_delta_por_movimento($mov, $quantidade - $qtdAnt);
    try {
        $pdo->beginTransaction();
        $up = $pdo->prepare('UPDATE chamado_itens SET quantidade = ?, valor_unitario = ?, subtotal = ?, observacao = ? WHERE id = ? AND chamado_id = ?');
        $ok = (bool) $up->execute([$quantidade, $vu, $sub, $obs !== '' ? $obs : null, $linhaId, $chamadoId]);
        if (!$ok) {
            $pdo->rollBack();

            return false;
        }
        repo_cliente_item_aplicar_estoque_delta($pdo, $itemId, $deltaEst);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return false;
    }
    $cid = (int) ($row['cliente_id'] ?? 0);
    audit_log_registar('chamado.item.alterar', 'chamado', $chamadoId, $cid > 0 ? $cid : null, [
        'linha_id'   => $linhaId,
        'item_nome'  => (string) ($row['item_nome'] ?? ''),
        'movimento'  => $mov,
        'quantidade' => rtrim(rtrim(sprintf('%.4f', $quantidade), '0'), '.'),
    ]);

    return true;
}

function repo_chamado_item_remover(int $linhaId, int $chamadoId): bool
{
    $pdo = db();
    if (!$pdo || $linhaId <= 0 || $chamadoId <= 0) {
        return false;
    }
    $st = $pdo->prepare('
        SELECT ci.item_id, ci.quantidade, ci.movimento, i.nome AS item_nome, ch.cliente_id
        FROM chamado_itens ci
        INNER JOIN cliente_itens i ON i.id = ci.item_id
        INNER JOIN chamados ch ON ch.id = ci.chamado_id
        WHERE ci.id = ? AND ci.chamado_id = ?
        LIMIT 1
    ');
    $st->execute([$linhaId, $chamadoId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    $itemId = (int) ($row['item_id'] ?? 0);
    $qtd    = (float) ($row['quantidade'] ?? 0);
    $mov    = (string) ($row['movimento'] ?? 'utilizado');
    $deltaEst = -repo_cliente_item_estoque_delta_por_movimento($mov, $qtd);
    try {
        $pdo->beginTransaction();
        $del = $pdo->prepare('DELETE FROM chamado_itens WHERE id = ? AND chamado_id = ? LIMIT 1');
        $ok  = (bool) $del->execute([$linhaId, $chamadoId]);
        if (!$ok) {
            $pdo->rollBack();

            return false;
        }
        repo_cliente_item_aplicar_estoque_delta($pdo, $itemId, $deltaEst);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return false;
    }
    $cid = (int) ($row['cliente_id'] ?? 0);
    audit_log_registar('chamado.item.remover', 'chamado', $chamadoId, $cid > 0 ? $cid : null, [
        'linha_id'  => $linhaId,
        'item_nome' => (string) ($row['item_nome'] ?? ''),
        'movimento' => $mov,
    ]);

    return true;
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
 * Gestor vincula um chamado a um ou mais técnicos/operadores da mesma empresa.
 *
 * @param list<int> $tecnicoUserIds
 * @return array{ok: bool, err: string}
 */
function repo_chamado_atribuir_tecnicos(int $chamadoId, array $tecnicoUserIds, int $empresaRaizId): array
{
    $pdo = db();
    if (!$pdo || $chamadoId <= 0 || $empresaRaizId <= 0) {
        return ['ok' => false, 'err' => 'Dados inválidos.'];
    }
    if (!repo_chamado_acessivel_operador_empresa($chamadoId, $empresaRaizId)) {
        return ['ok' => false, 'err' => 'Chamado fora da empresa.'];
    }

    $ids = [];
    foreach ($tecnicoUserIds as $tid) {
        $tid = (int) $tid;
        if ($tid > 0 && !in_array($tid, $ids, true)) {
            $ids[] = $tid;
        }
    }
    if (!empty($ids)) {
        $stAtual = $pdo->prepare('SELECT tecnico_user_id FROM chamados WHERE id = ? LIMIT 1');
        $stAtual->execute([$chamadoId]);
        $principalAtual = (int) ($stAtual->fetchColumn() ?: 0);
        if ($principalAtual > 0 && in_array($principalAtual, $ids, true)) {
            $ids = array_values(array_merge([$principalAtual], array_values(array_diff($ids, [$principalAtual]))));
        }
    }
    if (count($ids) > 1 && !repo_chamado_tecnicos_table_exists()) {
        return ['ok' => false, 'err' => 'Migração chamado_tecnicos pendente para múltiplos técnicos.'];
    }

    $tecnicos = [];
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = $ids;
        $params[] = $empresaRaizId;
        $st = $pdo->prepare("
            SELECT id, nome
            FROM usuarios
            WHERE id IN ($placeholders) AND perfil = 'operador' AND empresa_id = ?
        ");
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $tecnicos[(int) $row['id']] = (string) ($row['nome'] ?? '');
        }
        if (count($tecnicos) !== count($ids)) {
            return ['ok' => false, 'err' => 'Técnico inválido para esta empresa.'];
        }
    }

    $principalId = $ids[0] ?? null;
    $nomes = [];
    foreach ($ids as $tid) {
        $nome = trim((string) ($tecnicos[$tid] ?? ''));
        if ($nome !== '') {
            $nomes[] = $nome;
        }
    }
    $responsavel = !empty($nomes) ? implode(', ', $nomes) : null;
    $statusSql = $principalId !== null ? ", status = IF(status = 'Aberto', 'Em andamento', status)" : '';

    $idsNovos = $ids;
    try {
        $pdo->beginTransaction();
        if (repo_chamado_tecnicos_table_exists()) {
            $oldIds = [];
            $stOld = $pdo->prepare('SELECT usuario_id FROM chamado_tecnicos WHERE chamado_id = ?');
            $stOld->execute([$chamadoId]);
            foreach ($stOld->fetchAll(PDO::FETCH_COLUMN) ?: [] as $oid) {
                $oldIds[] = (int) $oid;
            }
            $idsNovos = array_values(array_diff($ids, $oldIds));
            $stDel = $pdo->prepare('DELETE FROM chamado_tecnicos WHERE chamado_id = ?');
            $stDel->execute([$chamadoId]);
            if (!empty($ids)) {
                $stIns = $pdo->prepare('INSERT INTO chamado_tecnicos (chamado_id, usuario_id) VALUES (?, ?)');
                foreach ($ids as $tid) {
                    $stIns->execute([$chamadoId, $tid]);
                }
            }
        }
        $st = $pdo->prepare("
            UPDATE chamados
            SET tecnico_user_id = ?,
                responsavel = ?
                $statusSql
            WHERE id = ?
        ");
        $st->execute([$principalId, $responsavel, $chamadoId]);
        $pdo->commit();

        $stCid = $pdo->prepare('SELECT cliente_id FROM chamados WHERE id = ? LIMIT 1');
        $stCid->execute([$chamadoId]);
        $cidTec = (int) ($stCid->fetchColumn() ?: 0);
        audit_log_registar('chamado.tecnico.atribuir', 'chamado', $chamadoId, $cidTec > 0 ? $cidTec : null, [
            'tecnicos'    => $nomes,
            'responsavel' => (string) ($responsavel ?? ''),
            'tecnico_ids' => $ids,
        ]);
        if ($idsNovos !== []) {
            require_once __DIR__ . '/notificacoes.php';
            notificar_tecnicos_chamado_atribuido($chamadoId, $idsNovos, 0);
        }

        return ['ok' => true, 'err' => ''];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'err' => 'Não foi possível atribuir técnico.'];
    }
}

/**
 * Compatibilidade: mantém a API antiga para telas/rotinas que enviam um único técnico.
 *
 * @return array{ok: bool, err: string}
 */
function repo_chamado_atribuir_tecnico(int $chamadoId, ?int $tecnicoUserId, int $empresaRaizId): array
{
    return repo_chamado_atribuir_tecnicos($chamadoId, $tecnicoUserId !== null && $tecnicoUserId > 0 ? [$tecnicoUserId] : [], $empresaRaizId);
}

/**
 * Gestor aprova a execução feita pelo técnico.
 *
 * @return array{ok: bool, err: string}
 */
function repo_chamado_salvar_checklist_gestor(int $chamadoId, int $gestorUserId, string $gestorNome, string $checklist): array
{
    $pdo = db();
    if (!$pdo || $chamadoId <= 0 || $gestorUserId <= 0) {
        return ['ok' => false, 'err' => 'Dados inválidos.'];
    }
    $ch = repo_chamado($chamadoId);
    if (!$ch) {
        return ['ok' => false, 'err' => 'Chamado não encontrado.'];
    }
    $checklistVal = trim($checklist) !== '' ? trim($checklist) : null;
    try {
        $st = $pdo->prepare('UPDATE chamados SET checklist_realizado = ? WHERE id = ?');
        $st->execute([$checklistVal, $chamadoId]);
        $cidAp = (int) ($ch['cliente_id'] ?? 0);
        audit_log_registar(
            'chamado.gestor.checklist',
            'chamado',
            $chamadoId,
            $cidAp > 0 ? $cidAp : null,
            ['gestor_user_id' => $gestorUserId],
            ['id' => $gestorUserId, 'nome' => $gestorNome !== '' ? $gestorNome : 'Gestor', 'perfil' => 'gestor']
        );

        return ['ok' => true, 'err' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'err' => $e->getMessage()];
    }
}

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
    $checklistVal = trim($checklist) !== '' ? trim($checklist) : null;
    $jaAprovado   = !empty($ch['aprovado_gestor_em']);
    try {
        $pdo->beginTransaction();
        if ($jaAprovado) {
            $st = $pdo->prepare('UPDATE chamados SET checklist_realizado = ? WHERE id = ?');
            $st->execute([$checklistVal, $chamadoId]);
        } else {
            $st = $pdo->prepare("
                UPDATE chamados
                SET status = 'Resolvido',
                    aprovado_gestor_em = NOW(),
                    aprovado_gestor_user_id = ?,
                    checklist_realizado = ?
                WHERE id = ?
            ");
            $st->execute([$gestorUserId, $checklistVal, $chamadoId]);
            repo_create_chamado_resposta(
                $chamadoId,
                $gestorNome !== '' ? $gestorNome : 'Gestor',
                'admin',
                'Atendimento aprovado pelo gestor.',
                false,
                $gestorUserId
            );
        }
        $pdo->commit();

        $cidAp = (int) ($ch['cliente_id'] ?? 0);
        audit_log_registar(
            $jaAprovado ? 'chamado.gestor.checklist' : 'chamado.gestor.aprovar',
            'chamado',
            $chamadoId,
            $cidAp > 0 ? $cidAp : null,
            $jaAprovado
                ? ['gestor_user_id' => $gestorUserId]
                : ['status_novo' => 'Resolvido', 'gestor_user_id' => $gestorUserId],
            ['id' => $gestorUserId, 'nome' => $gestorNome !== '' ? $gestorNome : 'Gestor', 'perfil' => 'gestor']
        );

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
    if (!repo_chamado_tecnico_vinculado($chamadoId, $operadorUserId)) {
        return ['ok' => false, 'err' => 'Este chamado não está atribuído ao seu usuário.'];
    }
    $stAtual = (string) ($ch['status'] ?? '');
    if (in_array($stAtual, ['Resolvido', 'Fechado'], true)) {
        return ['ok' => true, 'err' => ''];
    }
    if (!empty($ch['finalizado_operador_em']) && !in_array($stAtual, ['Aberto', 'Em andamento'], true)) {
        return ['ok' => true, 'err' => ''];
    }
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare('
            UPDATE chamados
            SET status = \'Aguardando Aprovação\',
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
            'Atendimento finalizado pelo técnico. Aguardando aprovação do gestor.',
            false,
            $operadorUserId
        );
        $pdo->commit();
        $cidOp = (int) ($ch['cliente_id'] ?? 0);
        audit_log_registar('chamado.operador.enviar_os', 'chamado', $chamadoId, $cidOp > 0 ? $cidOp : null, [
            'operador_user_id' => $operadorUserId,
            'status_novo'      => 'Aguardando Aprovação',
        ]);

        return ['ok' => true, 'err' => ''];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'err' => $e->getMessage()];
    }
}

function repo_create_chamado_resposta(int $chamadoId, string $autor, string $tipo, string $texto, bool $interna = false, ?int $autorUsuarioId = null): ?int
{
    $pdo = db();
    if (!$pdo) return null;
    $stmt = $pdo->prepare('
        INSERT INTO chamado_respostas (chamado_id, autor, tipo, texto, interna, enviado_em)
        VALUES (?,?,?,?,?,NOW())
    ');
    $stmt->execute([$chamadoId, $autor, $tipo, $texto, $interna ? 1 : 0]);
    $newId = (int) $pdo->lastInsertId();
    if ($newId > 0 && $autorUsuarioId !== null && $autorUsuarioId > 0) {
        require_once __DIR__ . '/notificacoes.php';
        $previewNotif = trim($texto);
        criarNotificacoesChamado($chamadoId, $newId, $autorUsuarioId, $interna, $previewNotif !== '' ? $previewNotif : null);
    }
    if ($newId > 0) {
        $chR = repo_chamado($chamadoId);
        $cidR = $chR ? (int) ($chR['cliente_id'] ?? 0) : 0;
        $txtLog = trim($texto);
        audit_log_registar('chamado.resposta', 'chamado', $chamadoId, $cidR > 0 ? $cidR : null, [
            'resposta_id' => $newId,
            'tipo'        => $tipo,
            'interna'     => $interna ? 1 : 0,
            'autor'       => function_exists('mb_substr') ? mb_substr($autor, 0, 80, 'UTF-8') : substr($autor, 0, 80),
            'texto'       => function_exists('mb_substr') ? mb_substr($txtLog, 0, 500, 'UTF-8') : substr($txtLog, 0, 500),
        ]);
    }

    return $newId;
}

function repo_notificacoes_table_exists(): bool
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
        $st  = $pdo->query("SHOW TABLES LIKE 'notificacoes'");
        $row = $st ? $st->fetch(PDO::FETCH_NUM) : false;
        $cache = $row !== false;
    } catch (Throwable $e) {
        $cache = false;
    }

    return $cache;
}

/**
 * SQL extra para notificar apenas usuários com conta ativa.
 */
function _repo_notificacao_sql_usuario_ativo(): string
{
    return repo_usuarios_ativo_column_exists() ? ' AND COALESCE(ativo, 1) = 1' : '';
}

/**
 * Gestores da empresa/prefeitura dona do chamado (empresa_id ou cliente_id legado).
 */
function _repo_notificacao_ids_gestores_empresa(PDO $pdo, int $empresaRaiz): array
{
    if ($empresaRaiz <= 0) {
        return [];
    }
    $ativo = _repo_notificacao_sql_usuario_ativo();
    $st    = $pdo->prepare(
        "SELECT id FROM usuarios WHERE perfil = 'gestor' AND (
            empresa_id = ? OR cliente_id IN (
                SELECT id FROM clientes WHERE id = ? OR empresa_id = ?
            )
        ){$ativo}"
    );
    $st->execute([$empresaRaiz, $empresaRaiz, $empresaRaiz]);

    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN, 0) ?: []);
}

/**
 * Gestores e usuários portal (perfil cliente) da matriz — alertas de medição/BM.
 *
 * @return list<int>
 */
function repo_notificacao_destinatarios_medicao_bm(int $clienteMatrizId): array
{
    $pdo = db();
    if (!$pdo || $clienteMatrizId <= 0 || !repo_notificacoes_table_exists()) {
        return [];
    }
    $raiz = repo_cliente_catalogo_dono_id($clienteMatrizId);
    if ($raiz <= 0) {
        $raiz = repo_cliente_matriz_raiz_id($clienteMatrizId);
    }
    if ($raiz <= 0) {
        $raiz = $clienteMatrizId;
    }
    $ativoSql = _repo_notificacao_sql_usuario_ativo();
    $ids      = _repo_notificacao_ids_gestores_empresa($pdo, $raiz);

    try {
        $st = $pdo->prepare(
            "SELECT id FROM usuarios WHERE perfil = 'cliente' AND cliente_id IN (
                SELECT id FROM clientes WHERE id = ? OR empresa_id = ?
            ){$ativoSql}"
        );
        $st->execute([$raiz, $raiz]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN, 0) ?: [] as $v) {
            $id = (int) $v;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
    } catch (Throwable $e) {
        // mantém gestores já coletados
    }

    return array_values(array_unique($ids));
}

/**
 * Destinatários que devem receber alerta de nova mensagem no chamado.
 *
 * @return list<int>
 */
function repo_notificacao_destinatarios_chamado(int $chamadoId, bool $somenteMensagemInterna): array
{
    $pdo = db();
    if (!$pdo || !repo_notificacoes_table_exists() || $chamadoId <= 0) {
        return [];
    }
    $ch = repo_chamado($chamadoId);
    if (!$ch) {
        return [];
    }
    $empresaRaiz = repo_cliente_catalogo_dono_id((int) ($ch['cliente_id'] ?? 0));
    $ids           = [];
    $ativoSql      = _repo_notificacao_sql_usuario_ativo();

    $pushIds = static function (array $rows) use (&$ids): void {
        foreach ($rows as $v) {
            $id = (int) $v;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
    };

    try {
        if ($somenteMensagemInterna) {
            $st = $pdo->query("SELECT id FROM usuarios WHERE perfil = 'admin'{$ativoSql}");
            $pushIds($st ? $st->fetchAll(PDO::FETCH_COLUMN, 0) : []);
            $pushIds(_repo_notificacao_ids_gestores_empresa($pdo, $empresaRaiz));

            return array_values(array_unique($ids));
        }

        $st = $pdo->query("SELECT id FROM usuarios WHERE perfil = 'admin'{$ativoSql}");
        $pushIds($st ? $st->fetchAll(PDO::FETCH_COLUMN, 0) : []);

        if ($empresaRaiz > 0) {
            $pushIds(_repo_notificacao_ids_gestores_empresa($pdo, $empresaRaiz));

            $st = $pdo->prepare(
                "SELECT id FROM usuarios WHERE perfil = 'cliente' AND cliente_id IN (
                    SELECT id FROM clientes WHERE id = ? OR empresa_id = ?
                ){$ativoSql}"
            );
            $st->execute([$empresaRaiz, $empresaRaiz]);
            $pushIds($st->fetchAll(PDO::FETCH_COLUMN, 0));
        }

        foreach (repo_chamado_tecnicos_list($chamadoId) as $tec) {
            $tid = (int) ($tec['id'] ?? 0);
            if ($tid > 0) {
                $ids[] = $tid;
            }
        }
        $tu = isset($ch['tecnico_user_id']) && $ch['tecnico_user_id'] !== null ? (int) $ch['tecnico_user_id'] : 0;
        if ($tu > 0) {
            $ids[] = $tu;
        }

        if ($empresaRaiz > 0) {
            $st = $pdo->prepare("SELECT id FROM usuarios WHERE perfil = 'operador' AND empresa_id = ?{$ativoSql}");
            $st->execute([$empresaRaiz]);
            $pushIds($st->fetchAll(PDO::FETCH_COLUMN, 0));
            $st2 = $pdo->prepare(
                "SELECT id FROM usuarios WHERE perfil = 'operador' AND cliente_id IN (
                    SELECT id FROM clientes WHERE id = ? OR empresa_id = ?
                ){$ativoSql}"
            );
            $st2->execute([$empresaRaiz, $empresaRaiz]);
            $pushIds($st2->fetchAll(PDO::FETCH_COLUMN, 0));
        }

        return array_values(array_unique($ids));
    } catch (Throwable $e) {
        return [];
    }
}

function repo_notificacao_insert(
    int $usuarioId,
    int $chamadoId,
    ?int $mensagemId,
    string $titulo,
    ?string $descricao,
    string $tipo = 'chamado_mensagem'
): bool {
    $pdo = db();
    if (!$pdo || !repo_notificacoes_table_exists() || $usuarioId <= 0 || $chamadoId <= 0) {
        return false;
    }
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO notificacoes (usuario_id, chamado_id, mensagem_id, tipo, titulo, descricao, lida, data_criacao)
             VALUES (?,?,?,?,?,?,0,NOW())'
        );

        return $stmt->execute([
            $usuarioId,
            $chamadoId,
            $mensagemId,
            $tipo,
            $titulo,
            $descricao,
        ]);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return list<array{id:int,chamado_id:int,titulo:string,descricao:?string,lida:int,data_criacao:string,link:string}>
 */
function repo_notificacoes_list_for_user(int $usuarioId, int $limit = 40): array
{
    $pdo = db();
    if (!$pdo || !repo_notificacoes_table_exists() || $usuarioId <= 0) {
        return [];
    }
    $limit = max(1, min(100, $limit));
    try {
        $st = $pdo->prepare(
            'SELECT id, chamado_id, tipo, titulo, descricao, lida,
                    DATE_FORMAT(data_criacao, \'%Y-%m-%d %H:%i:%s\') AS data_criacao
             FROM notificacoes
             WHERE usuario_id = ?
             ORDER BY data_criacao DESC, id DESC
             LIMIT ' . (int) $limit
        );
        $st->execute([$usuarioId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $cid           = (int) ($r['chamado_id'] ?? 0);
            $r['id']       = (int) ($r['id'] ?? 0);
            $r['chamado_id'] = $cid;
            $r['tipo']     = (string) ($r['tipo'] ?? '');
            $r['lida']     = (int) ($r['lida'] ?? 0);
            $r['titulo']   = (string) ($r['titulo'] ?? '');
            $r['descricao'] = isset($r['descricao']) ? ($r['descricao'] !== '' ? (string) $r['descricao'] : null) : null;
            $tipoNot = (string) ($r['tipo'] ?? '');
            if (in_array($tipoNot, ['medicao_custo_pendente', 'medicao_bm_importado'], true)
                && preg_match('/(\d{4}-\d{2})/', $r['titulo'], $mYm)) {
                $r['link'] = $tipoNot === 'medicao_bm_importado'
                    ? 'medicao_mes.php?mes=' . $mYm[1]
                    : 'medicao_ver.php?mes=' . $mYm[1];
            } else {
                $r['link'] = 'chamado_detalhe.php?id=' . $cid;
            }
        }
        unset($r);

        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

function repo_notificacoes_count_unread(int $usuarioId): int
{
    return repo_notificacoes_count_for_user($usuarioId, 'unread');
}

function repo_notificacoes_count_for_user(int $usuarioId, ?string $filtro = null): int
{
    $pdo = db();
    if (!$pdo || !repo_notificacoes_table_exists() || $usuarioId <= 0) {
        return 0;
    }
    $sql = 'SELECT COUNT(*) FROM notificacoes WHERE usuario_id = ?';
    $params = [$usuarioId];
    if ($filtro === 'unread') {
        $sql .= ' AND lida = 0';
    }
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * @return array{rows:list<array>,total:int}
 */
function repo_notificacoes_list_paginated(int $usuarioId, int $page, int $perPage, ?string $filtro = null): array
{
    $pdo = db();
    if (!$pdo || !repo_notificacoes_table_exists() || $usuarioId <= 0) {
        return ['rows' => [], 'total' => 0];
    }
    $page    = max(1, $page);
    $perPage = max(1, min(50, $perPage));
    $offset  = ($page - 1) * $perPage;
    $where   = 'usuario_id = ?';
    $params  = [$usuarioId];
    if ($filtro === 'unread') {
        $where .= ' AND lida = 0';
    }
    try {
        $stCount = $pdo->prepare('SELECT COUNT(*) FROM notificacoes WHERE ' . $where);
        $stCount->execute($params);
        $total = (int) $stCount->fetchColumn();

        $st = $pdo->prepare(
            'SELECT id, chamado_id, titulo, descricao, lida,
                    DATE_FORMAT(data_criacao, \'%Y-%m-%d %H:%i:%s\') AS data_criacao
             FROM notificacoes
             WHERE ' . $where . '
             ORDER BY data_criacao DESC, id DESC
             LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset
        );
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $cid            = (int) ($r['chamado_id'] ?? 0);
            $r['id']        = (int) ($r['id'] ?? 0);
            $r['chamado_id'] = $cid;
            $r['lida']      = (int) ($r['lida'] ?? 0);
            $r['titulo']    = (string) ($r['titulo'] ?? '');
            $r['descricao'] = isset($r['descricao']) ? ($r['descricao'] !== '' ? (string) $r['descricao'] : null) : null;
            $r['link']      = 'chamado_detalhe.php?id=' . $cid;
        }
        unset($r);

        return ['rows' => $rows, 'total' => $total];
    } catch (Throwable $e) {
        return ['rows' => [], 'total' => 0];
    }
}

function repo_notificacao_marcar_lida(int $notificacaoId, int $usuarioId): bool
{
    $pdo = db();
    if (!$pdo || !repo_notificacoes_table_exists() || $notificacaoId <= 0 || $usuarioId <= 0) {
        return false;
    }
    try {
        $st = $pdo->prepare(
            'UPDATE notificacoes SET lida = 1, data_leitura = NOW() WHERE id = ? AND usuario_id = ? AND lida = 0'
        );

        return $st->execute([$notificacaoId, $usuarioId]);
    } catch (Throwable $e) {
        return false;
    }
}

function repo_notificacoes_marcar_todas_lidas(int $usuarioId): int
{
    $pdo = db();
    if (!$pdo || !repo_notificacoes_table_exists() || $usuarioId <= 0) {
        return 0;
    }
    try {
        $st = $pdo->prepare(
            'UPDATE notificacoes SET lida = 1, data_leitura = NOW() WHERE usuario_id = ? AND lida = 0'
        );
        $st->execute([$usuarioId]);

        return $st->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

function repo_notificacoes_marcar_lidas_chamado(int $usuarioId, int $chamadoId): int
{
    $pdo = db();
    if (!$pdo || !repo_notificacoes_table_exists() || $usuarioId <= 0 || $chamadoId <= 0) {
        return 0;
    }
    try {
        $st = $pdo->prepare(
            'UPDATE notificacoes SET lida = 1, data_leitura = NOW()
             WHERE usuario_id = ? AND chamado_id = ? AND lida = 0'
        );
        $st->execute([$usuarioId, $chamadoId]);

        return $st->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
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
    $newId = (int) $pdo->lastInsertId();
    if ($newId > 0) {
        chamado_anexo_sync_primeira_imagem_para_ponto(
            (int) $d['chamado_id'],
            (string) $d['nome_arquivo'],
            is_string($d['mime'] ?? null) && ($d['mime'] ?? '') !== '' ? (string) $d['mime'] : null,
            (int) ($d['tamanho'] ?? 0),
            (string) ($d['nome_original'] ?? '')
        );
        $chAn = repo_chamado((int) $d['chamado_id']);
        $cidA  = $chAn ? (int) ($chAn['cliente_id'] ?? 0) : 0;
        $mimeS = is_string($d['mime'] ?? null) ? (string) $d['mime'] : '';
        $ehFoto = ($mimeS !== '' && strncmp($mimeS, 'image/', 6) === 0)
            || (function_exists('upload_extensao_imagem') && upload_extensao_imagem((string) ($d['nome_original'] ?? '')));
        audit_log_registar('chamado.anexo', 'chamado', (int) $d['chamado_id'], $cidA > 0 ? $cidA : null, [
            'anexo_id'       => $newId,
            'nome_original'  => function_exists('mb_substr') ? mb_substr((string) ($d['nome_original'] ?? ''), 0, 200, 'UTF-8') : substr((string) ($d['nome_original'] ?? ''), 0, 200),
            'mime'           => $mimeS,
            'tamanho_bytes'  => (int) ($d['tamanho'] ?? 0),
            'resposta_id'    => !empty($d['resposta_id']) ? (int) $d['resposta_id'] : null,
            'enviado_tipo'   => (string) ($d['enviado_tipo'] ?? ''),
            'enviado_por'    => function_exists('mb_substr')
                ? mb_substr(trim((string) ($d['enviado_por'] ?? '')), 0, 120, 'UTF-8')
                : substr(trim((string) ($d['enviado_por'] ?? '')), 0, 120),
            'tipo_ficheiro'  => $ehFoto ? 'imagem' : 'outro',
        ]);
    }

    return $newId;
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

/**
 * Resumo de anexos para listagem admin (uma query para vários chamados).
 *
 * @param list<int> $chamadoIds
 * @return array<int, array{total: int, imagens: list<array{id: int, nome: string}>}>
 */
function repo_chamados_anexos_resumo_listagem(array $chamadoIds): array
{
    $chamadoIds = array_values(array_unique(array_filter(array_map(static function ($v): int {
        $n = (int) $v;

        return $n > 0 ? $n : 0;
    }, $chamadoIds))));
    $out = [];
    foreach ($chamadoIds as $cid) {
        $out[$cid] = ['total' => 0, 'imagens' => []];
    }
    if ($chamadoIds === []) {
        return $out;
    }
    $pdo = db();
    if (!$pdo) {
        return $out;
    }
    $placeholders = implode(',', array_fill(0, count($chamadoIds), '?'));
    $sql = 'SELECT chamado_id, id, nome_original, mime FROM chamado_anexos WHERE chamado_id IN (' . $placeholders
        . ') ORDER BY chamado_id, enviado_em DESC, id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($chamadoIds);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cid = (int) ($r['chamado_id'] ?? 0);
        if (!isset($out[$cid])) {
            continue;
        }
        $out[$cid]['total']++;
        $mime = strtolower((string) ($r['mime'] ?? ''));
        $nome = (string) ($r['nome_original'] ?? '');
        $ehImg = upload_extensao_imagem($nome)
            || ($mime !== '' && strncmp($mime, 'image/', 8) === 0 && strpos($mime, 'svg') === false);
        if ($ehImg) {
            $out[$cid]['imagens'][] = [
                'id'   => (int) ($r['id'] ?? 0),
                'nome' => $nome,
            ];
        }
    }

    return $out;
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
    $limpo = preg_replace('/\s*\([^)]*\)\s*$/u', '', $nome);
    $nome  = trim($limpo !== null && $limpo !== '' ? $limpo : $nome);
    $parts = preg_split('/\s+/u', $nome, -1, PREG_SPLIT_NO_EMPTY);
    $parts = array_values(array_filter(
        $parts,
        static fn (string $p): bool => preg_match('/\p{L}/u', $p) === 1
    ));
    if ($parts === []) {
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
        $empresa = defined('APP_BRAND_NAME') ? (string) APP_BRAND_NAME : 'OnLight';
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
    $ini = trim((string) ($u['iniciais'] ?? ''));
    if ($ini === '' && trim((string) ($u['nome'] ?? '')) !== '') {
        $ini = repo_usuario_calcular_iniciais((string) $u['nome']);
    }
    $u['iniciais'] = $ini !== '' ? $ini : 'US';
    return $u;
}

/**
 * Coluna `usuarios.ativo` (conta pode fazer login). Migração 046.
 */
function repo_usuarios_ativo_column_exists(): bool
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
        $st = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'ativo'");
        $cache = (bool) ($st && $st->fetch(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        $cache = false;
    }
    return $cache;
}

/**
 * Senha correta mas conta inativa — para mensagem específica no login.
 */
function repo_usuario_credenciais_corretas_mas_inativa(string $email, string $senha): bool
{
    if (!repo_usuarios_ativo_column_exists()) {
        return false;
    }
    $pdo = db();
    if (!$pdo) {
        return false;
    }
    try {
        $stmt = $pdo->prepare('SELECT senha_hash, COALESCE(ativo, 1) AS ativo FROM usuarios WHERE email = ? LIMIT 1');
        $stmt->execute([trim($email)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int) ($row['ativo'] ?? 1) !== 0) {
            return false;
        }
        return password_verify($senha, (string) ($row['senha_hash'] ?? ''));
    } catch (Throwable $e) {
        return false;
    }
}

function repo_user_by_email(string $email): ?array
{
    $pdo = db();
    if (!$pdo) {
        return null;
    }

    $sql = 'SELECT * FROM usuarios WHERE email = ?';
    if (repo_usuarios_ativo_column_exists()) {
        $sql .= ' AND COALESCE(ativo, 1) = 1';
    }
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
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
    $sqlAtivo = repo_usuarios_ativo_column_exists()
        ? 'COALESCE(u.ativo, 1) AS ativo'
        : '1 AS ativo';
    $sql = "
        SELECT u.id, u.nome, u.email, u.perfil, u.modulo_perfil, u.cliente_id, u.empresa_id, u.iniciais,
               $sqlAtivo,
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
        if (isset($r['ativo'])) {
            $r['ativo'] = (int) $r['ativo'];
        }
    }
    unset($r);

    return ['rows' => $rows, 'total' => $total];
}

/**
 * Exclusão permanente do utilizador (apenas administradores da plataforma).
 *
 * @return array{ok: bool, err: string}
 */
/**
 * Exclusão permanente por gestor da empresa (operadores/clientes no escopo).
 *
 * @return array{ok: bool, err: string}
 */
function repo_usuario_delete_by_gestor(int $targetId, int $gestorUserId, int $empresaId): array
{
    if ($targetId <= 0 || $gestorUserId <= 0 || $empresaId <= 0) {
        return ['ok' => false, 'err' => 'Pedido inválido.'];
    }
    if ($targetId === $gestorUserId) {
        return ['ok' => false, 'err' => 'Não é possível excluir o seu próprio utilizador.'];
    }
    $alvo = repo_user_by_id($targetId);
    if (!$alvo) {
        return ['ok' => false, 'err' => 'Utilizador não encontrado.'];
    }
    $perfilAlvo = (string) ($alvo['perfil'] ?? '');
    if (in_array($perfilAlvo, ['admin'], true)) {
        return ['ok' => false, 'err' => 'Sem permissão para excluir este perfil.'];
    }
    $uEid = isset($alvo['empresa_id']) && $alvo['empresa_id'] !== null ? (int) $alvo['empresa_id'] : 0;
    $uCid = isset($alvo['cliente_id']) && $alvo['cliente_id'] !== null ? (int) $alvo['cliente_id'] : 0;
    $noEscopo = ($uEid === $empresaId)
        || ($perfilAlvo === 'cliente' && $uCid > 0 && repo_cliente_pertence_empresa($uCid, $empresaId));
    if (!$noEscopo) {
        return ['ok' => false, 'err' => 'Utilizador fora do escopo da sua empresa.'];
    }

    return repo_usuario_delete_by_admin($targetId, $gestorUserId);
}

function repo_usuario_delete_by_admin(int $targetId, int $actorAdminId): array
{
    $pdo = db();
    if (!$pdo || $targetId <= 0 || $actorAdminId <= 0) {
        return ['ok' => false, 'err' => 'Pedido inválido.'];
    }
    if ($targetId === $actorAdminId) {
        return ['ok' => false, 'err' => 'Não é possível excluir o seu próprio utilizador.'];
    }
    try {
        $st = $pdo->prepare('SELECT id, perfil FROM usuarios WHERE id = ? LIMIT 1');
        $st->execute([$targetId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'err' => 'Utilizador não encontrado.'];
        }
        if (($row['perfil'] ?? '') === 'admin') {
            $cnt = (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE perfil = 'admin'")->fetchColumn();
            if ($cnt < 2) {
                return ['ok' => false, 'err' => 'Não é possível excluir o último administrador.'];
            }
        }
        $del = $pdo->prepare('DELETE FROM usuarios WHERE id = ?');
        $ok  = $del->execute([$targetId]);
        $n   = $del->rowCount();
        return ['ok' => (bool) $ok && $n > 0, 'err' => ($ok && $n > 0) ? '' : 'Não foi possível excluir o utilizador.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'err' => 'Erro ao excluir: ' . $e->getMessage()];
    }
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
    ?int $empresaIdInput = null,
    ?int $ativoOpt = null
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

        $upAtivo = $ativoOpt !== null && repo_usuarios_ativo_column_exists();
        $ativoVal = 1;
        if ($upAtivo) {
            $ativoVal = ((int) $ativoOpt !== 0) ? 1 : 0;
            $actorUid = (int) ($gestorUserId ?? 0);
            if ($targetId === $actorUid && $ativoVal === 0) {
                return ['ok' => false, 'err' => 'Não é possível desativar a sua própria conta.'];
            }
        }
        if ($upAtivo) {
            $stmt = $pdo->prepare('UPDATE usuarios SET nome = ?, email = ?, perfil = ?, modulo_perfil = ?, cliente_id = ?, empresa_id = ?, iniciais = ?, is_super_admin = ?, ativo = ? WHERE id = ?');
            $ok   = $stmt->execute([$nome, $email, $perfil, $mpf, $clienteIdOut, $empresaIdOut, $iniciais, $keepSuper, $ativoVal, $targetId]);
        } else {
            $stmt = $pdo->prepare('UPDATE usuarios SET nome = ?, email = ?, perfil = ?, modulo_perfil = ?, cliente_id = ?, empresa_id = ?, iniciais = ?, is_super_admin = ? WHERE id = ?');
            $ok   = $stmt->execute([$nome, $email, $perfil, $mpf, $clienteIdOut, $empresaIdOut, $iniciais, $keepSuper, $targetId]);
        }
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

function repo_usuario_password_resets_table_exists(): bool
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
        $st   = $pdo->query("SHOW TABLES LIKE 'usuario_password_resets'");
        $row  = $st ? $st->fetch(PDO::FETCH_NUM) : false;
        $cache = $row !== false;
    } catch (Throwable $e) {
        $cache = false;
    }

    return $cache;
}

/**
 * Cria token de recuperação e envia e-mail (se o utilizador existir).
 *
 * @return array{ok: bool, err: string, mailed: bool}
 *   ok=false apenas para erro de validação ou serviço; mailed indica se e-mail foi enviado.
 */
function repo_password_reset_request(string $email, string $siteBaseUrl): array
{
    if (!db_ok()) {
        return ['ok' => false, 'err' => 'Base de dados indisponível.', 'mailed' => false];
    }
    if (!repo_usuario_password_resets_table_exists()) {
        return [
            'ok'  => false,
            'err' => 'Recuperação de senha: falta a tabela na base de dados. O administrador deve executar no MySQL o script database/migrations/042_usuario_password_resets.sql (phpMyAdmin → SQL → colar e executar).',
            'mailed' => false,
        ];
    }
    $email = trim($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'err' => 'Indique um e-mail válido.', 'mailed' => false];
    }

    require_once __DIR__ . '/mailer.php';

    $u = repo_user_by_email($email);
    if (!$u) {
        return ['ok' => true, 'err' => '', 'mailed' => false];
    }

    $pdo = db();
    if (!$pdo) {
        return ['ok' => false, 'err' => 'Base de dados indisponível.', 'mailed' => false];
    }

    $uid = (int) ($u['id'] ?? 0);
    if ($uid <= 0) {
        return ['ok' => true, 'err' => '', 'mailed' => false];
    }

    try {
        $raw    = bin2hex(random_bytes(32));
        $digest = hash('sha256', $raw, false);
        $exp    = (new DateTimeImmutable('now'))->modify('+1 hour')->format('Y-m-d H:i:s');

        $pdo->prepare('DELETE FROM usuario_password_resets WHERE usuario_id = ? AND used_at IS NULL')->execute([$uid]);
        $ins = $pdo->prepare('INSERT INTO usuario_password_resets (usuario_id, token_hash, expires_at) VALUES (?,?,?)');
        $ins->execute([$uid, $digest, $exp]);

        $base = rtrim($siteBaseUrl, '/');
        $url  = $base . '/redefinir_senha.php?token=' . rawurlencode($raw);
        $nome = (string) ($u['nome'] ?? 'Utilizador');
        $mr   = mail_password_reset((string) ($u['email'] ?? $email), $nome, $url);
        if (empty($mr['ok'])) {
            error_log('mail_password_reset falhou: ' . (string) ($mr['motivo'] ?? 'unknown'));

            return ['ok' => true, 'err' => '', 'mailed' => false];
        }

        return ['ok' => true, 'err' => '', 'mailed' => true];
    } catch (Throwable $e) {
        error_log('repo_password_reset_request: ' . $e->getMessage());

        return ['ok' => false, 'err' => 'Não foi possível processar o pedido. Tente mais tarde.', 'mailed' => false];
    }
}

/**
 * @return array{id:int,usuario_id:int,nome:string,email:string}|null
 */
function repo_password_reset_lookup(string $tokenPlain): ?array
{
    if (!db_ok() || !repo_usuario_password_resets_table_exists()) {
        return null;
    }
    $tokenPlain = trim($tokenPlain);
    if (strlen($tokenPlain) < 64) {
        return null;
    }
    $digest = hash('sha256', $tokenPlain, false);
    $pdo    = db();
    if (!$pdo) {
        return null;
    }
    try {
        $st = $pdo->prepare('
            SELECT r.id, r.usuario_id, r.expires_at, r.used_at, u.nome, u.email
            FROM usuario_password_resets r
            INNER JOIN usuarios u ON u.id = r.usuario_id
            WHERE r.token_hash = ? LIMIT 1
        ');
        $st->execute([$digest]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        if (!empty($row['used_at'])) {
            return null;
        }
        $exp = strtotime((string) $row['expires_at']);
        if ($exp === false || $exp < time()) {
            return null;
        }

        return [
            'id'         => (int) $row['id'],
            'usuario_id' => (int) $row['usuario_id'],
            'nome'       => (string) $row['nome'],
            'email'      => (string) $row['email'],
        ];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @return array{ok: bool, err: string}
 */
function repo_password_reset_apply(string $tokenPlain, string $senhaNova, string $senhaNova2): array
{
    if ($senhaNova !== $senhaNova2) {
        return ['ok' => false, 'err' => 'A nova senha e a confirmação não coincidem.'];
    }
    if (strlen($senhaNova) < 6) {
        return ['ok' => false, 'err' => 'A senha deve ter pelo menos 6 caracteres.'];
    }
    $ctx = repo_password_reset_lookup($tokenPlain);
    if (!$ctx) {
        return ['ok' => false, 'err' => 'Link inválido ou expirado. Peça um novo e-mail de recuperação.'];
    }
    $pdo = db();
    if (!$pdo) {
        return ['ok' => false, 'err' => 'Base de dados indisponível.'];
    }
    try {
        $pdo->beginTransaction();
        $hash = password_hash($senhaNova, PASSWORD_BCRYPT);
        $st1   = $pdo->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?');
        $st1->execute([$hash, $ctx['usuario_id']]);
        $st2 = $pdo->prepare('UPDATE usuario_password_resets SET used_at = NOW() WHERE id = ?');
        $st2->execute([$ctx['id']]);
        $pdo->prepare('DELETE FROM usuario_password_resets WHERE usuario_id = ? AND id <> ? AND used_at IS NULL')
            ->execute([$ctx['usuario_id'], $ctx['id']]);
        $pdo->commit();

        return ['ok' => true, 'err' => ''];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'err' => 'Não foi possível atualizar a senha.'];
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
require_once __DIR__ . '/medicao_custo_repo.php';

