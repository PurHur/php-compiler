<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmPregMatches;
use PHPCompiler\VM\HashTable;

/**
 * mb_ereg*() for compiled JIT/AOT modules (#33811 follow-up #33648/#33655, php-in-PHP).
 *
 * SSOT: {@see VmMbstring::eregMatch()} / {@see VmMbstring::eregMatchAnchored()}
 * php-src: ext/mbstring/php_mbregex.c — PHP_FUNCTION(mb_ereg) / mb_ereg_match
 *
 * {@see self::$lastMatch} pairs match argv with {@see lastRegistersHt()} for future &$regs (#33811).
 */
final class MbEregJitHelper
{
    /** @var array{matched: bool, registers: array<int, string>}|null */
    private static ?array $lastMatch = null;

    public static function eregMatchArgv(string $pattern, string $string): int
    {
        return self::matchArgv($pattern, $string, false, 'mb_ereg');
    }

    public static function eregiMatchArgv(string $pattern, string $string): int
    {
        return self::matchArgv($pattern, $string, true, 'mb_eregi');
    }

    /** Reserved for &$regs lowering once by-ref FUNCCALL IR is fixed (#33811). */
    public static function lastRegistersHt(): HashTable
    {
        $out = self::$lastMatch ?? ['matched' => false, 'registers' => []];
        if ($out['matched']) {
            return VmPregMatches::hostMatchesToHashTable($out['registers'], 0);
        }

        return new HashTable();
    }

    public static function matchAnchoredArgv(
        string $pattern,
        string $string,
        string $options,
        int $hasOptions
    ): int {
        if ('' === $pattern) {
            throw new \ValueError('mb_ereg_match(): Argument #1 ($pattern) must not be empty');
        }
        $opt = 0 !== $hasOptions ? $options : null;

        return VmMbstring::eregMatchAnchored($pattern, $string, $opt) ? 1 : 0;
    }

    private static function matchArgv(
        string $pattern,
        string $string,
        bool $caseInsensitive,
        string $function
    ): int {
        if ('' === $pattern) {
            throw new \ValueError(sprintf(
                '%s(): Argument #1 ($pattern) must not be empty',
                $function
            ));
        }
        self::$lastMatch = VmMbstring::eregMatch($pattern, $string, $caseInsensitive);

        return self::$lastMatch['matched'] ? 1 : 0;
    }
}
