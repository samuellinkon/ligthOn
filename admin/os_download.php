<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/upload.php';
$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('os');

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('ID inválido.');
}
if (!db_ok()) {
    http_response_code(500);
    exit('Banco indisponível.');
}

$anexo = repo_os_pedido_anexo($id);
if (!$anexo) {
    http_response_code(404);
    exit('Anexo não encontrado.');
}

$osA = repo_os_pedido((int) ($anexo['os_pedido_id'] ?? 0));
if ($osA) {
    gestor_assert_escopo_cliente((int) ($osA['cliente_id'] ?? 0), 'os.php');
}

$path = upload_dir_os_pedido((int) $anexo['os_pedido_id']) . DIRECTORY_SEPARATOR . $anexo['nome_arquivo'];
if (!is_file($path)) {
    http_response_code(404);
    exit('Arquivo ausente no disco.');
}

$mime = $anexo['mime'] ?: 'application/octet-stream';
$size = filesize($path);
$nome = $anexo['nome_original'];
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . rawurlencode($nome) . '"; filename*=UTF-8\'\'' . rawurlencode($nome));
header('Content-Length: ' . $size);
header('Cache-Control: private, no-transform, no-store, must-revalidate');
readfile($path);
exit;
