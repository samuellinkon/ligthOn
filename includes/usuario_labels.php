<?php

/**
 * Rótulos de interface para perfis armazenados no banco (sem alterar ENUM/campos).
 */
function perfil_acesso_label_pt(string $perfil): string
{
    $p = strtolower(trim($perfil));

    return match ($p) {
        'operador' => 'Técnico',
        'cliente'  => 'Cliente',
        'gestor'   => 'Gestor',
        default    => $perfil,
    };
}

/** Rótulo de acesso na sidebar (Gestor, Cliente, Técnico; admin usa tipo da sessão). */
function usuario_perfil_sidebar_label(array $u): string
{
    $perfil = strtolower(trim((string) ($u['perfil'] ?? '')));
    if (in_array($perfil, ['gestor', 'cliente', 'operador'], true)) {
        return perfil_acesso_label_pt($perfil);
    }
    $tipo = trim((string) ($u['tipo'] ?? ''));
    if ($tipo !== '') {
        return $tipo;
    }

    return perfil_acesso_label_pt($perfil);
}

/** Nome exibido na sidebar (sem sufixo de cargo entre parênteses). */
function usuario_nome_sidebar(string $nome): string
{
    $nome = trim($nome);
    if ($nome === '') {
        return '';
    }
    $limpo = preg_replace('/\s*\([^)]*\)\s*$/u', '', $nome);

    return trim($limpo !== null && $limpo !== '' ? $limpo : $nome);
}

/** Primeira letra do nome para avatar na sidebar (ex.: «João» → «J»). */
function usuario_inicial_sidebar_avatar(string $nome): string
{
    $nomeLimpo = usuario_nome_sidebar($nome);
    if ($nomeLimpo === '') {
        return '?';
    }
    if (preg_match('/\p{L}/u', $nomeLimpo, $m)) {
        return mb_strtoupper($m[0], 'UTF-8');
    }

    return '?';
}

/** Iniciais do avatar na sidebar (nome sem parênteses; ignora tokens só com pontuação). */
function usuario_iniciais_avatar(string $nome): string
{
    $nomeLimpo = usuario_nome_sidebar($nome);
    if ($nomeLimpo === '') {
        return '??';
    }
    if (function_exists('repo_usuario_calcular_iniciais')) {
        return repo_usuario_calcular_iniciais($nomeLimpo);
    }
    if (function_exists('initials')) {
        return initials($nomeLimpo);
    }

    return '??';
}
