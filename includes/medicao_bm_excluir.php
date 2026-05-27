<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/medicao_helpers.php';
require_once __DIR__ . '/medicao_custo_repo.php';

/**
 * IDs de chamados criados pela importação do relatório BM no mês de referência.
 *
 * @return list<int>
 */
function medicao_bm_chamados_importados_ids(int $clienteMatrizId, string $refYm): array
{
    if ($clienteMatrizId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $refYm)) {
        return [];
    }
    $pdo = db();
    if (!$pdo) {
        return [];
    }

    $periodoDe  = $refYm . '-01';
    $periodoAte = date('Y-m-t', strtotime($periodoDe . ' 12:00:00'));

    try {
        $st = $pdo->prepare(
            "SELECT c.id
             FROM chamados c
             INNER JOIN clientes cl ON cl.id = c.cliente_id
             WHERE (cl.id = ? OR cl.empresa_id = ?)
               AND (
                   c.origem_os = 'Importação BM'
                   OR ((c.ponto_referencia LIKE 'BM:%' OR c.ponto_referencia LIKE 'GIP:%') AND TRIM(c.ponto_referencia) <> '')
               )
               AND DATE(c.aberto_em) BETWEEN ? AND ?"
        );
        $st->execute([$clienteMatrizId, $clienteMatrizId, $periodoDe, $periodoAte]);
        $ids = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Itens de catálogo ligados só aos chamados indicados (sem outros vínculos).
 *
 * @param list<int> $chamadoIds
 * @return list<int>
 */
function medicao_bm_itens_orfaos_dos_chamados(int $clienteMatrizId, array $chamadoIds): array
{
    if ($clienteMatrizId <= 0 || $chamadoIds === []) {
        return [];
    }
    $pdo = db();
    if (!$pdo) {
        return [];
    }

    $donorId = repo_cliente_catalogo_dono_id($clienteMatrizId);
    if ($donorId <= 0) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($chamadoIds), '?'));
    $params       = $chamadoIds;

    try {
        $st = $pdo->prepare(
            "SELECT DISTINCT ci.item_id AS item_id
             FROM chamado_itens ci
             WHERE ci.chamado_id IN ($placeholders)"
        );
        $st->execute($params);
        $candidatos = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $iid = (int) ($row['item_id'] ?? 0);
            if ($iid > 0) {
                $candidatos[$iid] = true;
            }
        }
        if ($candidatos === []) {
            return [];
        }

        $orfaos = [];
        foreach (array_keys($candidatos) as $itemId) {
            if (medicao_bm_item_pode_excluir_catalogo($pdo, $itemId, $chamadoIds, $donorId)) {
                $orfaos[] = $itemId;
            }
        }

        return $orfaos;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @param list<int> $chamadoIdsExcluir chamados que serão removidos
 */
function medicao_bm_item_pode_excluir_catalogo(PDO $pdo, int $itemId, array $chamadoIdsExcluir, int $donorId): bool
{
    if ($itemId <= 0) {
        return false;
    }

    $ph = $chamadoIdsExcluir !== [] ? implode(',', array_fill(0, count($chamadoIdsExcluir), '?')) : '0';

    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM chamado_itens
         WHERE item_id = ? AND chamado_id NOT IN ($ph)"
    );
    $st->execute(array_merge([$itemId], $chamadoIdsExcluir));
    if ((int) $st->fetchColumn() > 0) {
        return false;
    }

    $st = $pdo->prepare('SELECT COUNT(*) FROM chamados WHERE servico_id = ?');
    $st->execute([$itemId]);
    if ((int) $st->fetchColumn() > 0) {
        return false;
    }

    if (repo_medicao_custos_table_exists()) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM medicao_custos WHERE item_id = ?');
        $st->execute([$itemId]);
        if ((int) $st->fetchColumn() > 0) {
            return false;
        }
    }

    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM os_pedido_itens WHERE item_id = ?');
        $st->execute([$itemId]);
        if ((int) $st->fetchColumn() > 0) {
            return false;
        }
    } catch (Throwable $e) {
        // tabela pode não existir
    }

    $st = $pdo->prepare(
        'SELECT id FROM cliente_itens
         WHERE id = ? AND (cliente_id = ? OR empresa_id = ?) AND ativo = 1
         LIMIT 1'
    );
    $st->execute([$itemId, $donorId, $donorId]);

    return (bool) $st->fetchColumn();
}

/**
 * @return array{
 *   ok:bool,
 *   tem_import:bool,
 *   n_linhas_import:int,
 *   n_chamados:int,
 *   n_itens_orfaos:int,
 *   nome_arquivo:string
 * }
 */
function medicao_bm_excluir_preview(int $clienteMatrizId, string $refYm): array
{
    $ret = [
        'ok'              => false,
        'tem_import'      => false,
        'n_linhas_import' => 0,
        'n_chamados'      => 0,
        'n_itens_orfaos'  => 0,
        'nome_arquivo'    => '',
    ];
    if ($clienteMatrizId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $refYm)) {
        return $ret;
    }

    $impPkg = repo_medicao_import_fetch($clienteMatrizId, $refYm);
    $cab    = $impPkg['cabecalho'] ?? null;
    $linhas = is_array($impPkg['linhas'] ?? null) ? $impPkg['linhas'] : [];
    if ($cab) {
        $ret['tem_import']      = true;
        $ret['n_linhas_import'] = count($linhas);
        $ret['nome_arquivo']    = (string) ($cab['nome_arquivo'] ?? '');
    }

    $chIds = medicao_bm_chamados_importados_ids($clienteMatrizId, $refYm);
    $ret['n_chamados']     = count($chIds);
    $ret['n_itens_orfaos'] = count(medicao_bm_itens_orfaos_dos_chamados($clienteMatrizId, $chIds));
    $ret['ok']             = $ret['tem_import'] || $ret['n_chamados'] > 0;

    return $ret;
}

/**
 * Prévia em lote para vários meses (listagem) — sem contagem de itens órfãos (evita N consultas pesadas).
 *
 * @param list<string> $refYms
 * @return array<string, array{ok:bool,tem_import:bool,n_linhas_import:int,n_chamados:int,n_itens_orfaos:int,nome_arquivo:string}>
 */
function medicao_bm_excluir_mapa_meses(int $clienteMatrizId, array $refYms): array
{
    $map = [];
    if ($clienteMatrizId <= 0) {
        return $map;
    }

    $valid = [];
    foreach ($refYms as $ym) {
        $ym = trim((string) $ym);
        if (preg_match('/^\d{4}-\d{2}$/', $ym)) {
            $valid[$ym] = true;
        }
    }
    if ($valid === []) {
        return $map;
    }

    $pdo = db();
    if (!$pdo) {
        return $map;
    }

    $empty = [
        'ok'              => false,
        'tem_import'      => false,
        'n_linhas_import' => 0,
        'n_chamados'      => 0,
        'n_itens_orfaos'  => 0,
        'nome_arquivo'    => '',
    ];
    foreach (array_keys($valid) as $ym) {
        $map[$ym] = $empty;
    }

    try {
        $ph = implode(',', array_fill(0, count($valid), '?'));
        $st = $pdo->prepare(
            "SELECT i.ref_ym AS ym, i.nome_arquivo,
                    (SELECT COUNT(*) FROM medicao_import_linhas mil WHERE mil.import_id = i.id) AS n_linhas
             FROM medicao_imports i
             WHERE i.cliente_matriz_id = ? AND i.ref_ym IN ($ph)"
        );
        $st->execute(array_merge([$clienteMatrizId], array_keys($valid)));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $ym = (string) ($row['ym'] ?? '');
            if (!isset($map[$ym])) {
                continue;
            }
            $map[$ym]['tem_import']      = true;
            $map[$ym]['n_linhas_import'] = (int) ($row['n_linhas'] ?? 0);
            $map[$ym]['nome_arquivo']    = (string) ($row['nome_arquivo'] ?? '');
            $map[$ym]['ok']              = true;
        }

        $stCh = $pdo->prepare(
            "SELECT DATE_FORMAT(c.aberto_em, '%Y-%m') AS ym, COUNT(*) AS n
             FROM chamados c
             INNER JOIN clientes cl ON cl.id = c.cliente_id
             WHERE (cl.id = ? OR cl.empresa_id = ?)
               AND (
                   c.origem_os = 'Importação BM'
                   OR ((c.ponto_referencia LIKE 'BM:%' OR c.ponto_referencia LIKE 'GIP:%') AND TRIM(c.ponto_referencia) <> '')
               )
             GROUP BY DATE_FORMAT(c.aberto_em, '%Y-%m')"
        );
        $stCh->execute([$clienteMatrizId, $clienteMatrizId]);
        foreach ($stCh->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $ym = (string) ($row['ym'] ?? '');
            if (!isset($map[$ym])) {
                continue;
            }
            $n = (int) ($row['n'] ?? 0);
            if ($n > 0) {
                $map[$ym]['n_chamados'] = $n;
                $map[$ym]['ok']         = true;
            }
        }
    } catch (Throwable $e) {
        return $map;
    }

    return $map;
}

/**
 * Texto do modal de confirmação.
 *
 * @param array{ok:bool,tem_import:bool,n_linhas_import:int,n_chamados:int,n_itens_orfaos:int,nome_arquivo:string} $preview
 */
function medicao_bm_excluir_mensagem_confirmacao(array $preview, string $refYm, bool $mencionarOpcaoItens = false): string
{
    $mesLabel = medicao_mes_label_pt($refYm);
    $msg      = 'Excluir DEFINITIVAMENTE a medição BM de ' . $mesLabel . ' (' . $refYm . ')? ';

    if (!empty($preview['tem_import'])) {
        $msg .= 'Será removida a planilha importada';
        $nome = trim((string) ($preview['nome_arquivo'] ?? ''));
        if ($nome !== '') {
            $msg .= ' («' . $nome . '»)';
        }
        $msg .= ' com ' . (int) ($preview['n_linhas_import'] ?? 0) . ' linha(s). ';
    }
    if ((int) ($preview['n_chamados'] ?? 0) > 0) {
        $msg .= 'Serão excluídos ' . (int) $preview['n_chamados'] . ' chamado(s) criados pela importação BM. ';
    }
    if ($mencionarOpcaoItens) {
        $msg .= 'Se marcar a opção abaixo, itens do catálogo usados apenas nesses chamados também serão removidos. ';
    } elseif ((int) ($preview['n_itens_orfaos'] ?? 0) > 0) {
        $msg .= 'Serão removidos ' . (int) $preview['n_itens_orfaos'] . ' item(ns) do catálogo usados só nesses chamados. ';
    }
    $msg .= 'Custos adicionais da medição e chamados manuais do período NÃO são alterados. Esta ação não pode ser desfeita.';

    return $msg;
}

/**
 * Remove importação BM do mês, chamados gerados pela importação e itens de catálogo órfãos.
 *
 * @return array{
 *   ok:bool,
 *   erro:string,
 *   n_import_removido:int,
 *   n_chamados:int,
 *   n_itens:int
 * }
 */
function repo_medicao_bm_excluir_mes_completo(
    int $clienteMatrizId,
    string $refYm,
    bool $excluirItensCatalogo = true
): array {
    $ret = [
        'ok'                => false,
        'erro'              => '',
        'n_import_removido' => 0,
        'n_chamados'        => 0,
        'n_itens'           => 0,
    ];

    if ($clienteMatrizId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $refYm)) {
        $ret['erro'] = 'Parâmetros inválidos.';

        return $ret;
    }

    $preview = medicao_bm_excluir_preview($clienteMatrizId, $refYm);
    if (!$preview['ok']) {
        $ret['erro'] = 'Não há importação BM nem chamados de importação para ' . medicao_mes_label_pt($refYm) . '.';

        return $ret;
    }

    $pdo = db();
    if (!$pdo) {
        $ret['erro'] = 'Banco indisponível.';

        return $ret;
    }

    $chamadoIds = medicao_bm_chamados_importados_ids($clienteMatrizId, $refYm);
    $itemIds    = $excluirItensCatalogo
        ? medicao_bm_itens_orfaos_dos_chamados($clienteMatrizId, $chamadoIds)
        : [];

    try {
        $pdo->beginTransaction();

        foreach ($chamadoIds as $chId) {
            if (!repo_delete_chamado($chId)) {
                throw new RuntimeException('Não foi possível excluir o chamado #' . $chId . '.');
            }
            $ret['n_chamados']++;
        }

        if ($preview['tem_import']) {
            $st = $pdo->prepare('DELETE FROM medicao_imports WHERE cliente_matriz_id = ? AND ref_ym = ?');
            $st->execute([$clienteMatrizId, $refYm]);
            if ($st->rowCount() > 0) {
                $ret['n_import_removido'] = 1;
            }
        }

        if ($excluirItensCatalogo && $itemIds !== []) {
            $donorId = repo_cliente_catalogo_dono_id($clienteMatrizId);
            foreach ($itemIds as $itemId) {
                if (repo_cliente_item_excluir($donorId, $itemId)) {
                    $ret['n_itens']++;
                }
            }
        }

        $pdo->commit();
        $ret['ok'] = true;

        if (!function_exists('audit_log_registar')) {
            require_once __DIR__ . '/audit_log.php';
        }
        audit_log_registar('medicao.excluir_bm_mes', 'medicao', null, $clienteMatrizId, [
            'ref_ym'              => $refYm,
            'n_chamados'          => $ret['n_chamados'],
            'n_itens'             => $ret['n_itens'],
            'n_import_removido'   => $ret['n_import_removido'],
            'excluir_itens'       => $excluirItensCatalogo,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $ret['erro'] = $e->getMessage() !== '' ? $e->getMessage() : 'Falha ao excluir dados do BM.';
    }

    return $ret;
}
