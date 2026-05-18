<?php
/**
 * Autenticação da API JSON pública (chave pública + secreta com hash no banco).
 */

declare(strict_types=1);

require_once __DIR__ . '/repository.php';

/**
 * Valida cabeçalhos X-Api-Key e X-Api-Secret e devolve JSON de erro ou null se OK.
 *
 * @return array<string,mixed>|null erro para api_public_json_exit, ou null se autorizado
 */
function api_public_auth_error(): ?array
{
    if ((repo_config_get('api_public_enabled') ?? '') !== '1') {
        return ['ok' => false, 'error' => 'api_disabled', 'message' => 'API JSON desativada nas configurações.'];
    }

    $pub = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
    $sec = trim((string) ($_SERVER['HTTP_X_API_SECRET'] ?? ''));

    if ($pub === '' || $sec === '') {
        return ['ok' => false, 'error' => 'unauthorized', 'message' => 'Informe os cabeçalhos X-Api-Key e X-Api-Secret.'];
    }

    $cfgPub = trim((string) repo_config_get('api_public_key'));
    $hash   = (string) repo_config_get('api_secret_hash');

    if ($cfgPub === '' || $hash === '' || !hash_equals($cfgPub, $pub)) {
        return ['ok' => false, 'error' => 'unauthorized', 'message' => 'Chaves inválidas ou não configuradas.'];
    }

    if (!password_verify($sec, $hash)) {
        return ['ok' => false, 'error' => 'unauthorized', 'message' => 'Chaves inválidas ou não configuradas.'];
    }

    return null;
}

function api_public_json_exit(int $httpCode, array $payload): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
 * Encerra o pedido com 401/403 se a autenticação falhar.
 */
function api_public_require_auth(): void
{
    $err = api_public_auth_error();
    if ($err !== null) {
        $code = (($err['error'] ?? '') === 'api_disabled') ? 403 : 401;
        api_public_json_exit($code, $err);
    }
}
