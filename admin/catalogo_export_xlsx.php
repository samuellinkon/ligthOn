<?php
$CRM_CATALOGO_EXPORT_PORTAL = !empty($CRM_CATALOGO_EXPORT_PORTAL);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/modules.php';
require_once __DIR__ . '/../includes/catalogo_export_xlsx.php';

if ($CRM_CATALOGO_EXPORT_PORTAL) {
    $CLIENTE = require_auth('cliente');
    require_modulo_cliente('catalogo');
    $clienteId = repo_cliente_catalogo_dono_id((int) ($CLIENTE['cliente_id'] ?? 0));
} else {
    $me = require_auth_gestao();
    require_modulo_admin('catalogo');
    $clienteId = (int) ($_GET['cliente_id'] ?? 0);
    if ($clienteId <= 0) {
        $clienteId = (int) (repo_catalogo_cliente_id_padrao_admin() ?? 0);
    }
}

if ($clienteId <= 0) {
    http_response_code(400);
    exit('Cliente inválido.');
}

if (!$CRM_CATALOGO_EXPORT_PORTAL) {
    gestor_assert_escopo_cliente($clienteId, 'catalogo_export_xlsx.php');
}

$itens = repo_cliente_itens_list($clienteId, false);
catalogo_export_xlsx_send($clienteId, $itens);
exit;
