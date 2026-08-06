<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * User-script AOT parse_ini_string native HT materializer (#26909, php-in-PHP).
 *
 * NestedJIT-safe NORMAL/flat subset: char-scan + concat only (no explode/preg/array
 * returns/by-ref). process_sections!=0 or scanner_mode!=NORMAL returns 0 so the
 * call site keeps compile-time materialization for those modes.
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(parse_ini_string)
 */
final class ParseIniNativeJitHelper
{
    /**
     * Parse $ini into $destPtr. Returns 1 on success, 0 on failure / unsupported mode.
     */
    public static function parseIntoNative(
        int $destPtr,
        string $ini,
        int $processSections,
        int $scannerMode
    ): int {
        if ($destPtr <= 0) {
            return 0;
        }
        // NestedJIT path covers NORMAL + flat only (#26909 done-when).
        if (0 !== $processSections || 0 !== $scannerMode) {
            return 0;
        }
        $len = 0;
        while (isset($ini[$len])) {
            ++$len;
        }
        $i = 0;
        while ($i < $len) {
            while ($i < $len) {
                $c = $ini[$i];
                if (' ' !== $c && "\t" !== $c) {
                    break;
                }
                ++$i;
            }
            if ($i >= $len) {
                break;
            }
            $c0 = $ini[$i];
            if ("\n" === $c0 || "\r" === $c0) {
                if ("\r" === $c0 && $i + 1 < $len && "\n" === $ini[$i + 1]) {
                    ++$i;
                }
                ++$i;
                continue;
            }
            if (';' === $c0 || '#' === $c0) {
                while ($i < $len) {
                    $c = $ini[$i];
                    if ("\n" === $c || "\r" === $c) {
                        break;
                    }
                    ++$i;
                }
                continue;
            }
            if ('[' === $c0) {
                while ($i < $len) {
                    $c = $ini[$i];
                    if ("\n" === $c || "\r" === $c) {
                        break;
                    }
                    ++$i;
                }
                continue;
            }
            $key = '';
            while ($i < $len) {
                $c = $ini[$i];
                if ('=' === $c || "\n" === $c || "\r" === $c) {
                    break;
                }
                $key .= $c;
                ++$i;
            }
            if ($i >= $len || '=' !== $ini[$i]) {
                while ($i < $len) {
                    $c = $ini[$i];
                    if ("\n" === $c || "\r" === $c) {
                        break;
                    }
                    ++$i;
                }
                continue;
            }
            ++$i;
            $keyOut = self::rtrimWs($key);
            if ('' === $keyOut) {
                return 0;
            }
            $klower = strtolower($keyOut);
            if ('on' === $klower || 'yes' === $klower || 'true' === $klower
                || 'off' === $klower || 'no' === $klower || 'false' === $klower
                || 'none' === $klower || 'null' === $klower) {
                return 0;
            }
            while ($i < $len) {
                $c = $ini[$i];
                if (' ' !== $c && "\t" !== $c) {
                    break;
                }
                ++$i;
            }
            $val = '';
            while ($i < $len) {
                $c = $ini[$i];
                if ("\n" === $c || "\r" === $c) {
                    break;
                }
                if (';' === $c || '#' === $c) {
                    break;
                }
                $val .= $c;
                ++$i;
            }
            $valOut = self::normalizeNormal(self::rtrimWs($val));
            phpc_native_ht_set_string_key($destPtr, $keyOut, $valOut);
            if ($i < $len && "\r" === $ini[$i] && $i + 1 < $len && "\n" === $ini[$i + 1]) {
                $i += 2;
            } elseif ($i < $len && ("\n" === $ini[$i] || "\r" === $ini[$i])) {
                ++$i;
            }
        }

        return 1;
    }

    private static function rtrimWs(string $s): string
    {
        $len = 0;
        while (isset($s[$len])) {
            ++$len;
        }
        $e = $len;
        while ($e > 0) {
            $c = $s[$e - 1];
            if (' ' !== $c && "\t" !== $c) {
                break;
            }
            --$e;
        }
        if ($e === $len) {
            return $s;
        }
        $out = '';
        $i = 0;
        while ($i < $e) {
            $out .= $s[$i];
            ++$i;
        }

        return $out;
    }

    private static function normalizeNormal(string $raw): string
    {
        $lower = strtolower($raw);
        if ('null' === $lower || 'none' === $lower || '""' === $lower || "''" === $lower) {
            return '';
        }
        if ('yes' === $lower || 'on' === $lower || 'true' === $lower) {
            return '1';
        }
        if ('no' === $lower || 'off' === $lower || 'false' === $lower) {
            return '';
        }

        return $raw;
    }
}
