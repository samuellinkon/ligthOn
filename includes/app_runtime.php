<?php
/**
 * Recursos de aplicação lidos do banco (app_config): modo debug, e-mail, SMTP.
 * Depende de db.php (sem carregar repository aqui no topo para evitar ordem circular).
 */

require_once __DIR__ . '/db.php';

/**
 * Modelo fixo da instância: administrador, empresa de gestão e iluminação, prefeitura
 * (órgão atendido) e operadores — sem alternância para várias empresas concorrentes no mesmo sistema.
 */
function app_operacao_prefeitura_unica(): bool
{
    return true;
}

/**
 * @deprecated Sempre false; não há modo com vários clientes na mesma instância.
 */
function app_clientes_multiplos(): bool
{
    return false;
}

/**
 * Modo debug: habilita botão "Preencher teste" nos formulários (ver form-fill-dev.js).
 * Com banco indisponível, usa o fallback FORM_FILL_DEV_TOOL em includes/config.php.
 */
function app_debug_mode(): bool
{
    if (db_ok()) {
        require_once __DIR__ . '/repository.php';
        return repo_config_get('debug_mode') === '1';
    }
    return defined('FORM_FILL_DEV_TOOL') && FORM_FILL_DEV_TOOL;
}

/**
 * Configuração efetiva de envio de e-mail (remetente + SMTP).
 *
 * @return array{
 *   from: string,
 *   from_name: string,
 *   smtp_enabled: bool,
 *   smtp_host: string,
 *   smtp_port: int,
 *   smtp_encryption: string,
 *   smtp_user: string,
 *   smtp_password: string
 * }
 */
function app_mail_settings(): array
{
    require_once __DIR__ . '/repository.php';
    $c = repo_config_all();

    $defFrom = defined('MAIL_FROM') ? (string) MAIL_FROM : 'nao-responda@crm-control.com';
    $defName = defined('MAIL_FROM_NAME') ? (string) MAIL_FROM_NAME : (defined('APP_BRAND_NAME') ? APP_BRAND_NAME : 'OnLight');

    $from = trim((string) ($c['mail_from'] ?? ''));
    if ($from === '') {
        $from = $defFrom;
    }
    $fromName = trim((string) ($c['mail_from_name'] ?? ''));
    if ($fromName === '') {
        $fromName = $defName;
    }

    $enc = strtolower(trim((string) ($c['smtp_encryption'] ?? 'tls')));
    if (!in_array($enc, ['tls', 'ssl', 'none'], true)) {
        $enc = 'tls';
    }
    $port = (int) ($c['smtp_port'] ?? 587);
    if ($port < 1 || $port > 65535) {
        $port = 587;
    }

    return [
        'from'              => $from,
        'from_name'         => $fromName,
        'smtp_enabled'      => (($c['smtp_enabled'] ?? '') === '1'),
        'smtp_host'         => trim((string) ($c['smtp_host'] ?? '')),
        'smtp_port'         => $port,
        'smtp_encryption'   => $enc,
        'smtp_user'         => trim((string) ($c['smtp_user'] ?? '')),
        'smtp_password'     => (string) ($c['smtp_password'] ?? ''),
    ];
}
