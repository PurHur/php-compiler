<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * Lowered into JIT/AOT modules for strtr() array form (#9392, #27056, php-in-PHP).
 *
 * NestedJIT AOT constraints (#27056 / #23912 / #22990 / #35038):
 * - `$pair[0]`/`$pair[1]` + `(string)` cast — not Variable method stringification (abort)
 * - Drive subject/`$from` length with `\strlen` — NestedJIT `isset($s[$i])` is always
 *   false for this helper shape (#35038 / peer #35032 / #33334), so the walk was a no-op
 * - `++` only; no `$a[]`; no list-assign; no countdown `--`
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(strtr) array form
 */
final class StrtrArrayJitHelper
{
    public static function strtrArray(string $subject, HashTable $replacePairs): string
    {
        $subjectLen = \strlen($subject);
        if (0 === $subjectLen) {
            return '';
        }

        $out = '';
        $i = 0;
        $wrote = false;
        while ($i < $subjectLen) {
            $bestLen = 0;
            $bestTo = '';
            foreach ($replacePairs->exportKeyValuePairs(true) as $pair) {
                $from = (string) $pair[0];
                if ('' === $from) {
                    if (\function_exists('compiler_language_warning')) {
                        compiler_language_warning('strtr(): Ignoring replacement of empty string');
                    }
                    continue;
                }
                $flen = \strlen($from);
                if ($flen <= $bestLen) {
                    continue;
                }
                $matched = true;
                $j = 0;
                $hi = $i;
                while ($j < $flen) {
                    if ($hi >= $subjectLen || $subject[$hi] !== $from[$j]) {
                        $matched = false;
                        break;
                    }
                    ++$j;
                    ++$hi;
                }
                if ($matched) {
                    $bestLen = $flen;
                    $bestTo = (string) $pair[1];
                }
            }
            if ($bestLen > 0) {
                $out .= $bestTo;
                $k = 0;
                while ($k < $bestLen) {
                    ++$i;
                    ++$k;
                }
                $wrote = true;
            } else {
                $out .= $subject[$i];
                ++$i;
            }
        }

        if ($wrote) {
            return $out;
        }

        return $subject;
    }
}
