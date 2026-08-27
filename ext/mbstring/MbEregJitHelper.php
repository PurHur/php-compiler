<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmPregMatches;
use PHPCompiler\VM\HashTable;

/**
 * mb_ereg*() for compiled JIT/AOT modules (#33811 follow-up #33648/#33655, #34389).
 *
 * SSOT: {@see VmMbstring::eregMatch()} / {@see VmMbstring::eregMatchAnchored()} /
 * {@see VmMbstring::eregReplace()}
 * php-src: ext/mbstring/php_mbregex.c — PHP_FUNCTION(mb_ereg) / mb_ereg_match / mb_ereg_replace
 *
 * {@see self::$lastMatch} pairs match argv with {@see lastRegistersHt()} for &$regs (#33811 / #35297).
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

    /** By-ref $regs after {@see eregMatchArgv} / {@see eregiMatchArgv} (#35297). */
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

    /**
     * mb_ereg_replace() — runtime pattern/replacement/string (#34389 leftover of #33765).
     *
     * @return string|false|null
     */
    public static function eregReplaceArgv(
        string $pattern,
        string $replacement,
        string $string,
        string $options,
        int $hasOptions
    ): string|false|null {
        $opt = 0 !== $hasOptions ? $options : null;

        return VmMbstring::eregReplace($pattern, $replacement, $string, false, $opt);
    }

    /**
     * mb_eregi_replace() — case-insensitive runtime replace (#34389 leftover of #33656).
     *
     * @return string|false|null
     */
    public static function eregiReplaceArgv(
        string $pattern,
        string $replacement,
        string $string,
        string $options,
        int $hasOptions
    ): string|false|null {
        $opt = 0 !== $hasOptions ? $options : null;

        return VmMbstring::eregReplace($pattern, $replacement, $string, true, $opt);
    }

    /**
     * mb_ereg ERE pattern → PCRE delimiter form for preg thin callback bridge (#35335).
     */
    public static function eregToPcrePatternArgv(
        string $pattern,
        string $options,
        int $hasOptions,
        int $caseInsensitive
    ): string {
        $opt = 0 !== $hasOptions ? $options : null;
        $ci = (0 !== $caseInsensitive) || VmMbstring::optionsImplyIgnoreCase($opt);
        $regex = VmMbstring::mbEregRegex($pattern, $ci, $opt);

        return $regex ?? '';
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
