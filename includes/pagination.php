<?php
/**
 * Lightweight pagination helper. Usage per list:
 *
 *   $perPage = 10;
 *   $page = hef_current_page();
 *   $total = $pdo->prepare('SELECT COUNT(*) FROM ...')->... ->fetchColumn();
 *   $stmt = $pdo->prepare('SELECT ... LIMIT ' . $perPage . ' OFFSET ' . hef_offset($page, $perPage));
 *   ...
 *   echo hef_pagination_links($page, $total, $perPage);
 *
 * (LIMIT/OFFSET are inlined as validated integers, not bound params —
 * PDO parameter binding for LIMIT/OFFSET is unreliable across drivers.)
 */

function hef_current_page(string $paramName = 'page'): int
{
    $page = (int) ($_GET[$paramName] ?? 1);
    return max(1, $page);
}

function hef_offset(int $page, int $perPage): int
{
    return ($page - 1) * $perPage;
}

function hef_pagination_links(int $currentPage, int $totalRows, int $perPage, string $paramName = 'page'): string
{
    $totalPages = (int) ceil($totalRows / $perPage);
    if ($totalPages <= 1) {
        return '';
    }

    $queryWithout = $_GET;
    $buildUrl = function (int $p) use ($queryWithout, $paramName) {
        $queryWithout[$paramName] = $p;
        return '?' . http_build_query($queryWithout);
    };

    $html = '<nav class="mt-3"><ul class="pagination pagination-sm justify-content-center mb-0">';

    $prevDisabled = $currentPage <= 1 ? ' disabled' : '';
    $html .= "<li class=\"page-item{$prevDisabled}\"><a class=\"page-link\" href=\"" . $buildUrl(max(1, $currentPage - 1)) . "\">Prev</a></li>";

    // Show a small window of page numbers around the current page
    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);

    if ($start > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $buildUrl(1) . '">1</a></li>';
        if ($start > 2) {
            $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
    }

    for ($p = $start; $p <= $end; $p++) {
        $active = $p === $currentPage ? ' active' : '';
        $html .= "<li class=\"page-item{$active}\"><a class=\"page-link\" href=\"" . $buildUrl($p) . "\">{$p}</a></li>";
    }

    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
        $html .= '<li class="page-item"><a class="page-link" href="' . $buildUrl($totalPages) . '">' . $totalPages . '</a></li>';
    }

    $nextDisabled = $currentPage >= $totalPages ? ' disabled' : '';
    $html .= "<li class=\"page-item{$nextDisabled}\"><a class=\"page-link\" href=\"" . $buildUrl(min($totalPages, $currentPage + 1)) . "\">Next</a></li>";

    $html .= '</ul></nav>';

    return $html;
}
