<?php
/**
 * Importação BM via CLI (aba MEDIÇÃO NN).
 *
 * Uso:
 *   php scripts/import_bm_medicao40.php --arquivo="/caminho/planilha.xlsx" --ref=2026-06 [--teste] [--cliente=12]
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/repository.php';
require_once $root . '/includes/medicao_xlsx_import.php';

$opts = getopt('', ['arquivo:', 'ref:', 'teste', 'cliente:', 'help']);

if (isset($opts['help']) || empty($opts['arquivo']) || empty($opts['ref'])) {
    fwrite(STDERR, "Uso: php scripts/import_bm_medicao40.php --arquivo=PATH --ref=YYYY-MM [--teste] [--cliente=ID]\n");
    exit(isset($opts['help']) ? 0 : 1);
}

$arquivo = (string) $opts['arquivo'];
$refYm   = (string) $opts['ref'];
$teste   = isset($opts['teste']);
$clienteId = isset($opts['cliente']) ? (int) $opts['cliente'] : (int) (repo_catalogo_cliente_id_padrao_admin() ?? 0);

if (!is_readable($arquivo)) {
    fwrite(STDERR, "Arquivo ilegível: {$arquivo}\n");
    exit(1);
}
if (!preg_match('/^\d{4}-\d{2}$/', $refYm)) {
    fwrite(STDERR, "ref inválido (use YYYY-MM): {$refYm}\n");
    exit(1);
}
if (!db_ok()) {
    fwrite(STDERR, "Banco indisponível.\n");
    exit(1);
}
if ($clienteId <= 0) {
    fwrite(STDERR, "Defina --cliente=ID ou empresa padrão do catálogo.\n");
    exit(1);
}

$parse = medicao_xlsx_parse_bm_upload($arquivo, $refYm, 'medicao');
if (!$parse['ok']) {
    fwrite(STDERR, 'Parse falhou: ' . ($parse['erro'] ?? '') . "\n");
    exit(1);
}

$linhas = is_array($parse['linhas'] ?? null) ? $parse['linhas'] : [];
if ($teste) {
    $linhas = array_slice($linhas, 0, 10);
}

$nomeArq = basename($arquivo);
$grav = repo_medicao_import_substituir(
    $clienteId,
    $refYm,
    $nomeArq,
    'CLI import_bm_medicao40',
    isset($parse['idx_qtd_medido']) ? (int) $parse['idx_qtd_medido'] : null,
    isset($parse['idx_valor_medido']) ? (int) $parse['idx_valor_medido'] : null,
    $linhas,
    0,
    !$teste
);

if (!$grav['ok']) {
    fwrite(STDERR, 'Gravação falhou: ' . ($grav['erro'] ?? '') . "\n");
    exit(1);
}

$soma = 0.0;
foreach ($linhas as $L) {
    $soma += (float) ($L['valor_medido_periodo'] ?? 0);
}

$modo = $teste ? 'teste' : 'completo';
echo "OK {$modo}: " . count($linhas) . " linha(s), ref {$refYm}, cliente {$clienteId}, soma R$ " . number_format($soma, 2, ',', '.') . "\n";
foreach (array_slice($linhas, 0, 5) as $L) {
    echo '  ' . ($L['item_codigo'] ?? '') . ' q=' . ($L['qtd_medido_periodo'] ?? '—') . ' v=' . ($L['valor_medido_periodo'] ?? '—') . "\n";
}
if (count($linhas) > 5) {
    echo "  ...\n";
}

exit(0);
