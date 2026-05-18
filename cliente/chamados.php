<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
$me = require_auth('cliente');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_cliente('chamados');

$CRM_CHAMADOS_PANEL = ['role' => 'cliente', 'user' => $me];
require __DIR__ . '/../includes/chamados_listagem_painel.php';
