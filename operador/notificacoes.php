<?php
require_once __DIR__ . '/../includes/auth.php';

$user = require_auth('operador', '../');
$NOTIF_PAINEL = [
    'uid'              => (int) ($user['id'] ?? 0),
    'sidebar'          => 'sidebar-operador.php',
    'voltar'           => 'index.php',
    'self'             => 'notificacoes.php',
    'pageTitle'        => 'Notificações',
    'activePage'       => '',
    'somenteAtribuido' => true,
];

require __DIR__ . '/../includes/notificacoes_painel.php';
