<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
$me = require_auth('operador');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_operador('chamados');

$CRM_CHAMADOS_PANEL = ['role' => 'operador', 'user' => $me];
require __DIR__ . '/../includes/chamados_listagem_painel.php';
