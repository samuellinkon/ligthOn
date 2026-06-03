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

function flash_set_warn(string $msg): void
{
    $_SESSION['__flash_warn'] = $msg;
}

function flash_get(): ?array
{
    if (!isset($_SESSION['__flash'])) return null;
    $f = $_SESSION['__flash'];
    unset($_SESSION['__flash']);
    return $f;
}

function flash_get_warn(): ?string
{
    if (!isset($_SESSION['__flash_warn'])) {
        return null;
    }
    $msg = (string) $_SESSION['__flash_warn'];
    unset($_SESSION['__flash_warn']);

    return $msg;
}

function render_flash(): void
{
    $f = flash_get();
    if ($f) {
        $cls  = $f['tipo'] === 'err' ? 'toast-err' : ($f['tipo'] === 'warn' ? 'toast-warn' : 'toast-ok');
        $icon = $f['tipo'] === 'err' ? '!' : ($f['tipo'] === 'warn' ? '⚠' : '✓');
        echo '<div class="toast ' . $cls . '">'
           . '<div class="toast-icon">' . $icon . '</div>'
           . '<div>' . htmlspecialchars($f['msg']) . '</div>'
           . '</div>';
    }

    $warn = flash_get_warn();
    if ($warn) {
        echo '<div class="toast toast-warn" style="position:static;margin-bottom:14px;">'
           . '<div class="toast-icon">⚠</div>'
           . '<div>' . htmlspecialchars($warn) . '</div>'
           . '</div>';
    }
}
