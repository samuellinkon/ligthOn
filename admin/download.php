<?php
/**
 * Servidor seguro de downloads de anexos.
 * Admin pode baixar qualquer anexo.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/upload.php';
require_auth('admin');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('clientes');

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('ID inválido.'); }

if (!db_ok()) { http_response_code(500); exit('Banco indisponível.'); }

$anexo = repo_cliente_anexo($id);
if (!$anexo) { http_response_code(404); exit('Anexo não encontrado.'); }

$path = upload_dir_cliente((int) $anexo['cliente_id']) . DIRECTORY_SEPARATOR . $anexo['nome_arquivo'];
if (!is_file($path)) { http_response_code(404); exit('Arquivo ausente no disco.'); }

$mime = $anexo['mime'] ?: 'application/octet-stream';
$size = filesize($path);
$nome = $anexo['nome_original'];

while (ob_get_level() > 0) { ob_end_clean(); }

header('Content-Description: File Transfer');
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . rawurlencode($nome) . '"; filename*=UTF-8\'\'' . rawurlencode($nome));
header('Content-Length: ' . $size);
header('Cache-Control: private, no-transform, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($path);
exit;
