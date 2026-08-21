<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * str_getcsv() NestedJIT helper for thin AOT (#27069, #33334, php-in-PHP).
 *
 * NestedJIT (#33334): a field loop that mixes quoted vs plain arms miscompiles
 * enclosure detection (`a,"b,c",d` splits on the inner comma). Unroll peels with
 * the probe-proven flat shape. No compound `||` (#28716). Doubled-enclosure and
 * proprietary escape remain VM/CsvJitHelper SSOT gaps under this NestedJIT path.
 *
 * Semantics SSOT: {@see VmCsv::parseLine()} / php-src ext/standard/file.c php_fgetcsv.
 */
final class CsvStrGetcsvJitHelper
{
    public static function stripLineTerminatorsArgv(string $line): string
    {
        $len = 0;
        while (isset($line[$len])) {
            ++$len;
        }
        while ($len > 0) {
            $c = $line[$len - 1];
            if ("\n" === $c) {
                --$len;
            } else {
                if ("\r" === $c) {
                    --$len;
                } else {
                    break;
                }
            }
        }
        if (0 === $len) {
            return '';
        }
        $out = '';
        $i = 0;
        while ($i < $len) {
            $out .= $line[$i];
            ++$i;
        }

        return $out;
    }

    /**
     * @return list<string|null>
     */
    public static function strGetcsvArgv(
        string $input,
        string $separator,
        string $enclosure,
        string $escape,
    ): array {
        $len = 0;
        while (isset($input[$len])) {
            ++$len;
        }
        while ($len > 0) {
            $c = $input[$len - 1];
            if ("\n" === $c) {
                --$len;
            } else {
                if ("\r" === $c) {
                    --$len;
                } else {
                    break;
                }
            }
        }
        if (0 === $len) {
            return [];
        }

        $delim = ',';
        if (isset($separator[0])) {
            $delim = $separator[0];
        }
        unset($enclosure, $escape);

        $fields = [];
        $n = 0;
        $i = 0;

        // Eight unrolled peels — do not wrap in a field loop (#33334).

        // peel 0
        if ($i < $len) {
            if ($input[$i] === '"') {
                ++$i;
                $field = '';
                while ($i < $len) {
                    $c = $input[$i];
                    if ($c === '"') {
                        ++$i;
                        break;
                    }
                    $field .= $c;
                    ++$i;
                }
                while ($i < $len && $input[$i] !== $delim) {
                    $field .= $input[$i];
                    ++$i;
                }
                $fields[$n] = $field;
                ++$n;
            } else {
                $field = '';
                while ($i < $len && $input[$i] !== $delim) {
                    $field .= $input[$i];
                    ++$i;
                }
                $fields[$n] = $field;
                ++$n;
            }
            if ($i < $len && $input[$i] === $delim) {
                ++$i;
                if ($i >= $len) {
                    $fields[$n] = '';
                    ++$n;
                }
            }
        }

        // peel 1
        if ($i < $len) {
            if ($input[$i] === '"') {
                ++$i;
                $field = '';
                while ($i < $len) {
                    $c = $input[$i];
                    if ($c === '"') {
                        ++$i;
                        break;
                    }
                    $field .= $c;
                    ++$i;
                }
                while ($i < $len && $input[$i] !== $delim) {
                    $field .= $input[$i];
                    ++$i;
                }
                $fields[$n] = $field;
                ++$n;
            } else {
                $field = '';
                while ($i < $len && $input[$i] !== $delim) {
                    $field .= $input[$i];
                    ++$i;
                }
                $fields[$n] = $field;
                ++$n;
            }
            if ($i < $len && $input[$i] === $delim) {
                ++$i;
                if ($i >= $len) {
                    $fields[$n] = '';
                    ++$n;
                }
            }
        }

        // peel 2
        if ($i < $len) {
            if ($input[$i] === '"') {
                ++$i;
                $field = '';
                while ($i < $len) {
                    $c = $input[$i];
                    if ($c === '"') {
                        ++$i;
                        break;
                    }
                    $field .= $c;
                    ++$i;
                }
                while ($i < $len && $input[$i] !== $delim) {
                    $field .= $input[$i];
                    ++$i;
                }
                $fields[$n] = $field;
                ++$n;
            } else {
                $field = '';
                while ($i < $len && $input[$i] !== $delim) {
                    $field .= $input[$i];
                    ++$i;
                }
                $fields[$n] = $field;
                ++$n;
            }
            if ($i < $len && $input[$i] === $delim) {
                ++$i;
                if ($i >= $len) {
                    $fields[$n] = '';
                    ++$n;
                }
            }
        }

        // peel 3
        if ($i < $len) {
            if ($input[$i] === '"') {
                ++$i;
                $field = '';
                while ($i < $len) {
                    $c = $input[$i];
                    if ($c === '"') {
                        ++$i;
                        break;
                    }
                    $field .= $c;
                    ++$i;
                }
                while ($i < $len && $input[$i] !== $delim) {
                    $field .= $input[$i];
                    ++$i;
                }
                $fields[$n] = $field;
                ++$n;
            } else {
                $field = '';
                while ($i < $len && $input[$i] !== $delim) {
                    $field .= $input[$i];
                    ++$i;
                }
                $fields[$n] = $field;
                ++$n;
            }
            if ($i < $len && $input[$i] === $delim) {
                ++$i;
                if ($i >= $len) {
                    $fields[$n] = '';
                    ++$n;
                }
            }
        }

        // peel 4
        if ($i < $len) {
            if ($input[$i] === '"') {
                ++$i;
                $field = '';
                while ($i < $len) {
                    $c = $input[$i];
                    if ($c === '"') {
                        ++$i;
                        break;
                    }
                    $field .= $c;
                    ++$i;
                }
                while ($i < $len && $input[$i] !== $delim) {
                    $field .= $input[$i];
                    ++$i;
                }
                $fields[$n] = $field;
                ++$n;
            } else {
                $field = '';
                while ($i < $len && $input[$i] !== $delim) {
                    $field .= $input[$i];
                    ++$i;
                }
                $fields[$n] = $field;
                ++$n;
            }
            if ($i < $len && $input[$i] === $delim) {
                ++$i;
                if ($i >= $len) {
                    $fields[$n] = '';
                    ++$n;
                }
            }
        }

        // peel 5
        if ($i < $len) {
            if ($input[$i] === '"') {
                ++$i;
                $field = '';
                while ($i < $len) {
                    $c = $input[$i];
                    if ($c === '"') {
                        ++$i;
                        break;
                    }
                    $field .= $c;
                    ++$i;
                }
                while ($i < $len && $input[$i] !== $delim) {
                    $field .= $input[$i];
                    ++$i;
                }
                $fields[$n] = $field;
                ++$n;
            } else {
                $field = '';
                while ($i < $len && $input[$i] !== $delim) {
                    $field .= $input[$i];
                    ++$i;
                }
                $fields[$n] = $field;
                ++$n;
            }
            if ($i < $len && $input[$i] === $delim) {
                ++$i;
                if ($i >= $len) {
                    $fields[$n] = '';
                    ++$n;
                }
            }
        }

        // peel 6
        if ($i < $len) {
            if ($input[$i] === '"') {
                ++$i;
                $field = '';
                while ($i < $len) {
                    $c = $input[$i];
                    if ($c === '"') {
                        ++$i;
                        break;
                    }
                    $field .= $c;
                    ++$i;
                }
                while ($i < $len && $input[$i] !== $delim) {
                    $field .= $input[$i];
                    ++$i;
                }
                $fields[$n] = $field;
                ++$n;
            } else {
                $field = '';
                while ($i < $len && $input[$i] !== $delim) {
                    $field .= $input[$i];
                    ++$i;
                }
                $fields[$n] = $field;
                ++$n;
            }
            if ($i < $len && $input[$i] === $delim) {
                ++$i;
                if ($i >= $len) {
                    $fields[$n] = '';
                    ++$n;
                }
            }
        }

        // peel 7
        if ($i < $len) {
            if ($input[$i] === '"') {
                ++$i;
                $field = '';
                while ($i < $len) {
                    $c = $input[$i];
                    if ($c === '"') {
                        ++$i;
                        break;
                    }
                    $field .= $c;
                    ++$i;
                }
                while ($i < $len && $input[$i] !== $delim) {
                    $field .= $input[$i];
                    ++$i;
                }
                $fields[$n] = $field;
                ++$n;
            } else {
                $field = '';
                while ($i < $len && $input[$i] !== $delim) {
                    $field .= $input[$i];
                    ++$i;
                }
                $fields[$n] = $field;
                ++$n;
            }
        }

        return $fields;
    }
}
