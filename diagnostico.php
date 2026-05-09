<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

echo "CRM diagnostico\n";
echo "PHP: " . PHP_VERSION . "\n";
echo "Diretorio: " . __DIR__ . "\n\n";

$arquivos = [
    'includes/config.php',
    'includes/db.php',
    'includes/auth.php',
    'includes/app_runtime.php',
    'login.php',
];

foreach ($arquivos as $arquivo) {
    echo $arquivo . ': ' . (is_file(__DIR__ . '/' . $arquivo) ? 'OK' : 'FALTANDO') . "\n";
}

echo "\nExtensoes:\n";
echo 'pdo: ' . (extension_loaded('pdo') ? 'OK' : 'FALTANDO') . "\n";
echo 'pdo_mysql: ' . (extension_loaded('pdo_mysql') ? 'OK' : 'FALTANDO') . "\n";
echo 'mbstring: ' . (extension_loaded('mbstring') ? 'OK' : 'FALTANDO') . "\n";

echo "\nBootstrap:\n";
try {
    require_once __DIR__ . '/includes/config.php';
    require_once __DIR__ . '/includes/db.php';
    echo "config/db: OK\n";
    echo 'db_ok: ' . (db_ok() ? 'SIM' : 'NAO') . "\n";
    if (!db_ok()) {
        echo 'db_error: ' . db_error() . "\n";
    } else {
        require_once __DIR__ . '/includes/repository.php';
        $st = db()->query("SELECT COUNT(*) FROM usuarios");
        echo 'usuarios: ' . (int) $st->fetchColumn() . "\n";
    }
} catch (Throwable $e) {
    echo "ERRO: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    echo $e->getFile() . ':' . $e->getLine() . "\n";
}
