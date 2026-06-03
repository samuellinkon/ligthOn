<?php
/**
 * Limites comerciais do plano SaaS por cliente (prefeitura matriz).
 */

require_once __DIR__ . '/repository.php';

function repo_cliente_plano_columns_exists(): bool
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
        $st = $pdo->query("SHOW COLUMNS FROM clientes LIKE 'plano_codigo'");
        $cache = $st && $st->fetch(PDO::FETCH_ASSOC) !== false;
    } catch (Throwable $e) {
        $cache = false;
    }

    return $cache;
}

/**
 * @return array<string, array<string, mixed>>
 */
function cliente_plano_presets(): array
{
    return [
        'padrao' => [
            'label' => 'Plano 1 — Padrão',
            'mensalidade' => 4000.00,
            'limite_pontos' => 6000,
            'limite_chamados_mes' => 300,
            'limite_itens_mes' => 200,
            'limite_storage_mb' => 5120,
            'limite_usuarios' => 12,
        ],
        'expandido' => [
            'label' => 'Plano 2 — Expandido',
            'mensalidade' => 6000.00,
            'limite_pontos' => 12000,
            'limite_chamados_mes' => 600,
            'limite_itens_mes' => 400,
            'limite_storage_mb' => 15360,
            'limite_usuarios' => 30,
        ],
        'dedicado' => [
            'label' => 'Plano 3 — Dedicado',
            'mensalidade' => 9000.00,
            'limite_pontos' => null,
            'limite_chamados_mes' => null,
            'limite_itens_mes' => null,
            'limite_storage_mb' => null,
            'limite_usuarios' => null,
        ],
    ];
}

function cliente_plano_codigo_label(string $codigo): string
{
    $codigo = strtolower(trim($codigo));
    $presets = cliente_plano_presets();
    if (isset($presets[$codigo]['label'])) {
        return (string) $presets[$codigo]['label'];
    }

    return $codigo === 'personalizado' ? 'Personalizado' : ucfirst($codigo);
}

function cliente_plano_metricas(): array
{
    return [
        'pontos' => ['label' => 'Pontos de iluminação', 'unidade' => 'postes ativos'],
        'chamados_mes' => ['label' => 'Chamados', 'unidade' => 'por mês'],
        'itens_mes' => ['label' => 'Itens em chamados', 'unidade' => 'por mês'],
        'storage' => ['label' => 'Armazenamento', 'unidade' => 'total'],
        'usuarios' => ['label' => 'Usuários ativos', 'unidade' => ''],
    ];
}

function cliente_plano_resolve_raiz_id(int $clienteId): int
{
    if ($clienteId <= 0) {
        return 0;
    }

    return repo_cliente_catalogo_dono_id($clienteId);
}

function cliente_plano_cliente_id_escopo(?array $user = null): int
{
    if ($user === null && function_exists('current_user')) {
        $user = current_user();
    }
    if (!$user) {
        return 0;
    }
    if (function_exists('gestao_scope_cliente_id')) {
        $escopo = gestao_scope_cliente_id($user);
        if ($escopo !== null && $escopo > 0) {
            return cliente_plano_resolve_raiz_id($escopo);
        }
    }

    return (int) (repo_catalogo_cliente_id_padrao_admin() ?? repo_cliente_raiz_principal_id() ?? 0);
}

function cliente_plano_super_admin_bypass(): bool
{
    if (!function_exists('current_user')) {
        return false;
    }
    $u = current_user();

    return !empty($u['is_super_admin']);
}

/**
 * @return array<string, int|null>
 */
function cliente_plano_limites(int $clienteId): array
{
    $raizId = cliente_plano_resolve_raiz_id($clienteId);
    $defaults = cliente_plano_presets()['padrao'];
    $empty = [
        'cliente_id' => $raizId,
        'plano_codigo' => 'padrao',
        'plano_mensalidade' => (float) $defaults['mensalidade'],
        'limite_pontos' => (int) $defaults['limite_pontos'],
        'limite_chamados_mes' => (int) $defaults['limite_chamados_mes'],
        'limite_itens_mes' => (int) $defaults['limite_itens_mes'],
        'limite_storage_mb' => (int) $defaults['limite_storage_mb'],
        'limite_usuarios' => (int) $defaults['limite_usuarios'],
    ];
    if ($raizId <= 0 || !repo_cliente_plano_columns_exists()) {
        return $empty;
    }
    $pdo = db();
    if (!$pdo) {
        return $empty;
    }
    try {
        $st = $pdo->prepare('
            SELECT plano_codigo, plano_mensalidade, limite_pontos, limite_chamados_mes,
                   limite_itens_mes, limite_storage_mb, limite_usuarios
              FROM clientes
             WHERE id = ?
             LIMIT 1
        ');
        $st->execute([$raizId]);
        $cli = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $empty;
    }
    if (!$cli) {
        return $empty;
    }

    return [
        'cliente_id' => $raizId,
        'plano_codigo' => (string) ($cli['plano_codigo'] ?? 'padrao'),
        'plano_mensalidade' => isset($cli['plano_mensalidade']) && $cli['plano_mensalidade'] !== null
            ? (float) $cli['plano_mensalidade'] : null,
        'limite_pontos' => array_key_exists('limite_pontos', $cli) && $cli['limite_pontos'] !== null
            ? (int) $cli['limite_pontos'] : null,
        'limite_chamados_mes' => array_key_exists('limite_chamados_mes', $cli) && $cli['limite_chamados_mes'] !== null
            ? (int) $cli['limite_chamados_mes'] : null,
        'limite_itens_mes' => array_key_exists('limite_itens_mes', $cli) && $cli['limite_itens_mes'] !== null
            ? (int) $cli['limite_itens_mes'] : null,
        'limite_storage_mb' => array_key_exists('limite_storage_mb', $cli) && $cli['limite_storage_mb'] !== null
            ? (int) $cli['limite_storage_mb'] : null,
        'limite_usuarios' => array_key_exists('limite_usuarios', $cli) && $cli['limite_usuarios'] !== null
            ? (int) $cli['limite_usuarios'] : null,
    ];
}

function cliente_plano_limite_por_metrica(array $limites, string $metrica): ?int
{
    return match ($metrica) {
        'pontos' => $limites['limite_pontos'] ?? null,
        'chamados_mes' => $limites['limite_chamados_mes'] ?? null,
        'itens_mes' => $limites['limite_itens_mes'] ?? null,
        'storage' => isset($limites['limite_storage_mb']) && $limites['limite_storage_mb'] !== null
            ? (int) $limites['limite_storage_mb'] * 1024 * 1024 : null,
        'usuarios' => $limites['limite_usuarios'] ?? null,
        default => null,
    };
}

function cliente_plano_percentual(int|float $uso, ?int $limite): ?float
{
    if ($limite === null || $limite <= 0) {
        return null;
    }

    return min(999.0, round(((float) $uso / (float) $limite) * 100, 1));
}

/**
 * @return array<string, int|float>
 */
function cliente_plano_uso(int $clienteId): array
{
    $raizId = cliente_plano_resolve_raiz_id($clienteId);
    $out = [
        'pontos' => 0,
        'chamados_mes' => 0,
        'itens_mes' => 0,
        'storage' => 0,
        'usuarios' => 0,
    ];
    $pdo = db();
    if (!$pdo || $raizId <= 0) {
        return $out;
    }

    try {
        $st = $pdo->prepare('
            SELECT COUNT(*)
              FROM pontos_iluminacao pi
              JOIN clientes c ON c.id = pi.cliente_id
             WHERE pi.status = \'Ativo\'
               AND (c.id = ? OR c.empresa_id = ?)
        ');
        $st->execute([$raizId, $raizId]);
        $out['pontos'] = (int) $st->fetchColumn();

        $st = $pdo->prepare('
            SELECT COUNT(*)
              FROM chamados ch
             WHERE ch.ativo = 1
               AND ch.status <> \'Cancelado\'
               AND DATE_FORMAT(ch.aberto_em, \'%Y-%m\') = DATE_FORMAT(NOW(), \'%Y-%m\')
               AND ch.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
        ');
        $st->execute([$raizId, $raizId]);
        $out['chamados_mes'] = (int) $st->fetchColumn();

        $st = $pdo->prepare('
            SELECT COALESCE(SUM(ci.quantidade), 0)
              FROM chamado_itens ci
              JOIN chamados ch ON ch.id = ci.chamado_id
             WHERE DATE_FORMAT(ci.criado_em, \'%Y-%m\') = DATE_FORMAT(NOW(), \'%Y-%m\')
               AND ch.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
        ');
        $st->execute([$raizId, $raizId]);
        $out['itens_mes'] = (int) round((float) $st->fetchColumn());

        $st = $pdo->prepare('
            SELECT COALESCE(SUM(tamanho), 0)
              FROM cliente_anexos
             WHERE cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
        ');
        $st->execute([$raizId, $raizId]);
        $bytes = (int) $st->fetchColumn();

        $st = $pdo->prepare('
            SELECT COALESCE(SUM(ca.tamanho), 0)
              FROM chamado_anexos ca
              JOIN chamados ch ON ch.id = ca.chamado_id
             WHERE ch.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
        ');
        $st->execute([$raizId, $raizId]);
        $bytes += (int) $st->fetchColumn();

        $st = $pdo->prepare('
            SELECT COALESCE(SUM(img.tamanho), 0)
              FROM ponto_iluminacao_imagens img
              JOIN pontos_iluminacao pi ON pi.id = img.ponto_iluminacao_id
             WHERE pi.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
        ');
        $st->execute([$raizId, $raizId]);
        $bytes += (int) $st->fetchColumn();
        $out['storage'] = $bytes;

        if (repo_usuarios_ativo_column_exists()) {
            $st = $pdo->prepare('
                SELECT COUNT(*)
                  FROM usuarios u
                 WHERE COALESCE(u.ativo, 1) = 1
                   AND u.perfil IN (\'cliente\', \'operador\', \'gestor\')
                   AND (
                        (u.perfil = \'cliente\' AND u.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?))
                     OR (u.perfil IN (\'operador\', \'gestor\') AND u.empresa_id = ?)
                   )
            ');
            $st->execute([$raizId, $raizId, $raizId]);
        } else {
            $st = $pdo->prepare('
                SELECT COUNT(*)
                  FROM usuarios u
                 WHERE u.perfil IN (\'cliente\', \'operador\', \'gestor\')
                   AND (
                        (u.perfil = \'cliente\' AND u.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?))
                     OR (u.perfil IN (\'operador\', \'gestor\') AND u.empresa_id = ?)
                   )
            ');
            $st->execute([$raizId, $raizId, $raizId]);
        }
        $out['usuarios'] = (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return $out;
    }

    return $out;
}

function cliente_plano_formatar_uso(string $metrica, int|float $uso): string
{
    if ($metrica === 'storage') {
        if ($uso >= 1073741824) {
            return number_format($uso / 1073741824, 1, ',', '.') . ' GB';
        }
        if ($uso >= 1048576) {
            return number_format($uso / 1048576, 1, ',', '.') . ' MB';
        }
        if ($uso >= 1024) {
            return number_format($uso / 1024, 1, ',', '.') . ' KB';
        }

        return (int) $uso . ' B';
    }

    return number_format((float) $uso, 0, ',', '.');
}

function cliente_plano_formatar_limite(string $metrica, ?int $limite): string
{
    if ($limite === null) {
        return 'Ilimitado';
    }
    if ($metrica === 'storage') {
        if ($limite >= 1073741824) {
            return number_format($limite / 1073741824, 1, ',', '.') . ' GB';
        }

        return number_format($limite / 1048576, 0, ',', '.') . ' MB';
    }

    return number_format((float) $limite, 0, ',', '.');
}

function cliente_plano_mensagem_metrica(string $metrica): string
{
    return match ($metrica) {
        'pontos' => 'Limite de pontos de iluminação do plano atingido.',
        'chamados_mes' => 'Limite mensal de chamados do plano atingido.',
        'itens_mes' => 'Limite mensal de itens em chamados do plano atingido.',
        'storage' => 'Limite de armazenamento de arquivos do plano atingido.',
        'usuarios' => 'Limite de usuários ativos do plano atingido.',
        default => 'Limite do plano atingido.',
    };
}

/**
 * @return array{ok:bool,warn:bool,pct:?float,uso:int|float,limite:?int,msg:string}
 */
function cliente_plano_checar(int $clienteId, string $metrica, int|float $delta = 1): array
{
    $limites = cliente_plano_limites($clienteId);
    $usoAtual = cliente_plano_uso($clienteId);
    $limite = cliente_plano_limite_por_metrica($limites, $metrica);
    $uso = (float) ($usoAtual[$metrica] ?? 0);
    $proj = $uso + (float) $delta;
    $pct = cliente_plano_percentual((int) ceil($proj), $limite);
    $ok = $limite === null || $proj <= (float) $limite;
    $warn = $pct !== null && $pct >= 80.0;

    return [
        'ok' => $ok,
        'warn' => $warn,
        'pct' => $pct,
        'uso' => $uso,
        'limite' => $limite,
        'msg' => $ok ? '' : cliente_plano_mensagem_metrica($metrica) . ' Ajuste o plano em Configurações.',
    ];
}

function cliente_plano_assert(int $clienteId, string $metrica, int|float $delta = 1): void
{
    if (!repo_cliente_plano_columns_exists()) {
        return;
    }
    $chk = cliente_plano_checar($clienteId, $metrica, $delta);
    if ($chk['ok'] || cliente_plano_super_admin_bypass()) {
        return;
    }

    throw new RuntimeException($chk['msg']);
}

function cliente_plano_warn_pos_acao(int $clienteId, string $metrica): void
{
    if (!repo_cliente_plano_columns_exists()) {
        return;
    }
    $chk = cliente_plano_checar($clienteId, $metrica, 0);
    if (!$chk['warn']) {
        return;
    }
    $labels = cliente_plano_metricas();
    $label = (string) ($labels[$metrica]['label'] ?? $metrica);
    $pctTxt = $chk['pct'] !== null ? number_format($chk['pct'], 0, ',', '.') . '%' : '80%+';
    $msg = 'Atenção: ' . $label . ' em ' . $pctTxt . ' do limite do plano.';
    if (function_exists('flash_set_warn')) {
        flash_set_warn($msg);
    }
}

/**
 * @return list<array<string,mixed>>
 */
function cliente_plano_resumo_metricas(int $clienteId): array
{
    $limites = cliente_plano_limites($clienteId);
    $uso = cliente_plano_uso($clienteId);
    $rows = [];
    foreach (cliente_plano_metricas() as $key => $meta) {
        $limite = cliente_plano_limite_por_metrica($limites, $key);
        $u = $uso[$key] ?? 0;
        $pct = cliente_plano_percentual((int) ceil((float) $u), $limite);
        $nivel = 'ok';
        if ($pct !== null && $pct >= 100) {
            $nivel = 'danger';
        } elseif ($pct !== null && $pct >= 80) {
            $nivel = 'warn';
        }
        $rows[] = [
            'key' => $key,
            'label' => $meta['label'],
            'unidade' => $meta['unidade'],
            'uso' => $u,
            'limite' => $limite,
            'pct' => $pct,
            'nivel' => $nivel,
            'uso_fmt' => cliente_plano_formatar_uso($key, $u),
            'limite_fmt' => cliente_plano_formatar_limite($key, $limite),
        ];
    }

    return $rows;
}

function cliente_plano_tem_alerta(int $clienteId): bool
{
    foreach (cliente_plano_resumo_metricas($clienteId) as $row) {
        if (($row['nivel'] ?? '') === 'warn' || ($row['nivel'] ?? '') === 'danger') {
            return true;
        }
    }

    return false;
}

function repo_cliente_plano_salvar(int $clienteId, array $d): bool
{
    if (!repo_cliente_plano_columns_exists()) {
        return false;
    }
    $pdo = db();
    if (!$pdo || $clienteId <= 0 || !repo_cliente($clienteId)) {
        return false;
    }

    $codigos = ['padrao', 'expandido', 'dedicado', 'personalizado'];
    $codigo = strtolower(trim((string) ($d['plano_codigo'] ?? 'padrao')));
    if (!in_array($codigo, $codigos, true)) {
        $codigo = 'personalizado';
    }

    $parseLim = static function ($raw): ?int {
        if ($raw === null) {
            return null;
        }
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }
        if (!ctype_digit($s)) {
            return null;
        }
        return max(0, (int) $s);
    };

    $mensRaw = trim((string) ($d['plano_mensalidade'] ?? ''));
    $mensalidade = null;
    if ($mensRaw !== '') {
        $mensRaw = str_replace(',', '.', $mensRaw);
        if (is_numeric($mensRaw)) {
            $mensalidade = round((float) $mensRaw, 2);
        }
    }

    $limites = [
        'limite_pontos' => $parseLim($d['limite_pontos'] ?? null),
        'limite_chamados_mes' => $parseLim($d['limite_chamados_mes'] ?? null),
        'limite_itens_mes' => $parseLim($d['limite_itens_mes'] ?? null),
        'limite_storage_mb' => $parseLim($d['limite_storage_mb'] ?? null),
        'limite_usuarios' => $parseLim($d['limite_usuarios'] ?? null),
    ];

    if ($mensalidade === null && $codigo !== 'personalizado' && isset(cliente_plano_presets()[$codigo])) {
        $mensalidade = (float) cliente_plano_presets()[$codigo]['mensalidade'];
    }

    $stmt = $pdo->prepare('
        UPDATE clientes
           SET plano_codigo = :plano_codigo,
               plano_mensalidade = :plano_mensalidade,
               limite_pontos = :limite_pontos,
               limite_chamados_mes = :limite_chamados_mes,
               limite_itens_mes = :limite_itens_mes,
               limite_storage_mb = :limite_storage_mb,
               limite_usuarios = :limite_usuarios
         WHERE id = :id
    ');

    return $stmt->execute([
        ':id' => $clienteId,
        ':plano_codigo' => $codigo,
        ':plano_mensalidade' => $mensalidade,
        ':limite_pontos' => $limites['limite_pontos'],
        ':limite_chamados_mes' => $limites['limite_chamados_mes'],
        ':limite_itens_mes' => $limites['limite_itens_mes'],
        ':limite_storage_mb' => $limites['limite_storage_mb'],
        ':limite_usuarios' => $limites['limite_usuarios'],
    ]);
}
