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
