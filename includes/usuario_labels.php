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
