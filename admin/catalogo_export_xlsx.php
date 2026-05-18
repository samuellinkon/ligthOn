<?php
require_once __DIR__ . '/../includes/auth.php';
$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('catalogo');

require_once __DIR__ . '/../includes/catalogo_export_xlsx.php';

$clienteId = (int) ($_GET['cliente_id'] ?? 0);
if ($clienteId <= 0) {
    $clienteId = (int) (repo_catalogo_cliente_id_padrao_admin() ?? 0);
}
if ($clienteId <= 0) {
    http_response_code(400);
    exit('Cliente inválido.');
}
gestor_assert_escopo_cliente($clienteId, 'catalogo_export_xlsx.php');

$itens = repo_cliente_itens_list($clienteId, false);
catalogo_export_xlsx_send($clienteId, $itens);
exit;
