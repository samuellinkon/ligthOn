<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/medicao_export_bm_boletim_xlsx.php';
require_once __DIR__ . '/chamados_medicao_tpl1.php';

function bm_med_dt_br(?string $raw): string
{
    if ($raw === null || $raw === '') {
        return '';
    }
    $t = strtotime($raw);

    return $t ? date('d/m/Y H:i', $t) : $raw;
}

function bm_med_movimento_pt(?string $raw): string
{
    $m = strtolower(trim((string) ($raw ?? '')));

    return match ($m) {
        'utilizado' => 'Utilizado',
        'devolvido' => 'Devolvido',
        default     => $m !== '' ? (string) $raw : '—',
    };
}

function bm_med_tipo_item_pt(?string $raw): string
{
    $t = strtolower(trim((string) ($raw ?? '')));

    return match ($t) {
        'produto', 'material' => 'Produto',
        'servico', 'serviço'  => 'Serviço',
        ''         => '—',
        default    => ucfirst($t),
    };
}

/** Data do lançamento (coluna DATA institucional), só dia `dd/mm/yyyy`. */
function bm_med_data_dia_br(?string $raw): string
{
    if ($raw === null || $raw === '') {
        return '';
    }
    $t = strtotime(substr(trim($raw), 0, 16));

    return $t ? date('d/m/Y', $t) : trim($raw);
}

/** Cabeçalhos exactos do modelo GIP (colunas A–S). Coluna B: ID do chamado. */
function bm_med_headers_gip(): array
{
    return [
        'DATA',
        'ID',
        'COORDENADA S',
        'COORDENADA W',
        'PLAQUETA / BARRAMENTO',
        'BAIRRO',
        'RUA - AVENIDA',
        'EQUIPE',
        'SERVIÇOS / MATERIAIS',
        'Tipo de Serviço',
        'CÓDIGO',
        'DESCRIÇÃO DOS ITENS',
        'QTD.',
        'UN',
        'VALOR UNITÁRIO (R$)',
        'VALOR TOTAL (R$)',
        'Material Retirados/Incluído',
        'Quantidade',
        'Marca Luminária',
    ];
}

function bm_med_parse_coord_num(?string $v): ?float
{
    $t = trim((string) ($v ?? ''));
    if ($t === '' || !is_numeric($t)) {
        return null;
    }

    return (float) $t;
}

function bm_med_coord_s(array $ln): ?float
{
    $a = bm_med_parse_coord_num(isset($ln['ch_latitude']) ? (string) $ln['ch_latitude'] : null);

    return $a ?? bm_med_parse_coord_num(isset($ln['pi_latitude']) ? (string) $ln['pi_latitude'] : null);
}

function bm_med_coord_w(array $ln): ?float
{
    $a = bm_med_parse_coord_num(isset($ln['ch_longitude']) ? (string) $ln['ch_longitude'] : null);

    return $a ?? bm_med_parse_coord_num(isset($ln['pi_longitude']) ? (string) $ln['pi_longitude'] : null);
}

function bm_med_rua_institucional(array $ln): string
{
    $log = trim((string) ($ln['ch_os_logradouro'] ?? ''));
    $num = trim((string) ($ln['ch_os_numero'] ?? ''));
    $rua = trim($log . ($num !== '' ? ' ' . $num : ''));
    if ($rua !== '') {
        return $rua;
    }

    return trim((string) ($ln['endereco_completo'] ?? ''));
}

function bm_med_bairro_institucional(array $ln): string
{
    $b = trim((string) ($ln['ch_os_bairro'] ?? ''));
    if ($b !== '') {
        return $b;
    }

    return trim((string) ($ln['pi_bairro'] ?? ''));
}

/** Texto institucional coluna Q (observações + movimento). */
function bm_med_material_retirados_incluido_txt(array $ln): string
{
    $obs = trim(str_replace(["\r", "\n"], ' ', (string) ($ln['observacao'] ?? '')));
    $mov = bm_med_movimento_pt((string) ($ln['movimento'] ?? ''));
    if ($mov !== '' && $mov !== '—') {
        return $obs !== '' ? ($obs . ' · Movimento: ' . $mov) : ('Movimento: ' . $mov);
    }

    return $obs;
}

function bm_med_tipo_servico_institucional(array $ln): string
{
    $a = trim((string) ($ln['chamado_problema_tipo'] ?? ''));
    if ($a !== '') {
        return $a;
    }

    return trim((string) ($ln['servico_categoria_tipo'] ?? ''));
}

/**
 * Coluna B (ID): número do chamado na BD (sem campo GIP dedicado).
 *
 * @param array<string,mixed> $ln Linha consolidada (template) com dados de geo/endereço
 */
function bm_med_linha_institucional_gip(
    \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
    int $r,
    array $ln,
    int $chamadoId
): void {
    $cidTxt = $chamadoId > 0 ? (string) $chamadoId : '';
    $sheet->setCellValue('A' . $r, bm_med_data_dia_br((string) ($ln['item_criado_em'] ?? '')));
    $sheet->setCellValue('B' . $r, $cidTxt);
    $lat = bm_med_coord_s($ln);
    $lon = bm_med_coord_w($ln);
    if ($lat !== null) {
        $sheet->setCellValue('C' . $r, $lat);
    }
    if ($lon !== null) {
        $sheet->setCellValue('D' . $r, $lon);
    }
    $sheet->setCellValue('E' . $r, trim((string) ($ln['ponto_codigo_poste'] ?? '')));
    $sheet->setCellValue('F' . $r, bm_med_bairro_institucional($ln));
    $sheet->setCellValue('G' . $r, bm_med_rua_institucional($ln));
    $sheet->setCellValue('H' . $r, trim((string) ($ln['tecnico_nomes'] ?? '')));
    $sheet->setCellValue('I' . $r, bm_med_tipo_item_pt((string) ($ln['item_tipo'] ?? '')));
    $sheet->setCellValue('J' . $r, bm_med_tipo_servico_institucional($ln));
    $sheet->setCellValue('K' . $r, trim((string) ($ln['item_codigo'] ?? '')));
    $sheet->setCellValue('L' . $r, trim((string) ($ln['item_nome'] ?? '')));
    $sheet->setCellValue('M' . $r, (float) ($ln['quantidade'] ?? 0));
    $sheet->setCellValue('N' . $r, trim((string) ($ln['catalogo_unidade'] ?? '')));
    $sheet->setCellValue('O' . $r, (float) ($ln['valor_unitario'] ?? 0));
    $sheet->setCellValue('P' . $r, (float) ($ln['subtotal'] ?? 0));
    $sheet->setCellValue('Q' . $r, bm_med_material_retirados_incluido_txt($ln));
    $sheet->setCellValue('R' . $r, '');
    $sheet->setCellValue('S' . $r, '');
}

/**
 * Agrupa lançamentos por chamado e consolida itens com a mesma chave (código + catálogo + VU + tipo + movimento),
 * somando quantidade e subtotal. Observações distintas são unidas com « | ».
 * Ordem: chamados por primeira aparição em `$det`, itens pela primeira aparição da chave.
 *
 * @param list<array<string,mixed>> $det Linhas de repo_catalogo_chamados_itens_periodo_por_data_lancamento
 *
 * @return list<array{chamado_id:int, meta:array<string,mixed>, ultimo_lancamento_em:string, itens:list<array<string,mixed>>}>
 */
function bm_med_agrupar_detalhamento_chamados_consolidado(array $det): array
{
    /** @var array<int, array{meta: array<string,mixed>, ultimo_lancamento_em: string, item_order: list<string>, items: array<string, array{template: array<string,mixed>, quantidade: float, subtotal: float, obs_set: array<string, true}>}> $byCid */
    $byCid  = [];
    $cidSeq = [];
    foreach ($det as $linha) {
        $cid = (int) ($linha['chamado_id'] ?? 0);
        if (!isset($byCid[$cid])) {
            $byCid[$cid] = [
                'meta'                 => $linha,
                'ultimo_lancamento_em' => '',
                'item_order'           => [],
                'items'                => [],
            ];
            $cidSeq[] = $cid;
        }
        $cre = trim((string) ($linha['item_criado_em'] ?? ''));
        if ($cre !== '') {
            $prev = $byCid[$cid]['ultimo_lancamento_em'];
            if ($prev === '' || strcmp($cre, $prev) > 0) {
                $byCid[$cid]['ultimo_lancamento_em'] = $cre;
            }
        }

        $cod  = trim((string) ($linha['item_codigo'] ?? ''));
        $iid  = (int) ($linha['item_id_catalogo'] ?? 0);
        $vu   = (float) ($linha['valor_unitario'] ?? 0);
        $tipo = strtolower(trim((string) ($linha['item_tipo'] ?? '')));
        $mov  = strtolower(trim((string) ($linha['movimento'] ?? '')));
        $gkey = $cod . "\x1e" . $iid . "\x1e" . sprintf('%.8F', $vu) . "\x1e" . $tipo . "\x1e" . $mov;

        if (!isset($byCid[$cid]['items'][$gkey])) {
            $byCid[$cid]['items'][$gkey] = [
                'template'   => $linha,
                'quantidade' => 0.0,
                'subtotal'   => 0.0,
                'obs_set'    => [],
            ];
            $byCid[$cid]['item_order'][] = $gkey;
        }
        $byCid[$cid]['items'][$gkey]['quantidade'] += (float) ($linha['quantidade'] ?? 0);
        $byCid[$cid]['items'][$gkey]['subtotal'] += (float) ($linha['subtotal'] ?? 0);
        $obs = trim((string) ($linha['observacao'] ?? ''));
        if ($obs !== '') {
            $byCid[$cid]['items'][$gkey]['obs_set'][$obs] = true;
        }
    }

    $out = [];
    foreach ($cidSeq as $cid) {
        if (!isset($byCid[$cid])) {
            continue;
        }
        $bucket = $byCid[$cid];
        $itens  = [];
        foreach ($bucket['item_order'] as $gk) {
            $g = $bucket['items'][$gk];
            $obsStr = $g['obs_set'] !== [] ? implode(' | ', array_keys($g['obs_set'])) : '';
            $tpl    = $g['template'];
            $tpl['quantidade']     = $g['quantidade'];
            $tpl['subtotal']       = $g['subtotal'];
            $tpl['valor_unitario'] = (float) ($tpl['valor_unitario'] ?? 0);
            $tpl['observacao']     = $obsStr;
            $itens[]               = $tpl;
        }
        $out[] = [
            'chamado_id'           => $cid,
            'meta'                 => $bucket['meta'],
            'ultimo_lancamento_em' => $bucket['ultimo_lancamento_em'],
            'itens'                => $itens,
        ];
    }

    return $out;
}

/** @return list<string> */
function bm_med_headers(): array
{
    return [
        'ITEM', 'DESCRIÇÃO', 'UNID.', 'QUANT. PREVISTA', '1º TERMO ADITIVO', '2º TERMO ADITIVO',
        'QUANT. TOTAL', 'PREÇO UNITÁRIO', 'SOMA DOS BM\'S', 'MEDIDO NO PERÍODO',
        'ACUMULADO INCLUINDO O PERÍODO ATUAL', 'SALDO A EXECUTAR', 'ACUMULADO ATÉ O PERÍODO ANTERIOR',
        'MEDIDO NO PERÍODO (R$)', 'SALDO A EXECUTAR (R$)', 'DESVIO (%)',
    ];
}

function bm_med_border(): array
{
    return ['borders' => medicao_bm_boletim_style_borders_all(MEDICAO_BM_BORDER_LIGHT, Border::BORDER_THIN)];
}

/**
 * Capa institucional (4 linhas), estrutura próxima ao modelo 1.xlsx: título hero, grelha 2x2, faixa de serviço, espaçador.
 *
 * @return int Primeira linha do bloco contrato ($c0).
 */
function bm_med_capa_boletim_montar(
    \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
    string $lastLt,
    array $st,
    string $nrBol,
    string $periodoTxt,
    string $emitido,
    string $brand,
    string $matrizLb,
    string $refYm
): int {
    $mesTxt = ($refYm !== '' && function_exists('medicao_mes_label_pt'))
        ? medicao_mes_label_pt($refYm)
        : $periodoTxt;

    $r = 1;
    $sheet->mergeCells("A{$r}:{$lastLt}{$r}");
    $sheet->setCellValue("A{$r}", "BOLETIM DE MEDIÇÃO  ·  Nº {$nrBol}");
    $sheet->getStyle("A{$r}:{$lastLt}{$r}")->applyFromArray($st['title_main']);
    $sheet->getStyle("A{$r}:{$lastLt}{$r}")->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(false);
    $sheet->getRowDimension($r)->setRowHeight(MEDICAO_BM_XLSX_ROW_TITLE);

    ++$r;
    $sheet->mergeCells("A{$r}:D{$r}");
    $sheet->setCellValue("A{$r}", "Medição\n" . $mesTxt);
    $sheet->mergeCells("E{$r}:H{$r}");
    $sheet->setCellValue("E{$r}", "Prefeitura / tomador\n" . $matrizLb);
    $sheet->mergeCells("I{$r}:L{$r}");
    $sheet->setCellValue("I{$r}", "Contrato\n—");
    $sheet->mergeCells("M{$r}:{$lastLt}{$r}");
    $sheet->setCellValue("M{$r}", "Período / emitido\n" . $periodoTxt . "\n" . $emitido);
    $sheet->getStyle("A{$r}:D{$r}")->applyFromArray($st['capa_grid_cell']);
    $sheet->getStyle("E{$r}:H{$r}")->applyFromArray($st['capa_grid_cell']);
    $sheet->getStyle("I{$r}:L{$r}")->applyFromArray($st['capa_grid_cell']);
    $sheet->getStyle("M{$r}:{$lastLt}{$r}")->applyFromArray($st['capa_grid_cell_plain']);
    $sheet->getStyle("A{$r}:{$lastLt}{$r}")->applyFromArray($st['section_outline_soft']);
    $sheet->getStyle("A{$r}:{$lastLt}{$r}")->getAlignment()->setIndent(1);
    $sheet->getRowDimension($r)->setRowHeight(MEDICAO_BM_XLSX_ROW_CAPA_GRID);

    ++$r;
    $sheet->mergeCells("A{$r}:{$lastLt}{$r}");
    $sheet->setCellValue(
        "A{$r}",
        'Manutenção da iluminação pública — medição  ·  ' . $brand
    );
    $sheet->getStyle("A{$r}:{$lastLt}{$r}")->applyFromArray($st['capa_service_banner']);
    $sheet->getRowDimension($r)->setRowHeight(MEDICAO_BM_XLSX_ROW_CAPA_SERVICE);

    ++$r;
    $sheet->mergeCells("A{$r}:{$lastLt}{$r}");
    $sheet->setCellValue("A{$r}", '');
    $sheet->getStyle("A{$r}:{$lastLt}{$r}")->applyFromArray([
        'fill' => medicao_bm_boletim_style_fill_solid(MEDICAO_BM_WHITE),
    ]);
    $sheet->getRowDimension($r)->setRowHeight(MEDICAO_BM_XLSX_ROW_SPACER);

    return $r + 1;
}

/** @param array{0:string,1:string} $L @param array{0:string,1:string} $R — layout A–P (faixas largas, estilo relatório) */
function bm_med_hdr_row(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $s, int $r, array $L, array $R): void
{
    $s->mergeCells("A{$r}:C{$r}");
    $s->setCellValue("A{$r}", $L[0]);
    $s->mergeCells("D{$r}:J{$r}");
    $s->setCellValue("D{$r}", $L[1]);
    $s->mergeCells("K{$r}:M{$r}");
    $s->setCellValue("K{$r}", $R[0]);
    $s->mergeCells("N{$r}:P{$r}");
    $s->setCellValue("N{$r}", $R[1]);
    $s->getStyle("A{$r}:C{$r}")->getAlignment()->setWrapText(true);
    $s->getStyle("D{$r}:J{$r}")->getAlignment()->setWrapText(true);
    $s->getStyle("K{$r}:M{$r}")->getAlignment()->setWrapText(true);
    $s->getStyle("N{$r}:P{$r}")->getAlignment()->setWrapText(true);
}

function bm_med_faixa(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $s, int $r, string $tit): void
{
    $st = medicao_bm_boletim_style_arrays_tipografia();
    $s->mergeCells('A' . $r . ':P' . $r);
    $s->setCellValue('A' . $r, $tit);
    $s->getStyle('A' . $r . ':P' . $r)->applyFromArray($st['title_sub_flat']);
    $s->getStyle('A' . $r . ':P' . $r)->applyFromArray($st['section_outline_soft']);
    $s->getStyle('A' . $r . ':P' . $r)->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);
    $s->getRowDimension($r)->setRowHeight(MEDICAO_BM_XLSX_ROW_SUB_META);
}

/** Agrupa qty/subtotal CRM por código. */
/** @return array<string,array{descricao:string,unidade:string,tipo:string,qty:float,subtotal:float}> */
function bm_med_crm_map(array $det): array
{
    $m = [];
    foreach ($det as $row) {
        $cod = trim((string) ($row['item_codigo'] ?? ''));
        if ($cod === '') {
            continue;
        }
        if (!isset($m[$cod])) {
            $m[$cod] = [
                'descricao' => trim((string) ($row['item_nome'] ?? '')),
                'unidade'   => trim((string) ($row['catalogo_unidade'] ?? '')),
                'tipo'      => strtolower(trim((string) ($row['item_tipo'] ?? ''))),
                'qty'       => 0.0,
                'subtotal'  => 0.0,
            ];
        }
        $m[$cod]['qty']      += (float) ($row['quantidade'] ?? 0);
        $m[$cod]['subtotal'] += (float) ($row['subtotal'] ?? 0);
    }
    return $m;
}

/** Aplica G/K/L/N/O/P onde J e M são entrada numérica ou vazios. */
function bm_med_formula_corpo(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $s, int $r): void
{
    $s->setCellValue("G{$r}", "=SUM(D{$r}:F{$r})");
    $s->setCellValue("K{$r}", "=IF(OR(NOT(ISNUMBER(M{$r})),M{$r}=0),J{$r},M{$r}+J{$r})");
    $s->setCellValue("L{$r}", "=MAX(0,G{$r}-K{$r})");
    $s->setCellValue("N{$r}", "=IFERROR(J{$r}*H{$r},0)");
    $s->setCellValue("O{$r}", "=IFERROR(L{$r}*H{$r},0)");
    $s->setCellValue("P{$r}", '=IF(G' . $r . '>0,L' . $r . '/G' . $r . ',"")');
}

/** @param ?array{qty:float,subtotal?:float} $crmSomado extra CRM mesmo código catálogo */
function bm_med_linha_bm(
    \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $s,
    int $r,
    array $bm,
    ?array $crmSomado
): void {
    $cod = trim((string) ($bm['item_codigo'] ?? ''));
    $s->setCellValue('A' . $r, $cod);
    $s->setCellValue('B' . $r, trim((string) ($bm['descricao'] ?? '')));
    $s->setCellValue('C' . $r, trim((string) ($bm['unidade'] ?? '')));

    $prev = $bm['qtd_prevista'] ?? null;
    $s->setCellValue('D' . $r, ($prev !== null && $prev !== '') ? (float) $prev : '');

    $vqMedBm = isset($bm['qtd_medido_periodo']) && $bm['qtd_medido_periodo'] !== null && $bm['qtd_medido_periodo'] !== ''
        ? (float) $bm['qtd_medido_periodo'] : 0.0;
    $addCrm = $crmSomado !== null ? (float) ($crmSomado['qty'] ?? 0.0) : 0.0;
    $s->setCellValue('J' . $r, max(0, $vqMedBm + $addCrm));

    $precoBm = isset($bm['preco_unitario']) && $bm['preco_unitario'] !== null && $bm['preco_unitario'] !== ''
        ? (float) $bm['preco_unitario'] : 0.0;
    $valBmMed = isset($bm['valor_medido_periodo']) && $bm['valor_medido_periodo'] !== null && $bm['valor_medido_periodo'] !== ''
        ? (float) $bm['valor_medido_periodo'] : 0.0;
    $cSub     = $crmSomado !== null ? (float) ($crmSomado['subtotal'] ?? 0.0) : 0.0;
    $denQ     = max(0.0, $vqMedBm + $addCrm);
    $precoH   = $precoBm;
    if ($denQ > 1e-9) {
        /* Média ponderada BM + CRM quando os dois existem */
        $precoH = ($valBmMed + $cSub) / $denQ;
    } elseif ($addCrm > 1e-9 && $crmSomado !== null) {
        $precoH = $cSub / $addCrm;
    }
    if ($precoH <= 0 && $precoBm > 0) {
        $precoH = $precoBm;
    }
    $s->setCellValue('H' . $r, round(max(0.0, $precoH), 6));

    /* SOMA DOS BM’S: apenas referência qty BM */
    if ($vqMedBm > 0) {
        $s->setCellValue('I' . $r, $vqMedBm);
    }

    $s->setCellValue('M' . $r, 0);
}

/** @param array{item_codigo:string,descricao:string,unidade:string,qtd_med:float,subtotal:float} $row */
function bm_med_linha_crm_agg(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $s, int $r, array $row): void
{
    $s->setCellValue('A' . $r, $row['item_codigo']);
    $s->setCellValue('B' . $r, $row['descricao']);
    $s->setCellValue('C' . $r, $row['unidade']);
    $s->setCellValue('J' . $r, max(0, $row['qtd_med']));
    $pu = ($row['qtd_med'] > 0.00001) ? $row['subtotal'] / $row['qtd_med'] : 0.0;
    $s->setCellValue('H' . $r, round($pu, 6));
    $s->setCellValue('M' . $r, 0);
}

function bm_med_grp_bm(string $cod): string
{
    if ($cod !== '' && preg_match('/^(\d+)/', $cod, $m)) {
        return $m[1];
    }
    return '__';
}

/**
 * Indicadores em duas linhas, blocos largos e contorno suave (estrutura tipo relatório).
 *
 * @param array<string,int|float|string> $kp
 *
 * @return int Linha imediatamente abaixo do bloco KPI
 */
function bm_med_kpis_grid(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $s, int $r, array $kp, array $st): int
{
    $lblFont  = medicao_bm_boletim_style_font(true, MEDICAO_BM_XLSX_SIZE_BASE, MEDICAO_BM_BRAND_PRIMARY);
    $fillSoft = medicao_bm_boletim_style_fill_solid(MEDICAO_BM_BG_SOFT);
    $r2       = $r + 1;

    $s->mergeCells("A{$r}:D{$r}");
    $s->setCellValue("A{$r}", "TOTAL CHAMADOS\n" . (string) ((int) ($kp['total_chamados'] ?? 0)));
    $s->mergeCells("E{$r}:H{$r}");
    $s->setCellValue("E{$r}", "RESOLVIDOS\n" . (string) ((int) ($kp['resolvidos'] ?? 0)));
    $s->mergeCells("I{$r}:P{$r}");
    $s->setCellValue("I{$r}", "EM ANDAMENTO\n" . (string) ((int) ($kp['em_andamento'] ?? 0)));

    $s->mergeCells("A{$r2}:F{$r2}");
    $s->setCellValue(
        "A{$r2}",
        "TOTAL UTILIZADO (R$)\nR$ " . number_format((float) ($kp['valor_utilizado'] ?? 0), 2, ',', '.')
    );
    $s->mergeCells("G{$r2}:P{$r2}");
    $s->setCellValue("G{$r2}", "TOTAL ANEXOS\n" . (string) ((int) ($kp['total_anexos'] ?? 0)));

    foreach (["A{$r}:D{$r}", "E{$r}:H{$r}", "I{$r}:P{$r}", "A{$r2}:F{$r2}", "G{$r2}:P{$r2}"] as $range) {
        $s->getStyle($range)->applyFromArray(['fill' => $fillSoft]);
        $s->getStyle($range)->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true)->setIndent(1);
    }
    $s->getStyle("A{$r}:D{$r}")->applyFromArray(['font' => $lblFont]);
    $s->getStyle("E{$r}:H{$r}")->applyFromArray(['font' => $lblFont]);
    $s->getStyle("I{$r}:P{$r}")->applyFromArray(['font' => $lblFont]);
    $s->getStyle("A{$r2}:F{$r2}")->applyFromArray(['font' => $lblFont]);
    $s->getStyle("G{$r2}:P{$r2}")->applyFromArray(['font' => $lblFont]);

    $s->getStyle("A{$r}:P{$r2}")->applyFromArray($st['section_outline_soft']);
    $s->getRowDimension($r)->setRowHeight(MEDICAO_BM_XLSX_ROW_CAPA_GRID);
    $s->getRowDimension($r2)->setRowHeight(MEDICAO_BM_XLSX_ROW_CAPA_GRID);

    return $r2 + 1;
}

/**
 * Corpo BM (zebra, grelha), destaques de contrato, detalhe de chamados e rodapé — não altera valores nem fórmulas.
 *
 * @param array{
 *   mode?:string,
 *   last_letter:string,
 *   body_rows?:list<int>,
 *   contract_valor_cell?:string,
 *   contract_saldo_cell?:string,
 *   contract_valor_merge?:string,
 *   contract_saldo_merge?:string,
 *   empty_bm_msg_row?:int,
 *   footer_row:int,
 *   footer_last_col:string,
 *   gip_header_row?:int,
 *   gip_empty_msg_row?:int,
 *   gip_tot_row?:int,
 *   det?:array{
 *     d_last:string,
 *     title_row:int,
 *     hdr_row:int,
 *     first:?int,
 *     last:?int,
 *     tot_row:int,
 *     empty_msg_row:?int
 *   }
 * } $p
 */
function bm_med_planilha_aplicar_estilos_marca_dados(
    \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
    array $p
): void {
    $st   = medicao_bm_boletim_style_arrays_tipografia();
    $mode = (string) ($p['mode'] ?? 'bm');

    if ($mode === 'gip') {
        $last    = (string) $p['last_letter'];
        $brs     = isset($p['body_rows']) && is_array($p['body_rows']) ? array_values(array_map('intval', $p['body_rows'])) : [];
        $hdr     = (int) ($p['gip_header_row'] ?? 0);
        $fRow    = (int) $p['footer_row'];
        $fLc     = (string) $p['footer_last_col'];

        if ($hdr > 0) {
            $sheet->getStyle('A' . $hdr . ':' . $last . $hdr)->applyFromArray($st['header_table']);
            $sheet->getRowDimension($hdr)->setRowHeight(MEDICAO_BM_XLSX_ROW_TABLE_HDR);
            $sheet->getStyle('A' . $hdr . ':' . $last . $hdr)->getAlignment()->setWrapText(false);
        }

        if (!empty($p['gip_empty_msg_row'])) {
            $er = (int) $p['gip_empty_msg_row'];
            $sheet->getStyle('A' . $er . ':' . $last . $er)->applyFromArray($st['title_sub_flat']);
            $sheet->getStyle('A' . $er . ':' . $last . $er)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        }

        if ($brs !== []) {
            $rIni = min($brs);
            $rFim = max($brs);
            foreach ($brs as $i => $br) {
                $stripe = ($i % 2 === 0) ? MEDICAO_BM_WHITE : MEDICAO_BM_BG_ALT;
                $rowSt   = array_merge($st['body_row'], ['fill' => medicao_bm_boletim_style_fill_solid($stripe)]);
                $sheet->getStyle('A' . $br . ':' . $last . $br)->applyFromArray($rowSt);
            }
            $sheet->getStyle('A' . $rIni . ':' . $last . $rFim)->applyFromArray($st['body_table_border_soft']);
            $txtLeft = [
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                    'vertical'   => Alignment::VERTICAL_CENTER,
                    'wrapText'   => false,
                ],
            ];
            foreach (['A', 'B', 'E', 'F', 'H', 'I', 'J', 'K', 'N', 'Q', 'R', 'S'] as $col) {
                $sheet->getStyle($col . $rIni . ':' . $col . $rFim)->applyFromArray($txtLeft);
            }
            foreach (['G', 'L'] as $col) {
                $sheet->getStyle($col . $rIni . ':' . $col . $rFim)->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
            }
            foreach (['C', 'D'] as $col) {
                $sheet->getStyle($col . $rIni . ':' . $col . $rFim)->applyFromArray($st['numeric']);
            }
            foreach (['M', 'R'] as $col) {
                $sheet->getStyle($col . $rIni . ':' . $col . $rFim)->applyFromArray([
                    'font'      => medicao_bm_boletim_style_font(false, MEDICAO_BM_XLSX_SIZE_BASE, MEDICAO_BM_TEXT_MAIN),
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER,
                        'wrapText'   => false,
                    ],
                ]);
            }
            $sheet->getStyle('O' . $rIni . ':P' . $rFim)->applyFromArray($st['money']);
            foreach ($brs as $br) {
                $sheet->getStyle('C' . $br)->getNumberFormat()->setFormatCode('#,##0.0000000');
                $sheet->getStyle('D' . $br)->getNumberFormat()->setFormatCode('#,##0.0000000');
                $sheet->getStyle('M' . $br)->getNumberFormat()->setFormatCode('#,##0.###');
                $sheet->getStyle('O' . $br)->getNumberFormat()->setFormatCode('"R$" #,##0.00');
                $sheet->getStyle('P' . $br)->getNumberFormat()->setFormatCode('"R$" #,##0.00');
            }
        }

        if (!empty($p['gip_tot_row'])) {
            $tot = (int) $p['gip_tot_row'];
            $sheet->getStyle('A' . $tot . ':' . $last . $tot)->applyFromArray($st['total_band']);
            $sheet->getStyle('M' . $tot)->applyFromArray($st['total_value']);
            $sheet->getStyle('P' . $tot)->applyFromArray($st['total_value']);
            $sheet->getStyle('M' . $tot)->getNumberFormat()->setFormatCode('#,##0.###');
            $sheet->getStyle('P' . $tot)->getNumberFormat()->setFormatCode('"R$" #,##0.00');
            $sheet->getStyle('M' . $tot)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle('P' . $tot)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT)->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle('A' . $tot . ':L' . $tot)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);
        }

        $sheet->getStyle('A' . $fRow . ':' . $fLc . $fRow)->getFont()->setName(MEDICAO_BM_XLSX_FONT)->setSize(8)->setBold(false);
        $sheet->getStyle('A' . $fRow . ':' . $fLc . $fRow)->getFont()->getColor()->setRGB(MEDICAO_BM_TEXT_MUTED);
        $sheet->getStyle('A' . $fRow . ':' . $fLc . $fRow)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);

        return;
    }

    $last = (string) $p['last_letter'];
    $brs  = isset($p['body_rows']) && is_array($p['body_rows']) ? array_values(array_map('intval', $p['body_rows'])) : [];

    if (!empty($p['contract_valor_merge'])) {
        $sheet->getStyle((string) $p['contract_valor_merge'])->applyFromArray($st['highlight_financial_box']);
    } else {
        $sheet->getStyle((string) $p['contract_valor_cell'])->applyFromArray($st['money']);
    }
    if (!empty($p['contract_saldo_merge'])) {
        $sheet->getStyle((string) $p['contract_saldo_merge'])->applyFromArray($st['highlight_financial_box']);
    } else {
        $sheet->getStyle((string) $p['contract_saldo_cell'])->applyFromArray($st['money']);
    }
    $sheet->getStyle((string) $p['contract_valor_cell'])->getNumberFormat()->setFormatCode('"R$" #,##0.00');
    $sheet->getStyle((string) $p['contract_saldo_cell'])->getNumberFormat()->setFormatCode('"R$" #,##0.00');

    if (!empty($p['empty_bm_msg_row'])) {
        $er = (int) $p['empty_bm_msg_row'];
        $sheet->getStyle('A' . $er . ':P' . $er)->applyFromArray($st['title_sub_flat']);
        $sheet->getStyle('A' . $er . ':P' . $er)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
    }

    if ($brs !== []) {
        $rIni = min($brs);
        $rFim = max($brs);
        foreach ($brs as $i => $br) {
            $stripe = ($i % 2 === 0) ? MEDICAO_BM_WHITE : MEDICAO_BM_BG_ALT;
            $rowSt  = array_merge($st['body_row'], ['fill' => medicao_bm_boletim_style_fill_solid($stripe)]);
            $sheet->getStyle('A' . $br . ':' . $last . $br)->applyFromArray($rowSt);
        }
        $sheet->getStyle('A' . $rIni . ':' . $last . $rFim)->applyFromArray($st['body_table_border_soft']);
        $sheet->getStyle('B' . $rIni . ':B' . $rFim)->applyFromArray($st['body_desc']);
        foreach (['D', 'E', 'F', 'G', 'I', 'J', 'K', 'L', 'M'] as $col) {
            $sheet->getStyle($col . $rIni . ':' . $col . $rFim)->applyFromArray($st['numeric']);
        }
        $sheet->getStyle('H' . $rIni . ':H' . $rFim)->applyFromArray($st['money']);
        $sheet->getStyle('N' . $rIni . ':O' . $rFim)->applyFromArray($st['money']);
        $sheet->getStyle('P' . $rIni . ':P' . $rFim)->applyFromArray($st['numeric']);
    }

    $det = isset($p['det']) && is_array($p['det']) ? $p['det'] : null;
    if ($det !== null) {
        $dLast = (string) $det['d_last'];
        $sheet->getStyle('A' . (int) $det['title_row'] . ':' . $dLast . (int) $det['title_row'])->applyFromArray($st['title_sub_flat']);
        $sheet->getStyle('A' . (int) $det['title_row'] . ':' . $dLast . (int) $det['title_row'])->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1)->setWrapText(true);
        $sheet->getRowDimension((int) $det['title_row'])->setRowHeight(MEDICAO_BM_XLSX_ROW_SUB_META);

        if (!empty($det['empty_msg_row'])) {
            $em = (int) $det['empty_msg_row'];
            $sheet->getStyle('A' . $em . ':' . $dLast . $em)->applyFromArray($st['title_sub_flat']);
            $sheet->getStyle('A' . $em . ':' . $dLast . $em)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        }

        $hdr = (int) $det['hdr_row'];
        $sheet->getStyle('A' . $hdr . ':' . $dLast . $hdr)->applyFromArray($st['header_table']);
        $sheet->getRowDimension($hdr)->setRowHeight(MEDICAO_BM_XLSX_ROW_TABLE_HDR);

        $df = isset($det['first']) ? (int) $det['first'] : 0;
        $dl = isset($det['last']) ? (int) $det['last'] : 0;
        if ($df > 0 && $dl >= $df) {
            for ($li = $df; $li <= $dl; ++$li) {
                $stripe = (($li - $df) % 2 === 0) ? MEDICAO_BM_WHITE : MEDICAO_BM_BG_ALT;
                $rowSt  = array_merge($st['body_row'], ['fill' => medicao_bm_boletim_style_fill_solid($stripe)]);
                $sheet->getStyle('A' . $li . ':' . $dLast . $li)->applyFromArray($rowSt);
            }
            $sheet->getStyle('A' . $df . ':' . $dLast . $dl)->applyFromArray($st['body_table_border_soft']);
            $sheet->getStyle('N' . $df . ':N' . $dl)->applyFromArray($st['numeric']);
            $sheet->getStyle('O' . $df . ':P' . $dl)->applyFromArray($st['money']);
            foreach (['K', 'Q'] as $cw) {
                $sheet->getStyle($cw . $df . ':' . $cw . $dl)->applyFromArray($st['body_desc']);
            }
        }

        $tot = (int) $det['tot_row'];
        $sheet->getStyle('A' . $tot . ':' . $dLast . $tot)->applyFromArray($st['total_band']);
        $sheet->getStyle('N' . $tot)->applyFromArray($st['total_value']);
        $sheet->getStyle('P' . $tot)->applyFromArray($st['total_value']);
        $sheet->getStyle('N' . $tot)->getNumberFormat()->setFormatCode('#,##0.###');
        $sheet->getStyle('P' . $tot)->getNumberFormat()->setFormatCode('"R$" #,##0.00');
        $sheet->getStyle('A' . $tot . ':M' . $tot)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);
    }

    $fRow = (int) $p['footer_row'];
    $fLc  = (string) $p['footer_last_col'];
    $sheet->getStyle('A' . $fRow . ':' . $fLc . $fRow)->getFont()->setName(MEDICAO_BM_XLSX_FONT)->setSize(8)->setBold(false);
    $sheet->getStyle('A' . $fRow . ':' . $fLc . $fRow)->getFont()->getColor()->setRGB(MEDICAO_BM_TEXT_MUTED);
    $sheet->getStyle('A' . $fRow . ':' . $fLc . $fRow)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
}

/**
 * Mantém compat: admin chama os 6 parâmetros; somente `$exportCtx` importa.
 *
 * @param array<string,mixed>|null $exportCtx
 */
function chamados_medicao_export_xlsx_send(
    array $__r,
    array $__m,
    array $__k,
    string $__p,
    string $__u,
    ?array $exportCtx = null
): void {
    unset($__r, $__m, $__k, $__p, $__u);
    bm_med_export_main(is_array($exportCtx) ? $exportCtx : []);
}

/**
 * Monta o workbook do boletim institucional (modelo GIP): capa A–S + tabela A–S por lançamentos consolidados.
 *
 * @param array<string,mixed> $ctx
 *
 * @return array{ss: Spreadsheet, sheet: \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet, thRow: int, body_rows: list<int>, gip_style_ctx: array<string,mixed>}
 */
function bm_med_workbook_build(array $ctx): array
{
    if (!defined('APP_BRAND_NAME')) {
        require_once __DIR__ . '/config.php';
    }

    $lastLetter = 'S';
    $st         = medicao_bm_boletim_style_arrays_tipografia();
    $hdrs       = bm_med_headers_gip();

    $periodoTxt = trim((string) ($ctx['periodo_label'] ?? '')) ?: '—';
    $usuario    = trim((string) ($ctx['usuario_nome'] ?? '')) ?: '—';
    $matrizLb   = trim((string) ($ctx['matriz_label'] ?? '')) ?: '—';
    /** @var list<array<string,mixed>> $det */
    $det = isset($ctx['detalhe_itens_linhas']) && is_array($ctx['detalhe_itens_linhas'])
        ? array_values($ctx['detalhe_itens_linhas'])
        : [];
    $refYm = preg_match('/^\d{4}-\d{2}$/', (string) ($ctx['ref_ym'] ?? '')) ? (string) $ctx['ref_ym'] : '';
    $nrBol = $refYm !== '' ? str_replace('-', '', $refYm) : date('Ym');

    $ss = new Spreadsheet();
    $ss->getDefaultStyle()->getFont()->setName(MEDICAO_BM_XLSX_FONT)->setSize(MEDICAO_BM_XLSX_SIZE_BASE);
    $ss->getDefaultStyle()->getFont()->getColor()->setRGB(MEDICAO_BM_TEXT_MAIN);
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle('MEDIÇÃO');
    $sheet->setShowGridlines(false);

    $emitido = date('d/m/Y H:i');
    $brand   = defined('APP_BRAND_NAME') ? (string) APP_BRAND_NAME : 'OnLight';

    $c0 = bm_med_capa_boletim_montar($sheet, $lastLetter, $st, $nrBol, $periodoTxt, $emitido, $brand, $matrizLb, $refYm);

    $thRow = $c0;
    $ci    = 1;
    foreach ($hdrs as $hTxt) {
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($ci) . $thRow, $hTxt);
        ++$ci;
    }

    $rx       = $thRow + 1;
    $bodyRows = [];
    $detGrp   = bm_med_agrupar_detalhamento_chamados_consolidado($det);
    $gipEmptyMsgRow = null;

    if ($detGrp === []) {
        $gipEmptyMsgRow = $rx;
        $sheet->mergeCells('A' . $rx . ':S' . $rx);
        $sheet->setCellValue(
            'A' . $rx,
            'Nenhum lançamento «utilizado» no período (filtro pela data em chamado_itens.criado_em; defina período nos filtros da lista de chamados).'
        );
        $sheet->getStyle('A' . $rx)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        ++$rx;
    } else {
        foreach ($detGrp as $grp) {
            $cid = (int) $grp['chamado_id'];
            foreach ($grp['itens'] as $ln) {
                bm_med_linha_institucional_gip($sheet, $rx, $ln, $cid);
                $bodyRows[] = $rx;
                ++$rx;
            }
        }
    }

    $dataFirst = $bodyRows !== [] ? min($bodyRows) : null;
    $dataLast  = $bodyRows !== [] ? max($bodyRows) : null;

    $tRowTot = $rx;
    $sheet->mergeCells('A' . $tRowTot . ':L' . $tRowTot);
    $sheet->setCellValue('A' . $tRowTot, 'TOTAIS');
    if ($dataFirst !== null && $dataLast !== null && $dataLast >= $dataFirst) {
        $sheet->setCellValue('M' . $tRowTot, '=SUM(M' . $dataFirst . ':M' . $dataLast . ')');
        $sheet->setCellValue('P' . $tRowTot, '=SUM(P' . $dataFirst . ':P' . $dataLast . ')');
    } else {
        $sheet->setCellValue('M' . $tRowTot, 0.0);
        $sheet->setCellValue('P' . $tRowTot, 0.0);
    }
    $sheet->getStyle('M' . $tRowTot)->getNumberFormat()->setFormatCode('#,##0.###');
    $sheet->getStyle('P' . $tRowTot)->getNumberFormat()->setFormatCode('"R$" #,##0.00');
    $sheet->getStyle('A' . $tRowTot . ':L' . $tRowTot)->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);

    $foot = $tRowTot + 1;
    $sheet->mergeCells('A' . $foot . ':S' . $foot);
    $sheet->setCellValue(
        'A' . $foot,
        'Documento gerado pelo sistema ' . $brand . ' · Boletim de medição (modelo GIP) · Gerado por: ' . $usuario
    );
    $sheet->getStyle('A' . $foot)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);

    if ($dataFirst !== null && $dataLast !== null && $dataLast >= $dataFirst) {
        $sheet->setAutoFilter('A' . $thRow . ':S' . $dataLast);
    }

    foreach (
        [
            'A' => 14, 'B' => 10, 'C' => 16, 'D' => 16, 'E' => 20, 'F' => 18, 'G' => 42,
            'H' => 18, 'I' => 22, 'J' => 18, 'K' => 16, 'L' => 48, 'M' => 10, 'N' => 8,
            'O' => 18, 'P' => 18, 'Q' => 24, 'R' => 12, 'S' => 22,
        ] as $Lt => $w
    ) {
        $sheet->getColumnDimension((string) $Lt)->setWidth((float) $w);
    }

    foreach ($bodyRows as $br) {
        $sheet->getStyle('A' . $br . ':S' . $br)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $vG = $sheet->getCell('G' . $br)->getValue();
        $vL = $sheet->getCell('L' . $br)->getValue();
        $lenG = is_string($vG) ? mb_strlen($vG) : 0;
        $lenL = is_string($vL) ? mb_strlen($vL) : 0;
        $len  = max($lenG, $lenL);
        $sheet->getRowDimension($br)->setRowHeight(min(96.0, max(13.5, 13.5 + (int) ceil($len / 52) * 12.0)));
    }

    $gipStyleCtx = [
        'mode'               => 'gip',
        'last_letter'        => $lastLetter,
        'gip_header_row'     => $thRow,
        'body_rows'          => $bodyRows,
        'gip_empty_msg_row'  => $gipEmptyMsgRow,
        'gip_tot_row'        => $tRowTot,
        'footer_row'         => $foot,
        'footer_last_col'    => 'S',
    ];
    bm_med_planilha_aplicar_estilos_marca_dados($sheet, $gipStyleCtx);

    $sheet->freezePane('A' . ($thRow + 1));
    $sheet->getPageSetup()
        ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
        ->setPaperSize(PageSetup::PAPERSIZE_A4)
        ->setFitToWidth(1)
        ->setFitToHeight(0)
        ->setHorizontalCentered(true)
        ->setRowsToRepeatAtTopByStartAndEnd($thRow, $thRow);
    $sheet->getPageMargins()->setLeft(0.25)->setRight(0.25)->setTop(0.3)->setBottom(0.3);
    $sheet->getHeaderFooter()->setOddFooter('&C&P / &N · ' . $brand);

    return [
        'ss'            => $ss,
        'sheet'         => $sheet,
        'thRow'         => $thRow,
        'body_rows'     => $bodyRows,
        'gip_style_ctx' => $gipStyleCtx,
    ];
}

/** @param array<string,mixed> $ctx */
function bm_med_export_main(array $ctx): void
{
    $tplPath = bm_med_tpl1_model_path();
    if (is_file($tplPath) && !bm_med_tpl1_validate($tplPath)) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'O ficheiro modelo assets/templates/boletim_medicao_modelo_1.xlsx existe mas não é válido para o boletim GIP. '
            . 'Substitua-o pelo modelo gerado (php scripts/seed_boletim_medicao_modelo_1.php) ou por um .xlsx com folha «MEDIÇÃO» e cabeçalho da tabela (DATA / ID ou legado PROTOCOLO GIP).';
        exit;
    }

    $built = bm_med_workbook_build($ctx);
    if (is_readable($tplPath) && bm_med_tpl1_validate($tplPath)) {
        bm_med_tpl1_overlay_surface($built['sheet'], $tplPath);
        bm_med_tpl1_apply_sample_body_row_style_from_template($built['sheet'], $tplPath, $built['body_rows'] ?? []);
        if (!empty($built['gip_style_ctx']) && is_array($built['gip_style_ctx'])) {
            bm_med_planilha_aplicar_estilos_marca_dados($built['sheet'], $built['gip_style_ctx']);
        }
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $fnTag = trim((string) ($ctx['arquivo_suffix'] ?? ''));
    $fnMid = ($fnTag !== '') ? ($fnTag . '_') : '';
    header('Content-Disposition: attachment; filename="boletim_medicao_' . $fnMid . date('Y-m-d_His') . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($built['ss']))->save('php://output');
}