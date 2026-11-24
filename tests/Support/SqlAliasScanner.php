<?php
declare(strict_types=1);

namespace TaskBoard\Tests\Support;

/**
 * Static SQL dialect guard (task #102).
 *
 * Production runs MariaDB while the test suite runs SQLite, and the two do
 * not agree on one piece of SQL: SQLite resolves a SELECT-list alias of an
 * aggregate function everywhere, MariaDB only accepts the alias as a bare
 * ORDER BY term. Any other use - "ORDER BY last_id IS NULL, last_id DESC" -
 * is a fatal ER_ILLEGAL_REFERENCE on production ("Reference 'last_id' not
 * supported (reference to group function)") that the SQLite suite cannot
 * see, which is exactly how the 2026-11-24 messages-page outage shipped
 * through 520 green tests. Repeating the aggregate itself ("ORDER BY
 * MAX(m.id) IS NULL, MAX(m.id) DESC") is valid on MariaDB, MySQL and SQLite
 * alike.
 *
 * The scanner walks the SQL string literals of a PHP source file and flags
 * every aggregate alias that is referenced inside an ORDER BY expression
 * instead of as a bare term. It is a targeted guard for this one hazard, not
 * a general SQL parser.
 */
final class SqlAliasScanner
{
    /** @return string[] every PHP file of the deployment that may carry SQL string literals */
    public static function codebaseFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [$root . '/index.php', $root . '/updater.php'];
        foreach (glob($root . '/src/*.php') ?: [] as $file) {
            $files[] = $file;
        }
        return $files;
    }

    /** @return string[] the SQL string literals of a PHP source (SELECT ... ORDER BY only) */
    public static function sqlStrings(string $source): array
    {
        $out = [];
        foreach (token_get_all($source) as $token) {
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $literal = substr($token[1], 1, -1);
            // Reverse the two escapes a single-quoted PHP string understands.
            $literal = str_replace(["\\'", '\\\\'], ["'", '\\'], $literal);
            if (stripos($literal, 'select') !== false && stripos($literal, 'order by') !== false) {
                $out[] = $literal;
            }
        }
        return $out;
    }

    /** SQL keywords that must never be mistaken for a SELECT-list alias. */
    private const KEYWORDS = 'is|null|asc|desc|and|or|not|from|where|group|order|by|having|limit|offset|union|all|distinct|join|inner|left|right|outer|cross|on|as|when|then|else|end|case|in|like|between|exists|over|partition|separator|select';

    /**
     * Returns one human-readable line per aggregate alias that is referenced
     * inside an ORDER BY expression of the given SQL instead of as a bare
     * term. Empty output means the statement is safe on every supported
     * dialect.
     *
     * @return string[]
     */
    public static function violations(string $sql): array
    {
        // Aliases born from an aggregate in the top-level SELECT list, e.g.
        // "MAX(m.id) last_id" or "COUNT(*) AS unread". Aggregates hidden in
        // subqueries expose their alias as a derived-table column, which the
        // outer query may reference freely on every dialect - so only the
        // top-level SELECT list is scanned.
        $aliases = self::aggregateAliases(self::selectList($sql));
        if ($aliases === [] || !preg_match_all('/order\s+by\s+/i', $sql, $clauses, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $found = [];
        foreach ($clauses[0] as [$marker, $offset]) {
            $tail = substr($sql, $offset + strlen($marker));
            foreach ($aliases as $alias => $function) {
                if (!preg_match_all('/\b' . preg_quote($alias, '/') . '\b/i', $tail, $hits, PREG_OFFSET_CAPTURE)) {
                    continue;
                }
                foreach ($hits[0] as [$hit, $hitOffset]) {
                    $before = rtrim(substr($tail, 0, $hitOffset));
                    $after = substr($tail, $hitOffset + strlen($hit));
                    // A bare term sits directly after ORDER BY or a comma and
                    // runs until ASC/DESC, the next comma, LIMIT or the end.
                    $bareStart = $before === '' || preg_match('/(?:order\s+by|,)\s*$/i', $before);
                    $bareEnd = $after === '' || preg_match('/^\s*(?:asc\b|desc\b|,|limit\b|$)/i', $after);
                    if ($bareStart && $bareEnd) {
                        continue;
                    }
                    $found[] = sprintf(
                        "aggregate alias \"%s\" (from %s()) used inside an ORDER BY expression: ORDER BY %s",
                        $alias,
                        $function,
                        trim($tail)
                    );
                    break;
                }
            }
        }
        return array_values(array_unique($found));
    }

    /**
     * The substring of the statement that holds the top-level SELECT list:
     * from the first top-level SELECT to the first top-level FROM (both
     * tracked at parenthesis depth 0 so subqueries stay out). Returns the
     * whole statement when no top-level FROM exists.
     */
    private static function selectList(string $sql): string
    {
        $depth = 0;
        $length = strlen($sql);
        $start = null;
        $end = $length;
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            if ($char === '(') {
                $depth++;
                continue;
            }
            if ($char === ')') {
                $depth = max(0, $depth - 1);
                continue;
            }
            if ($depth !== 0 || !preg_match('/[a-z]/i', $char)) {
                continue;
            }
            if (preg_match('/\bselect\b/i', $sql, $m, 0, $i) && $m[0] === substr($sql, $i, strlen($m[0])) && $start === null) {
                $start = $i;
                $i += strlen($m[0]) - 1;
                continue;
            }
            if ($start !== null && preg_match('/\bfrom\b/i', $sql, $m, 0, $i) && $m[0] === substr($sql, $i, strlen($m[0]))) {
                $end = $i;
                break;
            }
        }
        if ($start === null) {
            return $sql;
        }
        return substr($sql, $start, $end - $start);
    }

    /**
     * Aggregate function calls mapped to their alias in a SELECT-list
     * fragment ("MAX(m.id) last_id" => last_id => MAX).
     *
     * @return array<string,string>
     */
    private static function aggregateAliases(string $selectList): array
    {
        $aliases = [];
        if (!preg_match_all(
            '/\b(min|max|count|sum|avg|group_concat)\s*\(((?:[^()]|\((?:[^()]|\([^()]*\))*\))*)\)\s*(?:as\s+)?([a-z_][a-z0-9_]*)/i',
            $selectList,
            $matches,
            PREG_SET_ORDER
        )) {
            return $aliases;
        }
        foreach ($matches as $m) {
            if (preg_match('/^(?:' . self::KEYWORDS . ')$/i', $m[3])) {
                continue;
            }
            $aliases[strtolower($m[3])] = strtoupper($m[1]);
        }
        return $aliases;
    }
}
