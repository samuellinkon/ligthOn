<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('chamados');

$CRM_CHAMADOS_PANEL = ['role' => 'gestao', 'user' => $me];
require __DIR__ . '/../includes/chamados_listagem_painel.php';
