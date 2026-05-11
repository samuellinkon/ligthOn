<?php
declare(strict_types=1);

/**
 * Constantes LightOn e badges XLS — partilhado entre exportações PhpSpreadsheet.
 */

const LO_WHITE = 'FFFFFFFF';
const LO_PURPLE = 'FF534AB7';
const LO_PURPLE_DARK = 'FF2F2A65';
const LO_PURPLE_HOVER = 'FF7F77DD';
const LO_PURPLE_DARK = 'FF2F2A65';
const LO_TEXT = 'FF1A1A2E';
const LO_MUTED = 'FF6B7280';
const LO_BG_PAGE = 'FFF8F8FB';
const LO_BORDER = 'FFE0E0E0';
const LO_BORDER_SOFT = 'FFF0F0F5';
const LO_SUCCESS = 'FF1D9E75';
const LO_WARNING = 'FFEF9F27';
const LO_DANGER = 'FFE24B4A';
const LO_INFO = 'FF3B82F6';

const CH_XLSX_HEAD = LO_PURPLE;
const CH_XLSX_HEAD_TXT = LO_WHITE;
const CH_XLSX_ROW_ALT = 'FFF8F8FB';
const CH_XLSX_BORDER = LO_BORDER;
const CH_XLSX_GRAY_HDR = 'FF6B7280';
const CH_XLSX_LOGISTICA_BG = 'FFE8E9EF';
const CH_XLSX_TOTAL_BLOCK = 'FFE8E6F7';

/**
 * @return array{0: string, 1: string}
 */
function lo_xlsx_status_badge_colors(string $status): array
{
    $u = mb_strtolower(trim($status));
    if ($u === '' || $u === '—') {
        return ['FFE8E9EF', LO_MUTED];
    }
    if (str_contains($u, 'medição') && str_contains($u, 'bm')) {
        return ['FFE8EEFF', 'FF4338CA'];
    }
    if (str_contains($u, 'cancel')) {
        return ['FFE8E9EF', 'FF4B5563'];
    }
    if (str_contains($u, 'resolv') || str_contains($u, 'fechad')) {
        return ['FFE6F6F1', LO_SUCCESS];
    }
    if (str_contains($u, 'urgent') || str_contains($u, 'urgente') || str_contains($u, 'venc')) {
        return ['FFFCEAEA', LO_DANGER];
    }
    if (str_contains($u, 'aguard') || str_contains($u, 'pendente')) {
        return ['FFFDF6EA', LO_WARNING];
    }
    if (str_contains($u, 'andamento') || str_contains($u, 'respond')) {
        return ['FFE8F1FE', LO_INFO];
    }
    if (str_contains($u, 'abert')) {
        return ['FFE8E6F7', LO_PURPLE];
    }

    return ['FFE8E6F7', LO_PURPLE];
}

/**
 * @return array{0: string, 1: string}
 */
function lo_xlsx_priority_badge_colors(string $prio): array
{
    $u = mb_strtolower(trim($prio));
    if ($u === '' || $u === '—') {
        return ['FFE8E9EF', LO_MUTED];
    }
    if (str_contains($u, 'baixa') || str_contains($u, 'baixo')) {
        return ['FFFFFBEB', 'FFB45309'];
    }
    if (str_contains($u, 'média') || str_contains($u, 'media')) {
        return ['FFE8F1FE', 'FF1D4ED8'];
    }
    if (str_contains($u, 'alta')) {
        return ['FFFDF6EA', 'FFC2410C'];
    }
    if (str_contains($u, 'urg')) {
        return ['FFFCEAEA', LO_DANGER];
    }

    return ['FFF5F4FC', LO_PURPLE];
}
