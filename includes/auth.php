<?php
/**
 * Autenticação (sessão PHP).
 * Com MySQL: login em `usuarios` (password_hash).
 * Sem banco: fallback mínimo só para layout (credenciais demo removidas).
 */

require_once __DIR__ . '/mock.php';
require_once __DIR__ . '/flash.php';

if (session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * Tenta autenticar.
 * - Se houver banco ativo, valida contra `usuarios` (password_hash).
 * - Se não, cai no fallback dos mocks.
 * Retorna o array do usuário (sem a senha) ou null.
 */
function mock_login(string $email, string $senha): ?array
{
    if (db_ok()) {
        $row = repo_user_by_email($email);
        if ($row && password_verify($senha, $row['senha_hash'])) {
            unset($row['senha_hash']);
            $_SESSION['user'] = $row;
            return $row;
        }
        return null;
    }

    global $MOCK_CREDENCIAIS;
    foreach ($MOCK_CREDENCIAIS as $u) {
        if (strcasecmp($u['email'], trim($email)) === 0 && $u['senha'] === $senha) {
            unset($u['senha']);
            $_SESSION['user'] = $u;
            return $u;
        }
    }
    return null;
}

/**
 * Encerra a sessão.
 */
function mock_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/**
 * Usuário logado (ou null).
 */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

/**
 * Exige um perfil. Se não atender, redireciona para login.
 * $perfil: 'admin' | 'cliente' | 'operador' | 'gestor' (ou null para qualquer).
 * $basePath: caminho relativo até a raiz do projeto.
 */
function require_auth(?string $perfil = null, string $basePath = '../'): array
{
    $u = current_user();

    // Fallback mock: se ninguém está logado, loga automaticamente
    // como o perfil esperado para não quebrar a navegação direta.
    if (!$u) {
        if (db_ok()) {
            header('Location: ' . $basePath . 'login.php');
            exit;
        }
        global $MOCK_USER_ADMIN, $MOCK_USER_CLIENTE, $MOCK_USER_OPERADOR;
        if ($perfil === 'cliente') {
            $u = $MOCK_USER_CLIENTE;
        } elseif ($perfil === 'operador') {
            $u = $MOCK_USER_OPERADOR;
        } else {
            $u = $MOCK_USER_ADMIN;
        }
        unset($u['senha']);
        $_SESSION['user'] = $u;
    }

    if ($perfil && ($u['perfil'] ?? '') !== $perfil) {
        header('Location: ' . $basePath . 'login.php?erro=perfil');
        exit;
    }

    return $u;
}

/**
 * Painel interno (rotas em /admin): administrador da plataforma ou gestor da empresa.
 */
function require_auth_gestao(string $basePath = '../'): array
{
    $u = require_auth(null, $basePath);
    $p = (string) ($u['perfil'] ?? '');
    if (!in_array($p, ['admin', 'gestor'], true)) {
        header('Location: ' . $basePath . 'login.php?erro=perfil');
        exit;
    }
    return $u;
}

/**
 * ID da empresa raiz (tenant) para filtrar dados quando o usuário é gestor; null para admin (visão global).
 * Usa `usuarios.empresa_id`; compatível com base antiga onde só existia `cliente_id`.
 */
function gestao_scope_cliente_id(?array $user = null): ?int
{
    $user = $user ?? current_user();
    if (!$user || (($user['perfil'] ?? '') !== 'gestor')) {
        return null;
    }
    $eid = isset($user['empresa_id']) && $user['empresa_id'] !== null && $user['empresa_id'] !== ''
        ? (int) $user['empresa_id'] : 0;
    if ($eid > 0) {
        return $eid;
    }
    $cid = isset($user['cliente_id']) && $user['cliente_id'] !== null && $user['cliente_id'] !== ''
        ? (int) $user['cliente_id'] : 0;
    return $cid > 0 ? $cid : null;
}

/**
 * Garante que o registro em `clientes` (conta do chamado, OS, etc.) pertence à empresa do gestor.
 */
function gestor_assert_escopo_cliente(?int $clienteIdRecurso, string $redirectScript = 'index.php', string $basePath = '../'): void
{
    $scope = gestao_scope_cliente_id();
    if ($scope === null) {
        return;
    }
    if (!function_exists('repo_cliente_pertence_empresa')) {
        require_once __DIR__ . '/repository.php';
    }
    if ($clienteIdRecurso === null || $clienteIdRecurso <= 0 || !repo_cliente_pertence_empresa((int) $clienteIdRecurso, $scope)) {
        if (function_exists('flash_set')) {
            flash_set('err', 'Este registro não pertence à sua empresa.');
        }
        header('Location: ' . $basePath . 'admin/' . ltrim($redirectScript, '/'));
        exit;
    }
}

/**
 * Sessão válida para APIs do painel interno (admin ou gestor).
 */
function current_user_painel_interno(): bool
{
    $u = current_user();
    if (!$u) {
        return false;
    }
    return in_array($u['perfil'] ?? '', ['admin', 'gestor'], true);
}

/**
 * Empresa raiz do operador (campo `empresa_id`, com fallback legado em `cliente_id`).
 */
function operador_empresa_id(?array $user = null): int
{
    $user = $user ?? current_user();
    if (!$user) {
        return 0;
    }
    $e = isset($user['empresa_id']) && $user['empresa_id'] !== null && $user['empresa_id'] !== ''
        ? (int) $user['empresa_id'] : 0;
    if ($e > 0) {
        return $e;
    }
    $c = isset($user['cliente_id']) && $user['cliente_id'] !== null && $user['cliente_id'] !== ''
        ? (int) $user['cliente_id'] : 0;
    return $c > 0 ? $c : 0;
}

/**
 * Apenas usuários admin com is_super_admin = 1 (gestão de módulos SaaS).
 */
function require_super_admin(string $basePath = '../'): array
{
    $u = require_auth('admin', $basePath);
    if (empty($u['is_super_admin'])) {
        if (function_exists('flash_set')) {
            flash_set('err', 'Acesso restrito ao super administrador.');
        }
        header('Location: ' . $basePath . 'admin/index.php');
        exit;
    }
    return $u;
}
