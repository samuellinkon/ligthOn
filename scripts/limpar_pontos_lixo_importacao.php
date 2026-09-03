<?php
/**
 * Remove postes-lixo de importações antigas (códigos tipo 649495065-31)
 * e qualquer ponto cujo Código não esteja na planilha oficial.
 *
 * Mantém os 10.417 Códigos da planilha Cadastro Ipojuca.
 * Chamados ligados a um lixo `CODIGO-N` são remapeados para o poste `CODIGO`.
 * Fotos do lixo caem com o ponto (ON DELETE CASCADE). Chamados sem remap
 * ficam com ponto_iluminacao_id NULL (ON DELETE SET NULL).
 *
 * Simulação (não apaga):
 *   php scripts/limpar_pontos_lixo_importacao.php \
 *     --planilha="/caminho/Cadastro Ipojuca_2026_FINAL IMPLANTACAO SISTEMA.xlsx"
 *
 * Aplicar de verdade:
 *   php scripts/limpar_pontos_lixo_importacao.php --planilha="..." --aplicar
 *
 * Sem a planilha, apaga só códigos com hífen (`123-45`):
 *   php scripts/limpar_pontos_lixo_importacao.php --apenas-hifen --aplicar
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/repository.php';
require_once $root . '/includes/pontos_iluminacao_import.php';

$opts = getopt('', ['planilha:', 'cliente:', 'aplicar', 'apenas-hifen', 'forcar', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "Uso: php scripts/limpar_pontos_lixo_importacao.php [--planilha=PATH] [--cliente=ID] [--apenas-hifen] [--aplicar] [--forcar]\n");
    exit(0);
}

$aplicar = isset($opts['aplicar']);
$apenasHifen = isset($opts['apenas-hifen']);
$forcar = isset($opts['forcar']);
$planilha = isset($opts['planilha']) ? (string) $opts['planilha'] : '';
$clienteArg = isset($opts['cliente']) ? (int) $opts['cliente'] : 0;

if ($planilha === '' && !$apenasHifen) {
    $candidatos = [
        '/Users/samuel/Desktop/Cadastro Ipojuca_2026_FINAL IMPLANTACAO SISTEMA.xlsx',
        $root . '/Cadastro Ipojuca_2026_FINAL IMPLANTACAO SISTEMA.xlsx',
    ];
    foreach ($candidatos as $c) {
        if (is_readable($c)) {
            $planilha = $c;
            break;
        }
    }
}

if (!db_ok()) {
    fwrite(STDERR, "Banco indisponível.\n");
    exit(1);
}

$pdo = db();
$scopeId = $clienteArg > 0 ? repo_cliente_matriz_raiz_id($clienteArg) : 0;
if ($scopeId <= 0) {
    $empresas = repo_clientes_empresas();
    $ipojuca = [];
    foreach ($empresas as $e) {
        $rotulo = trim((string) ($e['empresa'] ?? '') . ' ' . (string) ($e['nome'] ?? ''));
        if (stripos($rotulo, 'ipojuca') !== false) {
            $ipojuca[] = $e;
        }
    }
    if (count($ipojuca) === 1) {
        $scopeId = (int) $ipojuca[0]['id'];
    } elseif (count($empresas) === 1) {
        $scopeId = (int) $empresas[0]['id'];
    }
}
if ($scopeId <= 0) {
    fwrite(STDERR, "Não achei a empresa. Passe --cliente=ID.\n");
    exit(1);
}

$oficial = [];
if (!$apenasHifen) {
    if ($planilha === '' || !is_readable($planilha)) {
        fwrite(STDERR, "Planilha oficial não encontrada. Passe --planilha=PATH ou use --apenas-hifen.\n");
        exit(1);
    }
    $parsed = pontos_iluminacao_import_parse_upload($planilha, basename($planilha));
    if (empty($parsed['ok'])) {
        fwrite(STDERR, 'Falha ao ler a planilha: ' . (string) ($parsed['erro'] ?? '') . "\n");
        exit(1);
    }
    foreach ($parsed['linhas'] as $linha) {
        $c = trim((string) ($linha['codigo_poste'] ?? ''));
        if ($c !== '') {
            $oficial[$c] = true;
        }
    }
    if (count($oficial) < 10000) {
        fwrite(STDERR, 'A planilha oficial deveria ter ~10.417 Códigos; li ' . count($oficial) . ". Abortei. Use --forcar se for intencional.\n");
        if (!$forcar) {
            exit(1);
        }
    }
}

$st = $pdo->prepare('
    SELECT pi.id, pi.cliente_id, pi.codigo_poste, pi.identificador_externo, pi.status
    FROM pontos_iluminacao pi
    WHERE pi.cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
    ORDER BY pi.codigo_poste ASC, pi.id ASC
');
$st->execute([$scopeId, $scopeId]);
$todos = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$manter = [];
$lixo = [];
foreach ($todos as $p) {
    $cod = trim((string) ($p['codigo_poste'] ?? ''));
    $ehHifen = (bool) preg_match('/^\d+-\d+$/', $cod);
    $ehOficial = $oficial !== [] && isset($oficial[$cod]);
    if ($apenasHifen) {
        $vaiFora = $ehHifen;
    } else {
        $vaiFora = !$ehOficial;
    }
    if ($vaiFora) {
        $lixo[] = $p;
    } else {
        $manter[$cod] = (int) $p['id'];
    }
}

$lixoIds = array_map(static fn (array $p): int => (int) $p['id'], $lixo);
$nLixo = count($lixoIds);
$nManter = count($manter);
$nTotal = count($todos);

echo "Empresa #{$scopeId}\n";
echo $planilha !== '' ? 'Planilha: ' . $planilha . "\n" : "Modo: apenas códigos com hífen\n";
echo 'Oficiais na planilha: ' . count($oficial) . "\n";
echo "Pontos no banco: {$nTotal}\n";
echo "Manter: {$nManter}\n";
echo "Remover (lixo): {$nLixo}\n";

if ($nLixo === 0) {
    echo "Nada para remover.\n";
    exit(0);
}

if (!$forcar && ($nLixo < 2000 || $nLixo > 5000) && !$apenasHifen) {
    fwrite(STDERR, "Quantidade de lixo ({$nLixo}) fora da faixa esperada (~3.383). Use --forcar para seguir.\n");
    exit(1);
}

$amostra = array_slice($lixo, 0, 15);
echo "Amostra a remover:\n";
foreach ($amostra as $p) {
    echo '  #' . (int) $p['id'] . '  ' . (string) $p['codigo_poste'] . "\n";
}

$ph = implode(',', array_fill(0, count($lixoIds), '?'));
$stCh = $pdo->prepare("SELECT id, ponto_iluminacao_id FROM chamados WHERE ponto_iluminacao_id IN ($ph)");
$stCh->execute($lixoIds);
$chamadosLixo = $stCh->fetchAll(PDO::FETCH_ASSOC) ?: [];
echo 'Chamados ligados ao lixo: ' . count($chamadosLixo) . "\n";

$remap = [];
$semRemap = 0;
$lixoPorId = [];
foreach ($lixo as $p) {
    $lixoPorId[(int) $p['id']] = trim((string) $p['codigo_poste']);
}
foreach ($chamadosLixo as $ch) {
    $pid = (int) ($ch['ponto_iluminacao_id'] ?? 0);
    $cod = $lixoPorId[$pid] ?? '';
    $alvo = 0;
    if (preg_match('/^(\d+)-\d+$/', $cod, $m) && isset($manter[$m[1]])) {
        $alvo = $manter[$m[1]];
    } elseif (isset($manter[$cod])) {
        $alvo = $manter[$cod];
    }
    if ($alvo > 0) {
        $remap[(int) $ch['id']] = $alvo;
    } else {
        $semRemap++;
    }
}
echo 'Chamados a remapear para o poste oficial: ' . count($remap) . "\n";
echo "Chamados que ficam sem poste (SET NULL): {$semRemap}\n";

if (!$aplicar) {
    echo "\nSimulação. Nada foi apagado. Rode de novo com --aplicar para executar.\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    if ($remap !== []) {
        $stUp = $pdo->prepare('UPDATE chamados SET ponto_iluminacao_id = ? WHERE id = ?');
        foreach ($remap as $chamadoId => $pontoId) {
            $stUp->execute([$pontoId, $chamadoId]);
        }
    }

    foreach (array_chunk($lixoIds, 400) as $chunk) {
        $phDel = implode(',', array_fill(0, count($chunk), '?'));
        $pdo->prepare("DELETE FROM pontos_iluminacao WHERE id IN ($phDel)")->execute($chunk);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Erro ao apagar: ' . $e->getMessage() . "\n");
    exit(1);
}

foreach ($lixoIds as $id) {
    $dir = $root . '/uploads/pontos_iluminacao/' . $id;
    if (!is_dir($dir)) {
        continue;
    }
    foreach (glob($dir . '/*') ?: [] as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
    @rmdir($dir);
}

require_once $root . '/includes/pontos_mapa_cache.php';
pontos_mapa_cache_invalidate_cliente($scopeId);

$stRest = $pdo->prepare('
    SELECT COUNT(*) FROM pontos_iluminacao
    WHERE cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
');
$stRest->execute([$scopeId, $scopeId]);
$restante = (int) $stRest->fetchColumn();

echo "\nPronto. Removidos: {$nLixo}. Parque restante: {$restante}.\n";
