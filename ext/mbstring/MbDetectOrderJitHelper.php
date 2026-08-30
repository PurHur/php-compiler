<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_detect_order() NestedJIT CSV parse (#35280 / #35856).
 *
 * Returns canonical comma-joined encodings (exploded in {@see JitMbDetectOrder}).
 * Mutable order lives in module global {@see MbDetectOrderRuntime}.
 * Iteration mirrors {@see MbDetectEncodingJitHelper} (strlen + for) — isset length loops
 * hang under thin AOT NestedJIT HELPER_O=0 (#35856).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_detect_order)
 */
final class MbDetectOrderJitHelper
{
    public const JOIN_DELIM = ',';

    /**
     * Parse runtime CSV setter; throws ValueError when invalid (#35280).
     */
    public static function parseOrderArgv(string $csv): string
    {
        $order = self::parseCsv($csv);
        if ([] === $order) {
            throw new \ValueError(
                'mb_detect_order(): Argument #1 ($encoding) must specify at least one encoding'
            );
        }

        return self::joinOrder($order);
    }

    /**
     * @return list<string>
     */
    private static function parseCsv(string $csv): array
    {
        $order = [];
        $part = '';
        $len = \strlen($csv);
        for ($i = 0; $i <= $len; ++$i) {
            $atEnd = ($i === $len);
            $ch = $atEnd ? ',' : $csv[$i];
            if (',' === $ch) {
                $piece = self::trimAscii($part);
                if ('' !== $piece) {
                    $canonical = self::resolveEncoding($piece);
                    if (null === $canonical) {
                        throw new \ValueError(
                            'mb_detect_order(): Argument #1 ($encoding) contains invalid encoding "'.$piece.'"'
                        );
                    }
                    $order[] = $canonical;
                }
                $part = '';
            } else {
                $part .= $ch;
            }
        }

        return $order;
    }

    /**
     * @param list<string> $order
     */
    private static function joinOrder(array $order): string
    {
        $out = '';
        foreach ($order as $i => $enc) {
            if ($i > 0) {
                $out .= self::JOIN_DELIM;
            }
            $out .= $enc;
        }

        return $out;
    }

    private static function trimAscii(string $s): string
    {
        $len = \strlen($s);
        $start = 0;
        while ($start < $len && (' ' === $s[$start] || "\t" === $s[$start])) {
            ++$start;
        }
        $end = $len;
        while ($end > $start) {
            $ch = $s[$end - 1];
            if (' ' !== $ch && "\t" !== $ch) {
                break;
            }
            --$end;
        }
        if ($start >= $end) {
            return '';
        }
        $out = '';
        for ($i = $start; $i < $end; ++$i) {
            $out .= $s[$i];
        }

        return $out;
    }

    private static function resolveEncoding(string $encoding): ?string
    {
        if (
            'UTF-8' === $encoding || 'utf-8' === $encoding
            || 'UTF8' === $encoding || 'utf8' === $encoding
        ) {
            return 'UTF-8';
        }
        if (
            'ASCII' === $encoding || 'ascii' === $encoding
            || 'US-ASCII' === $encoding || 'us-ascii' === $encoding
        ) {
            return 'ASCII';
        }
        if (
            'ISO-8859-1' === $encoding || 'iso-8859-1' === $encoding
            || 'latin1' === $encoding || 'LATIN1' === $encoding
            || 'ISO8859-1' === $encoding || 'ISO88591' === $encoding
        ) {
            return 'ISO-8859-1';
        }
        if (
            'SJIS' === $encoding || 'sjis' === $encoding
            || 'Shift_JIS' === $encoding || 'shift_jis' === $encoding
            || 'SHIFT-JIS' === $encoding
        ) {
            return 'SJIS';
        }
        if (
            'EUC-JP' === $encoding || 'euc-jp' === $encoding
            || 'EUC_JP' === $encoding || 'eucJP' === $encoding
        ) {
            return 'EUC-JP';
        }
        if (
            '8BIT' === $encoding || '8bit' === $encoding
            || 'BINARY' === $encoding || 'binary' === $encoding
        ) {
            return '8BIT';
        }

        return null;
    }
}
