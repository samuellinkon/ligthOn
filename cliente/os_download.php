<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/upload.php';
$user = require_auth('cliente');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_cliente('os');

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
$os = repo_os_pedido((int) ($anexo['os_pedido_id'] ?? 0));
$clienteId = (int) ($user['cliente_id'] ?? 0);
if (!$os || !repo_cliente_pertence_empresa((int) $os['cliente_id'], $clienteId)) {
    http_response_code(403);
    exit('Acesso negado.');
}

$path = upload_dir_os_pedido((int) $anexo['os_pedido_id']) . DIRECTORY_SEPARATOR . $anexo['nome_arquivo'];
if (!is_file($path)) {
    http_response_code(404);
    exit('Arquivo ausente.');
}

$mime = $anexo['mime'] ?: 'application/octet-stream';
$size = filesize($path);
$nome = $anexo['nome_original'];
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . rawurlencode($nome) . '"; filename*=UTF-8\'\'' . rawurlencode($nome));
header('Content-Length: ' . (string) $size);
readfile($path);
exit;
