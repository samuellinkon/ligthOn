<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require_once dirname(__DIR__) . '/vendor/autoload.php';

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

/**
 * Detalhe por unidade: quantidade inteira &gt; 1 gera N linhas (qtd 1 / subtotal = VU).
 * Quantidade decimal ou ≤ 1 mantém uma linha com seq. 1/1.
 *
 * @param list<array<string,mixed>> $det
 *
 * @return list<array<string,mixed>>
 */
function bm_med_expandir_detalhe_por_unidade(array $det): array
{
    $out      = [];
    $epsWhole = 1e-6;
    foreach ($det as $linha) {
        $qtd = (float) ($linha['quantidade'] ?? 0);
        $vu  = (float) ($linha['valor_unitario'] ?? 0);
        $n   = (int) round($qtd);
        if ($qtd > 1 && abs($qtd - $n) < $epsWhole) {
            for ($i = 1; $i <= $n; ++$i) {
                $nova              = $linha;
                $nova['quantidade'] = 1.0;
                $nova['subtotal']   = $vu;
                $nova['seq_item']   = $i . '/' . $n;
                $out[]             = $nova;
            }
        } else {
            $out[] = array_merge($linha, ['seq_item' => '1/1']);
        }
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
    return ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'BBBBBB']]]];
}

/** @param array{0:string,1:string} $L @param array{0:string,1:string} $R — layout fixo A–P */
function bm_med_hdr_row(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $s, int $r, array $L, array $R): void
{
    $s->mergeCells("A{$r}:C{$r}");
    $s->setCellValue("A{$r}", $L[0]);
    $s->mergeCells("D{$r}:I{$r}");
    $s->setCellValue("D{$r}", $L[1]);
    $s->mergeCells("J{$r}:L{$r}");
    $s->setCellValue("J{$r}", $R[0]);
    $s->mergeCells("M{$r}:P{$r}");
    $s->setCellValue("M{$r}", $R[1]);
    $s->getStyle("A{$r}:C{$r}")->getAlignment()->setWrapText(true);
    $s->getStyle("J{$r}:L{$r}")->getAlignment()->setWrapText(true);
    $s->getStyle("A{$r}")->getFont()->setBold(true);
    $s->getStyle("J{$r}")->getFont()->setBold(true);
}

function bm_med_faixa(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $s, int $r, string $tit): void
{
    $s->mergeCells('A' . $r . ':P' . $r);
    $s->setCellValue('A' . $r, $tit);
    $s->getStyle('A' . $r . ':P' . $r)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFE066');
    $s->getStyle('A' . $r . ':P' . $r)->getFont()->setBold(true)->setSize(9);
    $s->getStyle('A' . $r . ':P' . $r)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);
    $s->getRowDimension($r)->setRowHeight(15);
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
 * KPIs em grelha técnica (rótulo | valor), sem cartões.
 *
 * @param array<string,int|float|string> $kp
 */
function bm_med_kpis_grid(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $s, int $r, array $kp, array $thin): void
{
    $pairs = [
        ['TOTAL CHAMADOS', (string) ((int) ($kp['total_chamados'] ?? 0))],
        ['RESOLVIDOS', (string) ((int) ($kp['resolvidos'] ?? 0))],
        ['EM ANDAMENTO', (string) ((int) ($kp['em_andamento'] ?? 0))],
        ['TOTAL UTILIZADO (R$)', 'R$ ' . number_format((float) ($kp['valor_utilizado'] ?? 0), 2, ',', '.')],
        ['TOTAL ANEXOS', (string) ((int) ($kp['total_anexos'] ?? 0))],
    ];
    /* Cinco blocos lado a lado em A:P (rótulo 2 colunas · valor 1 ou 2 colunas). */
    foreach ($pairs as $i => $pair) {
        if ($i < 4) {
            $ls = 1 + $i * 3;
            $cL1 = Coordinate::stringFromColumnIndex($ls);
            $cL2 = Coordinate::stringFromColumnIndex($ls + 1);
            $cV  = Coordinate::stringFromColumnIndex($ls + 2);
            $s->mergeCells("{$cL1}{$r}:{$cL2}{$r}");
            $s->setCellValue("{$cL1}{$r}", $pair[0]);
            $s->setCellValue("{$cV}{$r}", $pair[1]);
            $s->getStyle("{$cL1}{$r}:{$cL2}{$r}")->getFont()->setBold(true)->setSize(8);
            $s->getStyle("{$cL1}{$r}:{$cL2}{$r}")->getAlignment()
                ->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);
            $s->getStyle("{$cV}{$r}")->getFont()->setSize(9);
            $s->getStyle("{$cV}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT)->setVertical(Alignment::VERTICAL_CENTER);
        } else {
            $s->mergeCells("M{$r}:N{$r}");
            $s->setCellValue("M{$r}", $pair[0]);
            $s->mergeCells("O{$r}:P{$r}");
            $s->setCellValue("O{$r}", $pair[1]);
            $s->getStyle("M{$r}:N{$r}")->getFont()->setBold(true)->setSize(8);
            $s->getStyle("M{$r}:N{$r}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);
            $s->getStyle("O{$r}:P{$r}")->getFont()->setSize(9);
            $s->getStyle("O{$r}:P{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT)->setVertical(Alignment::VERTICAL_CENTER);
        }
    }
    $s->getStyle("A{$r}:P{$r}")->applyFromArray($thin);
    $s->getRowDimension($r)->setRowHeight(16);
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

/** @param array<string,mixed> $ctx */
function bm_med_export_main(array $ctx): void
{
    if (!defined('APP_BRAND_NAME')) {
        require_once __DIR__ . '/config.php';
    }

    $hdrs       = bm_med_headers();
    $lastLetter = Coordinate::stringFromColumnIndex(count($hdrs)); // P
    $thin       = bm_med_border();

    $periodoTxt = trim((string) ($ctx['periodo_label'] ?? '')) ?: '—';
    $usuario    = trim((string) ($ctx['usuario_nome'] ?? '')) ?: '—';
    $matrizLb   = trim((string) ($ctx['matriz_label'] ?? '')) ?: '—';
    /** @var list<array<string,mixed>> $bmLinhas */
    $bmLinhas = isset($ctx['bm_linhas']) && is_array($ctx['bm_linhas']) ? array_values($ctx['bm_linhas']) : [];
    /** @var list<array<string,mixed>> $det */ $det =
        isset($ctx['detalhe_itens_linhas']) && is_array($ctx['detalhe_itens_linhas']) ? array_values($ctx['detalhe_itens_linhas']) : [];
    $refYm = preg_match('/^\d{4}-\d{2}$/', (string) ($ctx['ref_ym'] ?? '')) ? (string) $ctx['ref_ym'] : '';
    $nrBol = $refYm !== '' ? str_replace('-', '', $refYm) : date('Ym');

    $incluirDetChamados = !empty($ctx['incluir_detalhamento_chamados']);

    $crmMap = bm_med_crm_map($det);

    $kpis = array_merge([
        'total_chamados' => 0, 'resolvidos' => 0, 'em_andamento' => 0,
        'valor_utilizado' => 0.0, 'total_anexos' => 0,
    ], isset($ctx['kpis']) && is_array($ctx['kpis']) ? $ctx['kpis'] : []);

    $ss = new Spreadsheet();
    $ss->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle('MEDIÇÃO');
    $sheet->setShowGridlines(false);

    $emitido = date('d/m/Y H:i');
    $brand   = defined('APP_BRAND_NAME') ? (string) APP_BRAND_NAME : 'LightOn';

    /* (1) Cabeçalho compacto (boletim técnico) */
    $rn = 1;
    $sheet->mergeCells("A{$rn}:C{$rn}");
    $sheet->setCellValue("A{$rn}", $brand . "\nGestão em iluminação pública");
    $sheet->getStyle("A{$rn}:C{$rn}")->getFont()->setSize(7);
    $sheet->getStyle("A{$rn}:C{$rn}")->getFont()->getColor()->setARGB('FF444444');
    $sheet->getStyle("A{$rn}:C{$rn}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->mergeCells("D{$rn}:{$lastLetter}{$rn}");
    $sheet->setCellValue("D{$rn}", "BOLETIM DE MEDIÇÃO  ·  Nº {$nrBol}");
    $sheet->getStyle("D{$rn}")->getFont()->setBold(true)->setSize(12);
    $sheet->getStyle("D{$rn}:{$lastLetter}{$rn}")->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getRowDimension($rn)->setRowHeight(26);
    ++$rn;
    $sheet->mergeCells("A{$rn}:{$lastLetter}{$rn}");
    $sheet->setCellValue("A{$rn}", 'Período: ' . $periodoTxt . '   ·   Emitido em: ' . $emitido);
    $sheet->getStyle("A{$rn}")->getFont()->setSize(9);
    $sheet->getStyle("A{$rn}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getRowDimension($rn)->setRowHeight(14);
    ++$rn;

    /* (2) Dados do contrato / prefeitura */
    $c0 = $rn;
    bm_med_hdr_row($sheet, $c0, ['Contrato', '—'], ['Prefeitura / tomador', $matrizLb]);
    bm_med_hdr_row($sheet, $c0 + 1, ['Empresa', $matrizLb], ['Objeto', 'Manutenção da iluminação pública — medição']);
    bm_med_hdr_row($sheet, $c0 + 2, ['Município / UF', '—'], ['Período (referência)', $periodoTxt]);
    bm_med_hdr_row($sheet, $c0 + 3, ['Valor previsto', '—'], ['Valor acumulado (R$) — tabela principal', '']);
    bm_med_hdr_row($sheet, $c0 + 4, ['Saldo a executar (R$) — tabela principal', ''], ['', '']);
    $contractValorAcumRow = $c0 + 3;
    $contractSaldoRow     = $c0 + 4;
    $hdrBot               = $c0 + 4;
    $sheet->getStyle("A{$c0}:{$lastLetter}{$hdrBot}")->applyFromArray($thin);
    for ($hh = $c0; $hh <= $hdrBot; ++$hh) {
        $sheet->getRowDimension($hh)->setRowHeight(13);
        $sheet->getStyle("A{$hh}:{$lastLetter}{$hh}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    }

    $rn = $hdrBot + 1;
    /* (3) Indicadores — linha técnica (rótulo | valor) */
    bm_med_kpis_grid($sheet, $rn, $kpis, $thin);
    ++$rn;

    /* Cabeçalho técnico da tabela BM (sem linha-título intermediária) */
    $thRow = $rn;
    $ci = 1;
    foreach ($hdrs as $hTxt) {
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($ci) . $thRow, $hTxt);
        ++$ci;
    }
    $sheet->getStyle("A{$thRow}:{$lastLetter}{$thRow}")->applyFromArray($thin);
    $sheet->getStyle("A{$thRow}:{$lastLetter}{$thRow}")
        ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF9F9F9F');
    $sheet->getStyle("A{$thRow}:{$lastLetter}{$thRow}")
        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    $sheet->getRowDimension($thRow)->setRowHeight(38);
    $sheet->getStyle("A{$thRow}:{$lastLetter}{$thRow}")->getFont()->setBold(true)->setSize(9);

    $dataStart = $thRow + 1;
    $rx        = $dataStart;
    $bodyRows  = [];

    $grpTit = [
        '1' => '1 — SERVIÇOS DE MANUTENÇÃO DO SISTEMA DE ILUMINAÇÃO PÚBLICA',
        '2' => '2 — SERVIÇOS DE GESTÃO DO SISTEMA DE ILUMINAÇÃO PÚBLICA',
        '3' => '3 — MATERIAIS / INSUMOS',
    ];

    if ($bmLinhas !== []) {
        usort(
            $bmLinhas,
            static fn (array $a, array $b): int => ((int) ($a['ordem'] ?? 0)) <=> ((int) ($b['ordem'] ?? 0))
                ?: strcmp((string) ($a['item_codigo'] ?? ''), (string) ($b['item_codigo'] ?? ''))
        );
        $lastGpBanner = '__';
        foreach ($bmLinhas as $bm) {
            $codOk = trim((string) ($bm['item_codigo'] ?? ''));
            $gp    = bm_med_grp_bm($codOk);
            if ($gp !== '__') {
                if ($gp !== $lastGpBanner) {
                    bm_med_faixa($sheet, $rx, $grpTit[$gp] ?? ('GRUPO ' . $gp . ' — SERVIÇOS / ITENS'));
                    ++$rx;
                    $lastGpBanner = $gp;
                }
            } else {
                $lastGpBanner = '__';
            }
            $extras = isset($crmMap[$codOk]) ? $crmMap[$codOk] : null;
            bm_med_linha_bm($sheet, $rx, $bm, $extras !== null ? ['qty' => $extras['qty'], 'subtotal' => $extras['subtotal']] : null);
            if ($extras !== null) {
                unset($crmMap[$codOk]);
            }
            $temGmanual = isset($bm['qtd_total']) && $bm['qtd_total'] !== null && $bm['qtd_total'] !== '' && (float) $bm['qtd_total'] > 0;

            bm_med_formula_corpo($sheet, $rx);
            if ($temGmanual) {
                $sheet->setCellValue('G' . $rx, (float) $bm['qtd_total']);
            }

            $bodyRows[] = $rx;
            ++$rx;
        }
    }

    /* Itens apenas no CRM (mesmo período por data de lançamento) */
    if ($crmMap !== []) {
        $titTipo = [
            'material' => 'MEDIÇÃO — ITENS ONLY CRM (AGRUPAMENTO MATERIAL)',
            'servico'  => 'MEDIÇÃO — ITENS ONLY CRM (AGRUPAMENTO SERVIÇO)',
            ''         => 'MEDIÇÃO — ITENS ONLY CRM (SEM TIPO)',
        ];
        $agg      = [];
        foreach ($crmMap as $cod => $inf) {
            $agg[] = [
                'item_codigo' => $cod,
                'descricao'   => $inf['descricao'] !== '' ? $inf['descricao'] : $cod,
                'unidade'     => $inf['unidade'],
                'tipo'        => $inf['tipo'],
                'qtd_med'     => $inf['qty'],
                'subtotal'    => $inf['subtotal'],
            ];
        }
        usort($agg, static function (array $a, array $b): int {
            return strcmp((string) $a['tipo'], (string) $b['tipo']) ?: strcmp($a['item_codigo'], $b['item_codigo']);
        });
        $titAnt = '__';
        foreach ($agg as $rowAgg) {
            $tt = ($rowAgg['tipo'] !== '') ? $titTipo[$rowAgg['tipo']] ?? $titTipo[''] : $titTipo[''];
            if ($tt !== $titAnt) {
                bm_med_faixa($sheet, $rx, $tt);
                ++$rx;
                $titAnt = $tt;
            }
            bm_med_linha_crm_agg($sheet, $rx, $rowAgg);
            bm_med_formula_corpo($sheet, $rx);
            $bodyRows[] = $rx;
            ++$rx;
        }
    }

    if ($bodyRows === []) {
        $sheet->mergeCells("A{$rx}:P{$rx}");
        $sheet->setCellValue(
            "A{$rx}",
            'Sem linhas na tabela principal: importe o boletim BM do mês ou confira lançamentos de catálogo com data do lançamento no período.'
        );
        $sheet->getStyle("A{$rx}")->getAlignment()->setWrapText(true);
        ++$rx;
        $sheet->setCellValue('M' . $contractValorAcumRow, '—');
        $sheet->setCellValue('D' . $contractSaldoRow, '—');
    } else {
        $rIni = min($bodyRows);
        $rFim = max($bodyRows);
        $sheet->setCellValue('M' . $contractValorAcumRow, '=SUBTOTAL(9,N' . $rIni . ':N' . $rFim . ')');
        $sheet->getStyle('M' . $contractValorAcumRow)->getNumberFormat()->setFormatCode('"R$" #,##0.00');
        $sheet->setCellValue('D' . $contractSaldoRow, '=SUBTOTAL(9,O' . $rIni . ':O' . $rFim . ')');
        $sheet->getStyle('D' . $contractSaldoRow)->getNumberFormat()->setFormatCode('"R$" #,##0.00');

        foreach ($bodyRows as $br) {
            foreach (["D{$br}", "E{$br}", "F{$br}", "I{$br}", "J{$br}", 'M' . $br] as $c) {
                $sheet->getStyle($c)->getNumberFormat()->setFormatCode('#,##0.00');
                $sheet->getStyle($c)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
            $sheet->getStyle("H{$br}")->getNumberFormat()->setFormatCode('"R$" #,##0.00');
            $sheet->getStyle("N{$br}:O{$br}")->getNumberFormat()->setFormatCode('"R$" #,##0.00');
            $sheet->getStyle("P{$br}")->getNumberFormat()->setFormatCode('0.00%');
            $sheet->getStyle('B' . $br)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            $sheet->getStyle("A{$br}:P{$br}")->applyFromArray($thin)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $vB = $sheet->getCell('B' . $br)->getValue();
            $len = is_string($vB) ? mb_strlen($vB) : 0;
            $sheet->getRowDimension($br)->setRowHeight(min(96.0, max(13.5, 13.5 + (int) ceil($len / 85) * 12.0)));
        }
    }

    foreach (
        [
            'A' => 11, 'B' => 54, 'C' => 8, 'D' => 14, 'E' => 12, 'F' => 12, 'G' => 14, 'H' => 14,
            /* A–P: tabela principal BM (larguras partilham a folha com o detalhe abaixo) */
            'I' => 12, 'J' => 14, 'K' => 18, 'L' => 13, 'M' => 16, 'N' => 16, 'O' => 16, 'P' => 12,
            /* Q–R só usadas no detalhamento analítico */
            'Q' => 14, 'R' => 26,
        ] as $Lt => $w
    ) {
        $sheet->getColumnDimension((string) $Lt)->setWidth((float) $w);
    }

    $footerSpanLast = $lastLetter;
    if ($incluirDetChamados) {
        ++$rx;
        $dCol0 = 1;
        $dHd   = [
            'Chamado', 'Cód. chamado', 'Data chamado', 'Data lançamento', 'Status', 'Prioridade', 'Técnico',
            'Poste', 'Endereço', 'Cód. item', 'Item', 'Seq. item', 'Tipo', 'Movimento',
            'Quantidade', 'Valor unitário (R$)', 'Subtotal (R$)', 'Observação',
        ];
        $nDetCols       = count($dHd);
        $detLastIx      = $dCol0 + $nDetCols - 1;
        $dLast          = Coordinate::stringFromColumnIndex($detLastIx);
        $footerSpanLast = $dLast;

        /* (5) Detalhamento analítico: uma linha por chamado_itens (movimento utilizado apenas na query). */
        $sheet->mergeCells("A{$rx}:{$dLast}{$rx}");
        $sheet->setCellValue(
            "A{$rx}",
            'DETALHAMENTO DOS CHAMADOS — por unidade; nas continuações do mesmo lançamento (chamado_item_id), chamado/endereço/item ficam em branco; ver Seq., Qtd e valores.'
        );
        $sheet->getStyle("A{$rx}")->getFont()->setBold(true)->setSize(9);
        $sheet->getStyle("A{$rx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setIndent(1);
        $sheet->getRowDimension($rx)->setRowHeight(13);
        ++$rx;

        $detHdrRow = $rx;
        foreach ($dHd as $i => $h) {
            $cl = Coordinate::stringFromColumnIndex($dCol0 + $i);
            $sheet->setCellValue($cl . $detHdrRow, $h);
        }
        $sheet->getStyle("A{$detHdrRow}:{$dLast}{$detHdrRow}")->applyFromArray($thin);
        $sheet->getStyle("A{$detHdrRow}:{$dLast}{$detHdrRow}")
            ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF9F9F9F');
        $sheet->getStyle("A{$detHdrRow}:{$dLast}{$detHdrRow}")->getFont()->setBold(true)->setSize(9);
        $sheet->getStyle("A{$detHdrRow}:{$dLast}{$detHdrRow}")
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getRowDimension($detHdrRow)->setRowHeight(38);

        $detPlan       = bm_med_expandir_detalhe_por_unidade($det);
        $li            = $detHdrRow + 1;
        if ($detPlan === []) {
            $sheet->mergeCells('A' . $li . ':' . $dLast . $li);
            $sheet->setCellValue('A' . $li, 'Nenhum lançamento «utilizado» no período (filtro pela data em chamado_itens.criado_em, ou abertura do chamado se o registo não tiver data).');
            $sheet->getStyle('A' . $li)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            ++$li;
            $detDataFirst = null;
            $detDataLast  = null;
        } else {
            $detDataFirst         = $li;
            $prevChamadoItemId   = -1;
            $colsDetalheRepetir  = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'M', 'N', 'R'];
            foreach ($detPlan as $ln) {
                $lanctoId = (int) ($ln['chamado_item_id'] ?? 0);
                /* Sem id de lançamento: mostrar tudo (compat). Com id: só a 1.ª linha da expansão preenche metadados. */
                $mostrarBlocoFixo = $lanctoId <= 0 || $lanctoId !== $prevChamadoItemId;
                $prevChamadoItemId = $lanctoId > 0 ? $lanctoId : -1;

                if ($mostrarBlocoFixo) {
                    $cid = (int) ($ln['chamado_id'] ?? 0);
                    $sheet->setCellValue('A' . $li, '#' . $cid);
                    $sheet->setCellValue('B' . $li, $cid > 0 ? (string) $cid : '');
                    $sheet->setCellValue('C' . $li, bm_med_dt_br(trim((string) ($ln['chamado_aberto_em'] ?? ''))));
                    $cre = trim((string) ($ln['item_criado_em'] ?? ''));
                    $sheet->setCellValue('D' . $li, $cre !== '' ? bm_med_dt_br($cre) : '—');
                    $sheet->setCellValue('E' . $li, (string) ($ln['chamado_status'] ?? ''));
                    $sheet->setCellValue('F' . $li, trim((string) ($ln['chamado_prioridade'] ?? '')) !== '' ? (string) $ln['chamado_prioridade'] : '—');
                    $sheet->setCellValue('G' . $li, trim((string) ($ln['tecnico_nomes'] ?? '')) !== '' ? (string) $ln['tecnico_nomes'] : '—');
                    $sheet->setCellValue('H' . $li, trim((string) ($ln['ponto_codigo_poste'] ?? '')) !== '' ? (string) $ln['ponto_codigo_poste'] : '—');
                    $sheet->setCellValue('I' . $li, trim((string) ($ln['endereco_completo'] ?? '')));
                    $sheet->setCellValue('J' . $li, trim((string) ($ln['item_codigo'] ?? '')));
                    $sheet->setCellValue('K' . $li, trim((string) ($ln['item_nome'] ?? '')));
                    $sheet->setCellValue('M' . $li, bm_med_tipo_item_pt((string) ($ln['item_tipo'] ?? '')));
                    $sheet->setCellValue('N' . $li, bm_med_movimento_pt((string) ($ln['movimento'] ?? '')));
                    $sheet->setCellValue('R' . $li, str_replace(["\r", "\n"], ' ', trim((string) ($ln['observacao'] ?? ''))));
                } else {
                    foreach ($colsDetalheRepetir as $cw) {
                        $sheet->setCellValue($cw . $li, '');
                    }
                }

                $sheet->setCellValue('L' . $li, trim((string) ($ln['seq_item'] ?? '1/1')));
                $sheet->setCellValue('O' . $li, (float) ($ln['quantidade'] ?? 0));
                $sheet->setCellValue('P' . $li, (float) ($ln['valor_unitario'] ?? 0));
                $sheet->setCellValue('Q' . $li, (float) ($ln['subtotal'] ?? 0));

                $sheet->getStyle('O' . $li)->getNumberFormat()->setFormatCode('#,##0.###');
                $sheet->getStyle('P' . $li)->getNumberFormat()->setFormatCode('"R$" #,##0.00');
                $sheet->getStyle('Q' . $li)->getNumberFormat()->setFormatCode('"R$" #,##0.00');
                if ($mostrarBlocoFixo) {
                    foreach (['I', 'K', 'R'] as $cw) {
                        $sheet->getStyle($cw . $li)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
                    }
                }
                $sheet->getStyle('A' . $li . ':' . $dLast . $li)->applyFromArray($thin)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
                ++$li;
            }
            $detDataLast = $li - 1;
        }

        if ($detPlan !== [] && $detDataFirst !== null && $detDataLast !== null && $detDataLast >= $detDataFirst) {
            $sheet->setAutoFilter('A' . $detHdrRow . ':' . $dLast . $detDataLast);
        }

        /* Linha de totais (somente quantidade + subtotal; linhas continuam analíticas). */
        $tRowTot = $li;
        $sheet->mergeCells("A{$tRowTot}:N{$tRowTot}");
        $sheet->setCellValue("A{$tRowTot}", 'TOTAIS');
        $sheet->getStyle("A{$tRowTot}:{$dLast}{$tRowTot}")->applyFromArray($thin);
        $sheet->getStyle("A{$tRowTot}:{$dLast}{$tRowTot}")->getFont()->setBold(true);
        if ($detDataFirst !== null && $detDataLast !== null && $detDataLast >= $detDataFirst) {
            $sheet->setCellValue("O{$tRowTot}", '=SUM(O' . $detDataFirst . ':O' . $detDataLast . ')');
            $sheet->setCellValue("Q{$tRowTot}", '=SUM(Q' . $detDataFirst . ':Q' . $detDataLast . ')');
        } else {
            $sheet->setCellValue('O' . $tRowTot, 0.0);
            $sheet->setCellValue('Q' . $tRowTot, 0.0);
        }
        $sheet->getStyle('O' . $tRowTot)->getNumberFormat()->setFormatCode('#,##0.###');
        $sheet->getStyle('Q' . $tRowTot)->getNumberFormat()->setFormatCode('"R$" #,##0.00');
        $sheet->getStyle("A{$tRowTot}:N{$tRowTot}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);

        $foot = $tRowTot + 1;
    } else {
        $foot = $rx;
    }
    $sheet->mergeCells("A{$foot}:{$footerSpanLast}{$foot}");
    $footTxt = 'Documento gerado pelo sistema ' . $brand . ' · Boletim operacional consolidado · Gerado por: ' . $usuario;
    if (!$incluirDetChamados) {
        $footTxt .= ' · Planilha sem detalhamento linha a linha por chamado.';
    }
    $sheet->setCellValue("A{$foot}", $footTxt);
    $sheet->getStyle("A{$foot}")->getFont()->setSize(8);
    $sheet->getStyle("A{$foot}")->getFont()->getColor()->setARGB('FF555555');
    $sheet->getStyle("A{$foot}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);

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

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $fnTag = trim((string) ($ctx['arquivo_suffix'] ?? ''));
    $fnMid = ($fnTag !== '') ? ($fnTag . '_') : '';
    header('Content-Disposition: attachment; filename="boletim_medicao_' . $fnMid . date('Y-m-d_His') . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($ss))->save('php://output');
}