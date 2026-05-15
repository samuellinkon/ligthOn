<?php
/**
 * Listagem e exportações de chamados (painel interno, portal cliente e operador).
 * O ponto de entrada define: $CRM_CHAMADOS_PANEL = ['role' => 'gestao'|'cliente'|'operador', 'user' => array].
 */
declare(strict_types=1);

if (!isset($CRM_CHAMADOS_PANEL) || !is_array($CRM_CHAMADOS_PANEL['user'] ?? null)) {
    http_response_code(500);
    exit('CRM_CHAMADOS_PANEL inválido');
}

$me = $CRM_CHAMADOS_PANEL['user'];
$CRM_CHAMADOS_PANEL_ROLE = (string) ($CRM_CHAMADOS_PANEL['role'] ?? 'gestao');
$CRM_CHAMADOS_IS_CLIENTE = ($CRM_CHAMADOS_PANEL_ROLE === 'cliente');
$CRM_CHAMADOS_IS_OPERADOR = ($CRM_CHAMADOS_PANEL_ROLE === 'operador');

require_once __DIR__ . '/medicao_helpers.php';
require_once __DIR__ . '/chamados_list_urls.php';
require_once __DIR__ . '/audit_log.php';

if ($CRM_CHAMADOS_IS_OPERADOR) {
    require_once __DIR__ . '/auth.php';
    $empresaOp = operador_empresa_id($me);
    $escopoLista = $empresaOp > 0 ? $empresaOp : null;
    $escopoCh = $empresaOp > 0 ? $empresaOp : null;
    $clienteIdListagem = 0;
    $envolvidoUser = 0;
    $tecnicoUserId = 0;
    $localQ = '';
    $tecnicoRepo = null;
    $localQRepo = null;
} elseif ($CRM_CHAMADOS_IS_CLIENTE) {
    $escopoCh = null;
    $uidCid = (int) ($me['cliente_id'] ?? 0);
    $matrizL = $uidCid > 0 ? repo_cliente_matriz_raiz_id($uidCid) : 0;
    $escopoLista = $matrizL > 0 ? $matrizL : null;
    $clienteIdListagem = 0;
    $envolvidoUser = 0;
    $tecnicoUserId = 0;
    $localQ = '';
    $tecnicoRepo = null;
    $localQRepo = null;
} else {
    $escopoCh = gestao_scope_cliente_id($me);

    $clienteIdListagem = max(0, (int) ($_GET['cliente_id'] ?? 0));
    if ($clienteIdListagem > 0) {
        gestor_assert_escopo_cliente($clienteIdListagem, 'chamados.php');
    }
    $envolvidoUser = max(0, (int) ($_GET['envolvido_user'] ?? 0));
    $tecnicoUserId = max(0, (int) ($_GET['tecnico_user_id'] ?? 0));
    $localQ        = trim((string) ($_GET['local_q'] ?? ''));
    $tecnicoRepo   = $tecnicoUserId > 0 ? $tecnicoUserId : null;
    $localQRepo    = $localQ !== '' ? $localQ : null;

    $escopoLista = $escopoCh;
    if ($clienteIdListagem > 0 && db_ok()) {
        $midList = repo_cliente_matriz_raiz_id($clienteIdListagem);
        if ($midList > 0) {
            $escopoLista = $midList;
        }
    }
}

$medicaoMes = trim((string) ($_GET['medicao_mes'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $medicaoMes)) {
    $medicaoMes = '';
}

$periodoLimpar = isset($_GET['periodo_limpar']) && (string) $_GET['periodo_limpar'] === '1';
$periodoDeRaw = trim((string) ($_GET['periodo_de'] ?? ''));
$periodoAteRaw = trim((string) ($_GET['periodo_ate'] ?? ''));
$periodoPresetGet = strtolower(trim((string) ($_GET['periodo_preset'] ?? '')));

$periodoDe  = null;
$periodoAte = null;
if ($periodoLimpar) {
    // sem filtro de data
} elseif ($medicaoMes !== '') {
    $periodoDe  = $medicaoMes . '-01';
    $periodoAte = date('Y-m-t', strtotime($periodoDe));
} elseif ($periodoPresetGet !== '' && ($presetRange = chamados_periodo_preset($periodoPresetGet)) !== null) {
    $periodoDe  = $presetRange['de'];
    $periodoAte = $presetRange['ate'];
} elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodoDeRaw)
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodoAteRaw)
    && $periodoDeRaw <= $periodoAteRaw) {
    $periodoDe  = $periodoDeRaw;
    $periodoAte = $periodoAteRaw;
} else {
    $periodoDe  = date('Y-m-01');
    $periodoAte = date('Y-m-t');
}

/** YYYY-MM para importação BM quando o período é um mês civil completo ou veio medicao_mes. */
$refYmBm = '';
if ($medicaoMes !== '') {
    $refYmBm = $medicaoMes;
} elseif ($periodoDe !== null && $periodoAte !== null && !$periodoLimpar) {
    if (preg_match('/^(\d{4}-\d{2})-\d{2}$/', $periodoDe, $m) && $periodoAte === date('Y-m-t', strtotime($periodoDe))) {
        $refYmBm = $m[1];
    }
}

if (!$CRM_CHAMADOS_IS_CLIENTE && !$CRM_CHAMADOS_IS_OPERADOR && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir') {
    $delId = (int) ($_POST['id'] ?? 0);
    if (db_ok() && $delId > 0) {
        $chDel = repo_chamado($delId);
        if ($chDel) {
            gestor_assert_escopo_cliente((int) ($chDel['cliente_id'] ?? 0), 'chamados.php');
        }
        if (repo_delete_chamado($delId)) {
            flash_set('ok', 'Chamado #' . $delId . ' marcado como inativo (exclusão lógica).');
        } else {
            flash_set('err', 'Não foi possível inativar o chamado.');
        }
    } else {
        flash_set('err', 'Exclusão requer banco de dados ativo.');
    }
    $redirMes = trim((string) ($_POST['medicao_mes'] ?? ''));
    $rd       = trim((string) ($_POST['periodo_de'] ?? ''));
    $ra       = trim((string) ($_POST['periodo_ate'] ?? ''));
    $rLim     = !empty($_POST['periodo_limpar']);
    $qsRed    = [];
    if (preg_match('/^\d{4}-\d{2}$/', $redirMes)) {
        $qsRed['medicao_mes'] = $redirMes;
    } elseif ($rLim) {
        $qsRed['periodo_limpar'] = '1';
    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rd) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ra)) {
        $qsRed['periodo_de']  = $rd;
        $qsRed['periodo_ate'] = $ra;
    }
    $pcid = max(0, (int) ($_POST['cliente_id_ctx'] ?? 0));
    if ($pcid > 0) {
        $qsRed['cliente_id'] = $pcid;
    }
    $peu = max(0, (int) ($_POST['envolvido_user_ctx'] ?? 0));
    if ($peu > 0) {
        $qsRed['envolvido_user'] = $peu;
    }
    $ptec = max(0, (int) ($_POST['tecnico_user_id_ctx'] ?? 0));
    if ($ptec > 0) {
        $qsRed['tecnico_user_id'] = $ptec;
    }
    $ploc = trim((string) ($_POST['local_q_ctx'] ?? ''));
    if ($ploc !== '') {
        $qsRed['local_q'] = $ploc;
    }
    $pf = strtolower(trim((string) ($_POST['f_ctx'] ?? '')));
    if ($pf !== '' && in_array($pf, ['abertos', 'andamento', 'aguardando', 'resolvidos', 'cancelados', 'urgentes'], true)) {
        $qsRed['f'] = $pf;
    }
    $redir = 'chamados.php' . ($qsRed !== [] ? '?' . http_build_query($qsRed) : '');
    header('Location: ' . $redir);
    exit;
}

$f = strtolower(trim((string) ($_GET['f'] ?? '')));
if ($CRM_CHAMADOS_IS_OPERADOR) {
    if (!in_array($f, ['', 'andamento', 'aguardando', 'resolvido'], true)) {
        $f = '';
    }
} elseif (!in_array($f, ['', 'abertos', 'andamento', 'aguardando', 'resolvidos', 'cancelados', 'urgentes'], true)) {
    $f = '';
}
$q = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;
$medicaoMatrizId = 0;
$medicaoMatrizNome = '';
if (db_ok()) {
    if ($CRM_CHAMADOS_IS_OPERADOR) {
        $medicaoMatrizId = (int) ($escopoLista ?? 0);
    } elseif ($CRM_CHAMADOS_IS_CLIENTE) {
        $medicaoMatrizId = (int) ($escopoLista ?? 0);
    } elseif ($escopoCh !== null && $escopoCh > 0) {
        $medicaoMatrizId = $escopoCh;
    } else {
        $medicaoMatrizId = (int) (repo_catalogo_cliente_id_padrao_admin() ?? 0);
        if ($medicaoMatrizId <= 0) {
            $empF = repo_clientes_empresas();
            $medicaoMatrizId = (int) (($empF[0]['id'] ?? 0));
        }
    }
    if ($medicaoMatrizId > 0) {
        $mcRow = repo_cliente($medicaoMatrizId);
        $medicaoMatrizNome = (string) ($mcRow['empresa'] ?? '');
    }
}

$tecnicosLista = [];
if (db_ok() && $medicaoMatrizId > 0 && !$CRM_CHAMADOS_IS_OPERADOR) {
    $tecnicosLista = repo_operadores_empresa($medicaoMatrizId);
}

$bmLista = [];
$bmMergeActive = !$CRM_CHAMADOS_IS_OPERADOR
    && $medicaoMatrizId > 0 && $refYmBm !== '' && $f === '' && $envolvidoUser <= 0 && $clienteIdListagem <= 0 && $tecnicoUserId <= 0 && $localQ === '' && db_ok();
if ($bmMergeActive) {
    $impPkg = repo_medicao_import_fetch($medicaoMatrizId, $refYmBm);
    $bmLista = medicao_import_linhas_para_chamados_listagem(
        is_array($impPkg['linhas'] ?? null) ? $impPkg['linhas'] : [],
        $refYmBm,
        $medicaoMatrizNome,
        $q
    );
}
$nBm = count($bmLista);

$envolvidoRepo = $envolvidoUser > 0 ? $envolvidoUser : null;

if (db_ok()) {
    if ($CRM_CHAMADOS_IS_OPERADOR) {
        $eidOp = (int) ($escopoLista ?? 0);
        if ($eidOp <= 0) {
            $lista = [];
            $totalRows = 0;
        } else {
            $resOp = repo_chamados_operador_list($eidOp, $f, $q, $page, $perPage, (int) ($me['id'] ?? 0));
            $lista = $resOp['rows'];
            $totalRows = $resOp['total'];
        }
    } elseif ($periodoDe !== null && $periodoAte !== null && $nBm > 0 && $f === '' && $bmMergeActive) {
        $resT     = repo_chamados_admin_list($f, $q, 1, 1, $escopoLista, $periodoDe, $periodoAte, null, $envolvidoRepo, $tecnicoRepo, $localQRepo, false);
        $totalCh  = $resT['total'];
        $totalRows = $nBm + $totalCh;
        $totalPagesCalc = max(1, (int) ceil($totalRows / $perPage));
        $page            = min(max(1, $page), $totalPagesCalc);
        $start           = ($page - 1) * $perPage;
        $lista           = [];
        $remain          = $perPage;
        for ($bi = $start; $bi < $nBm && $remain > 0; $bi++) {
            $lista[] = $bmLista[$bi];
            $remain--;
        }
        if ($remain > 0) {
            $chOff = max(0, $start - $nBm);
            $resCh = repo_chamados_admin_list($f, $q, 1, $remain, $escopoLista, $periodoDe, $periodoAte, $chOff, $envolvidoRepo, $tecnicoRepo, $localQRepo, false);
            foreach ($resCh['rows'] as $row) {
                $lista[] = $row;
            }
        }
    } else {
        $res       = repo_chamados_admin_list($f, $q, $page, $perPage, $escopoLista, $periodoDe, $periodoAte, null, $envolvidoRepo, $tecnicoRepo, $localQRepo, false);
        $lista     = $res['rows'];
        $totalRows = $res['total'];
    }
} else {
    if ($CRM_CHAMADOS_IS_OPERADOR) {
        $lista = [];
        $totalRows = 0;
    } else {
        $lista = $MOCK_CHAMADOS;
    if ($f === 'abertos') {
        $lista = array_values(array_filter($lista, fn ($c) => ($c['status'] ?? '') === 'Aberto'));
    } elseif ($f === 'andamento') {
        $lista = array_values(array_filter($lista, fn ($c) => ($c['status'] ?? '') === 'Em andamento'));
    } elseif ($f === 'aguardando') {
        $lista = array_values(array_filter($lista, fn ($c) => ($c['status'] ?? '') === 'Aguardando'));
    } elseif ($f === 'resolvidos') {
        $lista = array_values(array_filter($lista, fn ($c) => in_array($c['status'] ?? '', ['Resolvido', 'Fechado'], true)));
    } elseif ($f === 'cancelados') {
        $lista = array_values(array_filter($lista, fn ($c) => ($c['status'] ?? '') === 'Cancelado'));
    } elseif ($f === 'urgentes') {
        $lista = array_values(array_filter($lista, fn ($c) => in_array($c['prioridade'] ?? '', ['Alta', 'Urgente'], true)
            && !in_array($c['status'] ?? '', ['Resolvido', 'Fechado', 'Cancelado'], true)));
    }
    if ($q !== '') {
        if (ctype_digit($q)) {
            $lista = array_values(array_filter($lista, fn ($c) => (int) ($c['id'] ?? 0) === (int) $q));
        } else {
            $ql = mb_strtolower($q);
            $lista = array_values(array_filter($lista, function ($c) use ($ql) {
                $hay = mb_strtolower(($c['titulo'] ?? '') . ' ' . ($c['cliente'] ?? ''));

                return mb_strpos($hay, $ql) !== false;
            }));
        }
    }
    $totalRows = count($lista);
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    $page       = min($page, $totalPages);
    $offset     = ($page - 1) * $perPage;
    $lista      = array_slice($lista, $offset, $perPage);
    }
}

$anexosResumoPorChamado = [];
if (db_ok() && $lista !== []) {
    $idsCh = [];
    foreach ($lista as $row) {
        if (empty($row['medicao_bm']) && (int) ($row['id'] ?? 0) > 0) {
            $idsCh[] = (int) $row['id'];
        }
    }
    if ($idsCh !== []) {
        $anexosResumoPorChamado = repo_chamados_anexos_resumo_listagem($idsCh);
    }
}

$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page       = min($page, $totalPages);

$dash = db_ok() ? repo_dashboard_admin_stats(($escopoLista !== null && $escopoLista > 0) ? $escopoLista : null) : null;

/**
 * Recolhe linhas para exportação (CRM; + BM na mesma ordem da listagem quando aplicável).
 *
 * @return list<array<string,mixed>>
 */
function adm_chamados_collect_export_rows(
    bool $mergeBm,
    array $bmLista,
    string $f,
    string $q,
    ?int $escopoLista,
    ?string $periodoDe,
    ?string $periodoAte,
    int $maxRows,
    ?int $envolvidoRepo = null,
    ?int $tecnicoUserId = null,
    ?string $localQ = null,
    bool $excluirCancelados = false
): array {
    $out = [];
    if ($mergeBm) {
        foreach ($bmLista as $r) {
            if (count($out) >= $maxRows) {
                return $out;
            }
            $out[] = $r;
        }
    }
    $tot = repo_chamados_admin_list($f, $q, 1, 1, $escopoLista, $periodoDe, $periodoAte, null, $envolvidoRepo, $tecnicoUserId, $localQ, $excluirCancelados)['total'];
    $off = 0;
    $batch = 2000;
    while ($off < $tot && count($out) < $maxRows) {
        $take = min($batch, $tot - $off, $maxRows - count($out));
        if ($take <= 0) {
            break;
        }
        $chunk = repo_chamados_admin_list($f, $q, 1, $take, $escopoLista, $periodoDe, $periodoAte, $off, $envolvidoRepo, $tecnicoUserId, $localQ, $excluirCancelados)['rows'];
        foreach ($chunk as $r) {
            $out[] = $r;
        }
        $off += $take;
    }

    return $out;
}

$admChPeriodoCtx = [
    'medicao_mes'       => $medicaoMes,
    'periodo_de'        => ($medicaoMes === '' && !$periodoLimpar && $periodoDe !== null) ? $periodoDe : '',
    'periodo_ate'       => ($medicaoMes === '' && !$periodoLimpar && $periodoAte !== null) ? $periodoAte : '',
    'periodo_limpar'    => $periodoLimpar,
    'cliente_id'        => $clienteIdListagem > 0 ? $clienteIdListagem : null,
    'envolvido_user'    => $envolvidoUser > 0 ? $envolvidoUser : null,
    'tecnico_user_id'   => $tecnicoUserId > 0 ? $tecnicoUserId : null,
    'local_q'           => $localQ !== '' ? $localQ : null,
];
$chamadosPeriodoPresetAtivo = (!$CRM_CHAMADOS_IS_CLIENTE && !$CRM_CHAMADOS_IS_OPERADOR)
    ? chamados_periodo_preset_ativo($periodoDe, $periodoAte, $periodoLimpar, $medicaoMes)
    : null;
$admChClearBuscaCtx = array_merge($admChPeriodoCtx, ['local_q' => null, 'tecnico_user_id' => null]);

$chamadosListagemHref = static function (int $p, string $filtro, string $busca, ?array $periodoCtxOverride = null) use ($CRM_CHAMADOS_IS_OPERADOR, $admChPeriodoCtx): string {
    if ($CRM_CHAMADOS_IS_OPERADOR) {
        return oper_chamados_url($p, $filtro, $busca);
    }

    return adm_chamados_url($p, $filtro, $busca, $periodoCtxOverride ?? $admChPeriodoCtx);
};

$exportFmt = strtolower(trim((string) ($_GET['export'] ?? '')));
if ($CRM_CHAMADOS_IS_OPERADOR && $exportFmt !== '') {
    require_once __DIR__ . '/flash.php';
    flash_set('err', 'Exportações não estão disponíveis na área do operador.');
    header('Location: ' . oper_chamados_url(1, $f, $q));
    exit;
}
$mergeBmExport = $periodoDe !== null && $periodoAte !== null && $nBm > 0 && $f === '' && $bmMergeActive;
$maxExportRows = 20000;
$maxPdfChamados = 120;

if ($exportFmt === 'pdf_anexos') {
    require_once __DIR__ . '/crm_export_pdf_debug.php';
    crm_export_pdf_flush_output_buffers();
    ob_start();

    require_once __DIR__ . '/chamados_periodo_export_pdf.php';
    require_once __DIR__ . '/chamados_periodo_pdf_dompdf.php';
    if (!defined('APP_BRAND_NAME')) {
        require_once __DIR__ . '/config.php';
    }
    $redirPosExport = adm_chamados_url(1, $f, $q, $admChPeriodoCtx);
    if (!db_ok()) {
        crm_export_pdf_flush_output_buffers();
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Exportação disponível com base de dados ativa.';
        exit;
    }
    if ($periodoLimpar) {
        crm_export_pdf_flush_output_buffers();
        flash_set('err', 'Para exportar PDF com anexos, defina um intervalo de datas ou use «Mês atual». A vista «Todo o período» não é suportada.');
        header('Location: ' . $redirPosExport);
        exit;
    }
    $totalPeriodo = repo_chamados_admin_list($f, $q, 1, 1, $escopoLista, $periodoDe, $periodoAte, null, $envolvidoRepo, $tecnicoRepo, $localQRepo, false)['total'];
    $rowsEx       = adm_chamados_collect_export_rows(
        false,
        [],
        $f,
        $q,
        $escopoLista,
        $periodoDe,
        $periodoAte,
        $maxPdfChamados + 1,
        $envolvidoRepo,
        $tecnicoRepo,
        $localQRepo,
        false
    );
    $listaTruncada = count($rowsEx) > $maxPdfChamados;
    if ($listaTruncada) {
        $rowsEx = array_slice($rowsEx, 0, $maxPdfChamados);
    }
    $crmRows = array_values(array_filter($rowsEx, static fn ($r) => empty($r['medicao_bm'])));
    $items   = [];
    foreach ($crmRows as $row) {
        $cid = (int) ($row['id'] ?? 0);
        if ($cid <= 0) {
            continue;
        }
        $items[] = [
            'chamado' => $row,
            'anexos'  => repo_chamado_anexos($cid),
        ];
    }
    if ($medicaoMes !== '') {
        $periodoLabel = 'Mês ' . $medicaoMes;
    } elseif ($periodoDe !== null && $periodoAte !== null) {
        $d1 = DateTimeImmutable::createFromFormat('Y-m-d', $periodoDe);
        $d2 = DateTimeImmutable::createFromFormat('Y-m-d', $periodoAte);
        if ($d1 instanceof DateTimeImmutable && $d2 instanceof DateTimeImmutable) {
            $periodoLabel = $d1->format('d/m/Y') . ' — ' . $d2->format('d/m/Y');
        } else {
            $periodoLabel = $periodoDe . ' — ' . $periodoAte;
        }
    } else {
        $periodoLabel = '—';
    }
    $brandPdf = defined('APP_BRAND_NAME') ? (string) APP_BRAND_NAME : 'CRM';
    $pdfDownloadName = 'Chamados e anexos — ' . $brandPdf . '.pdf';
    if ($periodoDe !== null && $periodoAte !== null) {
        $fd = DateTimeImmutable::createFromFormat('Y-m-d', $periodoDe);
        $fa = DateTimeImmutable::createFromFormat('Y-m-d', $periodoAte);
        if ($fd instanceof DateTimeImmutable && $fa instanceof DateTimeImmutable) {
            $pdfDownloadName = 'Chamados e anexos — ' . $fd->format('d_m_Y') . ' — ' . $fa->format('d_m_Y') . ' — ' . $brandPdf . '.pdf';
        }
    }
    $resumoPdf = repo_chamados_admin_relatorio_resumo(
        $f,
        $q,
        $escopoLista,
        $periodoDe,
        $periodoAte,
        $envolvidoRepo,
        $tecnicoRepo,
        $localQRepo,
        false
    );
    $orgaoPdf = $medicaoMatrizNome !== '' ? $medicaoMatrizNome : (string) $brandPdf;
    if ($clienteIdListagem > 0 && db_ok()) {
        $rowCliPdf = repo_cliente($clienteIdListagem);
        if ($rowCliPdf && trim((string) ($rowCliPdf['empresa'] ?? '')) !== '') {
            $orgaoPdf = trim((string) $rowCliPdf['empresa']);
        }
    }
    require_once __DIR__ . '/chamados_periodo_pdf_merge.php';
    $pdfMergeInfo = chamados_periodo_pdf_anexos_para_merge($items);
    $htmlPdfBin   = chamados_periodo_anexos_export_html(
        $items,
        $periodoLabel,
        $totalPeriodo,
        count($items),
        $listaTruncada,
        false,
        true,
        $resumoPdf,
        $orgaoPdf,
        $pdfMergeInfo['anexo_ids']
    );
    $autoloadComposer = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_readable($autoloadComposer)) {
        crm_export_pdf_flush_output_buffers();
        flash_set(
            'err',
            'PDF indisponível: execute «composer install» na raiz do projeto (é necessário o pacote dompdf/dompdf). Sem isso não é possível gerar o ficheiro.'
        );
        header('Location: ' . $redirPosExport);
        exit;
    }
    audit_log_chamados_listagem_export('pdf_anexos', [
        'cliente_id'             => $clienteIdListagem,
        'filtro'                 => $f,
        'busca'                  => $q,
        'medicao_mes'            => $medicaoMes !== '' ? $medicaoMes : null,
        'periodo_de'             => $periodoDe,
        'periodo_ate'            => $periodoAte,
        'periodo_limpar'         => $periodoLimpar,
        'linhas_no_ficheiro'     => count($items),
        'total_listagem_periodo' => $totalPeriodo,
        'lista_truncada'         => $listaTruncada,
    ]);
    try {
        crm_export_pdf_flush_output_buffers();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        chamados_periodo_anexos_stream_pdf($htmlPdfBin, $pdfDownloadName, $pdfMergeInfo['paths']);
    } catch (Throwable $e) {
        crm_export_pdf_log_failure($e, [
            'php'              => PHP_VERSION,
            'chamados_no_pdf'  => count($items),
            'periodo_de'       => $periodoDe,
            'periodo_ate'      => $periodoAte,
            'html_bytes'       => strlen($htmlPdfBin),
            'pdf_download'     => $pdfDownloadName,
        ]);

        if (crm_export_pdf_wants_browser_debug()) {
            crm_export_pdf_flush_output_buffers();
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/html; charset=UTF-8');
            }
            $safeRedir = htmlspecialchars($redirPosExport, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $logPath   = htmlspecialchars(dirname(__DIR__) . '/writable/pdf_export_debug.log', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Debug PDF</title></head>';
            echo '<body style="font-family:system-ui,sans-serif;padding:20px;max-width:960px;margin:0 auto;line-height:1.45">';
            echo '<h1 style="color:#b91c1c">Erro ao gerar PDF (modo debug)</h1>';
            echo '<p>Stack abaixo. O mesmo detalhe foi acrescentado a <code>' . $logPath . '</code> e uma linha resumo foi enviada para o <strong>error_log</strong> do PHP (XAMPP: pasta <code>logs</code> do XAMPP).</p>';
            echo '<h2>Mensagem</h2><pre style="background:#1e293b;color:#f1f5f9;padding:12px;border-radius:8px;overflow:auto;white-space:pre-wrap">';
            echo htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            echo '</pre><h2>Stack trace</h2><pre style="background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;overflow:auto;font-size:12px;line-height:1.45">';
            echo htmlspecialchars($e->getTraceAsString(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            echo '</pre><p><a href="' . $safeRedir . '">← Voltar aos chamados</a></p></body></html>';
            exit;
        }

        $det = trim($e->getMessage());
        if (strlen($det) > 220) {
            $det = substr($det, 0, 217) . '…';
        }
        $flashMsg = 'Não foi possível gerar o PDF.' . ($det !== '' ? ' ' . $det : '');
        if (defined('CRM_EXPORT_PDF_DEBUG') && CRM_EXPORT_PDF_DEBUG) {
            $flashMsg .= ' [Dev: ver writable/pdf_export_debug.log ou clique de novo em «Exportar PDF (anexos)» com debug ativo para ver o stack no browser.]';
        }
        crm_export_pdf_flush_output_buffers();
        flash_set('err', $flashMsg);
        header('Location: ' . $redirPosExport);
        exit;
    }
    exit;
}

if ($exportFmt === 'csv_itens') {
    if (!db_ok()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Exportação disponível com base de dados ativa.';
        exit;
    }
    if ($periodoLimpar || $periodoDe === null || $periodoAte === null) {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><title>Período</title></head><body><p>Defina um período (datas ou mês) para exportar itens.</p><p><a href="chamados.php">Voltar</a></p></body></html>';
        exit;
    }
    $matrizItensId = ($escopoLista !== null && $escopoLista > 0)
        ? $escopoLista
        : (int) (repo_catalogo_cliente_id_padrao_admin() ?? 0);
    if ($matrizItensId <= 0) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Matriz não definida.';
        exit;
    }
    $linhasIt = repo_chamados_itens_utilizados_periodo_linhas($matrizItensId, $periodoDe, $periodoAte);
    audit_log_chamados_listagem_export('csv_itens', [
        'cliente_id'     => $clienteIdListagem,
        'filtro'         => $f,
        'busca'          => $q,
        'medicao_mes'    => $medicaoMes !== '' ? $medicaoMes : null,
        'periodo_de'     => $periodoDe,
        'periodo_ate'    => $periodoAte,
        'matriz_itens_id' => $matrizItensId,
        'n_linhas'       => count($linhasIt),
    ]);
    $stamp    = date('Y-m-d_His');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="chamados_itens_' . $stamp . '.csv"');
    $out = fopen('php://output', 'w');
    if ($out !== false) {
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        $sep = ';';
        fputcsv($out, [
            'Ref_mês',
            'Chamado_ID',
            'Unidade',
            'Produto_serviço',
            'Código',
            'Tipo_catálogo',
            'Unidade_medida',
            'Quantidade',
            'Valor_unitário',
            'Subtotal',
            'Observação',
        ], $sep);
        foreach ($linhasIt as $li) {
            fputcsv($out, [
                (string) ($li['ref_mes'] ?? ''),
                (string) (int) ($li['chamado_id'] ?? 0),
                (string) ($li['unidade_nome'] ?? ''),
                (string) ($li['produto_nome'] ?? ''),
                (string) ($li['produto_codigo'] ?? ''),
                (string) ($li['produto_tipo'] ?? ''),
                (string) ($li['catalogo_unidade'] ?? ''),
                number_format((float) ($li['quantidade'] ?? 0), 4, ',', ''),
                number_format((float) ($li['valor_unitario'] ?? 0), 4, ',', ''),
                number_format((float) ($li['subtotal'] ?? 0), 2, ',', ''),
                str_replace(["\r", "\n"], ' ', (string) ($li['observacao'] ?? '')),
            ], $sep);
        }
        fclose($out);
    }
    exit;
}

if (in_array($exportFmt, ['xlsx', 'xlsx_detalhes'], true)) {
    if (!db_ok()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Exportação disponível com base de dados ativa.';
        exit;
    }
    require_once __DIR__ . '/chamados_medicao_export_xlsx.php';

    if ($periodoLimpar) {
        $periodoTextoBr = 'Todo o período';
    } elseif ($medicaoMes !== '') {
        $ym = explode('-', $medicaoMes);
        if (count($ym) === 2) {
            $ma = (int) $ym[1];
            $ya = (int) $ym[0];
            $mesesPt = [
                1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril', 5 => 'maio', 6 => 'junho',
                7 => 'julho', 8 => 'agosto', 9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro',
            ];
            $periodoTextoBr = isset($mesesPt[$ma]) ? (mb_convert_case($mesesPt[$ma], MB_CASE_TITLE, 'UTF-8') . ' de ' . $ya) : $medicaoMes;
        } else {
            $periodoTextoBr = $medicaoMes;
        }
    } elseif ($periodoDe !== null && $periodoAte !== null) {
        $d1 = DateTimeImmutable::createFromFormat('Y-m-d', $periodoDe);
        $d2 = DateTimeImmutable::createFromFormat('Y-m-d', $periodoAte);
        if ($d1 instanceof DateTimeImmutable && $d2 instanceof DateTimeImmutable) {
            $periodoTextoBr = $d1->format('d/m/Y') . ' até ' . $d2->format('d/m/Y');
        } else {
            $periodoTextoBr = $periodoDe . ' — ' . $periodoAte;
        }
    } else {
        $periodoTextoBr = '—';
    }

    $nomeOp = trim((string) ($me['nome'] ?? ''));
    if ($nomeOp === '') {
        $nomeOp = trim((string) ($me['email'] ?? '')) ?: '—';
    }
    $tipoOp = trim((string) ($me['tipo'] ?? ''));
    if ($tipoOp !== '') {
        $nomeOp .= ' · ' . $tipoOp;
    }

    $matrizItensId = ($escopoLista !== null && $escopoLista > 0)
        ? $escopoLista
        : (int) (repo_catalogo_cliente_id_padrao_admin() ?? 0);
    $pDeIt = (!$periodoLimpar && $periodoDe !== null && $periodoAte !== null) ? $periodoDe : '';
    $pAtIt = (!$periodoLimpar && $periodoDe !== null && $periodoAte !== null) ? $periodoAte : '';
    $pDeItDet = $pDeIt;
    $pAtItDet = $pAtIt;
    if ($pDeIt !== '' && $pAtIt !== '' && preg_match('/^\d{4}-\d{2}$/', $refYmBm)) {
        $primeiroMes = $refYmBm . '-01';
        $ultimoMes   = date('Y-m-t', strtotime($primeiroMes));
        if ($pDeIt === $primeiroMes && $pAtIt === $ultimoMes) {
            $pAtItDet = medicao_bm_export_v2_periodo_ate($refYmBm);
        }
    }

    /** Linhas do boletim BM importadas (lista «mês atual» virtual) — alimentação da aba MEDIÇÃO */
    $bmLinhasBoletim = [];
    if ($medicaoMatrizId > 0 && preg_match('/^\d{4}-\d{2}$/', $refYmBm)) {
        $__impBk = repo_medicao_import_fetch($medicaoMatrizId, $refYmBm);
        if (!empty($__impBk['linhas']) && is_array($__impBk['linhas'])) {
            $bmLinhasBoletim = $__impBk['linhas'];
        }
    }

    /** Detalhamento CRM: período pela data em que o item foi registrado em chamado_itens.criado_em */
    $detalheLinhasBoletim = [];
    if ($matrizItensId > 0 && $pDeItDet !== '' && $pAtItDet !== '') {
        $detalheLinhasBoletim = repo_catalogo_chamados_itens_periodo_por_data_lancamento(
            $matrizItensId,
            $pDeItDet,
            $pAtItDet,
            'utilizado'
        );
    }

    $kpMed = repo_chamados_medicao_export_kpis(
        $f,
        $q,
        $escopoLista,
        $periodoDe,
        $periodoAte,
        $envolvidoRepo,
        $tecnicoRepo,
        $localQRepo,
        false
    );

    audit_log_chamados_listagem_export($exportFmt === 'xlsx_detalhes' ? 'xlsx_detalhes' : 'xlsx', [
        'cliente_id'      => $clienteIdListagem,
        'filtro'          => $f,
        'busca'           => $q,
        'medicao_mes'     => $medicaoMes !== '' ? $medicaoMes : null,
        'periodo_de'      => $periodoDe,
        'periodo_ate'     => $periodoAte,
        'periodo_limpar'  => $periodoLimpar,
        'ref_ym_bm'       => preg_match('/^\d{4}-\d{2}$/', $refYmBm) ? $refYmBm : '',
        'merge_bm'        => $mergeBmExport,
        'matriz_itens_id' => $matrizItensId,
        'total_chamados'  => (int) ($kpMed['total_chamados_crm'] ?? 0) + ($mergeBmExport ? $nBm : 0),
    ]);

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    chamados_medicao_export_xlsx_send(
        [],
        [],
        [],
        '',
        '',
        [
            'periodo_label'         => $periodoTextoBr,
            'usuario_nome'           => $nomeOp,
            'matriz_cliente_id'       => $matrizItensId,
            'matriz_label'            => ($medicaoMatrizNome !== '' ? $medicaoMatrizNome : ($matrizItensId > 0 ? 'Matriz #' . $matrizItensId : '—')),
            'periodo_de'              => $pDeIt,
            'periodo_ate'             => $pAtIt,
            'periodo_crm_de'          => $pDeItDet,
            'periodo_crm_ate'         => $pAtItDet,
            'ref_ym'                  => preg_match('/^\d{4}-\d{2}$/', $refYmBm) ? $refYmBm : '',
            'bm_linhas'               => $bmLinhasBoletim,
            'detalhe_itens_linhas'    => $detalheLinhasBoletim,
            'kpis'                    => [
                'total_chamados'   => (int) ($kpMed['total_chamados_crm'] ?? 0) + ($mergeBmExport ? $nBm : 0),
                'resolvidos'       => (int) ($kpMed['resolvidos'] ?? 0),
                'em_andamento'     => (int) ($kpMed['em_andamento'] ?? 0),
                'valor_utilizado'  => (float) ($kpMed['valor_itens_utilizados'] ?? 0),
                'total_anexos'     => (int) ($kpMed['quantidade_anexos'] ?? 0),
            ],
            'incluir_detalhamento_chamados' => ($pDeItDet !== '' && $pAtItDet !== ''),
            'arquivo_suffix'               => $exportFmt === 'xlsx_detalhes' ? 'com_detalhes_chamados' : '',
        ]
    );
    exit;
}

if (in_array($exportFmt, ['csv_crm', 'json_crm'], true)) {
    if (!db_ok()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Exportação disponível com base de dados ativa.';
        exit;
    }
    $rowsEx = adm_chamados_collect_export_rows(
        false,
        [],
        $f,
        $q,
        $escopoLista,
        $periodoDe,
        $periodoAte,
        $maxExportRows,
        $envolvidoRepo,
        $tecnicoRepo,
        $localQRepo,
        true
    );
    audit_log_chamados_listagem_export($exportFmt, [
        'cliente_id'     => $clienteIdListagem,
        'filtro'         => $f,
        'busca'          => $q,
        'medicao_mes'    => $medicaoMes !== '' ? $medicaoMes : null,
        'periodo_de'     => $periodoDe,
        'periodo_ate'    => $periodoAte,
        'periodo_limpar' => $periodoLimpar,
        'n_linhas'       => count($rowsEx),
    ]);
    $stamp = date('Y-m-d_His');
    if ($exportFmt === 'json_crm') {
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="chamados_crm_sem_cancelados_' . $stamp . '.json"');
        echo json_encode(
            [
                'exportado_em' => date('c'),
                'filtro'       => $f,
                'busca'        => $q,
                'periodo'      => $periodoLimpar ? null : (($periodoDe ?? '') !== '' ? ['de' => $periodoDe, 'ate' => $periodoAte] : null),
                'medicao_mes'  => $medicaoMes !== '' ? $medicaoMes : null,
                'sem_cancelados' => true,
                'apenas_crm'     => true,
                'limite'       => $maxExportRows,
                'total'        => count($rowsEx),
                'chamados'     => $rowsEx,
            ],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
        exit;
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="chamados_crm_sem_cancelados_' . $stamp . '.csv"');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        http_response_code(500);
        exit;
    }
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    $sep = ';';
    fputcsv($out, [
        'ID',
        'Prefeitura',
        'Título',
        'Descrição',
        'Prioridade',
        'Status',
        'Responsável',
        'Técnicos',
        'Data_abertura',
        'Endereço',
        'Latitude',
        'Longitude',
        'Aguardando_aprovação_gestor',
    ], $sep);
    foreach ($rowsEx as $c) {
        $aguarda = (!empty($c['finalizado_operador_em']) && empty($c['aprovado_gestor_em'])) ? 'Sim' : 'Não';
        fputcsv($out, [
            (string) (int) ($c['id'] ?? 0),
            (string) ($c['cliente'] ?? ''),
            (string) ($c['titulo'] ?? ''),
            str_replace(["\r", "\n"], ' ', (string) ($c['descricao'] ?? '')),
            (string) ($c['prioridade'] ?? ''),
            (string) ($c['status'] ?? ''),
            (string) ($c['responsavel'] ?? ''),
            (string) ($c['tecnico_nome'] ?? $c['responsavel'] ?? ''),
            (string) ($c['data'] ?? ''),
            (string) ($c['endereco_completo'] ?? ''),
            isset($c['latitude']) ? (string) $c['latitude'] : '',
            isset($c['longitude']) ? (string) $c['longitude'] : '',
            $aguarda,
        ], $sep);
    }
    fclose($out);
    exit;
}

if (in_array($exportFmt, ['csv', 'json'], true)) {
    if (!db_ok()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Exportação disponível com base de dados ativa.';
        exit;
    }
    $rowsEx = adm_chamados_collect_export_rows(
        $mergeBmExport,
        $bmLista,
        $f,
        $q,
        $escopoLista,
        $periodoDe,
        $periodoAte,
        $maxExportRows,
        $envolvidoRepo,
        $tecnicoRepo,
        $localQRepo,
        false
    );
    audit_log_chamados_listagem_export($exportFmt, [
        'cliente_id'     => $clienteIdListagem,
        'filtro'         => $f,
        'busca'          => $q,
        'medicao_mes'    => $medicaoMes !== '' ? $medicaoMes : null,
        'periodo_de'     => $periodoDe,
        'periodo_ate'    => $periodoAte,
        'periodo_limpar' => $periodoLimpar,
        'merge_bm'       => $mergeBmExport,
        'n_linhas'       => count($rowsEx),
    ]);
    $stamp = date('Y-m-d_His');
    if ($exportFmt === 'json') {
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="chamados_' . $stamp . '.json"');
        echo json_encode(
            [
                'exportado_em' => date('c'),
                'filtro'       => $f,
                'busca'        => $q,
                'periodo'      => $periodoLimpar ? null : (($periodoDe ?? '') !== '' ? ['de' => $periodoDe, 'ate' => $periodoAte] : null),
                'medicao_mes'  => $medicaoMes !== '' ? $medicaoMes : null,
                'limite'       => $maxExportRows,
                'total'        => count($rowsEx),
                'chamados'     => $rowsEx,
            ],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
        exit;
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="chamados_' . $stamp . '.csv"');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        http_response_code(500);
        exit;
    }
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    $sep = ';';
    fputcsv($out, [
        'Tipo',
        'ID',
        'Ref_medição_BM',
        'Prefeitura',
        'Título',
        'Descrição',
        'Prioridade',
        'Status',
        'Responsável',
        'Técnicos',
        'Data_abertura',
        'Endereço',
        'Latitude',
        'Longitude',
        'Aguardando_aprovação_gestor',
    ], $sep);
    foreach ($rowsEx as $c) {
        $isBm = !empty($c['medicao_bm']);
        $tipo = $isBm ? 'BM' : 'CRM';
        $idDisp = $isBm ? ('BM-' . (int) ($c['medicao_bm_linha_id'] ?? 0)) : (string) (int) ($c['id'] ?? 0);
        $refBm = $isBm ? (string) ($c['medicao_bm_ref_ym'] ?? '') : '';
        $aguarda = (!$isBm && !empty($c['finalizado_operador_em']) && empty($c['aprovado_gestor_em'])) ? 'Sim' : 'Não';
        fputcsv($out, [
            $tipo,
            $idDisp,
            $refBm,
            (string) ($c['cliente'] ?? ''),
            (string) ($c['titulo'] ?? ''),
            str_replace(["\r", "\n"], ' ', (string) ($c['descricao'] ?? '')),
            (string) ($c['prioridade'] ?? ''),
            (string) ($c['status'] ?? ''),
            (string) ($c['responsavel'] ?? ''),
            (string) ($c['tecnico_nome'] ?? $c['responsavel'] ?? ''),
            (string) ($c['data'] ?? ''),
            (string) ($c['endereco_completo'] ?? ''),
            isset($c['latitude']) ? (string) $c['latitude'] : '',
            isset($c['longitude']) ? (string) $c['longitude'] : '',
            $aguarda,
        ], $sep);
    }
    fclose($out);
    exit;
}

$fromN = $totalRows === 0 ? 0 : ($page - 1) * $perPage + 1;
$toN   = $totalRows === 0 ? 0 : min(($page - 1) * $perPage + count($lista), $totalRows);

$pageTitle  = $CRM_CHAMADOS_IS_CLIENTE ? 'Meus chamados' : 'Chamados';
$basePath   = '../';
$activePage = 'chamados';

$topTitle    = $CRM_CHAMADOS_IS_CLIENTE ? 'Meus chamados' : 'Chamados';
$topSubtitle = $CRM_CHAMADOS_IS_OPERADOR
    ? ''
    : ($CRM_CHAMADOS_IS_CLIENTE ? 'Lista dos chamados da sua prefeitura.' : 'Lista filtrada pela data de abertura (por defeito: mês atual).');
$topSearch   = $CRM_CHAMADOS_IS_OPERADOR
    ? 'Buscar por ID ou assunto...'
    : 'Buscar por ID, título ou prefeitura...';
$topAction   = $CRM_CHAMADOS_IS_OPERADOR
    ? ['label' => 'Início', 'href' => 'index.php', 'icon' => '←']
    : ['label' => 'Novo chamado', 'href' => 'chamado_novo.php', 'icon' => '+'];

$chPanelSidebar = $CRM_CHAMADOS_IS_CLIENTE
    ? 'sidebar-cliente.php'
    : ($CRM_CHAMADOS_IS_OPERADOR ? 'sidebar-operador.php' : 'sidebar-admin.php');

if ($CRM_CHAMADOS_IS_OPERADOR) {
    $operadorPwa = true;
    $topbarCompact = true;
}

include __DIR__ . '/head.php';
?>
<div class="app">
<?php include __DIR__ . '/' . $chPanelSidebar; ?>
<main class="main">
<?php include __DIR__ . '/topbar.php'; ?>

<section class="content<?= $CRM_CHAMADOS_IS_OPERADOR ? ' ch-op-page' : '' ?>">
  <style>
    .chamados-periodo-quick__btn--active {
      background: var(--primary, #534ab7);
      border-color: var(--primary, #534ab7);
      color: #fff;
    }
    .chamados-row--inativo { opacity: 0.82; background: rgba(248, 250, 252, 0.9); }
    .chamados-row--inativo .td-title { text-decoration: line-through; text-decoration-color: rgba(100, 116, 139, 0.55); }
    .chamados-col-anexos { width: 52px; text-align: center; white-space: nowrap; }
    .td-ch-anexos { text-align: center; vertical-align: middle; }
    .chamados-anexos-trigger {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 40px;
      height: 36px;
      padding: 0;
      border: 1px solid var(--border-soft, #e2e8f0);
      border-radius: 10px;
      background: var(--surface-raised, #f8fafc);
      color: var(--primary, #534ab7);
      cursor: pointer;
      transition: background 0.15s ease, border-color 0.15s ease;
    }
    .chamados-anexos-trigger:hover {
      background: #fff;
      border-color: var(--primary, #534ab7);
    }
    .chamados-anexos-modal[hidden] { display: none !important; }
    .chamados-anexos-modal {
      position: fixed;
      inset: 0;
      z-index: 10060;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 16px;
    }
    .chamados-anexos-modal__scrim {
      position: absolute;
      inset: 0;
      background: rgba(15, 23, 42, 0.72);
      border: 0;
      cursor: pointer;
    }
    .chamados-anexos-modal__box {
      position: relative;
      z-index: 1;
      width: min(96vw, 920px);
      max-height: min(90vh, 880px);
      display: flex;
      flex-direction: column;
      background: #fff;
      border-radius: 16px;
      box-shadow: 0 24px 60px rgba(0, 0, 0, 0.35);
      overflow: hidden;
    }
    .chamados-anexos-modal__head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 14px 16px;
      border-bottom: 1px solid var(--border-soft, #e2e8f0);
    }
    .chamados-anexos-modal__head h3 {
      margin: 0;
      font-size: 16px;
      font-weight: 700;
      color: var(--text, #0f172a);
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .chamados-anexos-modal__body {
      position: relative;
      flex: 1;
      min-height: 200px;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 12px 56px;
      background: #0f172a;
    }
    .chamados-anexos-modal__empty {
      padding: 32px 20px;
      text-align: center;
      color: #e2e8f0;
      line-height: 1.55;
      max-width: 420px;
    }
    .chamados-anexos-modal__empty a { color: #93c5fd; }
    .chamados-anexos-nav {
      position: absolute;
      top: 50%;
      transform: translateY(-50%);
      z-index: 2;
      width: 44px;
      height: 44px;
      border-radius: 999px;
      border: 0;
      background: rgba(255, 255, 255, 0.12);
      color: #fff;
      font-size: 26px;
      line-height: 1;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .chamados-anexos-nav:hover { background: rgba(255, 255, 255, 0.22); }
    .chamados-anexos-nav--prev { left: 10px; }
    .chamados-anexos-nav--next { right: 10px; }
    .chamados-anexos-slide-wrap {
      width: 100%;
      text-align: center;
      touch-action: pan-y;
    }
    .chamados-anexos-slide-wrap img {
      max-width: 100%;
      max-height: min(68vh, 620px);
      width: auto;
      height: auto;
      object-fit: contain;
      border-radius: 8px;
      background: #1e293b;
    }
    .chamados-anexos-caption {
      margin: 10px 0 0;
      font-size: 13px;
      color: #94a3b8;
      word-break: break-word;
    }
    .chamados-anexos-counter {
      position: absolute;
      bottom: 10px;
      left: 50%;
      transform: translateX(-50%);
      margin: 0;
      font-size: 12px;
      font-weight: 600;
      color: #cbd5e1;
      letter-spacing: 0.04em;
    }
    .chamados-anexos-modal__foot {
      padding: 10px 16px 14px;
      border-top: 1px solid var(--border-soft, #e2e8f0);
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      background: #f8fafc;
    }
<?php if ($CRM_CHAMADOS_IS_OPERADOR): ?>
    .ch-op-feed {
      margin: 13px 0;
      padding: 0 12px 12px;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }
    .ch-op-empty {
      text-align: center;
      padding: 28px 16px;
      margin: 0;
    }
    .ch-op-card {
      border: 1px solid var(--border, #e2e8f0);
      border-radius: 16px;
      background: #fff;
      box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
      overflow: hidden;
      display: flex;
      flex-direction: column;
    }
    .ch-op-card__main {
      display: block;
      padding: 14px 16px;
      text-decoration: none;
      color: inherit;
      flex: 1 1 auto;
      min-height: 72px;
    }
    .ch-op-card__main--static {
      cursor: default;
    }
    .ch-op-card__main:hover,
    .ch-op-card__main:focus-visible {
      background: var(--surface-raised, #f8fafc);
    }
    .ch-op-card__main:focus-visible {
      outline: 2px solid var(--primary, #534ab7);
      outline-offset: -2px;
    }
    .ch-op-card__top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      margin-bottom: 8px;
    }
    .ch-op-card__id {
      font-size: 12px;
      font-weight: 800;
      color: var(--primary, #534ab7);
      letter-spacing: 0.04em;
    }
    .ch-op-card__badges {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      justify-content: flex-end;
    }
    .ch-op-card__title {
      margin: 0 0 6px;
      font-size: 16px;
      font-weight: 700;
      line-height: 1.35;
      color: var(--text, #0f172a);
    }
    .ch-op-card__org {
      margin: 0 0 8px;
      font-size: 13px;
      color: var(--muted, #64748b);
      line-height: 1.4;
    }
    .ch-op-card__date {
      margin: 0 0 6px;
      font-size: 12px;
      color: var(--muted, #64748b);
    }
    .ch-op-card__addr {
      margin: 0;
      font-size: 13px;
      color: var(--text, #0f172a);
      line-height: 1.45;
      word-break: break-word;
    }
    .ch-op-card__geo {
      margin: 6px 0 0;
      font-size: 11px;
      color: var(--muted, #64748b);
      font-variant-numeric: tabular-nums;
    }
    .ch-op-card__wait {
      display: block;
      margin-top: 8px;
      font-size: 12px;
      color: var(--muted, #64748b);
    }
    .ch-op-card__toolbar {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 8px;
      padding: 8px 12px 12px;
      border-top: 1px solid var(--border-soft, #f1f5f9);
      background: var(--surface-raised, #fafafe);
    }
    .ch-op-anexos-btn.chamados-anexos-trigger {
      width: 44px;
      height: 44px;
      border-radius: 12px;
    }
<?php endif; ?>
  </style>
  <?php if (!$CRM_CHAMADOS_IS_OPERADOR): ?>
  <div class="dashboard-admin-metrics">
  <div class="cards-metrics">
    <div class="card metric">
      <div class="metric-top">
        <div><div class="metric-label">Abertos</div><div class="metric-value"><?= $dash ? (int) $dash['ch_abertos'] : count(array_filter($MOCK_CHAMADOS, fn ($c) => ($c['status'] ?? '') === 'Aberto')) ?></div></div>
        <div class="icon-box">AB</div>
      </div>
      <div class="metric-change metric-change--admin"><?= $dash ? 'Ao vivo' : 'Mock' ?></div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div><div class="metric-label">Em andamento</div><div class="metric-value"><?= $dash ? (int) $dash['ch_andamento'] : count(array_filter($MOCK_CHAMADOS, fn ($c) => ($c['status'] ?? '') === 'Em andamento')) ?></div></div>
        <div class="icon-box">EA</div>
      </div>
      <div class="metric-change metric-change--admin">Em atendimento</div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div><div class="metric-label">Urgentes</div><div class="metric-value"><?= $dash ? (int) $dash['ch_urgentes'] : count(array_filter($MOCK_CHAMADOS, fn ($c) => in_array($c['prioridade'] ?? '', ['Alta', 'Urgente'], true))) ?></div></div>
        <div class="icon-box">!!</div>
      </div>
      <div class="metric-change metric-change--admin">Prioridade alta</div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div><div class="metric-label">Resolvidos 7d</div><div class="metric-value"><?= $dash ? (int) $dash['ch_resolvidos_7d'] : count(array_filter($MOCK_CHAMADOS, fn ($c) => in_array($c['status'] ?? '', ['Resolvido', 'Fechado'], true))) ?></div></div>
        <div class="icon-box">OK</div>
      </div>
      <div class="metric-change metric-change--admin"><?= $dash ? 'Últimos 7 dias' : 'Todos (mock)' ?></div>
    </div>
  </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="panel-head" style="flex-wrap:wrap;gap:12px;">
      <div style="flex:1;min-width:0;">
        <h4 style="margin:0;"><?= $CRM_CHAMADOS_IS_OPERADOR ? 'Os meus chamados' : 'Todos os chamados' ?></h4>
        <span class="panel-sub"><?= (int) $totalRows ?> registro(s)</span>
      </div>
    </div>

    <?php if ($CRM_CHAMADOS_IS_OPERADOR): ?>
    <div class="filters" style="padding:12px 20px;border-bottom:1px solid var(--border);display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
      <form method="get" action="chamados.php" class="filters--form" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;flex:1;min-width:0;">
        <div class="form-group" style="margin:0;flex:1;min-width:200px;">
          <label for="q_op" style="font-size:12px;">Buscar</label>
          <input type="search" id="q_op" name="q" class="input" value="<?= htmlspecialchars($q) ?>" placeholder="ID ou palavra no assunto">
        </div>
        <?php if ($f !== ''): ?><input type="hidden" name="f" value="<?= htmlspecialchars($f) ?>"><?php endif; ?>
        <button type="submit" class="btn btn-primary">Buscar</button>
        <?php if ($q !== ''): ?>
        <a href="<?= htmlspecialchars(oper_chamados_url(1, $f, '')) ?>" class="btn btn-secondary">Limpar</a>
        <?php endif; ?>
      </form>
    </div>
    <div class="filters" style="padding:8px 20px 16px;display:flex;flex-wrap:wrap;gap:8px;border-bottom:1px solid var(--border);">
      <a href="<?= htmlspecialchars(oper_chamados_url(1, '', $q)) ?>" class="chip <?= $f === '' ? 'active' : '' ?>">Todos</a>
      <a href="<?= htmlspecialchars(oper_chamados_url(1, 'andamento', $q)) ?>" class="chip <?= $f === 'andamento' ? 'active' : '' ?>">Em andamento</a>
      <a href="<?= htmlspecialchars(oper_chamados_url(1, 'aguardando', $q)) ?>" class="chip <?= $f === 'aguardando' ? 'active' : '' ?>">Aguardando</a>
    </div>
    <?php else: ?>
    <div class="filters" style="padding:12px 20px;border-bottom:1px solid var(--border);display:grid;gap:12px;">
      <div class="chamados-periodo-quick" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <?php
          $chPeriodoBtn = static function (string $preset, string $label) use ($admChPeriodoCtx, $f, $q, $chamadosPeriodoPresetAtivo): string {
              $active = $chamadosPeriodoPresetAtivo === $preset;
              $cls    = 'btn btn-secondary btn-sm' . ($active ? ' chamados-periodo-quick__btn--active' : '');
              $href   = adm_chamados_periodo_quick_url(1, $f, $q, $admChPeriodoCtx, $preset === 'limpar' ? 'limpar' : $preset);

              return '<a class="' . htmlspecialchars($cls) . '" href="' . htmlspecialchars($href) . '">' . htmlspecialchars($label) . '</a>';
          };
          echo $chPeriodoBtn('hoje', 'Dia atual');
          echo $chPeriodoBtn('mes', 'Mês atual');
          echo $chPeriodoBtn('limpar', 'Todo o período');
        ?>
      </div>

      <form method="get" action="chamados.php" style="display:flex;flex-wrap:nowrap;gap:12px;align-items:flex-end;width:100%;min-width:0;overflow-x:auto;">
        <?php if ($clienteIdListagem > 0): ?><input type="hidden" name="cliente_id" value="<?= (int) $clienteIdListagem ?>"><?php endif; ?>
        <?php if ($envolvidoUser > 0): ?><input type="hidden" name="envolvido_user" value="<?= (int) $envolvidoUser ?>"><?php endif; ?>
        <div class="form-group" style="margin:0;flex:0 0 150px;min-width:0;max-width:180px;">
          <label for="filtro_status" style="font-size:12px;">Status</label>
          <select id="filtro_status" name="f" class="select">
            <option value=""<?= $f === '' ? ' selected' : '' ?>>Todos</option>
            <option value="abertos"<?= $f === 'abertos' ? ' selected' : '' ?>>Abertos</option>
            <option value="andamento"<?= $f === 'andamento' ? ' selected' : '' ?>>Em andamento</option>
            <option value="aguardando"<?= $f === 'aguardando' ? ' selected' : '' ?>>Aguardando</option>
            <option value="resolvidos"<?= $f === 'resolvidos' ? ' selected' : '' ?>>Resolvidos</option>
            <option value="cancelados"<?= $f === 'cancelados' ? ' selected' : '' ?>>Cancelados</option>
            <option value="urgentes"<?= $f === 'urgentes' ? ' selected' : '' ?>>Urgentes</option>
          </select>
        </div>
        <div class="form-group" style="margin:0;flex:0 0 142px;min-width:0;">
          <label for="periodo_de" style="font-size:12px;">De</label>
          <input type="date" id="periodo_de" name="periodo_de" class="input" value="<?= $periodoLimpar ? '' : htmlspecialchars((string) $periodoDe) ?>">
        </div>
        <div class="form-group" style="margin:0;flex:0 0 142px;min-width:0;">
          <label for="periodo_ate" style="font-size:12px;">Até</label>
          <input type="date" id="periodo_ate" name="periodo_ate" class="input" value="<?= $periodoLimpar ? '' : htmlspecialchars((string) $periodoAte) ?>">
        </div>
        <div class="form-group" style="margin:0;flex:1 1 0;min-width:140px;">
          <label for="q" style="font-size:12px;">Buscar</label>
          <input type="search" id="q" name="q" class="input" value="<?= htmlspecialchars($q) ?>" placeholder="ID, título ou órgão" style="width:100%;min-width:0;min-height:40px;font-size:14px;">
        </div>
        <?php if (!$CRM_CHAMADOS_IS_CLIENTE && !$CRM_CHAMADOS_IS_OPERADOR): ?>
        <div class="form-group" style="margin:0;flex:1 1 0;min-width:140px;">
          <label for="local_q" style="font-size:12px;">Local (endereço, bairro…)</label>
          <input type="search" id="local_q" name="local_q" class="input" value="<?= htmlspecialchars($localQ) ?>" placeholder="Filtrar por texto no local" style="width:100%;min-width:0;min-height:40px;font-size:14px;">
        </div>
        <div class="form-group" style="margin:0;flex:0 1 160px;min-width:120px;max-width:200px;">
          <label for="tecnico_user_id" style="font-size:12px;">Técnico</label>
          <select id="tecnico_user_id" name="tecnico_user_id" class="select">
            <option value="0">Todos</option>
            <?php foreach ($tecnicosLista as $tec): ?>
            <option value="<?= (int) $tec['id'] ?>"<?= $tecnicoUserId === (int) $tec['id'] ? ' selected' : '' ?>><?= htmlspecialchars((string) ($tec['nome'] ?? '')) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary" style="justify-content:center;flex:0 0 auto;flex-shrink:0;">Aplicar filtros</button>
        <?php if ($q !== '' || (!$CRM_CHAMADOS_IS_CLIENTE && ($localQ !== '' || $tecnicoUserId > 0))): ?>
        <a href="<?= htmlspecialchars($chamadosListagemHref(1, $f, '', $admChClearBuscaCtx)) ?>" class="btn" style="flex-shrink:0;">Limpar busca</a>
        <?php endif; ?>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($CRM_CHAMADOS_IS_OPERADOR): ?>
    <div class="ch-op-feed" role="list">
      <?php if (empty($lista)): ?>
      <p class="ch-op-empty muted">Nenhum chamado encontrado.</p>
      <?php else: ?>
      <?php foreach ($lista as $c): ?>
      <?php
        $isBm = !empty($c['medicao_bm']);
        $enderecoLista = $isBm ? '' : trim((string) ($c['endereco_completo'] ?? ''));
        $latLista = $isBm ? null : ($c['latitude'] ?? null);
        $lngLista = $isBm ? null : ($c['longitude'] ?? null);
        $arxRow = ['total' => 0, 'imagens' => []];
        if (!$isBm && db_ok()) {
            $cidArx = (int) ($c['id'] ?? 0);
            $arxRow = $anexosResumoPorChamado[$cidArx] ?? ['total' => 0, 'imagens' => []];
        }
        $nAnx = (int) ($arxRow['total'] ?? 0);
        $imgsArx = is_array($arxRow['imagens'] ?? null) ? $arxRow['imagens'] : [];
        $nImg = count($imgsArx);
        $payloadJsonOp = '';
        $titleAnxOp = '';
        if (!$isBm && db_ok() && $nAnx > 0) {
            $payloadAnxOp = [
                'chamadoId' => (int) ($c['id'] ?? 0),
                'titulo'    => (string) ($c['titulo'] ?? ''),
                'total'     => $nAnx,
                'imagens'   => $imgsArx,
            ];
            $payloadJsonOp = htmlspecialchars(
                json_encode($payloadAnxOp, JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE),
                ENT_QUOTES,
                'UTF-8'
            );
            $titleAnxOp = $nImg > 0
                ? $nImg . ' imagem(ns) · ' . $nAnx . ' anexo(s) no total'
                : $nAnx . ' anexo(s) (sem imagem para pré-visualização)';
        }
      ?>
      <article class="ch-op-card" role="listitem">
        <?php if (!$isBm): ?>
        <a class="ch-op-card__main" href="chamado_detalhe.php?id=<?= (int) ($c['id'] ?? 0) ?>">
        <?php else: ?>
        <div class="ch-op-card__main ch-op-card__main--static">
        <?php endif; ?>
          <div class="ch-op-card__top">
            <span class="ch-op-card__id"><?= $isBm ? 'BM-' . (int) ($c['medicao_bm_linha_id'] ?? 0) : '#' . (int) ($c['id'] ?? 0) ?></span>
            <div class="ch-op-card__badges">
              <?php if (!$isBm): ?>
              <span class="badge <?= status_class((string) ($c['prioridade'] ?? '')) ?>"><?= htmlspecialchars((string) ($c['prioridade'] ?? '')) ?></span>
              <?php endif; ?>
              <span class="badge <?= status_class((string) ($c['status'] ?? '')) ?>"><?= htmlspecialchars((string) ($c['status'] ?? '')) ?></span>
            </div>
          </div>
          <h3 class="ch-op-card__title"><?= htmlspecialchars((string) ($c['titulo'] ?? '')) ?></h3>
          <?php if ($isBm && !empty($c['medicao_bm_resumo'])): ?>
          <p class="ch-op-card__org"><?= htmlspecialchars((string) $c['medicao_bm_resumo']) ?></p>
          <?php elseif (!empty($c['cliente'])): ?>
          <p class="ch-op-card__org"><?= htmlspecialchars((string) $c['cliente']) ?></p>
          <?php elseif (!empty($c['tecnico_nome']) || !empty($c['responsavel'])): ?>
          <p class="ch-op-card__org"><?= htmlspecialchars('Técnicos: ' . (string) ($c['tecnico_nome'] ?? $c['responsavel'] ?? '—')) ?></p>
          <?php endif; ?>
          <p class="ch-op-card__date"><?= date('d/m/Y H:i', strtotime((string) ($c['data'] ?? 'now'))) ?></p>
          <?php if ($enderecoLista !== ''): ?>
          <p class="ch-op-card__addr"><?= htmlspecialchars($enderecoLista) ?></p>
          <?php endif; ?>
          <?php if ($latLista !== null && $lngLista !== null && !$isBm): ?>
          <p class="ch-op-card__geo">GPS <?= htmlspecialchars(number_format((float) $latLista, 5, '.', '')) ?>, <?= htmlspecialchars(number_format((float) $lngLista, 5, '.', '')) ?></p>
          <?php endif; ?>
          <?php if (!$isBm && !empty($c['finalizado_operador_em']) && empty($c['aprovado_gestor_em'])): ?>
          <span class="ch-op-card__wait">Aguardando aprovação do gestor</span>
          <?php endif; ?>
        <?php if (!$isBm): ?>
        </a>
        <?php else: ?>
        </div>
        <?php endif; ?>
        <?php if ($payloadJsonOp !== ''): ?>
        <div class="ch-op-card__toolbar">
          <button type="button"
                  class="chamados-anexos-trigger ch-op-anexos-btn"
                  data-chamados-anexos="<?= $payloadJsonOp ?>"
                  title="<?= htmlspecialchars($titleAnxOp, ENT_QUOTES, 'UTF-8') ?>"
                  aria-label="Pré-visualizar anexos do chamado <?= (int) ($c['id'] ?? 0) ?>">
            <?php if ($nImg > 0): ?>
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            <?php else: ?>
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19A4 4 0 1 1 21.44 11.05z"/><line x1="8.12" y1="8.12" x2="15.88" y2="15.88"/></svg>
            <?php endif; ?>
          </button>
        </div>
        <?php endif; ?>
      </article>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="chamados-admin-list chamados-admin-list--anexos" style="min-width:1090px;">
        <thead>
          <tr>
            <th>ID</th>
            <th>Título</th>
            <th>Prioridade</th>
            <th>Status</th>
            <th>Data</th>
            <th>Endereço</th>
            <th>Latitude</th>
            <th>Longitude</th>
            <th class="chamados-col-anexos" scope="col" title="Anexos / imagens">Anx.</th>
            <th class="text-right">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($lista)): ?>
          <tr><td colspan="10" class="muted" style="padding:24px;text-align:center;">Nenhum chamado encontrado.</td></tr>
          <?php else: ?>
          <?php foreach ($lista as $c): ?>
          <?php
            $isBm = !empty($c['medicao_bm']);
            $chInativo = !$isBm && isset($c['ativo']) && (int) $c['ativo'] === 0;
          ?>
          <tr class="<?= $chInativo ? 'chamados-row--inativo' : '' ?>">
            <td class="td-id"><?= $isBm ? 'BM-' . (int) ($c['medicao_bm_linha_id'] ?? 0) : '#' . (int) $c['id'] ?></td>
            <td>
              <div class="td-title"><?= htmlspecialchars((string) ($c['titulo'] ?? '')) ?></div>
              <?php if ($isBm && !empty($c['medicao_bm_resumo'])): ?>
              <small class="muted"><?= htmlspecialchars((string) $c['medicao_bm_resumo']) ?></small>
              <?php else: ?>
              <small class="muted">Técnicos: <?= htmlspecialchars((string) ($c['tecnico_nome'] ?? $c['responsavel'] ?? '—')) ?></small>
              <?php endif; ?>
            </td>
            <td><span class="badge <?= status_class((string) ($c['prioridade'] ?? '')) ?>"><?= htmlspecialchars((string) ($c['prioridade'] ?? '')) ?></span></td>
            <td>
              <span class="badge <?= status_class((string) ($c['status'] ?? '')) ?>"><?= htmlspecialchars((string) ($c['status'] ?? '')) ?></span>
              <?php if ($chInativo): ?>
                <span class="badge urgent" style="margin-left:4px;font-size:10px;" title="Exclusão lógica">Inativo</span>
                <?php if (!empty($c['excluido_em'])): ?>
                <small class="muted" style="display:block;margin-top:4px;">Inativado em <?= htmlspecialchars((string) $c['excluido_em']) ?><?= !empty($c['excluido_por_nome']) ? ' · ' . htmlspecialchars((string) $c['excluido_por_nome']) : '' ?></small>
                <?php endif; ?>
              <?php elseif (!$isBm && !empty($c['finalizado_operador_em']) && empty($c['aprovado_gestor_em'])): ?>
                <small class="muted" style="display:block;margin-top:4px;">Aguardando aprovação</small>
              <?php endif; ?>
            </td>
            <td class="td-mute"><?= date('d/m/Y H:i', strtotime((string) ($c['data'] ?? 'now'))) ?></td>
            <?php
            $enderecoLista = $isBm ? '' : trim((string) ($c['endereco_completo'] ?? ''));
            $latLista = $isBm ? null : ($c['latitude'] ?? null);
            $lngLista = $isBm ? null : ($c['longitude'] ?? null);
            ?>
            <td class="td-ch-endereco td-mute">
              <?php if ($isBm || $enderecoLista === ''): ?>
                —
              <?php else: ?>
                <?php
                $endMax = 56;
                $endTrunc = mb_strlen($enderecoLista) > $endMax;
                $endShort = $endTrunc ? mb_substr($enderecoLista, 0, $endMax) . '…' : $enderecoLista;
                ?>
                <span class="td-ch-endereco-text"<?= $endTrunc ? ' title="' . htmlspecialchars($enderecoLista, ENT_QUOTES, 'UTF-8') . '"' : '' ?>><?= htmlspecialchars($endShort) ?></span>
              <?php endif; ?>
            </td>
            <td class="td-ch-geo td-mute"><?= $isBm || $latLista === null ? '—' : htmlspecialchars(number_format((float) $latLista, 6, '.', '')) ?></td>
            <td class="td-ch-geo td-mute"><?= $isBm || $lngLista === null ? '—' : htmlspecialchars(number_format((float) $lngLista, 6, '.', '')) ?></td>
            <td class="td-ch-anexos">
              <?php
              $arxRow = ['total' => 0, 'imagens' => []];
              if (!$isBm && db_ok()) {
                  $cidArx = (int) ($c['id'] ?? 0);
                  $arxRow = $anexosResumoPorChamado[$cidArx] ?? ['total' => 0, 'imagens' => []];
              }
              $nAnx = (int) ($arxRow['total'] ?? 0);
              $imgsArx = is_array($arxRow['imagens'] ?? null) ? $arxRow['imagens'] : [];
              $nImg = count($imgsArx);
              ?>
              <?php if (!$isBm && db_ok() && $nAnx > 0):
                  $payloadAnx = [
                      'chamadoId' => (int) ($c['id'] ?? 0),
                      'titulo'    => (string) ($c['titulo'] ?? ''),
                      'total'     => $nAnx,
                      'imagens'   => $imgsArx,
                  ];
                  $payloadJson = htmlspecialchars(
                      json_encode($payloadAnx, JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE),
                      ENT_QUOTES,
                      'UTF-8'
                  );
                  $titleAnx = $nImg > 0
                      ? $nImg . ' imagem(ns) · ' . $nAnx . ' anexo(s) no total'
                      : $nAnx . ' anexo(s) (sem imagem para pré-visualização)';
                  ?>
              <button type="button"
                      class="chamados-anexos-trigger"
                      data-chamados-anexos="<?= $payloadJson ?>"
                      title="<?= htmlspecialchars($titleAnx, ENT_QUOTES, 'UTF-8') ?>"
                      aria-label="Pré-visualizar anexos do chamado <?= (int) ($c['id'] ?? 0) ?>">
                <?php if ($nImg > 0): ?>
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                <?php else: ?>
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19A4 4 0 1 1 21.44 11.05z"/><line x1="8.12" y1="8.12" x2="15.88" y2="15.88"/></svg>
                <?php endif; ?>
              </button>
              <?php else: ?>
              <span class="td-mute">—</span>
              <?php endif; ?>
            </td>
            <td class="td-actions">
              <?php if ($isBm): ?>
              <a class="action primary" href="<?= htmlspecialchars('medicao_ver.php?' . http_build_query(['mes' => (string) ($c['medicao_bm_ref_ym'] ?? $refYmBm)])) ?>">Ver chamado</a>
              <?php else: ?>
              <a class="action primary" href="chamado_detalhe.php?id=<?= (int) $c['id'] ?>">Ver</a>
              <?php if (db_ok()): ?>
              <a class="action chamados-row-pdf" href="chamado_detalhe.php?id=<?= (int) $c['id'] ?>&amp;export=pdf" target="_blank" rel="noopener" title="PDF do chamado e anexos">
                <span class="sr-only">PDF do chamado e anexos</span>
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
              </a>
              <?php endif; ?>
              <?php if (!$CRM_CHAMADOS_IS_CLIENTE && !$chInativo): ?>
              <form method="post" action="chamados.php" style="display:inline;" data-confirm="Inativar chamado #<?= (int) $c['id'] ?>? O registo permanece no sistema (exclusão lógica) e poderá ser consultado em «Excluídos / inativos»." data-confirm-danger>
                <input type="hidden" name="acao" value="excluir">
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <?php if ($clienteIdListagem > 0): ?><input type="hidden" name="cliente_id_ctx" value="<?= (int) $clienteIdListagem ?>"><?php endif; ?>
                <?php if ($envolvidoUser > 0): ?><input type="hidden" name="envolvido_user_ctx" value="<?= (int) $envolvidoUser ?>"><?php endif; ?>
                <?php if ($tecnicoUserId > 0): ?><input type="hidden" name="tecnico_user_id_ctx" value="<?= (int) $tecnicoUserId ?>"><?php endif; ?>
                <?php if ($localQ !== ''): ?><input type="hidden" name="local_q_ctx" value="<?= htmlspecialchars($localQ) ?>"><?php endif; ?>
                <?php if ($f !== ''): ?><input type="hidden" name="f_ctx" value="<?= htmlspecialchars($f) ?>"><?php endif; ?>
                <?php if ($periodoLimpar): ?><input type="hidden" name="periodo_limpar" value="1"><?php elseif ($medicaoMes !== ''): ?><input type="hidden" name="medicao_mes" value="<?= htmlspecialchars($medicaoMes) ?>"><?php else: ?><input type="hidden" name="periodo_de" value="<?= htmlspecialchars((string) $periodoDe) ?>"><input type="hidden" name="periodo_ate" value="<?= htmlspecialchars((string) $periodoAte) ?>"><?php endif; ?>
                <button type="submit" class="action danger" style="background:none;border:none;cursor:pointer;padding:0;font:inherit;" title="Exclusão lógica">Inativar</button>
              </form>
              <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <span class="pag-info">Mostrando <?= (int) $fromN ?>–<?= (int) $toN ?> de <?= (int) $totalRows ?></span>
      <div class="pag-controls">
        <?php if ($page <= 1): ?>
          <span class="pag-btn" style="opacity:.4;">‹</span>
        <?php else: ?>
          <a class="pag-btn" href="<?= htmlspecialchars($chamadosListagemHref($page - 1, $f, $q)) ?>">‹</a>
        <?php endif; ?>
        <?php
        $window = 5;
        $start = max(1, $page - (int) floor($window / 2));
        $end   = min($totalPages, $start + $window - 1);
        $start = max(1, $end - $window + 1);
        for ($pi = $start; $pi <= $end; $pi++):
        ?>
          <?php if ($pi === $page): ?>
            <span class="pag-btn active"><?= $pi ?></span>
          <?php else: ?>
            <a class="pag-btn" href="<?= htmlspecialchars($chamadosListagemHref($pi, $f, $q)) ?>"><?= $pi ?></a>
          <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page >= $totalPages): ?>
          <span class="pag-btn" style="opacity:.4;">›</span>
        <?php else: ?>
          <a class="pag-btn" href="<?= htmlspecialchars($chamadosListagemHref($page + 1, $f, $q)) ?>">›</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</section>

<div id="chamados-anexos-modal" class="chamados-anexos-modal" hidden aria-modal="true" role="dialog" aria-labelledby="chamados-anexos-modal-title">
  <button type="button" class="chamados-anexos-modal__scrim" data-chamados-anexos-close tabindex="-1" aria-label="Fechar"></button>
  <div class="chamados-anexos-modal__box">
    <div class="chamados-anexos-modal__head">
      <h3 id="chamados-anexos-modal-title">Anexos</h3>
      <button type="button" class="btn btn-ghost btn-sm" data-chamados-anexos-close>Fechar</button>
    </div>
    <div class="chamados-anexos-modal__body" id="chamados-anexos-modal-body">
      <div id="chamados-anexos-modal-empty" class="chamados-anexos-modal__empty" hidden></div>
      <div id="chamados-anexos-modal-carousel" hidden>
        <button type="button" class="chamados-anexos-nav chamados-anexos-nav--prev" id="chamados-anexos-prev" aria-label="Imagem anterior">‹</button>
        <button type="button" class="chamados-anexos-nav chamados-anexos-nav--next" id="chamados-anexos-next" aria-label="Próxima imagem">›</button>
        <div class="chamados-anexos-slide-wrap" id="chamados-anexos-touch">
          <img id="chamados-anexos-slide-img" src="" alt="" decoding="async">
          <p id="chamados-anexos-slide-caption" class="chamados-anexos-caption"></p>
        </div>
        <p id="chamados-anexos-slide-counter" class="chamados-anexos-counter"></p>
      </div>
    </div>
    <div class="chamados-anexos-modal__foot">
      <a id="chamados-anexos-link-detalhe" class="btn btn-primary btn-sm" href="#">Abrir chamado</a>
    </div>
  </div>
</div>

<script>
(function () {
  var modal = document.getElementById('chamados-anexos-modal');
  if (!modal) return;
  var titleEl = document.getElementById('chamados-anexos-modal-title');
  var emptyEl = document.getElementById('chamados-anexos-modal-empty');
  var carouselEl = document.getElementById('chamados-anexos-modal-carousel');
  var imgEl = document.getElementById('chamados-anexos-slide-img');
  var capEl = document.getElementById('chamados-anexos-slide-caption');
  var ctrEl = document.getElementById('chamados-anexos-slide-counter');
  var btnPrev = document.getElementById('chamados-anexos-prev');
  var btnNext = document.getElementById('chamados-anexos-next');
  var linkDet = document.getElementById('chamados-anexos-link-detalhe');
  var touchEl = document.getElementById('chamados-anexos-touch');
  var state = { imgs: [], idx: 0, touchStart: null };

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function imgUrl(id) {
    return 'chamado_download.php?id=' + encodeURIComponent(String(id)) + '&inline=1';
  }

  function showSlide() {
    if (!state.imgs.length || !imgEl) return;
    var it = state.imgs[state.idx];
    imgEl.src = imgUrl(it.id);
    imgEl.alt = it.nome || 'Imagem anexada';
    if (capEl) capEl.textContent = it.nome || '';
    if (ctrEl) ctrEl.textContent = state.idx + 1 + ' / ' + state.imgs.length;
    if (btnPrev) btnPrev.hidden = state.imgs.length < 2;
    if (btnNext) btnNext.hidden = state.imgs.length < 2;
  }

  function openModal(payload) {
    var cid = payload.chamadoId;
    var tit = payload.titulo || '';
    var total = payload.total || 0;
    state.imgs = Array.isArray(payload.imagens) ? payload.imagens : [];
    state.idx = 0;
    if (linkDet) linkDet.href = 'chamado_detalhe.php?id=' + encodeURIComponent(String(cid));
    if (titleEl) {
      titleEl.textContent = 'Chamado #' + cid + (tit ? ' — ' + tit : '');
    }
    if (!emptyEl || !carouselEl) return;
    if (state.imgs.length) {
      emptyEl.hidden = true;
      carouselEl.hidden = false;
      showSlide();
    } else {
      carouselEl.hidden = true;
      emptyEl.hidden = false;
      emptyEl.innerHTML =
        '<p>Este chamado tem <strong>' +
        esc(String(total)) +
        '</strong> anexo(s), mas nenhum é imagem pré-visualizável aqui (ex.: só PDF ou documentos).</p>' +
        '<p style="margin-top:12px"><a href="' +
        esc('chamado_detalhe.php?id=' + cid) +
        '">Abrir o chamado para ver ou baixar os ficheiros</a></p>';
    }
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
  }

  function closeModal() {
    modal.hidden = true;
    document.body.style.overflow = '';
    if (imgEl) {
      imgEl.removeAttribute('src');
      imgEl.alt = '';
    }
    if (capEl) capEl.textContent = '';
    if (ctrEl) ctrEl.textContent = '';
  }

  function step(d) {
    if (state.imgs.length < 2) return;
    state.idx = (state.idx + d + state.imgs.length) % state.imgs.length;
    showSlide();
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.chamados-anexos-trigger');
    if (btn && btn.getAttribute('data-chamados-anexos')) {
      e.preventDefault();
      try {
        var raw = btn.getAttribute('data-chamados-anexos') || '{}';
        openModal(JSON.parse(raw));
      } catch (err) {
        return;
      }
      return;
    }
    if (e.target.closest('[data-chamados-anexos-close]')) {
      e.preventDefault();
      closeModal();
    }
  });

  if (btnPrev) btnPrev.addEventListener('click', function () { step(-1); });
  if (btnNext) btnNext.addEventListener('click', function () { step(1); });

  document.addEventListener('keydown', function (e) {
    if (modal.hidden) return;
    if (e.key === 'Escape') closeModal();
    else if (e.key === 'ArrowLeft') step(-1);
    else if (e.key === 'ArrowRight') step(1);
  });

  if (touchEl) {
    touchEl.addEventListener('touchstart', function (e) {
      if (e.changedTouches && e.changedTouches[0]) {
        state.touchStart = e.changedTouches[0].clientX;
      }
    }, { passive: true });
    touchEl.addEventListener('touchend', function (e) {
      if (state.touchStart == null || !e.changedTouches || !e.changedTouches[0]) return;
      var dx = e.changedTouches[0].clientX - state.touchStart;
      state.touchStart = null;
      if (dx > 50) step(-1);
      else if (dx < -50) step(1);
    }, { passive: true });
  }
})();
</script>

<?php include __DIR__ . '/footer.php'; ?>
