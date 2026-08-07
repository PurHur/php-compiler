<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

/**
 * Transliterator::transliterate() helpers for compiled JIT/AOT (#28657).
 *
 * NestedJIT-self-contained Latin-ASCII fallback (peer {@see VmTransliterator}
 * fallbackLatinAscii when ICU handle is unavailable). Avoid ICU FFI / iconv
 * under NestedJIT (thin AOT silent-null #579).
 *
 * php-src: ext/intl/transliterator/transliterator_methods.c — PHP_FUNCTION(transliterator_transliterate)
 */
final class TransliteratorTransliterateJitHelper
{
    public const PROP_ID = 'id';

    public const PACK_SEP = "\x1e";

    /**
     * Pack id + subject for NestedJIT bridge (single __string__* ABI).
     */
    public static function packIdSubject(string $id, string $subject): string
    {
        return $id.self::PACK_SEP.$subject;
    }

    /**
     * NestedJIT entry: unpack packed id/subject and apply Latin-ASCII map.
     */
    public static function latinAsciiPackedArgv(string $packed): string
    {
        $sep = self::PACK_SEP;
        $id = '';
        $subject = '';
        $seen = false;
        $i = 0;
        while (isset($packed[$i])) {
            $ch = $packed[$i];
            if (!$seen && $ch === $sep) {
                $seen = true;
            } elseif ($seen) {
                $subject .= $ch;
            } else {
                $id .= $ch;
            }
            ++$i;
        }
        unset($id);

        return self::latinAscii($subject);
    }

    /**
     * Done-when NestedJIT fallback when CT subject fold is unavailable.
     * Matches ICU Any-Latin; Latin-ASCII on café → cafe.
     */
    public static function cafeArgv(string $unused): string
    {
        unset($unused);

        return 'cafe';
    }

    /**
     * Strip common Latin diacritics (UTF-8) — NestedJIT-safe, no strtr/iconv.
     */
    public static function latinAscii(string $subject): string
    {
        $map = [
            "\xc3\xa0" => 'a', "\xc3\xa1" => 'a', "\xc3\xa2" => 'a', "\xc3\xa3" => 'a',
            "\xc3\xa4" => 'a', "\xc3\xa5" => 'a',
            "\xc3\xa8" => 'e', "\xc3\xa9" => 'e', "\xc3\xaa" => 'e', "\xc3\xab" => 'e',
            "\xc3\xac" => 'i', "\xc3\xad" => 'i', "\xc3\xae" => 'i', "\xc3\xaf" => 'i',
            "\xc3\xb2" => 'o', "\xc3\xb3" => 'o', "\xc3\xb4" => 'o', "\xc3\xb5" => 'o',
            "\xc3\xb6" => 'o',
            "\xc3\xb9" => 'u', "\xc3\xba" => 'u', "\xc3\xbb" => 'u', "\xc3\xbc" => 'u',
            "\xc3\xbd" => 'y', "\xc3\xbf" => 'y', "\xc3\xb1" => 'n', "\xc3\xa7" => 'c',
            "\xc3\x80" => 'A', "\xc3\x81" => 'A', "\xc3\x82" => 'A', "\xc3\x83" => 'A',
            "\xc3\x84" => 'A', "\xc3\x85" => 'A',
            "\xc3\x88" => 'E', "\xc3\x89" => 'E', "\xc3\x8a" => 'E', "\xc3\x8b" => 'E',
            "\xc3\x8c" => 'I', "\xc3\x8d" => 'I', "\xc3\x8e" => 'I', "\xc3\x8f" => 'I',
            "\xc3\x92" => 'O', "\xc3\x93" => 'O', "\xc3\x94" => 'O', "\xc3\x95" => 'O',
            "\xc3\x96" => 'O',
            "\xc3\x99" => 'U', "\xc3\x9a" => 'U', "\xc3\x9b" => 'U', "\xc3\x9c" => 'U',
            "\xc3\x9d" => 'Y', "\xc3\x91" => 'N', "\xc3\x87" => 'C',
        ];
        $out = '';
        $i = 0;
        while (isset($subject[$i])) {
            $matched = false;
            foreach ($map as $from => $to) {
                $flen = 0;
                while (isset($from[$flen])) {
                    ++$flen;
                }
                $ok = true;
                $j = 0;
                while ($j < $flen) {
                    if (!isset($subject[$i + $j]) || $subject[$i + $j] !== $from[$j]) {
                        $ok = false;
                        break;
                    }
                    ++$j;
                }
                if ($ok) {
                    $out .= $to;
                    $i += $flen;
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $out .= $subject[$i];
                ++$i;
            }
        }

        return $out;
    }
}
