<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('chamados');

require_once __DIR__ . '/../includes/medicao_helpers.php';

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

$medicaoMes = trim((string) ($_GET['medicao_mes'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $medicaoMes)) {
    $medicaoMes = '';
}

$periodoLimpar = isset($_GET['periodo_limpar']) && (string) $_GET['periodo_limpar'] === '1';
$periodoDeRaw = trim((string) ($_GET['periodo_de'] ?? ''));
$periodoAteRaw = trim((string) ($_GET['periodo_ate'] ?? ''));

$periodoDe  = null;
$periodoAte = null;
if ($periodoLimpar) {
    // sem filtro de data
} elseif ($medicaoMes !== '') {
    $periodoDe  = $medicaoMes . '-01';
    $periodoAte = date('Y-m-t', strtotime($periodoDe));
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir') {
    $delId = (int) ($_POST['id'] ?? 0);
    if (db_ok() && $delId > 0) {
        $chDel = repo_chamado($delId);
        if ($chDel) {
            gestor_assert_escopo_cliente((int) ($chDel['cliente_id'] ?? 0), 'chamados.php');
        }
        if (repo_delete_chamado($delId)) {
            flash_set('ok', 'Chamado #' . $delId . ' excluído.');
        } else {
            flash_set('err', 'Não foi possível excluir o chamado.');
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
if (!in_array($f, ['', 'abertos', 'andamento', 'aguardando', 'resolvidos', 'cancelados', 'urgentes'], true)) {
    $f = '';
}
$q = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;
$medicaoMatrizId = 0;
$medicaoMatrizNome = '';
if (db_ok()) {
    if ($escopoCh !== null && $escopoCh > 0) {
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
if (db_ok() && $medicaoMatrizId > 0) {
    $tecnicosLista = repo_operadores_empresa($medicaoMatrizId);
}

$bmLista = [];
$bmMergeActive = $medicaoMatrizId > 0 && $refYmBm !== '' && $f === '' && $envolvidoUser <= 0 && $clienteIdListagem <= 0 && $tecnicoUserId <= 0 && $localQ === '' && db_ok();
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
    if ($periodoDe !== null && $periodoAte !== null && $nBm > 0 && $f === '' && $bmMergeActive) {
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

$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page       = min($page, $totalPages);

$dash = db_ok() ? repo_dashboard_admin_stats() : null;

/**
 * @param array{medicao_mes?:string,periodo_de?:string,periodo_ate?:string,periodo_limpar?:bool,cliente_id?:int,envolvido_user?:int,tecnico_user_id?:int,local_q?:string} $periodoCtx
 */
function adm_chamados_url(int $p, string $filtro, string $busca, array $periodoCtx): string
{
    $qs = [];
    if ($filtro !== '') {
        $qs['f'] = $filtro;
    }
    if ($busca !== '') {
        $qs['q'] = $busca;
    }
    if ($p > 1) {
        $qs['page'] = $p;
    }
    if (!empty($periodoCtx['periodo_limpar'])) {
        $qs['periodo_limpar'] = '1';
    } elseif (($periodoCtx['medicao_mes'] ?? '') !== '') {
        $qs['medicao_mes'] = $periodoCtx['medicao_mes'];
    } else {
        if (($periodoCtx['periodo_de'] ?? '') !== '') {
            $qs['periodo_de'] = $periodoCtx['periodo_de'];
        }
        if (($periodoCtx['periodo_ate'] ?? '') !== '') {
            $qs['periodo_ate'] = $periodoCtx['periodo_ate'];
        }
    }
    if (!empty($periodoCtx['cliente_id'])) {
        $qs['cliente_id'] = (int) $periodoCtx['cliente_id'];
    }
    if (!empty($periodoCtx['envolvido_user'])) {
        $qs['envolvido_user'] = (int) $periodoCtx['envolvido_user'];
    }
    if (!empty($periodoCtx['tecnico_user_id'])) {
        $qs['tecnico_user_id'] = (int) $periodoCtx['tecnico_user_id'];
    }
    if (!empty($periodoCtx['local_q'])) {
        $qs['local_q'] = (string) $periodoCtx['local_q'];
    }

    return 'chamados.php' . ($qs ? '?' . http_build_query($qs) : '');
}

/**
 * URL de exportação com os mesmos filtros da listagem.
 */
function adm_chamados_export_url(string $format, string $filtro, string $busca, array $periodoCtx): string
{
    $base = adm_chamados_url(1, $filtro, $busca, $periodoCtx);

    return $base . ((strpos($base, '?') !== false) ? '&' : '?') . 'export=' . rawurlencode($format);
}

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
$admChClearBuscaCtx = array_merge($admChPeriodoCtx, ['local_q' => null, 'tecnico_user_id' => null]);

$exportFmt = strtolower(trim((string) ($_GET['export'] ?? '')));
$mergeBmExport = $periodoDe !== null && $periodoAte !== null && $nBm > 0 && $f === '' && $bmMergeActive;
$maxExportRows = 20000;
$maxPdfChamados = 120;

if ($exportFmt === 'pdf_anexos') {
    require_once __DIR__ . '/../includes/crm_export_pdf_debug.php';
    crm_export_pdf_flush_output_buffers();
    ob_start();

    require_once __DIR__ . '/../includes/chamados_periodo_export_pdf.php';
    require_once __DIR__ . '/../includes/chamados_periodo_pdf_dompdf.php';
    if (!defined('APP_BRAND_NAME')) {
        require_once __DIR__ . '/../includes/config.php';
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
    require_once __DIR__ . '/../includes/chamados_periodo_pdf_merge.php';
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
    require_once __DIR__ . '/../includes/chamados_medicao_export_xlsx.php';

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
    if ($matrizItensId > 0 && $pDeIt !== '' && $pAtIt !== '') {
        $detalheLinhasBoletim = repo_catalogo_chamados_itens_periodo_por_data_lancamento(
            $matrizItensId,
            $pDeIt,
            $pAtIt,
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
            'incluir_detalhamento_chamados' => ($exportFmt === 'xlsx_detalhes'),
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

$pageTitle  = 'Chamados';
$basePath   = '../';
$activePage = 'chamados';

$topTitle    = 'Chamados';
$topSubtitle = 'Lista filtrada pela data de abertura (por defeito: mês atual).';
$topSearch   = 'Buscar por ID, título ou prefeitura...';
$topAction   = ['label' => 'Novo chamado', 'href' => 'chamado_novo.php', 'icon' => '+'];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
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

  <div class="card">
    <div class="panel-head" style="flex-wrap:wrap;gap:12px;">
      <div style="flex:1;min-width:0;">
        <h4 style="margin:0;">Todos os chamados</h4>
        <span class="panel-sub"><?= (int) $totalRows ?> registro(s)</span>
      </div>
      <?php if (db_ok()): ?>
      <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
        <span class="muted" style="font-size:12px;">Exportar:</span>
        <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars(adm_chamados_export_url('xlsx', $f, $q, $admChPeriodoCtx)) ?>" title="Boletim de medição e tabela principal (sem secção «DETALHAMENTO DOS CHAMADOS»)">Excel — boletim</a>
        <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars(adm_chamados_export_url('xlsx_detalhes', $f, $q, $admChPeriodoCtx)) ?>" title="Igual ao boletim, mais a tabela «DETALHAMENTO DOS CHAMADOS» linha a linha">Excel — com detalhes</a>
        <?php if (!$periodoLimpar): ?>
        <?php
          $hrefPdfAnexos = adm_chamados_export_url('pdf_anexos', $f, $q, $admChPeriodoCtx);
          if (defined('CRM_EXPORT_PDF_DEBUG') && CRM_EXPORT_PDF_DEBUG) {
              $hrefPdfAnexos .= '&pdf_debug=1';
          }
        ?>
        <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($hrefPdfAnexos) ?>" title="Gera PDF com lista de chamados e imagens anexadas (Dompdf)">Exportar PDF (anexos)</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="filters" style="padding:12px 20px;border-bottom:1px solid var(--border);display:grid;gap:12px;">
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <?php
          $qsPeriodoLimpar = array_filter([
              'periodo_limpar'    => '1',
              'cliente_id'        => $clienteIdListagem > 0 ? $clienteIdListagem : null,
              'envolvido_user'    => $envolvidoUser > 0 ? $envolvidoUser : null,
              'tecnico_user_id'   => $tecnicoUserId > 0 ? $tecnicoUserId : null,
              'local_q'           => $localQ !== '' ? $localQ : null,
              'f'                 => $f !== '' ? $f : null,
              'q'                 => $q !== '' ? $q : null,
          ], static fn ($v) => $v !== null && $v !== '');
          $qsMesAtual = array_filter([
              'cliente_id'        => $clienteIdListagem > 0 ? $clienteIdListagem : null,
              'envolvido_user'    => $envolvidoUser > 0 ? $envolvidoUser : null,
              'tecnico_user_id'   => $tecnicoUserId > 0 ? $tecnicoUserId : null,
              'local_q'           => $localQ !== '' ? $localQ : null,
              'f'                 => $f !== '' ? $f : null,
              'q'                 => $q !== '' ? $q : null,
          ], static fn ($v) => $v !== null && $v !== '');
          $urlPeriodoLimpar = 'chamados.php' . ($qsPeriodoLimpar !== [] ? '?' . http_build_query($qsPeriodoLimpar) : '');
          $urlMesAtual      = 'chamados.php' . ($qsMesAtual !== [] ? '?' . http_build_query($qsMesAtual) : '');
        ?>
        <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($urlPeriodoLimpar) ?>">Todo o período</a>
        <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($urlMesAtual) ?>">Mês atual</a>
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
        <button type="submit" class="btn btn-primary" style="justify-content:center;flex:0 0 auto;flex-shrink:0;">Aplicar filtros</button>
        <?php if ($q !== '' || $localQ !== '' || $tecnicoUserId > 0): ?>
        <a href="<?= htmlspecialchars(adm_chamados_url(1, $f, '', $admChClearBuscaCtx)) ?>" class="btn" style="flex-shrink:0;">Limpar busca</a>
        <?php endif; ?>
      </form>
    </div>

    <div class="table-wrap">
      <table class="chamados-admin-list" style="min-width:1040px;">
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
            <th class="text-right">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($lista)): ?>
          <tr><td colspan="9" class="muted" style="padding:24px;text-align:center;">Nenhum chamado encontrado.</td></tr>
          <?php else: ?>
          <?php foreach ($lista as $c): ?>
          <?php $isBm = !empty($c['medicao_bm']); ?>
          <tr>
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
              <?php if (!$isBm && !empty($c['finalizado_operador_em']) && empty($c['aprovado_gestor_em'])): ?>
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
              <form method="post" action="chamados.php" style="display:inline;" data-confirm="Excluir chamado #<?= (int) $c['id'] ?> e todo o histórico?" data-confirm-danger>
                <input type="hidden" name="acao" value="excluir">
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <?php if ($clienteIdListagem > 0): ?><input type="hidden" name="cliente_id_ctx" value="<?= (int) $clienteIdListagem ?>"><?php endif; ?>
                <?php if ($envolvidoUser > 0): ?><input type="hidden" name="envolvido_user_ctx" value="<?= (int) $envolvidoUser ?>"><?php endif; ?>
                <?php if ($tecnicoUserId > 0): ?><input type="hidden" name="tecnico_user_id_ctx" value="<?= (int) $tecnicoUserId ?>"><?php endif; ?>
                <?php if ($localQ !== ''): ?><input type="hidden" name="local_q_ctx" value="<?= htmlspecialchars($localQ) ?>"><?php endif; ?>
                <?php if ($f !== ''): ?><input type="hidden" name="f_ctx" value="<?= htmlspecialchars($f) ?>"><?php endif; ?>
                <?php if ($periodoLimpar): ?><input type="hidden" name="periodo_limpar" value="1"><?php elseif ($medicaoMes !== ''): ?><input type="hidden" name="medicao_mes" value="<?= htmlspecialchars($medicaoMes) ?>"><?php else: ?><input type="hidden" name="periodo_de" value="<?= htmlspecialchars((string) $periodoDe) ?>"><input type="hidden" name="periodo_ate" value="<?= htmlspecialchars((string) $periodoAte) ?>"><?php endif; ?>
                <button type="submit" class="action danger" style="background:none;border:none;cursor:pointer;padding:0;font:inherit;">Excluir</button>
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

    <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <span class="pag-info">Mostrando <?= (int) $fromN ?>–<?= (int) $toN ?> de <?= (int) $totalRows ?></span>
      <div class="pag-controls">
        <?php if ($page <= 1): ?>
          <span class="pag-btn" style="opacity:.4;">‹</span>
        <?php else: ?>
          <a class="pag-btn" href="<?= htmlspecialchars(adm_chamados_url($page - 1, $f, $q, $admChPeriodoCtx)) ?>">‹</a>
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
            <a class="pag-btn" href="<?= htmlspecialchars(adm_chamados_url($pi, $f, $q, $admChPeriodoCtx)) ?>"><?= $pi ?></a>
          <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page >= $totalPages): ?>
          <span class="pag-btn" style="opacity:.4;">›</span>
        <?php else: ?>
          <a class="pag-btn" href="<?= htmlspecialchars(adm_chamados_url($page + 1, $f, $q, $admChPeriodoCtx)) ?>">›</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
