<?php
/**
 * Recria catálogo: 10 produtos (estoque 100) + 5 serviços.
 * Uso: php scripts/seed_catalogo_ipojuca_15.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/db.php';

$sqlFile = $root . '/database/seed_catalogo_ipojuca_15.sql';
$pdo = db();
if (!$pdo || !is_file($sqlFile)) {
    fwrite(STDERR, "Banco ou SQL indisponível.\n");
    exit(1);
}

$sql = (string) file_get_contents($sqlFile);
$sql = preg_replace('/--.*$/m', '', $sql);
$parts = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql)));

$pdo->exec('SET NAMES utf8mb4');
foreach ($parts as $stmt) {
    if ($stmt === '') {
        continue;
    }
    if (stripos($stmt, 'SELECT') === 0) {
        $st = $pdo->query($stmt);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            echo ($r['tipo'] ?? '') . ': ' . ($r['qtd'] ?? '') . ' itens, estoque somado ' . ($r['estoque_total'] ?? '') . "\n";
        }
        continue;
    }
    try {
        $pdo->exec($stmt);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}

$st = $pdo->query("SELECT tipo, codigo, nome, estoque_saldo, valor_unitario FROM cliente_itens ORDER BY ordem");
while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    echo sprintf(
        "[%s] %s — %s | estoque: %s | R$ %s\n",
        $r['tipo'],
        $r['codigo'],
        $r['nome'],
        $r['estoque_saldo'],
        number_format((float) $r['valor_unitario'], 2, ',', '.')
    );
}
