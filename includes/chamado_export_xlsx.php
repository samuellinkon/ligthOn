<?php
declare(strict_types=1);

/**
 * Exportação XLSX formatada do chamado (várias abas) — PhpSpreadsheet.
 *
 * Chamado individual: aba «Resumo» em layout vertical Campo|Valor (colunas A:B).
 * Lista de chamados (futuro): usar relatório tabular horizontal — não reutilizar esta aba.
 */

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/lighton_export_xlsx_brand.php';

/** Fundos de bloco / cards (aba Resumo chamado individual) */
const LO_CARD_IDENT = 'FFF7F6FD';
const LO_CARD_RESP  = 'FFF9F9FC';
const LO_CARD_LOC   = 'FFF5F8FE';
const LO_CARD_DESC  = 'FFF1F2F8';
const LO_CARD_INDIC = 'FFF0EDFA';

const CH_XLSX_INTERNA       = 'FFFFF9E6';
const CH_XLSX_FOOTER_BAND = 'FFF5F4FC';
const CH_XLSX_WARN_TXT    = LO_TEXT;

function lo_xlsx_rich_money_card(string $titulo, float $valor): RichText
{
    $rt = new RichText();
    $r1 = $rt->createTextRun($titulo . "\n");
    $r1->getFont()->setSize(9)->getColor()->setARGB(LO_MUTED);
    $txt = 'R$ ' . number_format($valor, 2, ',', '.');
    $r2 = $rt->createTextRun($txt);
    $r2->getFont()->setBold(true)->setSize(15)->getColor()->setARGB(LO_PURPLE);

    return $rt;
}

function lo_xlsx_rich_int_card(string $titulo, int $n): RichText
{
    $rt = new RichText();
    $r1 = $rt->createTextRun($titulo . "\n");
    $r1->getFont()->setSize(9)->getColor()->setARGB(LO_MUTED);
    $r2 = $rt->createTextRun((string) $n);
    $r2->getFont()->setBold(true)->setSize(15)->getColor()->setARGB(LO_TEXT);

    return $rt;
}

/**
 * @param array<string,mixed> $ch
 * @param list<array<string,mixed>> $respostas
 * @param list<array<string,mixed>> $materiais
 * @param list<array<string,mixed>> $anexos
 * @param list<array<string,mixed>> $pontoFotos
 * @param array<string,mixed>|null $exportUser utilizador que exporta (nome)
 */
function chamado_export_xlsx_send(
    array $ch,
    array $respostas,
    array $materiais,
    array $anexos,
    array $pontoFotos,
    int $chamadoId,
    ?array $exportUser,
    float $totalUtilizado
): void {
    $spreadsheet = new Spreadsheet();
    $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
    $spreadsheet->getProperties()
        ->setCreator('LightOn')
        ->setTitle('Chamado #' . $chamadoId)
        ->setSubject('Relatório operacional')
        ->setDescription('Exportação gerencial do chamado');

    $thinBorder = [
        'borders' => [
            'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => CH_XLSX_BORDER]],
        ],
    ];

    $fmtBytes = static function (int $bytes): string {
        if ($bytes < 1024) {
            return (string) $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB'];
        $v     = $bytes / 1024;
        $i     = 0;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }

        return number_format($v, $v >= 10 ? 1 : 2, ',', '.') . ' ' . $units[$i];
    };

    /** Largura automática com teto (caracteres ~ Excel width) */
    $autoWidthCap = static function (\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws, string $col, float $max = 55.0): void {
        $ws->getColumnDimension($col)->setAutoSize(true);
        $dim = $ws->getColumnDimension($col);
        $w   = $dim->getWidth();
        if ($w > $max) {
            $dim->setAutoSize(false)->setWidth($max);
        }
    };

    $adminDownloadUrl = static function (int $anexoId): string {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $host  = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $sn    = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $base  = ($https ? 'https://' : 'http://') . $host . dirname($sn);

        return $base . '/chamado_download.php?id=' . $anexoId;
    };

    if (!defined('APP_BRAND_NAME')) {
        require_once __DIR__ . '/config.php';
    }
    $brandName = defined('APP_BRAND_NAME') ? (string) APP_BRAND_NAME : 'LightOn';
    $tagline   = defined('APP_BRAND_TAGLINE') ? trim((string) APP_BRAND_TAGLINE) : 'Gestão em Iluminação Pública';
    if ($tagline === '') {
        $tagline = 'Gestão em Iluminação Pública';
    }

    $emitidoStr = date('d/m/Y H:i:s');

    $prefeitura = trim((string) ($ch['cliente'] ?? ''));
    $statusTxt  = trim((string) ($ch['status'] ?? ''));
    $prioTxt    = trim((string) ($ch['prioridade'] ?? ''));
    $abertoTxt  = '';
    if (!empty($ch['data'])) {
        $ts = strtotime((string) $ch['data']);
        $abertoTxt = $ts ? date('d/m/Y H:i', $ts) : (string) $ch['data'];
    }
    $posteTxt = '';
    $pid = (int) ($ch['ponto_iluminacao_id'] ?? 0);
    if ($pid > 0) {
        $cod = trim((string) ($ch['ponto_codigo_poste'] ?? ''));
        $posteTxt = $cod !== '' ? $cod : ('#' . $pid);
    }
    $tecStr = '';
    if (!empty($ch['tecnico_nomes']) && is_array($ch['tecnico_nomes'])) {
        $tecStr = implode(', ', array_map(static fn ($n) => trim((string) $n), $ch['tecnico_nomes']));
    }
    if ($tecStr === '' && trim((string) ($ch['tecnico_nome'] ?? '')) !== '') {
        $tecStr = trim((string) ($ch['tecnico_nome'] ?? ''));
    }
    if ($tecStr === '') {
        $tecStr = '—';
    }

    $tipoTxt = trim((string) ($ch['problema_os'] ?? ''));
    if ($tipoTxt === '') {
        $tipoTxt = trim((string) ($ch['tipo_os'] ?? ''));
    }
    $categoriaTxt = trim((string) ($ch['servico_tipo'] ?? ''));
    $canalTxt       = trim((string) ($ch['origem_os'] ?? ''));

    $responsavelTxt = trim((string) ($ch['responsavel'] ?? ''));
    if ($responsavelTxt === '') {
        $responsavelTxt = '—';
    }

    $lat = $ch['latitude'] ?? null;
    $lng = $ch['longitude'] ?? null;
    $coords = '';
    if ($lat !== null && $lat !== '' && $lng !== null && $lng !== '') {
        $coords = (string) $lat . ', ' . (string) $lng;
    }

    $endereco = trim(str_replace(["\r\n", "\r"], "\n", (string) ($ch['endereco_completo'] ?? '')));
    $descricao = trim(str_replace(["\r\n", "\r"], "\n", (string) ($ch['descricao'] ?? '')));

    $matsUtil = [];
    $matsDev  = [];
    foreach ($materiais as $m) {
        if (strtolower(trim((string) ($m['movimento'] ?? ''))) === 'devolvido') {
            $matsDev[] = $m;
        } else {
            $matsUtil[] = $m;
        }
    }

    $eu = $exportUser ?? [];
    $exportNome = trim((string) ($eu['nome'] ?? $eu['email'] ?? ''));
    if ($exportNome === '') {
        $exportNome = '—';
    }

    /* ---------- Aba 1: Resumo (identidade LightOn — vertical A:B) ---------- */
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Resumo');
    $sheet->setShowGridlines(false);

    foreach (range('C', 'Z') as $colHide) {
        $sheet->getColumnDimension($colHide)->setVisible(false);
    }

    $sheet->getColumnDimension('A')->setWidth(28);
    $sheet->getColumnDimension('B')->setWidth(86);

    $pairBorderResumo = [
        'borders' => [
            'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => LO_BORDER]],
        ],
    ];
    $cardOutlineSoft = [
        'borders' => [
            'outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FFE8E6F7']],
        ],
    ];

    $root = dirname(__DIR__);
    $logoPath = null;
    if (defined('APP_BRAND_LOGO') && is_string(APP_BRAND_LOGO) && APP_BRAND_LOGO !== '') {
        $cand = $root . '/' . ltrim((string) APP_BRAND_LOGO, '/');
        if (is_file($cand) && preg_match('/\.(png|jpg|jpeg)$/i', $cand)) {
            $logoPath = $cand;
        }
    }
    if ($logoPath === null) {
        foreach (['assets/img/lighton-logo-sidebar.png', 'assets/img/lighton-icon.png', 'assets/img/logo.png'] as $rel) {
            $cand = $root . '/' . $rel;
            if (is_file($cand)) {
                $logoPath = $cand;
                break;
            }
        }
    }

    $hdrRows = [1 => 24.0, 2 => 20.0, 3 => 12.0, 4 => 32.0, 5 => 48.0, 6 => 40.0];
    foreach ($hdrRows as $rn => $h) {
        $sheet->getRowDimension((int) $rn)->setRowHeight($h);
    }

    $sheet->mergeCells('A1:A6');

    $brandLeft = $brandName . "\n" . $tagline;
    if ($logoPath !== null) {
        try {
            $drawing = new Drawing();
            $drawing->setName('Logo');
            $drawing->setPath($logoPath);
            $drawing->setHeight(104);
            $drawing->setCoordinates('A1');
            $drawing->setOffsetX(18);
            $drawing->setOffsetY(14);
            $drawing->setWorksheet($sheet);
        } catch (Throwable $e) {
            $sheet->setCellValue('A1', $brandLeft);
        }
    } else {
        $sheet->setCellValue('A1', $brandLeft);
    }

    $sheet->setCellValue('B1', $brandName);
    $sheet->getStyle('B1')->getFont()->setBold(true)->setSize(15)->getColor()->setARGB(LO_WHITE);
    $sheet->getStyle('B1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_BOTTOM);

    $sheet->setCellValue('B2', $tagline);
    $sheet->getStyle('B2')->getFont()->setSize(10)->getColor()->setARGB('FFE8E6FF');
    $sheet->getStyle('B2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);

    $sheet->setCellValue('B4', 'RELATÓRIO DE CHAMADO');
    $sheet->getStyle('B4')->getFont()->setBold(true)->setSize(22)->getColor()->setARGB(LO_WHITE);
    $sheet->getStyle('B4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER);

    $sheet->setCellValue('B5', '#' . $chamadoId);
    $sheet->getStyle('B5')->getFont()->setBold(true)->setSize(38)->getColor()->setARGB(LO_WHITE);
    $sheet->getStyle('B5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER);

    $sheet->setCellValue('B6', 'Emitido em:' . "\n" . $emitidoStr);
    $sheet->getStyle('B6')->getFont()->setSize(10)->getColor()->setARGB('FFE8E6FF');
    $sheet->getStyle('B6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);

    $hdrRange = 'A1:B6';
    $sheet->getStyle($hdrRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(LO_PURPLE);
    $sheet->getStyle($hdrRange)->applyFromArray([
        'borders' => [
            'outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => LO_PURPLE_DARK]],
        ],
    ]);
    if ($logoPath === null) {
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13)->getColor()->setARGB(LO_WHITE);
        $sheet->getStyle('A1')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true)->setIndent(1);
    }

    $sheet->getRowDimension(7)->setRowHeight(12);

    $r = 8;
    $sheet->mergeCells('A' . $r . ':B' . $r);
    $introCtx = ($prefeitura !== '' ? $prefeitura . "\n\n" : '')
        . "Documento operacional de atendimento\nGerado em: {$emitidoStr}";
    $sheet->setCellValue('A' . $r, $introCtx);
    $sheet->getStyle('A' . $r)->getFont()->setSize(11)->getColor()->setARGB(LO_MUTED);
    $sheet->getStyle('A' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    $sheet->getStyle('A' . $r)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(LO_BG_PAGE);
    $sheet->getStyle('A' . $r)->applyFromArray([
        'borders' => [
            'outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => LO_BORDER]],
        ],
    ]);
    $sheet->getRowDimension($r)->setRowHeight(56);
    $r++;

    $sheet->getRowDimension($r)->setRowHeight(14);
    $r++;

    $freezeStart = $r;

    $pairIx = 0;

    $writePairPremium = static function (
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws,
        int &$row,
        string $label,
        string $value,
        array $borderStyle,
        int &$ix,
        ?float $minHeight = null
    ): void {
        $disp = $value === '' ? '—' : $value;
        $ws->setCellValue('A' . $row, $label);
        $ws->setCellValue('B' . $row, $disp);
        $ws->getStyle('A' . $row . ':B' . $row)->applyFromArray($borderStyle);
        $ws->getStyle('A' . $row)->getFont()->setSize(10)->setItalic(false)->getColor()->setARGB(LO_MUTED);
        $ws->getStyle('B' . $row)->getFont()->setSize(13)->setBold(false)->getColor()->setARGB(LO_TEXT);
        $ws->getStyle('B' . $row)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);
        if (($ix % 2) === 0) {
            $ws->getStyle('A' . $row . ':B' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(CH_XLSX_ROW_ALT);
        }
        $chars = 72;
        $len = mb_strlen((string) $disp);
        $estLines = max(1, (int) ceil($len / $chars));
        $h = 15.0 * $estLines + 18.0;
        if ($minHeight !== null) {
            $h = max($h, $minHeight);
        }
        $ws->getRowDimension($row)->setRowHeight(min(220.0, max(28.0, $h)));
        $ix++;
        $row++;
    };

    $writeBadgeRow = static function (
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws,
        int &$row,
        string $label,
        string $displayText,
        string $bgArgb,
        string $fgArgb,
        array $borderStyle,
        int &$ix
    ): void {
        $txt = $displayText === '' ? '—' : $displayText;
        $ws->setCellValue('A' . $row, $label);
        $ws->setCellValue('B' . $row, $txt);
        $ws->getStyle('A' . $row)->getFont()->setSize(10)->getColor()->setARGB(LO_MUTED);
        $ws->getStyle('B' . $row)->getFont()->setBold(true)->setSize(12)->getColor()->setARGB($fgArgb);
        $ws->getStyle('B' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($bgArgb);
        $ws->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1)->setWrapText(false);
        $ws->getStyle('A' . $row . ':B' . $row)->applyFromArray($borderStyle);
        if (($ix % 2) === 0) {
            $ws->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(CH_XLSX_ROW_ALT);
        }
        $ws->getRowDimension($row)->setRowHeight(34);
        $ix++;
        $row++;
    };

    $writeBlockTitle = static function (
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws,
        int &$row,
        string $title,
        string $bgArgb
    ): void {
        $ws->mergeCells('A' . $row . ':B' . $row);
        $ws->setCellValue('A' . $row, $title);
        $ws->getStyle('A' . $row)->getFont()->setBold(true)->setSize(10)->getColor()->setARGB(LO_PURPLE);
        $ws->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($bgArgb);
        $ws->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);
        $ws->getStyle('A' . $row)->applyFromArray([
            'borders' => [
                'left'   => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => LO_PURPLE]],
                'top'    => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => LO_BORDER]],
                'bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => LO_BORDER]],
                'right'  => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => LO_BORDER]],
            ],
        ]);
        $ws->getRowDimension($row)->setRowHeight(28);
        $row++;
    };

    $writeBlockTitle($sheet, $r, 'Identificação', LO_CARD_IDENT);
    $writePairPremium($sheet, $r, 'Chamado', '#' . $chamadoId, $pairBorderResumo, $pairIx);
    $writePairPremium($sheet, $r, 'Tipo', $tipoTxt, $pairBorderResumo, $pairIx);
    $writePairPremium($sheet, $r, 'Categoria', $categoriaTxt, $pairBorderResumo, $pairIx);
    $writePairPremium($sheet, $r, 'Canal', $canalTxt, $pairBorderResumo, $pairIx);
    [$stBg, $stFg] = lo_xlsx_status_badge_colors($statusTxt);
    $writeBadgeRow($sheet, $r, 'Status', $statusTxt, $stBg, $stFg, $pairBorderResumo, $pairIx);
    [$prBg, $prFg] = lo_xlsx_priority_badge_colors($prioTxt);
    $writeBadgeRow($sheet, $r, 'Prioridade', $prioTxt, $prBg, $prFg, $pairBorderResumo, $pairIx);
    $writePairPremium($sheet, $r, 'Aberto em', $abertoTxt, $pairBorderResumo, $pairIx);

    $sheet->getRowDimension($r)->setRowHeight(12);
    $r++;

    $writeBlockTitle($sheet, $r, 'Responsáveis', LO_CARD_RESP);
    $writePairPremium($sheet, $r, 'Prefeitura / órgão', $prefeitura, $pairBorderResumo, $pairIx);
    $writePairPremium($sheet, $r, 'Responsável', $responsavelTxt, $pairBorderResumo, $pairIx);
    $writePairPremium($sheet, $r, 'Técnicos', $tecStr, $pairBorderResumo, $pairIx);

    $sheet->getRowDimension($r)->setRowHeight(12);
    $r++;

    $writeBlockTitle($sheet, $r, 'Localização', LO_CARD_LOC);
    $writePairPremium($sheet, $r, 'Poste', $posteTxt, $pairBorderResumo, $pairIx);
    $writePairPremium($sheet, $r, 'Endereço', $endereco, $pairBorderResumo, $pairIx);
    $writePairPremium($sheet, $r, 'Coordenadas', $coords, $pairBorderResumo, $pairIx);

    $sheet->getRowDimension($r)->setRowHeight(12);
    $r++;

    $writeBlockTitle($sheet, $r, 'Descrição', LO_CARD_DESC);
    $sheet->mergeCells('A' . $r . ':B' . $r);
    $sheet->setCellValue('A' . $r, $descricao !== '' ? $descricao : '—');
    $sheet->getStyle('A' . $r)->getFont()->setSize(12)->getColor()->setARGB(LO_TEXT);
    $sheet->getStyle('A' . $r)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP)->setIndent(1);
    $sheet->getStyle('A' . $r)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(LO_CARD_DESC);
    $sheet->getStyle('A' . $r)->applyFromArray([
        'borders' => [
            'outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => LO_BORDER]],
        ],
    ]);
    $dLen = mb_strlen((string) ($descricao !== '' ? $descricao : '—'));
    $sheet->getRowDimension($r)->setRowHeight(min(180.0, max(64.0, 14.0 * max(1, (int) ceil($dLen / 68)) + 28.0)));
    $r++;

    $sheet->getRowDimension($r)->setRowHeight(12);
    $r++;

    $writeBlockTitle($sheet, $r, 'Indicadores', LO_CARD_INDIC);
    $rowInd = $r;
    $sheet->setCellValue('A' . $rowInd, lo_xlsx_rich_money_card('Total utilizado', $totalUtilizado));
    $sheet->setCellValue('B' . $rowInd, lo_xlsx_rich_int_card('Itens utilizados', count($matsUtil)));
    foreach (['A', 'B'] as $col) {
        $sheet->getStyle($col . $rowInd)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true)->setIndent(1);
        $sheet->getStyle($col . $rowInd)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFDFCFF');
        $sheet->getStyle($col . $rowInd)->applyFromArray($cardOutlineSoft);
    }
    $sheet->getRowDimension($rowInd)->setRowHeight(58);
    $rowInd++;

    $sheet->setCellValue('A' . $rowInd, lo_xlsx_rich_int_card('Anexos', count($anexos)));
    $sheet->setCellValue('B' . $rowInd, lo_xlsx_rich_int_card('Mensagens', count($respostas)));
    foreach (['A', 'B'] as $col) {
        $sheet->getStyle($col . $rowInd)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true)->setIndent(1);
        $sheet->getStyle($col . $rowInd)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFDFCFF');
        $sheet->getStyle($col . $rowInd)->applyFromArray($cardOutlineSoft);
    }
    $sheet->getRowDimension($rowInd)->setRowHeight(58);
    $r = $rowInd + 1;

    $sheet->mergeCells('A' . $r . ':B' . $r);
    $sheet->setCellValue(
        'A' . $r,
        'Itens recolhidos (logística / devoluções, não compõem valor financeiro): ' . count($matsDev)
    );
    $sheet->getStyle('A' . $r)->getFont()->setSize(10)->getColor()->setARGB(LO_MUTED);
    $sheet->getStyle('A' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    $sheet->getStyle('A' . $r)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(LO_BORDER_SOFT);
    $sheet->getRowDimension($r)->setRowHeight(26);
    $r++;

    $sheet->freezePane('A' . $freezeStart);

    $footerRow = $r + 1;
    $sheet->mergeCells('A' . $footerRow . ':B' . ($footerRow + 2));
    $rodape = "Gerado por LightOn · {$brandName}\n"
        . 'Exportado em ' . $emitidoStr . "\n"
        . 'Utilizador: ' . $exportNome . "\n"
        . 'LightOn · identidade visual do sistema';
    $sheet->setCellValue('A' . $footerRow, $rodape);
    $sheet->getStyle('A' . $footerRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(CH_XLSX_FOOTER_BAND);
    $sheet->getStyle('A' . $footerRow)->applyFromArray($pairBorderResumo);
    $sheet->getStyle('A' . $footerRow)->getFont()->setSize(10)->getColor()->setARGB(LO_MUTED);
    $sheet->getStyle('A' . $footerRow)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);
    $sheet->getRowDimension($footerRow)->setRowHeight(54);

    $headerRepeatEnd = 6;
    $sheet->getPageSetup()
        ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
        ->setPaperSize(PageSetup::PAPERSIZE_A4)
        ->setFitToWidth(1)
        ->setFitToHeight(0)
        ->setHorizontalCentered(true)
        ->setRowsToRepeatAtTopByStartAndEnd(1, $headerRepeatEnd);
    $sheet->getPageMargins()->setTop(0.22)->setBottom(0.22)->setLeft(0.28)->setRight(0.28)->setHeader(0.18)->setFooter(0.28);
    $sheet->getHeaderFooter()
        ->setOddHeader('&C&"-,Bold"&11LightOn · Chamado #' . $chamadoId)
        ->setOddFooter('&C&9Gerado por LightOn · ' . $brandName . ' · &D &T · &P/&N');

    /* ---------- Conversa (timeline) ---------- */
    $conv = $spreadsheet->createSheet();
    $conv->setTitle('Conversa');
    $conv->mergeCells('A1:D1');
    $conv->setCellValue('A1', 'Linha do tempo — mensagens do chamado');
    $conv->getStyle('A1')->getFont()->setBold(true)->setSize(12)->getColor()->setARGB(CH_XLSX_HEAD_TXT);
    $conv->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(CH_XLSX_HEAD);
    $conv->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);
    $conv->getRowDimension(1)->setRowHeight(26);

    $conv->setCellValue('A2', 'Data');
    $conv->setCellValue('B2', 'Usuário');
    $conv->setCellValue('C2', 'Visibilidade');
    $conv->setCellValue('D2', 'Mensagem');
    $conv->getStyle('A2:D2')->applyFromArray($thinBorder);
    $conv->getStyle('A2:D2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(CH_XLSX_HEAD);
    $conv->getStyle('A2:D2')->getFont()->setBold(true);
    $conv->getStyle('A2:D2')->getFont()->getColor()->setARGB(CH_XLSX_HEAD_TXT);
    $conv->getRowDimension(2)->setRowHeight(22);

    $rx = 3;
    foreach ($respostas as $rp) {
        $interna = !empty($rp['interna']);
        $conv->setCellValue('A' . $rx, (string) ($rp['data'] ?? ''));
        $conv->setCellValue('B' . $rx, (string) ($rp['autor'] ?? ''));
        $conv->setCellValue('C' . $rx, $interna ? 'Interna' : 'Pública');
        $conv->setCellValue('D' . $rx, str_replace(["\r\n", "\r"], "\n", (string) ($rp['texto'] ?? '')));
        $conv->getStyle('A' . $rx . ':D' . $rx)->applyFromArray($thinBorder);
        $conv->getStyle('D' . $rx)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        if ($interna) {
            $conv->getStyle('A' . $rx . ':D' . $rx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(CH_XLSX_INTERNA);
            $conv->getStyle('C' . $rx)->getFont()->setBold(true)->getColor()->setARGB('FFB45309');
        } elseif (($rx % 2) === 0) {
            $conv->getStyle('A' . $rx . ':D' . $rx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(CH_XLSX_ROW_ALT);
        }
        $len = mb_strlen((string) ($rp['texto'] ?? ''));
        $conv->getRowDimension($rx)->setRowHeight(min(140.0, max(20.0, 14.0 * max(1, (int) ceil($len / 85)) + 8.0)));
        $rx++;
    }
    if ($rx > 3) {
        $conv->setAutoFilter('A2:D' . ($rx - 1));
    }
    $conv->freezePane('A3');
    $conv->getColumnDimension('A')->setWidth(19);
    $conv->getColumnDimension('B')->setWidth(22);
    $conv->getColumnDimension('C')->setWidth(14);
    $autoWidthCap($conv, 'D', 72);

    $footC = $rx + 1;
    $conv->mergeCells('A' . $footC . ':D' . $footC);
    $conv->setCellValue('A' . $footC, 'Gerado por LightOn · ' . $brandName . ' · ' . $emitidoStr);
    $conv->getStyle('A' . $footC)->getFont()->setSize(9)->getColor()->setARGB('FF94A3B8');
    $conv->getStyle('A' . $footC)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    /* ---------- Itens utilizados ---------- */
    $u = $spreadsheet->createSheet();
    $u->setTitle('Itens utilizados');
    $u->mergeCells('A1:G1');
    $u->setCellValue('A1', 'Materiais e serviços utilizados no chamado (valores monetários)');
    $u->getStyle('A1')->getFont()->setBold(true)->setSize(12)->getColor()->setARGB(CH_XLSX_HEAD_TXT);
    $u->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(CH_XLSX_HEAD);
    $u->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);
    $u->getRowDimension(1)->setRowHeight(28);

    $hdr = ['Item', 'Código', 'Tipo', 'Qtd', 'Valor unit.', 'Subtotal', 'Obs'];
    $c = 1;
    foreach ($hdr as $h) {
        $L = Coordinate::stringFromColumnIndex($c);
        $u->setCellValue($L . '2', $h);
        $c++;
    }
    $u->getStyle('A2:G2')->applyFromArray($thinBorder);
    $u->getStyle('A2:G2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(CH_XLSX_HEAD);
    $u->getStyle('A2:G2')->getFont()->setBold(true);
    $u->getStyle('A2:G2')->getFont()->getColor()->setARGB(CH_XLSX_HEAD_TXT);
    $u->getRowDimension(2)->setRowHeight(24);

    $r = 3;
    $currencyFmt = '"R$" #,##0.00';
    foreach ($matsUtil as $m) {
        $u->setCellValue('A' . $r, (string) ($m['item_nome'] ?? ''));
        $u->setCellValue('B' . $r, (string) ($m['item_codigo'] ?? ''));
        $u->setCellValue('C' . $r, (string) ($m['item_tipo'] ?? ''));
        $u->setCellValue('D' . $r, isset($m['quantidade']) ? (float) $m['quantidade'] : 0);
        $u->setCellValue('E' . $r, isset($m['valor_unitario']) ? (float) $m['valor_unitario'] : 0);
        $u->setCellValue('F' . $r, isset($m['subtotal']) ? (float) $m['subtotal'] : 0);
        $u->setCellValue('G' . $r, (string) ($m['observacao'] ?? ''));
        $u->getStyle('A' . $r . ':G' . $r)->applyFromArray($thinBorder);
        $u->getStyle('E' . $r . ':F' . $r)->getNumberFormat()->setFormatCode($currencyFmt);
        $u->getStyle('F' . $r)->getFont()->setBold(true);
        $u->getStyle('F' . $r)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFEFF6FF');
        $u->getStyle('G' . $r)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        if (($r % 2) === 1) {
            $u->getStyle('A' . $r . ':G' . $r)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(CH_XLSX_ROW_ALT);
            $u->getStyle('F' . $r)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE8EEF9');
        }
        $r++;
    }

    $blankBeforeTot = $r;
    $u->getRowDimension($blankBeforeTot)->setRowHeight(8);

    $totRow = $blankBeforeTot + 1;
    $u->mergeCells('A' . $totRow . ':E' . $totRow);
    $u->setCellValue('A' . $totRow, 'TOTAL UTILIZADO NO CHAMADO');
    $u->setCellValue('F' . $totRow, $totalUtilizado);
    $u->getStyle('A' . $totRow . ':G' . $totRow)->getFont()->setBold(true)->setSize(12);
    $u->getStyle('A' . $totRow . ':G' . $totRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(CH_XLSX_TOTAL_BLOCK);
    $u->getStyle('A' . $totRow . ':G' . $totRow)->applyFromArray([
        'borders' => [
            'outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => CH_XLSX_HEAD]],
        ],
    ]);
    $u->getStyle('F' . $totRow)->getNumberFormat()->setFormatCode($currencyFmt);
    $u->getStyle('A' . $totRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT)->setVertical(Alignment::VERTICAL_CENTER);
    $u->getRowDimension($totRow)->setRowHeight(30);

    $hdrLast = $totRow - 2;
    if ($hdrLast >= 3) {
        $u->setAutoFilter('A2:G' . $hdrLast);
    }
    $u->freezePane('A3');
    foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $L) {
        $autoWidthCap($u, $L, 28);
    }
    $autoWidthCap($u, 'G', 45);

    $footU = $totRow + 2;
    $u->mergeCells('A' . $footU . ':G' . $footU);
    $u->setCellValue('A' . $footU, 'Gerado por LightOn · Valores em Real (R$) · ' . $emitidoStr);
    $u->getStyle('A' . $footU)->getFont()->setSize(9)->getColor()->setARGB('FF94A3B8');
    $u->getStyle('A' . $footU)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    /* ---------- Itens recolhidos ---------- */
    $d = $spreadsheet->createSheet();
    $d->setTitle('Itens recolhidos');
    $d->mergeCells('A1:E1');
    $d->setCellValue('A1', 'Registo logístico — não entra no valor financeiro do chamado');
    $d->getStyle('A1')->getFont()->setBold(true)->setSize(11)->getColor()->setARGB(CH_XLSX_WARN_TXT);
    $d->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(CH_XLSX_LOGISTICA_BG);
    $d->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    $d->getRowDimension(1)->setRowHeight(36);

    $hdrD = ['Item', 'Código', 'Tipo', 'Qtd', 'Obs'];
    $c = 1;
    foreach ($hdrD as $h) {
        $L = Coordinate::stringFromColumnIndex($c);
        $d->setCellValue($L . '2', $h);
        $c++;
    }
    $d->getStyle('A2:E2')->applyFromArray($thinBorder);
    $d->getStyle('A2:E2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(CH_XLSX_GRAY_HDR);
    $d->getStyle('A2:E2')->getFont()->setBold(true);
    $d->getStyle('A2:E2')->getFont()->getColor()->setARGB('FFFFFFFF');
    $d->getRowDimension(2)->setRowHeight(22);

    $r = 3;
    foreach ($matsDev as $m) {
        $d->setCellValue('A' . $r, (string) ($m['item_nome'] ?? ''));
        $d->setCellValue('B' . $r, (string) ($m['item_codigo'] ?? ''));
        $d->setCellValue('C' . $r, (string) ($m['item_tipo'] ?? ''));
        $d->setCellValue('D' . $r, isset($m['quantidade']) ? (float) $m['quantidade'] : 0);
        $d->setCellValue('E' . $r, (string) ($m['observacao'] ?? ''));
        $d->getStyle('A' . $r . ':E' . $r)->applyFromArray($thinBorder);
        $d->getStyle('E' . $r)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        if (($r % 2) === 0) {
            $d->getStyle('A' . $r . ':E' . $r)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8FAFC');
        } else {
            $d->getStyle('A' . $r . ':E' . $r)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF1F5F9');
        }
        $r++;
    }
    if ($r > 3) {
        $d->setAutoFilter('A2:E' . ($r - 1));
    }
    $d->freezePane('A3');
    foreach (range('A', 'E') as $L) {
        $autoWidthCap($d, $L, 40);
    }

    $footD = $r + 1;
    $d->mergeCells('A' . $footD . ':E' . $footD);
    $d->setCellValue('A' . $footD, 'Gerado por LightOn · Devoluções / recolha · ' . $emitidoStr);
    $d->getStyle('A' . $footD)->getFont()->setSize(9)->getColor()->setARGB('FF94A3B8');
    $d->getStyle('A' . $footD)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    /* ---------- Anexos ---------- */
    $ax = $spreadsheet->createSheet();
    $ax->setTitle('Anexos');
    $ax->mergeCells('A1:F1');
    $ax->setCellValue('A1', 'Anexos do chamado');
    $ax->getStyle('A1')->getFont()->setBold(true)->setSize(12)->getColor()->setARGB(CH_XLSX_HEAD_TXT);
    $ax->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(CH_XLSX_HEAD);
    $ax->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);
    $ax->getRowDimension(1)->setRowHeight(26);

    $ax->setCellValue('A2', 'Nome do arquivo');
    $ax->setCellValue('B2', 'Tipo (MIME)');
    $ax->setCellValue('C2', 'Tamanho');
    $ax->setCellValue('D2', 'Enviado por');
    $ax->setCellValue('E2', 'Data');
    $ax->setCellValue('F2', 'Link');
    $ax->getStyle('A2:F2')->applyFromArray($thinBorder);
    $ax->getStyle('A2:F2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(CH_XLSX_HEAD);
    $ax->getStyle('A2:F2')->getFont()->setBold(true);
    $ax->getStyle('A2:F2')->getFont()->getColor()->setARGB(CH_XLSX_HEAD_TXT);
    $ax->getRowDimension(2)->setRowHeight(22);

    $r = 3;
    foreach ($anexos as $a) {
        $aid = (int) ($a['id'] ?? 0);
        $ax->setCellValue('A' . $r, (string) ($a['nome_original'] ?? ''));
        $mime = (string) ($a['mime'] ?? '');
        $ax->setCellValue('B' . $r, $mime !== '' ? $mime : '—');
        $ax->setCellValue('C' . $r, isset($a['tamanho']) ? $fmtBytes((int) $a['tamanho']) : '—');
        $ax->setCellValue('D' . $r, (string) ($a['enviado_por'] ?? ''));
        $ax->setCellValue('E' . $r, (string) ($a['enviado_em'] ?? ''));
        $url = $aid > 0 ? $adminDownloadUrl($aid) : '';
        if ($url !== '') {
            $ax->setCellValue('F' . $r, 'Abrir arquivo');
            $ax->getCell('F' . $r)->getHyperlink()->setUrl($url)->setTooltip('Transferir anexo');
            $ax->getStyle('F' . $r)->getFont()->setUnderline(true)->getColor()->setARGB('FF2563EB');
        } else {
            $ax->setCellValue('F' . $r, '—');
        }
        $ax->getStyle('A' . $r . ':F' . $r)->applyFromArray($thinBorder);
        $ax->getStyle('A' . $r)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        if (($r % 2) === 0) {
            $ax->getStyle('A' . $r . ':F' . $r)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(CH_XLSX_ROW_ALT);
        }
        $r++;
    }
    if ($r > 3) {
        $ax->setAutoFilter('A2:F' . ($r - 1));
    }
    $ax->freezePane('A3');
    foreach (['A', 'B', 'D', 'E', 'F'] as $L) {
        $autoWidthCap($ax, $L, 36);
    }
    $ax->getColumnDimension('C')->setWidth(14);

    $footA = $r + 1;
    $ax->mergeCells('A' . $footA . ':F' . $footA);
    $ax->setCellValue('A' . $footA, 'Gerado por LightOn · Tamanhos em KB/MB · ' . $emitidoStr);
    $ax->getStyle('A' . $footA)->getFont()->setSize(9)->getColor()->setARGB('FF94A3B8');
    $ax->getStyle('A' . $footA)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    /* ---------- Log (futuro) ---------- */
    $lg = $spreadsheet->createSheet();
    $lg->setTitle('Log');
    $lg->setCellValue('A1', 'Histórico / auditoria');
    $lg->setCellValue('A2', 'Esta aba está reservada para futuras entradas de log e histórico administrativo.');
    $lg->getStyle('A1')->getFont()->setBold(true)->setSize(12);

    $spreadsheet->setActiveSheetIndex(0);

    foreach ($spreadsheet->getAllSheets() as $wsGrid) {
        $wsGrid->setShowGridlines(false);
    }

    $stamp = date('Y-m-d_His');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="chamado_' . $chamadoId . '_' . $stamp . '.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
}
