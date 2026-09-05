<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for strtr() two-string form (#9392, #36382, php-in-PHP).
 *
 * NestedJIT AOT constraints (peer {@see StrtrArrayJitHelper} #27056 / #35038):
 * - Self-contained — no cross-class call into ext/standard string helpers (NestedJIT stubs
 *   to the subject / SEGV on constant `$from`/`$to` — #36382 Nyholm
 *   `strtr(strtolower(substr(...)), '_', '-')`)
 * - Drive lengths with `\strlen` — NestedJIT `isset($s[$i])` is always false in this shape
 *   (legacy byteLength-via-isset walk / #35038), so a delegated strtr walk was a no-op
 * - `++` only; no `$a[]` append-assign for the xlat seed; no countdown `--`
 *
 * php-src: ext/standard/string.c — php_strtr() / PHP_FUNCTION(strtr) three-arg form
 */
final class StrtrTwoStringJitHelper
{
    public static function strtrTwoString(string $subject, string $from, string $to): string
    {
        $fromLen = \strlen($from);
        if (0 === $fromLen) {
            return $subject;
        }
        $toLen = \strlen($to);
        $pairLen = $fromLen < $toLen ? $fromLen : $toLen;
        if (0 === $pairLen) {
            return $subject;
        }

        // Single-byte map (Nyholm HTTP_ → header rename) — avoid 256-slot table.
        if (1 === $pairLen) {
            $fromByte = $from[0];
            $toByte = $to[0];
            $subjectLen = \strlen($subject);
            $out = '';
            $i = 0;
            $wrote = false;
            while ($i < $subjectLen) {
                $ch = $subject[$i];
                if ($ch === $fromByte) {
                    $out .= $toByte;
                    $wrote = true;
                } else {
                    $out .= $ch;
                }
                ++$i;
            }
            if ($wrote) {
                return $out;
            }

            return $subject;
        }

        // php_strtr(): unsigned char xlat[256]; map then rewrite.
        $xlat = [];
        $i = 0;
        while ($i < 256) {
            $xlat[$i] = $i;
            ++$i;
        }
        $i = 0;
        while ($i < $pairLen) {
            $xlat[\ord($from[$i])] = \ord($to[$i]);
            ++$i;
        }
        $subjectLen = \strlen($subject);
        $out = '';
        $i = 0;
        while ($i < $subjectLen) {
            $out .= \chr($xlat[\ord($subject[$i])]);
            ++$i;
        }

        return $out;
    }
}
