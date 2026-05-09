<?php
/**
 * Módulo de contas/cobranças desativado — mantido apenas redirecionamento para bookmarks antigos.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_auth_gestao();
header('Location: index.php', true, 302);
exit;
