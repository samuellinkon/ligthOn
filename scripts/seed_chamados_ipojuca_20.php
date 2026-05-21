<?php
/**
 * Insere 20 chamados de demo Ipojuca (10 com poste, 10 sem).
 *
 * Uso: php scripts/seed_chamados_ipojuca_20.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/repository.php';

$sqlFile = $root . '/database/seed_chamados_ipojuca_20.sql';
if (!is_file($sqlFile)) {
    fwrite(STDERR, "Arquivo não encontrado: {$sqlFile}\n");
    exit(1);
}

$pdo = db();
if (!$pdo) {
    fwrite(STDERR, "Banco indisponível.\n");
    exit(1);
}

$sql = (string) file_get_contents($sqlFile);
$sql = preg_replace('/--.*$/m', '', $sql);
$parts = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql)));

$pdo->exec('SET NAMES utf8mb4');

foreach ($parts as $stmt) {
    if ($stmt === '' || stripos($stmt, 'SELECT') === 0) {
        continue;
    }
    try {
        $pdo->exec($stmt);
    } catch (Throwable $e) {
        fwrite(STDERR, "Erro SQL: " . $e->getMessage() . "\n");
        exit(1);
    }
}

$st = $pdo->query("
    SELECT ch.id, ch.titulo, ch.ponto_iluminacao_id, p.codigo_poste, ch.status
    FROM chamados ch
    LEFT JOIN pontos_iluminacao p ON p.id = ch.ponto_iluminacao_id
    WHERE ch.titulo LIKE 'Seed Ipojuca —%'
    ORDER BY ch.ponto_iluminacao_id IS NULL, ch.id
");
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$comPonto = 0;
$semPonto = 0;
foreach ($rows as $r) {
    if (!empty($r['ponto_iluminacao_id'])) {
        $comPonto++;
    } else {
        $semPonto++;
    }
    echo sprintf(
        "#%s %s | poste: %s | %s\n",
        $r['id'],
        $r['titulo'],
        $r['codigo_poste'] ?? '—',
        $r['status']
    );
}
echo "\nTotal: " . count($rows) . " chamados ({$comPonto} com poste, {$semPonto} sem poste).\n";

if (count($rows) > 0) {
    $stCid = $pdo->query('SELECT cliente_id FROM chamados WHERE titulo LIKE \'Seed Ipojuca —%\' LIMIT 1');
    $cidSeed = (int) ($stCid->fetchColumn() ?: 0);
    $empresaId = $cidSeed > 0 ? repo_cliente_matriz_raiz_id($cidSeed) : 0;
    if ($empresaId <= 0 && $cidSeed > 0) {
        $empresaId = $cidSeed;
    }
    $operadores = $empresaId > 0 ? repo_operadores_empresa($empresaId) : [];
    if (!empty($operadores)) {
        $opId = (int) $operadores[0]['id'];
        $vinc = 0;
        foreach ($rows as $r) {
            $chId = (int) ($r['id'] ?? 0);
            if ($chId <= 0) {
                continue;
            }
            $atrib = repo_chamado_atribuir_tecnicos($chId, [$opId], $empresaId);
            if (!empty($atrib['ok'])) {
                $vinc++;
            }
        }
        echo "Técnicos: {$vinc} chamado(s) vinculado(s) ao operador #{$opId} ({$operadores[0]['nome']}).\n";
    } else {
        echo "Aviso: nenhum operador cadastrado na empresa #{$empresaId}; chamados ficaram sem técnico vinculado.\n";
    }
}
