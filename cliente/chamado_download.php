<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/upload.php';
$user = require_auth('cliente');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_cliente('chamados');

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('ID inválido.'); }
if (!db_ok()) { http_response_code(500); exit('Banco indisponível.'); }

$anexo = repo_chamado_anexo($id);
if (!$anexo) { http_response_code(404); exit('Anexo não encontrado.'); }

/* Só permite baixar se o chamado pertencer ao cliente logado */
$chamado = repo_chamado((int) $anexo['chamado_id']);
if (!$chamado || (int) $chamado['cliente_id'] !== (int) $user['cliente_id']) {
    http_response_code(403); exit('Acesso negado.');
}

$path = upload_dir_chamado((int) $anexo['chamado_id']) . DIRECTORY_SEPARATOR . $anexo['nome_arquivo'];
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
