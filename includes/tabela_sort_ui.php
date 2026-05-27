<?php
/**
 * Cabeçalhos e atributos de linha para tabelas ordenáveis (↕ / ▲ / ▼).
 */

declare(strict_types=1);

if (!function_exists('crm_sort_th')) {
    /**
     * @param array{class?: string, right?: bool, type?: string, title?: string} $opts
     */
    function crm_sort_th(string $label, ?string $key = null, array $opts = []): void
    {
        $class = trim((string) ($opts['class'] ?? ''));
        $right = !empty($opts['right']);
        $type  = trim((string) ($opts['type'] ?? ''));
        $title = trim((string) ($opts['title'] ?? ''));

        $thClass = $class;
        if ($right) {
            $thClass = trim($thClass . ' text-right');
        }

        echo '<th scope="col"';
        if ($thClass !== '') {
            echo ' class="' . htmlspecialchars($thClass, ENT_QUOTES, 'UTF-8') . '"';
        }
        if ($title !== '') {
            echo ' title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"';
        }
        echo '>';

        if ($key === null || $key === '') {
            echo '<div class="catalogo-excel-sort catalogo-excel-sort--static">';
            echo '<span class="catalogo-excel-sort__label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
            echo '<span class="catalogo-excel-sort__icon catalogo-excel-sort__icon--spacer" aria-hidden="true">↕</span>';
            echo '</div>';
        } else {
            $btnClass = 'catalogo-excel-sort' . ($right ? ' text-right' : '');
            echo '<button type="button" class="' . htmlspecialchars($btnClass, ENT_QUOTES, 'UTF-8') . '"';
            echo ' data-sort-key="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '"';
            if ($type !== '') {
                echo ' data-sort-type="' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '"';
            }
            echo '>';
            echo '<span class="catalogo-excel-sort__label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
            echo '<span class="catalogo-excel-sort__icon" aria-hidden="true">↕</span>';
            echo '</button>';
        }

        echo '</th>';
    }
}

if (!function_exists('crm_sort_row_attr')) {
    /** @param array<string, string|int|float|null> $values */
    function crm_sort_row_attr(array $values): string
    {
        $parts = ['data-sort-row'];
        foreach ($values as $key => $val) {
            $k = preg_replace('/[^a-z0-9_-]/i', '', (string) $key);
            if ($k === '') {
                continue;
            }
            $parts[] = 'data-sort-' . $k . '="' . htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8') . '"';
        }

        return implode(' ', $parts);
    }
}

if (!function_exists('crm_sort_prioridade_rank')) {
    function crm_sort_prioridade_rank(string $prioridade): int
    {
        static $map = [
            'Urgente' => 0,
            'Alta'    => 1,
            'Normal'  => 2,
            'Baixa'   => 3,
        ];

        return $map[$prioridade] ?? 9;
    }
}
