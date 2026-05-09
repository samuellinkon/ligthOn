<?php
/**
 * Mensagens flash (sobrevivem a 1 redirect).
 * Uso:
 *   flash_set('ok', 'Cliente salvo!');
 *   $m = flash_get(); // retorna ['tipo' => 'ok'|'err', 'msg' => '...'] ou null
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function flash_set(string $tipo, string $msg): void
{
    $_SESSION['__flash'] = ['tipo' => $tipo, 'msg' => $msg];
}

function flash_get(): ?array
{
    if (!isset($_SESSION['__flash'])) return null;
    $f = $_SESSION['__flash'];
    unset($_SESSION['__flash']);
    return $f;
}

function render_flash(): void
{
    $f = flash_get();
    if (!$f) return;

    $cls  = $f['tipo'] === 'err' ? 'toast-err' : 'toast-ok';
    $icon = $f['tipo'] === 'err' ? '!' : '✓';
    echo '<div class="toast ' . $cls . '">'
       . '<div class="toast-icon">' . $icon . '</div>'
       . '<div>' . htmlspecialchars($f['msg']) . '</div>'
       . '</div>';
}
