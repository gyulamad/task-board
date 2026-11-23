<?php
declare(strict_types=1);

/**
 * The shared CRUD list engine (Task #57, admin refactor step 1).
 *
 * Every list page of the board (tasks, projects, users, templates, know-hows)
 * is the same machine wearing different clothes: a WHERE built from request
 * filters, one COUNT, a clamped page number, a LIMIT/OFFSET fetch through an
 * allowlisted sort, a URL builder that keeps the active filters, a sortable
 * table with an empty-state row, the pagination window and (on the lists that
 * have one) the swap-safe bulk-select wiring. Before this module those parts
 * lived as copy-pasted blocks in every page branch and had already started to
 * drift apart (the projects list grew its own private sort_header copy).
 *
 * The v2 refactor (steps 2-5 of the #57 plan) moves the mechanics here and
 * leaves the entity-specific bits - which rows to query, what every column
 * renders - with the page as small closures. Each helper produces exactly the
 * markup the pages emitted before, so the rendered lists stay byte-for-byte
 * compatible with what the E2E suite pins; the parity tests
 * (tests/E2E/CrudAbstractionTest.php) prove the -v2 pages match the originals.
 *
 * Step 6 of the plan (its own subtask) applies this engine to every remaining
 * entity page the same way: build the v2 page first, prove parity, then switch
 * the links.
 */

/** Default rows per CRUD list page. Every list of the board shows 20. */
const TB_CRUD_DEFAULT_PER_PAGE = 20;

/**
 * Runs the standard CRUD list query for a page.
 *
 * $config keys:
 *   pdo          - the application PDO handle
 *   table        - the FROM clause including joins, e.g. "users u" or
 *                  "tasks t JOIN statuses s ON s.id=t.status_id ..."
 *   select       - the SELECT column list (default *)
 *   where        - condition strings (no leading WHERE), AND-combined
 *   params       - the bound parameters for $where, in order
 *   sortColumns  - allowlist map sort key => SQL fragment with a {dir}
 *                  placeholder (the same shape sort_state() takes)
 *   defaultSort  - sort key used when ?sort= is missing or unknown
 *   defaultDir   - 'asc' (default) or 'desc'
 *   orderBySuffix- optional tiebreak fragment appended after the sort key
 *                  fragment, e.g. "t.id {dir}" (the {dir} placeholder gets
 *                  the active direction). Lists whose sortColumns already
 *                  carry per-column tiebreaks (users) omit it.
 *   perPage      - rows per page (default TB_CRUD_DEFAULT_PER_PAGE)
 *
 * The page number comes from ?p=, clamped into [1, pages]. Returns:
 *   ['total' => int, 'pages' => int, 'page' => int, 'perPage' => int,
 *    'rows' => array, 'sort' => array]
 * The 'sort' entry is the sort_state() array the table headers need again.
 *
 * Every value reaches the query either as a bound parameter or through the
 * sort_state() allowlist, so a crafted URL can inject nothing.
 */
function tb_crud_list(array $config): array {
    $pdo = $config['pdo'];
    $table = (string)$config['table'];
    $where = array_values($config['where'] ?? []);
    $params = array_values($config['params'] ?? []);
    $perPage = max(1, (int)($config['perPage'] ?? TB_CRUD_DEFAULT_PER_PAGE));
    $sort = sort_state($config['sortColumns'], (string)$config['defaultSort'], (string)($config['defaultDir'] ?? 'asc'));
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $orderSql = $sort['sql'];
    if (isset($config['orderBySuffix'])) {
        $orderSql .= ', ' . str_replace('{dir}', strtoupper($sort['dir']), (string)$config['orderBySuffix']);
    }
    $cs = $pdo->prepare('SELECT COUNT(*) FROM ' . $table . $whereSql);
    $cs->execute($params);
    $total = (int)$cs->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min(max(1, (int)($_GET['p'] ?? 1)), $pages);
    $sql = 'SELECT ' . ($config['select'] ?? '*') . ' FROM ' . $table . $whereSql
        . ' ORDER BY ' . $orderSql
        . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage);
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return ['total' => $total, 'pages' => $pages, 'page' => $page, 'perPage' => $perPage, 'rows' => $st->fetchAll(), 'sort' => $sort];
}

/**
 * Builds the URL builder closure a CRUD list page hands to the table, the
 * pagination and the sort headers.
 *
 * $page is the page name the generated links point back to (the -v2 pages
 * pass their own name, so filters and pagination keep working on the new
 * URLs too). $keepKeys lists the request keys every generated link must
 * carry: each passes through filter_query_value(), so array filters survive
 * as arrays (?assignee[]=2&assignee[]=5) and missing or empty keys are
 * dropped. The returned closure merges $merge overrides on top ('p' for
 * pagination, the sort toggles for headers), the same way the per-page
 * closures did before the refactor.
 */
function tb_crud_url_builder(string $page, array $keepKeys): Closure {
    $kept = [];
    foreach ($keepKeys as $key) {
        $value = filter_query_value($key);
        if ($value !== null) {
            $kept[$key] = $value;
        }
    }
    return function (array $merge = []) use ($page, $kept): string {
        return '?' . http_build_query(array_merge(['page' => $page], $kept, $merge));
    };
}

/**
 * Renders the pagination footer of a CRUD list: the Bootstrap pagination
 * window (a sliding five-page window with first/last and ellipsis links)
 * inside the standard flex wrapper, plus the "Page X of Y" counter.
 *
 * $opts keys:
 *   ariaLabel - the <nav aria-label> of the list ("Task list pages", ...)
 *   page      - the active page number (1-based)
 *   pages     - the total page count
 *   url       - the page's URL builder closure; called with ['p' => n]
 *   trailing  - optional HTML after the pagination (bulk action buttons)
 *
 * When $pages is 1 the nav and the counter are omitted and only the wrapper
 * (with the trailing content) renders - the same behaviour the pages had.
 */
function tb_crud_pagination(array $opts): string {
    $page = max(1, (int)$opts['page']);
    $pages = max(1, (int)$opts['pages']);
    $url = $opts['url'];
    $label = (string)$opts['ariaLabel'];
    $trailing = (string)($opts['trailing'] ?? '');
    $nav = '';
    if ($pages > 1) {
        $nav = '<nav aria-label="' . h($label) . '"><ul class="pagination mb-0">'
            . '<li class="page-item ' . ($page <= 1 ? 'disabled' : '') . '"><a class="page-link" href="' . h($url(['p' => max(1, $page - 1)])) . '">&laquo;</a></li>';
        $winStart = max(1, $page - 2);
        $winEnd = min($pages, $winStart + 4);
        if ($winEnd - $winStart < 4) $winStart = max(1, $winEnd - 4);
        if ($winStart > 1) {
            $nav .= '<li class="page-item"><a class="page-link" href="' . h($url(['p' => 1])) . '">1</a></li>';
            if ($winStart > 2) {
                $nav .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
            }
        }
        for ($i = $winStart; $i <= $winEnd; $i++) {
            $nav .= '<li class="page-item ' . ($i === $page ? 'active' : '') . '"><a class="page-link" href="' . h($url(['p' => $i])) . '">' . $i . '</a></li>';
        }
        if ($winEnd < $pages) {
            if ($winEnd < $pages - 1) {
                $nav .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
            }
            $nav .= '<li class="page-item"><a class="page-link" href="' . h($url(['p' => $pages])) . '">' . $pages . '</a></li>';
        }
        $nav .= '<li class="page-item ' . ($page >= $pages ? 'disabled' : '') . '"><a class="page-link" href="' . h($url(['p' => min($pages, $page + 1)])) . '">&raquo;</a></li>'
            . '</ul></nav>'
            . '<span class="small muted">Page ' . $page . ' of ' . $pages . '</span>';
    }
    return '<div class="d-flex justify-content-between align-items-center mt-3 gap-2 flex-wrap">' . $nav . $trailing . '</div>';
}

/**
 * Renders a CRUD list table: the responsive Bootstrap table skeleton, the
 * header row from the column definitions and one row per entry, with the
 * empty-state row when the list came back empty.
 *
 * $opts keys:
 *   columns      - the column definitions; every entry is one of
 *                    ['header' => '<th ...>...</th>']           custom header
 *                    ['label' => 'Actions']                      plain th
 *                    ['label', 'sortKey', 'sort', 'url']         sortable th:
 *                      $sort is the active sort_state() array and $url the
 *                      page's URL builder; the header link points at
 *                      $url(sort_toggle('sortKey', $sort)) and renders
 *                      through the shared sort_header() helper.
 *   cell         - per column: closure(array $row): string returning the
 *                  full "<td ...>...</td>" markup of that cell
 *   rows         - the fetched rows (tb_crud_list()['rows'])
 *   emptyText    - the empty-state message
 *   emptyColspan - the colspan of the empty-state cell
 *
 * Only the mechanics live here; every visible cell is what the page's
 * closures emit, so the markup stays exactly what the pages rendered before.
 */
function tb_crud_table(array $opts): string {
    $columns = $opts['columns'];
    $rows = $opts['rows'];
    $head = '';
    foreach ($columns as $column) {
        if (isset($column['header'])) {
            $head .= $column['header'];
            continue;
        }
        if (!empty($column['sortKey'])) {
            $head .= sort_header($column['label'], $column['sortKey'], $column['sort'], $column['url'](sort_toggle($column['sortKey'], $column['sort'])));
            continue;
        }
        $head .= '<th>' . h((string)$column['label']) . '</th>';
    }
    $body = '';
    foreach ($rows as $row) {
        $body .= '<tr>';
        foreach ($columns as $column) {
            $body .= $column['cell']($row);
        }
        $body .= '</tr>';
    }
    if ($rows === []) {
        $body = '<tr><td colspan="' . (int)$opts['emptyColspan'] . '" class="text-center muted py-4">' . h((string)$opts['emptyText']) . '</td></tr>';
    }
    return '<div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div>';
}

/**
 * Renders the swap-safe bulk-select wiring of a CRUD list that has row
 * checkboxes (the task list and later the projects list). The script is
 * parameterized so every list can keep its own element ids and row class.
 *
 * $opts keys:
 *   selectAllId  - id of the "select all" checkbox in the table head
 *   rowClass     - class of the per-row checkboxes
 *   bulkButtonId - id of the bulk action button (optional)
 *   countId      - id of the selected-count badge span (optional)
 *   moveButtonId - id of the "move to project" submit (optional)
 *   moveSelectId - id of the target-project select (optional)
 *
 * The wiring is exactly the one the task list shipped with #51: every
 * element is looked up inside sync() and every listener is delegated on
 * document (plus a re-sync on the region swap event), so the wiring keeps
 * working when the auto-refresh swaps the list region's content.
 */
function tb_crud_bulk_select_script(array $opts): string {
    $allId = (string)$opts['selectAllId'];
    $rowClass = (string)$opts['rowClass'];
    $btnId = isset($opts['bulkButtonId']) ? (string)$opts['bulkButtonId'] : null;
    $countId = isset($opts['countId']) ? (string)$opts['countId'] : null;
    $moveBtnId = isset($opts['moveButtonId']) ? (string)$opts['moveButtonId'] : null;
    $moveSelectId = isset($opts['moveSelectId']) ? (string)$opts['moveSelectId'] : null;
    $sync = 'function sync(){var all=document.getElementById(\'' . $allId . '\');var bs=rows();var n=bs.filter(function(b){return b.checked;}).length;';
    if ($btnId !== null) {
        $sync .= 'var btn=document.getElementById(\'' . $btnId . '\');if(btn)btn.disabled=n===0;';
    }
    if ($moveBtnId !== null) {
        $sync .= 'var moveBtn=document.getElementById(\'' . $moveBtnId . '\');if(moveBtn)moveBtn.disabled=n===0;';
    }
    if ($moveSelectId !== null) {
        $sync .= 'var moveSelect=document.getElementById(\'' . $moveSelectId . '\');if(moveSelect)moveSelect.disabled=n===0;';
    }
    $sync .= 'if(all){all.checked=n>0&&n===bs.length;all.indeterminate=n>0&&n<bs.length;}';
    if ($countId !== null) {
        $sync .= 'var lbl=document.getElementById(\'' . $countId . '\');if(lbl)lbl.textContent=String(n);';
    }
    $sync .= '}';
    return '<script>
(function(){
// (#51) Every element is looked up inside sync() and every listener is
// delegated on document, so the wiring keeps working when the auto-refresh
// swaps the list region\'s content underneath it.
function rows(){return Array.prototype.slice.call(document.querySelectorAll(\'.' . $rowClass . '\'));}
' . $sync . '
document.addEventListener(\'change\',function(e){
  if(e.target&&e.target.id===\'' . $allId . '\'){var v=e.target.checked;rows().forEach(function(b){b.checked=v;});sync();return;}
  if(e.target&&e.target.classList&&e.target.classList.contains(\'' . $rowClass . '\'))sync();
});
document.addEventListener(\'tb-list-region:swapped\',sync);
sync();
})();
</script>';
}
