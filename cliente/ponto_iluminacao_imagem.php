<?php
/**
 * Exibe imagem do poste (portal cliente) — mesmo fluxo do admin, escopo da matriz.
 */
require_once __DIR__ . '/../includes/auth.php';

$user = require_auth('cliente');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_cliente('pontos_iluminacao');
require_once __DIR__ . '/../includes/upload.php';

$id = (int) ($_GET['id'] ?? 0);
$userClienteId = (int) ($user['cliente_id'] ?? 0);
$scopeRaiz = $userClienteId > 0 ? repo_cliente_matriz_raiz_id($userClienteId) : 0;

if ($id <= 0 || !db_ok() || $scopeRaiz <= 0) {
    http_response_code(400);
    exit;
}

$img = repo_ponto_iluminacao_imagem($id);
if (!$img) {
    http_response_code(404);
    exit;
}
$ponto = repo_ponto_iluminacao((int) ($img['ponto_iluminacao_id'] ?? 0));
if (!$ponto || !repo_ponto_iluminacao_pertence_empresa((int) ($ponto['id'] ?? 0), $scopeRaiz)) {
    http_response_code(404);
    exit;
}

$path = upload_dir_ponto_iluminacao((int) $img['ponto_iluminacao_id']) . DIRECTORY_SEPARATOR . ($img['nome_arquivo'] ?? '');
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$mime = (string) ($img['mime'] ?? '');
if ($mime === '' || $mime === 'application/octet-stream') {
    $mime = function_exists('mime_content_type') ? (mime_content_type($path) ?: 'image/jpeg') : 'image/jpeg';
}
if (stripos($mime, 'image/') !== 0) {
    $mime = 'image/jpeg';
}

$nome = (string) ($img['nome_original'] ?? 'imagem');
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . rawurlencode($nome) . '"');
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: private, max-age=3600');
readfile($path);
exit;
