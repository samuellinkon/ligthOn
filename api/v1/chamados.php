<?php
/**
 * GET JSON — lista de chamados (escopo da matriz padrão do catálogo).
 * Cabeçalhos: X-Api-Key, X-Api-Secret
 *
 * Query opcional: page, per_page (máx. 200), filtro, q, data_de, data_ate (YYYY-MM-DD)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/api_public_auth.php';

api_public_require_auth();

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = (int) ($_GET['per_page'] ?? 50);
$perPage = max(1, min(200, $perPage));

$filtro = strtolower(trim((string) ($_GET['filtro'] ?? 'todos')));
if (!in_array($filtro, ['todos', 'abertos', 'andamento', 'aguardando', 'resolvidos', 'cancelados', 'urgentes'], true)) {
    $filtro = 'todos';
}
if ($filtro === 'todos') {
    $filtro = '';
}

$q = trim((string) ($_GET['q'] ?? ''));
$dataDe = trim((string) ($_GET['data_de'] ?? ''));
$dataAte = trim((string) ($_GET['data_ate'] ?? ''));

$dataAbertaDe = null;
$dataAbertaAte = null;
if ($dataDe !== '' && $dataAte !== ''
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDe) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAte)
    && $dataDe <= $dataAte) {
    $dataAbertaDe = $dataDe;
    $dataAbertaAte = $dataAte;
}

$empresaRaizId = repo_catalogo_cliente_id_padrao_admin();
if ($empresaRaizId === null || $empresaRaizId <= 0) {
    api_public_json_exit(503, [
        'ok' => false,
        'error' => 'no_scope',
        'message' => 'Não há matriz (prefeitura) definida para o catálogo.',
    ]);
}

$lista = repo_chamados_admin_list(
    $filtro,
    $q,
    $page,
    $perPage,
    $empresaRaizId,
    $dataAbertaDe,
    $dataAbertaAte
);

api_public_json_exit(200, [
    'ok'    => true,
    'page'  => $page,
    'per_page' => $perPage,
    'total' => $lista['total'],
    'rows'  => $lista['rows'],
]);
