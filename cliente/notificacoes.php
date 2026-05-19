<?php
require_once __DIR__ . '/../includes/auth.php';

$user = require_auth('cliente', '../');
$NOTIF_PAINEL = [
    'uid'        => (int) ($user['id'] ?? 0),
    'sidebar'    => 'sidebar-cliente.php',
    'voltar'     => 'index.php',
    'self'       => 'notificacoes.php',
    'pageTitle'  => 'Notificações',
    'activePage' => '',
];

require __DIR__ . '/../includes/notificacoes_painel.php';
