<?php
/**
 * Módulos: regras por role de acesso em saas_modulos.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/flash.php';

/** Grupos persistidos em saas_modulos (valor legado "admin" normaliza para super_admin). */
const APP_SAAS_GRUPOS = ['super_admin', 'gestor', 'cliente', 'operador'];

function app_modulo_grupo_normaliza(string $grupo): string
{
    $g = strtolower(trim($grupo));
    if ($g === 'admin') {
        return 'super_admin';
    }
    return in_array($g, APP_SAAS_GRUPOS, true) ? $g : $g;
}

/**
 * Grupo SaaS do painel interno (/admin): super_admin (admin plataforma) ou gestor.
 */
function app_grupo_painel_interno(?array $user = null): string
{
    $user = $user ?? (function_exists('current_user') ? current_user() : null);
    if (!$user) {
        return 'super_admin';
    }
    if (($user['perfil'] ?? '') === 'gestor') {
        return 'gestor';
    }
    return 'super_admin';
}

/**
 * Módulo do menu admin habilitado para o usuário atual.
 */
function app_modulo_painel_habilitado(string $moduloKey, ?array $user = null): bool
{
    return app_modulo_habilitado(app_grupo_painel_interno($user), $moduloKey, $user);
}

/**
 * Módulo visível se a role permite. Não há mais perfis extras de módulos por usuário.
 */
function app_modulo_habilitado(string $grupo, string $moduloKey, ?array $user = null): bool
{
    $grupo = app_modulo_grupo_normaliza($grupo);
    if (!db_ok()) {
        return true;
    }
    if (!in_array($grupo, APP_SAAS_GRUPOS, true)) {
        return true;
    }
    static $memo = [];
    $memoKey = $grupo . '|' . $moduloKey;
    if (array_key_exists($memoKey, $memo)) {
        return $memo[$memoKey];
    }

    $allowedKeys = app_saas_modulos_keys_por_grupo($grupo);
    if ($allowedKeys !== [] && !in_array($moduloKey, $allowedKeys, true)) {
        $memo[$memoKey] = false;
        return false;
    }

    $saasMap = repo_saas_modulos_habilitados_map($grupo);
    $instOn  = true;
    if ($saasMap !== [] && array_key_exists($moduloKey, $saasMap)) {
        $instOn = (bool) $saasMap[$moduloKey];
    }
    if (!$instOn) {
        $memo[$memoKey] = false;
        return false;
    }
    $memo[$memoKey] = true;
    return true;
}

function require_modulo_admin(string $moduloKey, string $basePath = '../'): void
{
    if (app_modulo_painel_habilitado($moduloKey)) {
        return;
    }
    flash_set('err', 'Este módulo não está disponível para o seu usuário ou foi desativado na instância.');
    header('Location: ' . $basePath . 'admin/index.php');
    exit;
}

function require_modulo_cliente(string $moduloKey, string $basePath = '../'): void
{
    if (app_modulo_habilitado('cliente', $moduloKey)) {
        return;
    }
    flash_set('err', 'Este módulo não está disponível para o seu usuário ou foi desativado na instância.');
    header('Location: ' . $basePath . 'cliente/index.php');
    exit;
}

function require_modulo_operador(string $moduloKey, string $basePath = '../'): void
{
    if (app_modulo_habilitado('operador', $moduloKey)) {
        return;
    }
    flash_set('err', 'Este módulo não está disponível para o seu usuário ou foi desativado na instância.');
    header('Location: ' . $basePath . 'operador/index.php');
    exit;
}

function api_modulo_painel_negado(string $moduloKey): void
{
    if (app_modulo_painel_habilitado($moduloKey)) {
        return;
    }
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'erro' => 'modulo_desativado']);
    exit;
}

/** @deprecated use api_modulo_painel_negado */
function api_modulo_admin_negado(string $moduloKey): void
{
    api_modulo_painel_negado($moduloKey);
}

/**
 * Chaves de módulo que o produto ainda expõe (sidebar + rotas).
 * Atualize ao adicionar um módulo novo na aplicação.
 *
 * @return list<string>
 */
function app_saas_modulos_keys_por_grupo(string $grupo): array
{
    $map = [
        'super_admin' => [
            'dashboard',
            'clientes',
            'usuarios',
            'chamados',
            'medicao',
            'os',
            'pontos_iluminacao',
            'catalogo',
            'relatorio_financeiro',
            'auditoria',
            'configuracoes',
            'suporte',
        ],
        'gestor' => [
            'dashboard',
            'clientes',
            'usuarios',
            'chamados',
            'medicao',
            'os',
            'pontos_iluminacao',
            'catalogo',
            'auditoria',
        ],
        'cliente' => [
            'chamados',
            'medicao',
            'catalogo',
            'auditoria',
            'pontos_iluminacao',
            'documentos',
            'suporte',
        ],
        'operador' => [
            'chamados',
        ],
    ];

    return $map[$grupo] ?? [];
}
