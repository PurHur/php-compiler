<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * http_build_query() for compiled JIT/AOT modules (#9443, #26869, php-in-PHP).
 *
 * NestedJIT user-script AOT constraints (#26869):
 * - Do not call VmHttpBuildQuery (list-destructure + TYPE_INDIRECT nested arrays).
 * - Do not call the string urlencode/rawurlencode ABI from this helper.
 * - Outer table: exportKeyValuePairs; nested child: iterateKeyed.
 * - Avoid [$k,$v] destructure — use $pair[0]/$pair[1].
 * - Percent-encoder must not use string/array digit indexing (NestedJIT segfault).
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
        $parts = [];
        foreach ($data->exportKeyValuePairs(true) as $pair) {
            $key = $pair[0]->resolveIndirect();
            $val = $pair[1]->resolveIndirect();
            $isInt = 1 === $key->type;
            $ks = $isInt ? (string) $key->toInt() : $key->toString();
            $ek = $isInt ? $ks : self::percentEncode($ks, $form);
            if ('' !== $prefix && $isInt) {
                $ek = $prefix.$ek;
            }
            if (6 === $val->type || 7 === $val->type) {
                $child = $val->toArray();
                foreach ($child->iterateKeyed(true) as $item) {
                    $ck = $item[0]->resolveIndirect();
                    $cv = $item[1]->resolveIndirect();
                    $cisInt = 1 === $ck->type;
                    $cks = $cisInt ? (string) $ck->toInt() : $ck->toString();
                    $cek = $cisInt ? $cks : self::percentEncode($cks, $form);
                    if (0 === $cv->type) {
                        continue;
                    }
                    if (1 === $cv->type) {
                        $cvs = (string) $cv->toInt();
                    } elseif (3 === $cv->type) {
                        $cvs = $cv->toBool() ? '1' : '0';
                    } elseif (2 === $cv->type) {
                        $cvs = (string) $cv->toFloat();
                    } else {
                        $cvs = self::percentEncode($cv->toString(), $form);
                    }
                    $parts[] = $ek.'%5B'.$cek.'%5D='.$cvs;
                }
                continue;
            }
            if (0 === $val->type) {
                continue;
            }
            if (1 === $val->type) {
                $vs = (string) $val->toInt();
            } elseif (3 === $val->type) {
                $vs = $val->toBool() ? '1' : '0';
            } elseif (2 === $val->type) {
                $vs = (string) $val->toFloat();
            } else {
                $vs = self::percentEncode($val->toString(), $form);
            }
            $parts[] = $ek.'='.$vs;
        }

        return \implode($separator, $parts);
    }

    /**
     * php-src php_url_encode — formEncoding true → space as '+'; false → RFC3986 (~ unescaped).
     */
    public static function percentEncode(string $data, bool $formEncoding): string
    {
        $out = '';
        $len = 0;
        while (isset($data[$len])) {
            ++$len;
        }
        for ($i = 0; $i < $len; ++$i) {
            $ord = \ord($data[$i]);
            if ($ord >= 48 && $ord <= 57) {
                $out .= $data[$i];
                continue;
            }
            if ($ord >= 65 && $ord <= 90) {
                $out .= $data[$i];
                continue;
            }
            if ($ord >= 97 && $ord <= 122) {
                $out .= $data[$i];
                continue;
            }
            if (45 === $ord) {
                $out .= '-';
                continue;
            }
            if (95 === $ord) {
                $out .= '_';
                continue;
            }
            if (46 === $ord) {
                $out .= '.';
                continue;
            }
            if (126 === $ord) {
                if ($formEncoding) {
                    $out .= '%7E';
                } else {
                    $out .= '~';
                }
                continue;
            }
            if (32 === $ord) {
                if ($formEncoding) {
                    $out .= '+';
                } else {
                    $out .= '%20';
                }
                continue;
            }
            $out .= self::pct($ord);
        }

        return $out;
    }

    public static function pct(int $ord): string
    {
        return '%'.self::h($ord >> 4).self::h($ord & 15);
    }

    public static function h(int $n): string
    {
        if ($n <= 0) {
            return '0';
        }
        if (1 === $n) {
            return '1';
        }
        if (2 === $n) {
            return '2';
        }
        if (3 === $n) {
            return '3';
        }
        if (4 === $n) {
            return '4';
        }
        if (5 === $n) {
            return '5';
        }
        if (6 === $n) {
            return '6';
        }
        if (7 === $n) {
            return '7';
        }
        if (8 === $n) {
            return '8';
        }
        if (9 === $n) {
            return '9';
        }
        if (10 === $n) {
            return 'A';
        }
        if (11 === $n) {
            return 'B';
        }
        if (12 === $n) {
            return 'C';
        }
        if (13 === $n) {
            return 'D';
        }
        if (14 === $n) {
            return 'E';
        }

        return 'F';
    }
}
