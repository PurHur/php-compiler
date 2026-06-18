<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * preg_replace() replacement-template expansion (php-src ext/pcre/php_pcre.c).
 *
 * Shared by VmPregNative (VM) and StringPregMatchJit (JIT/AOT).
 */
final class PregReplacementExpand
{
    /**
     * @param mixed $ovector pcre2 ovector pointer (FFI) or list<int> flat [start,end,...]
     */
    public static function expand(string $replacement, mixed $ovector, int $count, string $subject): string
    {
        if (!str_contains($replacement, '$') && !str_contains($replacement, '\\')) {
            return $replacement;
        }

        $out = '';
        $len = \strlen($replacement);
        for ($i = 0; $i < $len; $i++) {
            $ch = $replacement[$i];
            if ('\\' === $ch && $i + 1 < $len) {
                $next = $replacement[$i + 1];
                if (\ctype_digit($next)) {
                    $j = $i + 1;
                    while ($j < $len && \ctype_digit($replacement[$j])) {
                        $j++;
                    }
                    $out .= self::captureGroupText(
                        (int) \substr($replacement, $i + 1, $j - $i - 1),
                        $ovector,
                        $count,
                        $subject
                    );
                    $i = $j - 1;
                    continue;
                }
                $out .= match ($next) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    '$' => '$',
                    '\\' => '\\',
                    default => $next,
                };
                $i++;
                continue;
            }
            if ('$' === $ch && $i + 1 < $len) {
                if ('{' === $replacement[$i + 1]) {
                    $j = $i + 2;
                    while ($j < $len && \ctype_digit($replacement[$j])) {
                        $j++;
                    }
                    if ($j < $len && '}' === $replacement[$j]) {
                        $out .= self::captureGroupText(
                            (int) \substr($replacement, $i + 2, $j - $i - 2),
                            $ovector,
                            $count,
                            $subject
                        );
                        $i = $j;
                        continue;
                    }
                } elseif (\ctype_digit($replacement[$i + 1])) {
                    $j = $i + 1;
                    while ($j < $len && \ctype_digit($replacement[$j])) {
                        $j++;
                    }
                    $out .= self::captureGroupText(
                        (int) \substr($replacement, $i + 1, $j - $i - 1),
                        $ovector,
                        $count,
                        $subject
                    );
                    $i = $j - 1;
                    continue;
                }
            }
            $out .= $ch;
        }

        return $out;
    }

    /**
     * @param mixed $ovector
     */
    private static function captureGroupText(int $idx, mixed $ovector, int $count, string $subject): string
    {
        if ($idx < 0 || $idx >= $count) {
            return '';
        }
        if (\is_array($ovector)) {
            $start = (int) $ovector[$idx * 2];
            $end = (int) $ovector[$idx * 2 + 1];
        } else {
            $start = (int) $ovector[$idx * 2];
            $end = (int) $ovector[$idx * 2 + 1];
        }
        if ($start < 0 || $end < 0) {
            return '';
        }

        return \substr($subject, $start, $end - $start);
    }
}
