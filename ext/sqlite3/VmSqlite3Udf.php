<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmCallable;

/**
 * SQLite3::createFunction scalar UDF + createAggregate support (#19862 / #20585).
 *
 * On PHP ≥ 8.3, registers via sqlite3_create_function_v2 + FFI::callback.
 * On PHP 8.2 (pinned CI), expands registered scalar calls with literal/nested-UDF
 * args in SQL before prepare/exec; aggregates rewrite SELECT agg(…) FROM ….
 */
final class VmSqlite3Udf
{
    /**
     * @param array<string, array{callback: Variable, closure: ?ClosureState, argc: int, ctx: Context}> $funcs
     */
    public static function expandSql(string $sql, array $funcs): string
    {
        if ([] === $funcs) {
            return $sql;
        }
        $names = array_keys($funcs);
        usort($names, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));
        $changed = true;
        $guard = 0;
        while ($changed && $guard < 64) {
            $changed = false;
            ++$guard;
            foreach ($names as $name) {
                $next = self::expandOne($sql, $name, $funcs[$name]);
                if ($next !== $sql) {
                    $sql = $next;
                    $changed = true;
                }
            }
        }

        return $sql;
    }

    /**
     * Evaluate `SELECT agg(args) FROM …` by stepping rows through PHP callbacks (#20585).
     *
     * @param array<string, array{
     *     step: Variable,
     *     stepClosure: ?ClosureState,
     *     final: Variable,
     *     finalClosure: ?ClosureState,
     *     argc: int,
     *     ctx: Context
     * }> $aggregates
     */
    public static function expandAggregates(\FFI\CData $db, string $sql, array $aggregates): string
    {
        if ([] === $aggregates) {
            return $sql;
        }
        $names = array_keys($aggregates);
        usort($names, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));
        foreach ($names as $name) {
            $rewritten = self::tryExpandAggregateSelect($db, $sql, $name, $aggregates[$name]);
            if (null !== $rewritten) {
                return $rewritten;
            }
        }

        return $sql;
    }

    /**
     * Evaluate `SELECT … FROM … ORDER BY col COLLATE name` via PHP callbacks (#22332).
     *
     * @param array<string, array{callback: Variable, closure: ?ClosureState, ctx: Context}> $collations
     */
    public static function expandCollations(\FFI\CData $db, string $sql, array $collations): string
    {
        if ([] === $collations) {
            return $sql;
        }
        $rewritten = self::tryExpandOrderByCollate($db, $sql, $collations);
        if (null !== $rewritten) {
            return $rewritten;
        }

        return $sql;
    }

    /**
     * @param array{
     *     step: Variable,
     *     stepClosure: ?ClosureState,
     *     final: Variable,
     *     finalClosure: ?ClosureState,
     *     argc: int,
     *     ctx: Context
     * } $entry
     */
    private static function tryExpandAggregateSelect(
        \FFI\CData $db,
        string $sql,
        string $name,
        array $entry
    ): ?string {
        $pattern = '/^\s*SELECT\s+'.preg_quote($name, '/').'\s*\((.*)\)\s+FROM\s+(.+?)\s*;?\s*$/is';
        if (1 !== preg_match($pattern, $sql, $m)) {
            return null;
        }
        $argsRaw = trim($m[1]);
        $fromRest = trim($m[2]);
        $argExprs = self::parseArgList($argsRaw);
        if (null === $argExprs) {
            return null;
        }
        if (-1 !== $entry['argc'] && \count($argExprs) !== $entry['argc']) {
            return null;
        }
        if ([] === $argExprs) {
            $innerSql = 'SELECT 1 FROM '.$fromRest;
        } else {
            $innerSql = 'SELECT '.implode(', ', $argExprs).' FROM '.$fromRest;
        }
        try {
            $rows = VmSqlite3Native::fetchAllRows($db, $innerSql);
        } catch (\Throwable) {
            return null;
        }
        self::attachClosure($entry['step'], $entry['stepClosure']);
        self::attachClosure($entry['final'], $entry['finalClosure']);

        $context = new Variable();
        $context->null();
        $rowCount = 0;
        foreach ($rows as $row) {
            ++$rowCount;
            $rowNum = new Variable(Variable::TYPE_INTEGER);
            $rowNum->int($rowCount);
            $phpArgs = [$context, $rowNum];
            if ([] !== $argExprs) {
                foreach ($row as $cell) {
                    $slot = new Variable();
                    self::assignPhpValue($slot, $cell);
                    $phpArgs[] = $slot;
                }
            }
            $context = VmCallable::invoke($entry['ctx'], $entry['step'], ...$phpArgs);
        }
        $finalRow = new Variable(Variable::TYPE_INTEGER);
        $finalRow->int(0); // php-src resets row_count before final callback
        $result = VmCallable::invoke($entry['ctx'], $entry['final'], $context, $finalRow);

        return 'SELECT '.self::sqlLiteralFromVariable($result);
    }

    /**
     * @param array<string, array{callback: Variable, closure: ?ClosureState, ctx: Context}> $collations
     */
    private static function tryExpandOrderByCollate(
        \FFI\CData $db,
        string $sql,
        array $collations
    ): ?string {
        $pattern = '/^\s*SELECT\s+(.+?)\s+FROM\s+(.+?)\s+ORDER\s+BY\s+([A-Za-z_][A-Za-z0-9_]*)\s+COLLATE\s+([A-Za-z_][A-Za-z0-9_]*)(?:\s+(ASC|DESC))?\s*;?\s*$/is';
        if (1 !== preg_match($pattern, $sql, $m)) {
            return null;
        }
        $collationName = strtolower($m[4]);
        if (!isset($collations[$collationName])) {
            return null;
        }
        $entry = $collations[$collationName];
        $orderCol = $m[3];
        $desc = isset($m[5]) && 0 === strcasecmp((string) $m[5], 'DESC');
        $baseSql = 'SELECT '.$m[1].' FROM '.$m[2];
        try {
            $fetched = self::fetchRowsWithNames($db, $baseSql);
        } catch (\Throwable) {
            return null;
        }
        $colIndex = null;
        foreach ($fetched['names'] as $i => $name) {
            if (0 === strcasecmp((string) $name, $orderCol)) {
                $colIndex = $i;
                break;
            }
        }
        if (null === $colIndex) {
            return null;
        }
        self::attachClosure($entry['callback'], $entry['closure']);
        $rows = $fetched['rows'];
        usort($rows, static function (array $a, array $b) use ($entry, $colIndex, $desc): int {
            $left = self::cellToString($a[$colIndex] ?? null);
            $right = self::cellToString($b[$colIndex] ?? null);
            $lv = new Variable();
            $lv->string($left);
            $rv = new Variable();
            $rv->string($right);
            $cmpVar = VmCallable::invoke($entry['ctx'], $entry['callback'], $lv, $rv);
            $cmp = (int) $cmpVar->resolveIndirect()->toInt();
            if (0 === $cmp) {
                return 0;
            }

            return $desc ? -$cmp : $cmp;
        });
        $tmp = '__phpc_cs_'.bin2hex(random_bytes(4));
        try {
            VmSqlite3Native::exec($db, 'CREATE TEMP TABLE '.$tmp.' AS '.$baseSql.' LIMIT 0');
            foreach ($rows as $row) {
                $literals = [];
                foreach ($row as $cell) {
                    $literals[] = self::sqlLiteralFromPhp($cell);
                }
                VmSqlite3Native::exec(
                    $db,
                    'INSERT INTO '.$tmp.' VALUES ('.implode(', ', $literals).')'
                );
            }
        } catch (\Throwable) {
            return null;
        }

        return 'SELECT * FROM '.$tmp;
    }

    /**
     * @return array{names: list<string>, rows: list<list<string|int|float|null>>}
     */
    private static function fetchRowsWithNames(\FFI\CData $db, string $sql): array
    {
        $rows = [];
        $names = [];
        $stmt = VmSqlite3Native::prepare($db, $sql);
        try {
            $colCount = VmSqlite3Native::columnCount($stmt);
            for ($i = 0; $i < $colCount; ++$i) {
                $names[] = VmSqlite3Native::columnName($stmt, $i);
            }
            while (true) {
                $rc = VmSqlite3Native::step($stmt);
                if (VmSqlite3Native::STEP_ROW !== $rc) {
                    break;
                }
                $row = [];
                for ($i = 0; $i < $colCount; ++$i) {
                    $row[] = VmSqlite3Native::columnValueAt($stmt, $i);
                }
                $rows[] = $row;
            }
        } finally {
            VmSqlite3Native::finalize($stmt);
        }

        return ['names' => $names, 'rows' => $rows];
    }

    private static function cellToString(mixed $cell): string
    {
        if (null === $cell) {
            return '';
        }

        return (string) $cell;
    }

    private static function sqlLiteralFromPhp(mixed $value): string
    {
        if (null === $value) {
            return 'NULL';
        }
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }
        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }

        return VmSqlite3Native::quoteSqlLiteral((string) $value);
    }

    private static function attachClosure(Variable $callback, ?ClosureState $closure): void
    {
        if (null === $closure) {
            return;
        }
        $resolved = $callback->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $resolved->type) {
            return;
        }
        $obj = $resolved->toObject();
        if (null === $obj->closureState) {
            $obj->closureState = $closure;
        }
    }

    /**
     * @param array{callback: Variable, closure: ?ClosureState, argc: int, ctx: Context} $entry
     */
    private static function expandOne(string $sql, string $name, array $entry): string
    {
        $len = \strlen($sql);
        $nameLen = \strlen($name);
        $out = '';
        $i = 0;
        while ($i < $len) {
            $ch = $sql[$i];
            if ("'" === $ch || '"' === $ch) {
                $end = self::skipQuoted($sql, $i);
                $out .= substr($sql, $i, $end - $i);
                $i = $end;
                continue;
            }
            if (self::matchIdentAt($sql, $i, $name, $nameLen)
                && ($i + $nameLen) < $len
                && '(' === $sql[$i + $nameLen]
            ) {
                $argsStart = $i + $nameLen + 1;
                $argsEnd = self::findMatchingParen($sql, $argsStart - 1);
                if (null === $argsEnd) {
                    $out .= $ch;
                    ++$i;
                    continue;
                }
                $argsRaw = substr($sql, $argsStart, $argsEnd - $argsStart);
                $parsed = self::parseArgList($argsRaw);
                if (null === $parsed) {
                    $out .= substr($sql, $i, $argsEnd + 1 - $i);
                    $i = $argsEnd + 1;
                    continue;
                }
                if (-1 !== $entry['argc'] && \count($parsed) !== $entry['argc']) {
                    $out .= substr($sql, $i, $argsEnd + 1 - $i);
                    $i = $argsEnd + 1;
                    continue;
                }
                $phpArgs = [];
                $ok = true;
                foreach ($parsed as $argSql) {
                    $val = self::evalArgLiteral($argSql);
                    if (false === $val && 'false' !== strtolower(trim($argSql))) {
                        // false means parse failure (null literal returns null).
                        if (!self::isNullLiteral($argSql)) {
                            $ok = false;
                            break;
                        }
                        $val = null;
                    }
                    $slot = new Variable();
                    self::assignPhpValue($slot, $val);
                    $phpArgs[] = $slot;
                }
                if (!$ok) {
                    $out .= substr($sql, $i, $argsEnd + 1 - $i);
                    $i = $argsEnd + 1;
                    continue;
                }
                if (null !== $entry['closure'] && null !== $entry['callback']->resolveIndirect()->toObject()->closureState) {
                    // already attached
                } elseif (null !== $entry['closure']) {
                    $obj = $entry['callback']->resolveIndirect()->toObject();
                    if (null === $obj->closureState) {
                        $obj->closureState = $entry['closure'];
                    }
                }
                $result = VmCallable::invoke($entry['ctx'], $entry['callback'], ...$phpArgs);
                $out .= self::sqlLiteralFromVariable($result);
                $i = $argsEnd + 1;
                continue;
            }
            $out .= $ch;
            ++$i;
        }

        return $out;
    }

    private static function matchIdentAt(string $sql, int $i, string $name, int $nameLen): bool
    {
        if ($i + $nameLen > \strlen($sql)) {
            return false;
        }
        if (0 !== substr_compare($sql, $name, $i, $nameLen, true)) {
            return false;
        }
        if ($i > 0) {
            $prev = $sql[$i - 1];
            if (ctype_alnum($prev) || '_' === $prev) {
                return false;
            }
        }

        return true;
    }

    private static function skipQuoted(string $sql, int $i): int
    {
        $q = $sql[$i];
        $len = \strlen($sql);
        ++$i;
        while ($i < $len) {
            if ($sql[$i] === $q) {
                if ($i + 1 < $len && $sql[$i + 1] === $q) {
                    $i += 2;
                    continue;
                }

                return $i + 1;
            }
            ++$i;
        }

        return $len;
    }

    private static function findMatchingParen(string $sql, int $openPos): ?int
    {
        $len = \strlen($sql);
        $depth = 0;
        for ($i = $openPos; $i < $len; ++$i) {
            $ch = $sql[$i];
            if ("'" === $ch || '"' === $ch) {
                $i = self::skipQuoted($sql, $i) - 1;
                continue;
            }
            if ('(' === $ch) {
                ++$depth;
            } elseif (')' === $ch) {
                --$depth;
                if (0 === $depth) {
                    return $i;
                }
            }
        }

        return null;
    }

    /** @return list<string>|null */
    private static function parseArgList(string $argsRaw): ?array
    {
        $trim = trim($argsRaw);
        if ('' === $trim) {
            return [];
        }
        $args = [];
        $len = \strlen($argsRaw);
        $start = 0;
        $depth = 0;
        for ($i = 0; $i < $len; ++$i) {
            $ch = $argsRaw[$i];
            if ("'" === $ch || '"' === $ch) {
                $i = self::skipQuoted($argsRaw, $i) - 1;
                continue;
            }
            if ('(' === $ch) {
                ++$depth;
            } elseif (')' === $ch) {
                --$depth;
            } elseif (',' === $ch && 0 === $depth) {
                $args[] = trim(substr($argsRaw, $start, $i - $start));
                $start = $i + 1;
            }
        }
        $args[] = trim(substr($argsRaw, $start));

        return $args;
    }

    /** @return mixed|false false = unparseable */
    private static function evalArgLiteral(string $argSql): mixed
    {
        $s = trim($argSql);
        if (self::isNullLiteral($s)) {
            return null;
        }
        if ('' !== $s && (('"' === $s[0] && str_ends_with($s, '"')) || ("'" === $s[0] && str_ends_with($s, "'")))) {
            $q = $s[0];
            $inner = substr($s, 1, -1);

            return str_replace($q.$q, $q, $inner);
        }
        if (is_numeric($s)) {
            return str_contains($s, '.') || str_contains(strtolower($s), 'e')
                ? (float) $s
                : (int) $s;
        }
        if ('true' === strtolower($s)) {
            return 1;
        }
        if ('false' === strtolower($s)) {
            return 0;
        }

        return false;
    }

    private static function isNullLiteral(string $s): bool
    {
        return 'null' === strtolower(trim($s));
    }

    private static function assignPhpValue(Variable $slot, mixed $value): void
    {
        if (null === $value) {
            $slot->null();
        } elseif (\is_int($value)) {
            $slot->int($value);
        } elseif (\is_float($value)) {
            $slot->float($value);
        } elseif (\is_bool($value)) {
            $slot->bool($value);
        } else {
            $slot->string((string) $value);
        }
    }

    private static function sqlLiteralFromVariable(Variable $var): string
    {
        $resolved = $var->resolveIndirect();

        return match ($resolved->type) {
            Variable::TYPE_NULL => 'NULL',
            Variable::TYPE_BOOLEAN => $resolved->toBool() ? '1' : '0',
            Variable::TYPE_INTEGER => (string) $resolved->toInt(),
            Variable::TYPE_FLOAT => (string) $resolved->toFloat(),
            Variable::TYPE_STRING => VmSqlite3Native::quoteSqlLiteral($resolved->toString()),
            default => 'NULL',
        };
    }
}
