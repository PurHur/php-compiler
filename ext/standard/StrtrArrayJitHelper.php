<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * Lowered into JIT/AOT modules for strtr() array form (#9392, #27056, php-in-PHP).
 *
 * NestedJIT AOT constraints (#27056 / #23912 / #22990):
 * - `$pair[0]`/`$pair[1]` + `(string)` cast — not Variable method stringification (abort)
 * - Drive the subject walk with `isset($subject[$i])` — not a precomputed length
 *   (isset-length was short-by-one under NestedJIT here, dropping the last byte)
 * - `++` only; no `$a[]`; no list-assign; no countdown `--`
 */
final class StrtrArrayJitHelper
{
    public static function strtrArray(string $subject, HashTable $replacePairs): string
    {
        if (!isset($subject[0])) {
            return '';
        }

        $out = '';
        $i = 0;
        $wrote = false;
        while (isset($subject[$i])) {
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
                $flen = 0;
                while (isset($from[$flen])) {
                    ++$flen;
                }
                if ($flen <= $bestLen) {
                    continue;
                }
                $matched = true;
                $j = 0;
                $hi = $i;
                while ($j < $flen) {
                    if (!isset($subject[$hi]) || $subject[$hi] !== $from[$j]) {
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
