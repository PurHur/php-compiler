<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmCallable;

/**
 * SQLite3::createFunction scalar UDF support (#19862).
 *
 * On PHP ≥ 8.3, registers via sqlite3_create_function_v2 + FFI::callback.
 * On PHP 8.2 (pinned CI), expands registered scalar calls with literal/nested-UDF
 * args in SQL before prepare/exec (issue repro + common app patterns).
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
