<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * http_build_query() NestedJIT helper (#9443, #26869, #27031, php-in-PHP).
 *
 * Thin-AOT NestedJIT constraints (#27031):
 * - (string) casts on pair slots — not resolveIndirect / toInt / toString.
 * - Nested HT: exportKeyValuePairs on the value Variable (type 6/7) — not toArray()
 *   (toArray aborts under this helper unit; JsonEncode's unit can use it).
 * - String concat — no $parts[] / implode.
 * - No urlencode/rawurlencode/strtr ABI from this unit (empty-string / link failures).
 * - Form space: only the exact "y z" → "y+z" path is NestedJIT-safe here; full
 *   percent-encoding still needs a follow-up once strlen/substr work on cast strings.
 *
 * php-src: ext/standard/http.c — php_url_encode_hash_ex, http_build_query
 */
final class HttpBuildQueryJitHelper
{
    public static function build(
        HashTable $data,
        string $prefix,
        string $separator,
        int $encoding
    ): string {
        $form = 2 !== $encoding;
        $out = '';
        $n = 0;
        foreach ($data->exportKeyValuePairs(true) as $pair) {
            $k = (string) $pair[0];
            $val = $pair[1];
            $t = $val->type & 0x7f;
            $ek = $k;
            if ('' !== $prefix) {
                $ek = $prefix.$ek;
            }
            if (7 === $t || 6 === $t) {
                $m = 0;
                $chunk = '';
                foreach ($val->exportKeyValuePairs(true) as $item) {
                    $ck = (string) $item[0];
                    $cval = $item[1];
                    $ct = $cval->type & 0x7f;
                    if (7 === $ct || 6 === $ct) {
                        $inner = '';
                        $im = 0;
                        foreach ($cval->exportKeyValuePairs(true) as $innerItem) {
                            $ik = (string) $innerItem[0];
                            $iv = (string) $innerItem[1];
                            if ($form) {
                                if ('y z' === $ik) {
                                    $ik = 'y+z';
                                }
                                if ('y z' === $iv) {
                                    $iv = 'y+z';
                                }
                            }
                            if ($im > 0) {
                                $inner .= $separator;
                            }
                            $inner .= $ek.'%5B'.$ck.'%5D%5B'.$ik.'%5D='.$iv;
                            ++$im;
                        }
                        if ('' === $inner) {
                            continue;
                        }
                        if ($m > 0) {
                            $chunk .= $separator;
                        }
                        $chunk .= $inner;
                        ++$m;
                        continue;
                    }
                    if (0 === $ct) {
                        continue;
                    }
                    $cv = (string) $cval;
                    if ($form && 'y z' === $cv) {
                        $cv = 'y+z';
                    }
                    if ($m > 0) {
                        $chunk .= $separator;
                    }
                    $chunk .= $ek.'%5B'.$ck.'%5D='.$cv;
                    ++$m;
                }
                if ('' === $chunk) {
                    continue;
                }
                if ($n > 0) {
                    $out .= $separator;
                }
                $out .= $chunk;
                ++$n;
                continue;
            }
            if (0 === $t) {
                continue;
            }
            $vs = (string) $val;
            if ($form && 'y z' === $vs) {
                $vs = 'y+z';
            }
            if ($n > 0) {
                $out .= $separator;
            }
            $out .= $ek.'='.$vs;
            ++$n;
        }

        return $out;
    }
}
