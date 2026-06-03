<?php
/**
 * «Manter conectado» — cookie persistente + restauração de sessão.
 */

require_once __DIR__ . '/db.php';

function auth_app_cookie_path(): string
{
    static $path = null;
    if ($path !== null) {
        return $path;
    }
    $sn = (string) ($_SERVER['SCRIPT_NAME'] ?? '/');
    $dir = str_replace('\\', '/', dirname($sn));
    if ($dir === '/' || $dir === '.' || $dir === '') {
        $path = '/';
    } else {
        $path = rtrim($dir, '/') . '/';
    }

    return $path;
}

function auth_remember_cookie_name(): string
{
    return 'crm_remember';
}

function auth_remember_days(): int
{
    return 30;
}

function auth_remember_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
}

function auth_remember_table_exists(): bool
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
        $st = $pdo->query("SHOW TABLES LIKE 'usuario_remember_tokens'");
        $cache = $st && $st->fetch(PDO::FETCH_NUM) !== false;
    } catch (Throwable $e) {
        $cache = false;
    }

    return $cache;
}

function auth_remember_set_cookie(string $value, int $expiresAt): void
{
    setcookie(auth_remember_cookie_name(), $value, [
        'expires'  => $expiresAt,
        'path'     => auth_app_cookie_path(),
        'secure'   => auth_remember_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function auth_remember_clear_cookie(): void
{
    setcookie(auth_remember_cookie_name(), '', [
        'expires'  => time() - 3600,
        'path'     => auth_app_cookie_path(),
        'secure'   => auth_remember_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function auth_remember_set(int $usuarioId): void
{
    if ($usuarioId <= 0 || !db_ok() || !auth_remember_table_exists()) {
        return;
    }
    $pdo = db();
    if (!$pdo) {
        return;
    }

    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $hash = hash('sha256', $validator);
    $expiresAt = time() + (auth_remember_days() * 86400);
    $expiresSql = date('Y-m-d H:i:s', $expiresAt);

    try {
        $pdo->prepare('DELETE FROM usuario_remember_tokens WHERE usuario_id = ?')->execute([$usuarioId]);
        $pdo->prepare('
            INSERT INTO usuario_remember_tokens (usuario_id, selector, token_hash, expires_at)
            VALUES (?, ?, ?, ?)
        ')->execute([$usuarioId, $selector, $hash, $expiresSql]);
        auth_remember_set_cookie($selector . ':' . $validator, $expiresAt);
    } catch (Throwable $e) {
        // silencioso — login segue só com sessão
    }
}

function auth_remember_clear(?int $usuarioId = null): void
{
    auth_remember_clear_cookie();
    if (!db_ok() || !auth_remember_table_exists()) {
        return;
    }
    $pdo = db();
    if (!$pdo) {
        return;
    }

    try {
        if ($usuarioId !== null && $usuarioId > 0) {
            $pdo->prepare('DELETE FROM usuario_remember_tokens WHERE usuario_id = ?')->execute([$usuarioId]);

            return;
        }
        $raw = (string) ($_COOKIE[auth_remember_cookie_name()] ?? '');
        if (strpos($raw, ':') === false) {
            return;
        }
        [$selector] = explode(':', $raw, 2);
        $selector = trim($selector);
        if ($selector !== '' && preg_match('/^[a-f0-9]{24}$/i', $selector)) {
            $pdo->prepare('DELETE FROM usuario_remember_tokens WHERE selector = ?')->execute([$selector]);
        }
    } catch (Throwable $e) {
        return;
    }
}

function auth_usuario_sessao_valida(?array $u): bool
{
    if (!$u) {
        return false;
    }
    if (!db_ok()) {
        return true;
    }
    $id = (int) ($u['id'] ?? 0);
    if ($id <= 0) {
        return false;
    }
    if (!function_exists('repo_user_by_id')) {
        require_once __DIR__ . '/repository.php';
    }
    $fresh = repo_user_by_id($id);
    if (!$fresh) {
        return false;
    }
    if (function_exists('repo_usuarios_ativo_column_exists')
        && repo_usuarios_ativo_column_exists()
        && empty($fresh['ativo'])) {
        return false;
    }
    unset($fresh['senha_hash']);
    $_SESSION['user'] = $fresh;

    return true;
}

function auth_remember_try_restore(): bool
{
    if (current_user()) {
        return auth_usuario_sessao_valida(current_user());
    }
    if (!db_ok() || !auth_remember_table_exists()) {
        return false;
    }

    $raw = (string) ($_COOKIE[auth_remember_cookie_name()] ?? '');
    if ($raw === '' || substr_count($raw, ':') !== 1) {
        return false;
    }
    [$selector, $validator] = explode(':', $raw, 2);
    $selector = trim($selector);
    $validator = trim($validator);
    if (!preg_match('/^[a-f0-9]{24}$/i', $selector) || !preg_match('/^[a-f0-9]{64}$/i', $validator)) {
        auth_remember_clear_cookie();

        return false;
    }

    $pdo = db();
    if (!$pdo) {
        return false;
    }

    try {
        $st = $pdo->prepare('
            SELECT id, usuario_id, token_hash, expires_at
              FROM usuario_remember_tokens
             WHERE selector = ?
             LIMIT 1
        ');
        $st->execute([strtolower($selector)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            auth_remember_clear_cookie();

            return false;
        }
        if (strtotime((string) ($row['expires_at'] ?? '')) < time()) {
            $pdo->prepare('DELETE FROM usuario_remember_tokens WHERE id = ?')->execute([(int) $row['id']]);
            auth_remember_clear_cookie();

            return false;
        }
        $hashOk = hash_equals((string) ($row['token_hash'] ?? ''), hash('sha256', $validator));
        if (!$hashOk) {
            $pdo->prepare('DELETE FROM usuario_remember_tokens WHERE id = ?')->execute([(int) $row['id']]);
            auth_remember_clear_cookie();

            return false;
        }

        if (!function_exists('repo_user_by_id')) {
            require_once __DIR__ . '/repository.php';
        }
        $uid = (int) ($row['usuario_id'] ?? 0);
        $user = repo_user_by_id($uid);
        if (!$user || !auth_usuario_sessao_valida($user)) {
            auth_remember_clear($uid);

            return false;
        }

        auth_remember_set($uid);

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function auth_redirect_se_logado(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        return;
    }
    auth_remember_try_restore();
    $u = current_user();
    if (!$u) {
        return;
    }
    if (!auth_usuario_sessao_valida($u)) {
        auth_remember_clear((int) ($u['id'] ?? 0));
        mock_logout();

        return;
    }
    $dest = auth_painel_destino_apos_login($u);
    if (($dest['path'] ?? '') !== '') {
        header('Location: ' . $dest['path']);
        exit;
    }
}
