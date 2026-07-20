<?php
/**
 * Auditoria no portal cliente foi desativada — apenas administradores.
 * Mantém o ficheiro para redirecionar URLs antigas.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';

require_auth('cliente');
flash_set('err', 'A auditoria não está disponível no portal do cliente.');
header('Location: index.php');
exit;
