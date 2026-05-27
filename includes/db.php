<?php
/**
 * Conexão PDO com MySQL.
 * - db()       => retorna PDO ou null (modo silencioso).
 * - db_ok()    => true se a conexão foi estabelecida.
 * - db_error() => string do último erro (debug).
 */

require_once __DIR__ . '/config.php';

function db(): ?PDO
{
    static $pdo = null;
    static $tentou = false;

    if ($tentou) return $pdo;
    $tentou = true;

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
    );

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        try {
            $pdo->exec("SET time_zone = '-03:00'");
        } catch (Throwable $tzEx) {
            // Ignora se o servidor MySQL não permitir SET time_zone.
        }
    } catch (Throwable $e) {
        $GLOBALS['__db_err'] = $e->getMessage();
        if (DB_REQUIRED) {
            http_response_code(500);
            die('Falha ao conectar ao banco: ' . htmlspecialchars($e->getMessage()));
        }
        $pdo = null;
    }

    return $pdo;
}

function db_ok(): bool
{
    return db() !== null;
}

function db_error(): string
{
    return $GLOBALS['__db_err'] ?? '';
}
