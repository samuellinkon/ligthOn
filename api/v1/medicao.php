<?php
/**
 * GET JSON — resumo mensal de medição (chamados + importações BM) na matriz padrão do catálogo.
 * Cabeçalhos: X-Api-Key, X-Api-Secret
 *
 * Query opcional: limite (máx. 120)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/api_public_auth.php';

api_public_require_auth();

$limite = (int) ($_GET['limite'] ?? 60);
$limite = max(1, min(120, $limite));

$empresaRaizId = repo_catalogo_cliente_id_padrao_admin();
if ($empresaRaizId === null || $empresaRaizId <= 0) {
    api_public_json_exit(503, [
        'ok' => false,
        'error' => 'no_scope',
        'message' => 'Não há matriz (prefeitura) definida para o catálogo.',
    ]);
}

$rows = repo_medicao_resumo_mensal_list($empresaRaizId, $limite);

api_public_json_exit(200, [
    'ok'   => true,
    'limite' => $limite,
    'rows' => $rows,
]);
