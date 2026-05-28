<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/medicao_csv_import.php';
require_once __DIR__ . '/medicao_helpers.php';

/**
 * Resolve item do catálogo pelo código ou cria com dados da planilha.
 */
function medicao_relatorio_item_catalogo_resolver(
    int $clienteMatrizId,
    string $codigo,
    string $nome,
    string $unidade,
    string $tipo,
    float $valorUnitario
): array {
    $ret = ['ok' => false, 'err' => '', 'item_id' => 0];
    if ($clienteMatrizId <= 0 || $codigo === '') {
        $ret['err'] = 'Item inválido.';

        return $ret;
    }

    $pdo = db();
    if (!$pdo) {
        $ret['err'] = 'Banco indisponível.';

        return $ret;
    }

    [, , , $codigoNorm] = repo_cliente_item_campos_normalizados('', null, 'UN', $codigo);
    if ($codigoNorm === null || $codigoNorm === '') {
        $ret['err'] = 'Item inválido.';

        return $ret;
    }

    $row = repo_cliente_item_row_por_codigo($clienteMatrizId, $codigoNorm, true);
    if ($row !== null && (int) ($row['id'] ?? 0) > 0) {
        $id = (int) $row['id'];
        if ($valorUnitario > 0 && abs((float) ($row['valor_unitario'] ?? 0) - $valorUnitario) > 0.0001) {
            $pdo->prepare('UPDATE cliente_itens SET valor_unitario = ? WHERE id = ? LIMIT 1')
                ->execute([$valorUnitario, $id]);
        }

        return ['ok' => true, 'err' => '', 'item_id' => $id];
    }

    $donorId = repo_cliente_catalogo_dono_id($clienteMatrizId);
    $salv = repo_cliente_item_salvar(
        $donorId,
        null,
        $tipo,
        $nome !== '' ? $nome : ('Item ' . $codigoNorm),
        $codigoNorm,
        $unidade !== '' ? $unidade : 'UN',
        $valorUnitario,
        null,
        1
    );
    if (!$salv['ok'] || empty($salv['id'])) {
        return ['ok' => false, 'err' => $salv['err'] !== '' ? $salv['err'] : 'Falha ao cadastrar item.', 'item_id' => 0];
    }

    return ['ok' => true, 'err' => '', 'item_id' => (int) $salv['id']];
}

/**
 * Garante itens de catálogo para códigos BM (1.1, 2.3…) sem duplicar entre importações.
 *
 * @param list<array<string,mixed>> $linhas saída do parse BM (medicao_import_linhas)
 */
function medicao_import_sync_catalogo_from_bm_linhas(int $clienteMatrizId, array $linhas): void
{
    if ($clienteMatrizId <= 0 || $linhas === []) {
        return;
    }

    $seen = [];
    foreach ($linhas as $L) {
        if (!is_array($L)) {
            continue;
        }
        $cod = trim((string) ($L['item_codigo'] ?? ''));
        if ($cod === '' || !preg_match('/^\d+(\.\d+)+$/', $cod)) {
            continue;
        }
        $key = medicao_bm_boletim_norm_cod($cod);
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $vu = isset($L['preco_unitario']) && $L['preco_unitario'] !== null && $L['preco_unitario'] !== ''
            ? (float) $L['preco_unitario']
            : 0.0;

        medicao_relatorio_item_catalogo_resolver(
            $clienteMatrizId,
            $cod,
            trim((string) ($L['descricao'] ?? '')),
            trim((string) ($L['unidade'] ?? '')) !== '' ? trim((string) ($L['unidade'] ?? '')) : 'UN',
            'produto',
            $vu
        );
    }
}

/**
 * @param list<array<string,mixed>> $grupos saída de medicao_relatorio_parse_grupos_chamados()['grupos']
 * @return array{ok:bool,erro:string,n_chamados:int,n_itens:int,n_chamados_pulados:int,chamado_ids:list<int>}
 */
function medicao_relatorio_import_criar_chamados(
    int $clienteMatrizId,
    string $refYm,
    array $grupos,
    ?int $userId = null,
    bool $pularExistentes = true,
    bool $notificarDestinatarios = true
): array {
    $ret = [
        'ok'                 => false,
        'erro'               => '',
        'n_chamados'         => 0,
        'n_itens'            => 0,
        'n_chamados_pulados' => 0,
        'chamado_ids'        => [],
    ];

    if ($clienteMatrizId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $refYm) || $grupos === []) {
        $ret['erro'] = 'Parâmetros inválidos para importação de chamados.';

        return $ret;
    }

    $pdo = db();
    if (!$pdo) {
        $ret['erro'] = 'Banco indisponível.';

        return $ret;
    }

    $periodoDe  = $refYm . '-01';
    $periodoAte = date('Y-m-t', strtotime($periodoDe));

    try {
        foreach ($grupos as $gr) {
            $proto = (string) ($gr['protocolo'] ?? '');
            if ($proto === '') {
                continue;
            }

            if ($pularExistentes) {
                $stEx = $pdo->prepare(
                    "SELECT id FROM chamados
                     WHERE cliente_id IN (SELECT id FROM clientes WHERE id = ? OR empresa_id = ?)
                       AND ponto_referencia IN (?, ?)
                       AND DATE(aberto_em) BETWEEN ? AND ?
                     LIMIT 1"
                );
                $stEx->execute([
                    $clienteMatrizId,
                    $clienteMatrizId,
                    'BM:' . $proto,
                    'GIP:' . $proto,
                    $periodoDe,
                    $periodoAte,
                ]);
                $exId = (int) ($stEx->fetchColumn() ?: 0);
                if ($exId > 0) {
                    $ret['n_chamados_pulados']++;
                    continue;
                }
            }

            $bairro = trim((string) ($gr['bairro'] ?? ''));
            $rua    = trim((string) ($gr['rua'] ?? ''));
            $titulo = $proto !== '' && !str_starts_with($proto, 'LINHA-')
                ? 'OS ' . $proto
                : 'OS importada BM';
            if ($bairro !== '' || $rua !== '') {
                $titulo .= ' — ' . trim($bairro . ($rua !== '' ? ' · ' . $rua : ''));
            }

            $dataYmd = (string) ($gr['data_ymd'] ?? '');
            if ($dataYmd === '' || $dataYmd < $periodoDe || $dataYmd > $periodoAte) {
                $dataYmd = min($periodoAte, date('Y-m-d'));
            }

            $descParts = array_filter([
                $gr['servicos_grupo'] ?? null,
                $gr['tipo_os'] ?? null,
                $gr['plaqueta'] !== '' ? 'Plaqueta: ' . $gr['plaqueta'] : null,
            ]);
            $descricao = $descParts !== [] ? implode(' · ', $descParts) : 'Importado do relatório detalhado BM.';

            $chPayload = [
                'cliente_id'        => $clienteMatrizId,
                'titulo'            => mb_substr($titulo, 0, 200),
                'descricao'         => $descricao,
                'data_abertura_os'  => $dataYmd,
                'origem_os'         => 'Importação BM',
                'tipo_os'           => ($gr['tipo_os'] ?? '') !== '' ? mb_substr((string) $gr['tipo_os'], 0, 80) : null,
                'ponto_referencia'  => 'BM:' . $proto,
                'os_bairro'         => $bairro !== '' ? mb_substr($bairro, 0, 120) : null,
                'os_logradouro'     => $rua !== '' ? mb_substr($rua, 0, 255) : null,
                'latitude'          => $gr['latitude'] ?? null,
                'longitude'         => $gr['longitude'] ?? null,
                'prioridade'        => 'Normal',
                'status'            => 'Validado',
                'responsavel'       => ($gr['equipe'] ?? '') !== '' ? mb_substr((string) $gr['equipe'], 0, 80) : null,
                'criado_por_user_id'=> $userId,
            ];

            $chId = repo_create_chamado($chPayload);
            if ($chId === null || $chId <= 0) {
                throw new RuntimeException('Falha ao criar chamado para protocolo ' . $proto);
            }

            $abertoEm = $dataYmd . ' 12:00:00';
            $pdo->prepare(
                'UPDATE chamados SET aberto_em = ?, finalizado_operador_em = COALESCE(finalizado_operador_em, ?),
                    aprovado_gestor_em = COALESCE(aprovado_gestor_em, ?), status = ?
                 WHERE id = ? LIMIT 1'
            )->execute([$abertoEm, $abertoEm, $abertoEm, 'Validado', $chId]);

            $itens = is_array($gr['itens'] ?? null) ? $gr['itens'] : [];
            foreach ($itens as $it) {
                $cod  = trim((string) ($it['codigo'] ?? ''));
                $qtd  = (float) ($it['quantidade'] ?? 0);
                $vu   = (float) ($it['valor_unitario'] ?? 0);
                $vtot = (float) ($it['valor_total'] ?? 0);
                if ($cod === '' || $qtd <= 0) {
                    continue;
                }

                $cat = medicao_relatorio_item_catalogo_resolver(
                    $clienteMatrizId,
                    $cod,
                    trim((string) ($it['descricao'] ?? '')),
                    trim((string) ($it['unidade'] ?? 'UN')),
                    (string) ($it['tipo'] ?? 'servico'),
                    $vu
                );
                if (!$cat['ok'] || (int) ($cat['item_id'] ?? 0) <= 0) {
                    throw new RuntimeException('Item ' . $cod . ': ' . ($cat['err'] ?? 'erro'));
                }

                $add = repo_chamado_item_adicionar(
                    $chId,
                    (int) $cat['item_id'],
                    $qtd,
                    'utilizado',
                    'Importação relatório BM',
                    $vu > 0 ? $vu : null,
                    $vtot > 0 ? $vtot : null
                );
                if (!$add['ok']) {
                    throw new RuntimeException('Chamado ' . $chId . ' item ' . $cod . ': ' . ($add['err'] ?? ''));
                }
                $ret['n_itens']++;
            }

            $pdo->prepare(
                'UPDATE chamado_itens SET criado_em = ? WHERE chamado_id = ?'
            )->execute([$abertoEm, $chId]);

            $ret['n_chamados']++;
            $ret['chamado_ids'][] = $chId;
        }

        $ret['ok'] = $ret['n_chamados'] > 0 || $ret['n_chamados_pulados'] > 0;
        if (!$ret['ok']) {
            $ret['erro'] = 'Nenhum chamado foi criado (todos já existiam ou sem dados).';
        }

        audit_log_registar('medicao.importar_chamados', 'medicao', null, $clienteMatrizId, [
            'ref_ym'             => $refYm,
            'n_chamados'         => $ret['n_chamados'],
            'n_itens'            => $ret['n_itens'],
            'n_chamados_pulados' => $ret['n_chamados_pulados'],
        ]);

        if ($notificarDestinatarios && $ret['n_chamados'] > 0) {
            require_once __DIR__ . '/notificacoes.php';
            $chRef = !empty($ret['chamado_ids']) ? (int) $ret['chamado_ids'][0] : 0;
            notificar_medicao_bm_importado(
                $clienteMatrizId,
                $refYm,
                $userId !== null && $userId > 0 ? $userId : 0,
                'chamados',
                (int) $ret['n_chamados'],
                null,
                $chRef
            );
        }

        if ($ret['ok'] && ($ret['n_itens'] > 0 || $ret['n_chamados_pulados'] > 0)) {
            $recEst = repo_catalogo_recalcular_estoque_saldo($clienteMatrizId);
            $ret['estoque_recalc_ok']        = $recEst['ok'];
            $ret['estoque_recalc_alterados'] = (int) ($recEst['itens_alterados'] ?? 0);
            if (!$recEst['ok'] && ($recEst['err'] ?? '') !== '') {
                $ret['estoque_recalc_erro'] = (string) $recEst['err'];
            }
        }
    } catch (Throwable $e) {
        $ret['ok']   = false;
        $ret['erro'] = $e->getMessage();
    }

    return $ret;
}

/**
 * @param list<list<string>> $rows
 */
function medicao_relatorio_import_from_rows(
    int $clienteMatrizId,
    string $refYm,
    array $rows,
    ?int $userId = null,
    ?int $limiteGrupos = null
): array {
    $pkg = medicao_relatorio_parse_grupos_chamados($rows);
    if (!$pkg['ok']) {
        return [
            'ok'   => false,
            'erro' => $pkg['erro'],
        ];
    }

    $grupos = $pkg['grupos'];
    if ($limiteGrupos !== null && $limiteGrupos > 0) {
        $grupos = array_slice($grupos, 0, $limiteGrupos);
    }

    $imp = medicao_relatorio_import_criar_chamados($clienteMatrizId, $refYm, $grupos, $userId);

    return array_merge($imp, [
        'parse' => $pkg,
    ]);
}
