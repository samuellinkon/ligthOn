<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';

$user = require_auth('cliente');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_cliente('pontos_iluminacao');

$userClienteId = (int) ($user['cliente_id'] ?? 0);
if (!db_ok() || $userClienteId <= 0) {
    flash_set('err', 'Banco indisponível ou cliente inválido.');
    header('Location: pontos_iluminacao.php');
    exit;
}

$scopeId = repo_cliente_matriz_raiz_id($userClienteId);
if ($scopeId <= 0) {
    flash_set('err', 'Empresa não vinculada ao seu acesso.');
    header('Location: pontos_iluminacao.php');
    exit;
}

@ini_set('memory_limit', '512M');
@ini_set('max_execution_time', '180');

$pontos = repo_pontos_iluminacao_list($scopeId, true, '', '');
if ($pontos === []) {
    flash_set('err', 'Nenhum poste cadastrado para exportar.');
    header('Location: pontos_iluminacao.php');
    exit;
}

if (function_exists('audit_log_registar')) {
    require_once __DIR__ . '/../includes/audit_log.php';
    audit_log_registar('pontos.exportar_xlsx', 'ponto_iluminacao', null, $scopeId, [
        'total'  => count($pontos),
        'portal' => 'cliente',
    ]);
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

require_once __DIR__ . '/../includes/pontos_iluminacao_export_xlsx.php';
pontos_iluminacao_export_xlsx_send($pontos, 'parque_iluminacao');
