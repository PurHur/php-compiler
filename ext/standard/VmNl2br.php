<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * nl2br() NestedJIT/AOT SSOT (#30813).
 *
 * Peer {@see VmChunkSplit} / {@see VmConvertUu}: NestedJIT-bundle with
 * {@see Nl2brJitHelper}. Use strlen/substr/ord — not `$s[$i]` string compares.
 * Prefer recursive offset walk (thin AOT NestedJIT hung or SIGSEGV'd on
 * mutating/`"\r"`-literal pair loops — peer #30859).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(nl2br)
 */
final class VmNl2br
{
    /**
     * @param int $useXhtml 0/1 — avoid NestedJIT bool ABI (#30812 peer)
     */
    public static function nl2br(string $string, int $useXhtml): string
    {
        $br = 0 !== $useXhtml ? '<br />' : '<br>';
        $len = \strlen($string);
        if (0 === $len) {
            return '';
        }
        // Fast path: no CR/LF — avoid NestedJIT recursion entirely.
        if (false === \strpos($string, "\n") && false === \strpos($string, "\r")) {
            return $string;
        }

        return self::buildFrom($string, 0, $len, $br);
    }

    /**
     * Recursive offset walk — NestedJIT-safe vs mutating loop index (#30859).
     * CR=13 / LF=10 via ord() — string `"\r"` compares SIGSEGV'd under NestedJIT AOT.
     */
    private static function buildFrom(string $string, int $i, int $len, string $br): string
    {
        if ($i >= $len) {
            return '';
        }
        $ch = \substr($string, $i, 1);
        $ord = \ord($ch);
        if (13 === $ord) {
            $out = $br.$ch;
            $next = $i + 1;
            if ($next < $len && 10 === \ord(\substr($string, $next, 1))) {
                $out = $out.\substr($string, $next, 1);
                $next = $next + 1;
            }

            return $out.self::buildFrom($string, $next, $len, $br);
        }
        if (10 === $ord) {
            $out = $br.$ch;
            $next = $i + 1;
            if ($next < $len && 13 === \ord(\substr($string, $next, 1))) {
                $out = $out.\substr($string, $next, 1);
                $next = $next + 1;
            }

            return $out.self::buildFrom($string, $next, $len, $br);
        }

        return $ch.self::buildFrom($string, $i + 1, $len, $br);
    }
}
