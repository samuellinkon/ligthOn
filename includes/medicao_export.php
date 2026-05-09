<?php
declare(strict_types=1);

/**
 * Planilha CSV (UTF-8 BOM, separador ;) — cabeçalho enxuto, totais e tabela de chamados.
 *
 * @param array<string,mixed> $clienteMatriz
 * @param array{n_chamados:int,valor_materiais:float,valor_servicos:float,valor_total:float} $tot
 * @param list<array<string,mixed>> $linhas
 */
function medicao_export_planilha_csv(
    array $clienteMatriz,
    string $contratoDisp,
    string $periodoTxt,
    array $tot,
    array $linhas,
    string $dataDe,
    string $dataAte
): void {
    $empresa = (string) ($clienteMatriz['empresa'] ?? '');
    $fn      = 'medicao_boletim_' . $dataDe . '_' . $dataAte . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    header('Cache-Control: no-store');

    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    if ($out === false) {
        return;
    }

    $w = static function (array $row) use ($out): void {
        fputcsv($out, $row, ';');
    };

    $w(['BOLETIM DE MEDIÇÃO', '', '', '', '', '', '', '', '', '', '']);
    $w(['', '', '', '', '', '', '', '', '', '', '']);

    $w([
        'Cliente / contratante',
        $empresa,
        'Contrato / referência',
        $contratoDisp,
        'Período de medição',
        $periodoTxt,
        '',
        '',
        '',
        '',
        '',
    ]);
    $w(['', '', '', '', '', '', '', '', '', '', '']);

    $w([
        'Valor materiais aplicados',
        'R$ ' . number_format((float) ($tot['valor_materiais'] ?? 0), 2, ',', '.'),
        'Valor serviços (itens)',
        'R$ ' . number_format((float) ($tot['valor_servicos'] ?? 0), 2, ',', '.'),
        'Valor total (período)',
        'R$ ' . number_format((float) ($tot['valor_total'] ?? 0), 2, ',', '.'),
        'Chamados no período',
        (string) (int) ($tot['n_chamados'] ?? 0),
        '',
        '',
        '',
    ]);
    $w(['', '', '', '', '', '', '', '', '', '', '']);

    $w([
        'Data',
        'Chamado',
        'Unidade',
        'Título',
        'Status',
        'Prioridade',
        'Técnico',
        'Serviço (cat.)',
        'Materiais',
        'Serv. itens',
        'Total',
    ]);

    foreach ($linhas as $r) {
        $w([
            (string) ($r['aberto_em_br'] ?? ''),
            isset($r['id']) ? '#' . (string) (int) $r['id'] : '',
            (string) ($r['unidade_nome'] ?? ''),
            (string) ($r['titulo'] ?? ''),
            (string) ($r['status'] ?? ''),
            (string) ($r['prioridade'] ?? ''),
            (string) ($r['tecnico_nome'] ?? ''),
            (string) ($r['servico_principal_nome'] ?? ''),
            number_format((float) ($r['valor_materiais'] ?? 0), 2, ',', '.'),
            number_format((float) ($r['valor_servicos_itens'] ?? 0), 2, ',', '.'),
            number_format((float) ($r['valor_total_linha'] ?? 0), 2, ',', '.'),
        ]);
    }

    $w([
        'TOTAIS',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        number_format((float) ($tot['valor_materiais'] ?? 0), 2, ',', '.'),
        number_format((float) ($tot['valor_servicos'] ?? 0), 2, ',', '.'),
        number_format((float) ($tot['valor_total'] ?? 0), 2, ',', '.'),
    ]);

    fclose($out);
}
