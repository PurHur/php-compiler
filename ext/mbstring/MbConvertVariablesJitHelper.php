<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_convert_variables() NestedJIT runtime — string by-ref (#35315 leftover of #4572).
 *
 * Leaf UTF-8 / ISO-8859-1 / ASCII via {@see MbConvertEncodingJitHelper::convertArgv}.
 * Array/object by-ref remain compile-time LogicException until a NestedJIT-safe walk lands.
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_convert_variables)
 */
final class MbConvertVariablesJitHelper
{
    /**
     * Convert one string; returns converted bytes (leaf encodings only).
     */
    public static function convertStringArgv(string $str, string $toEncoding, string $fromCsv): string
    {
        $fromList = self::parseFromCsv($fromCsv);
        foreach ($fromList as $from) {
            if (!self::leafPairSupported($toEncoding, $from)) {
                continue;
            }

            return MbConvertEncodingJitHelper::convertArgv($str, $toEncoding, $from);
        }

        return '';
    }

    /**
     * Detected from-encoding for a successful string convert, or "" on failure.
     */
    public static function detectFromArgv(string $str, string $toEncoding, string $fromCsv): string
    {
        $fromList = self::parseFromCsv($fromCsv);
        if ([] === $fromList) {
            return '';
        }
        foreach ($fromList as $from) {
            if (!self::leafPairSupported($toEncoding, $from)) {
                continue;
            }

            return $from;
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private static function parseFromCsv(string $fromCsv): array
    {
        if ('' === $fromCsv) {
            return [];
        }
        $out = [];
        foreach (explode(',', $fromCsv) as $p) {
            if ('' !== $p) {
                $out[] = $p;
            }
        }

        return $out;
    }

    private static function leafPairSupported(string $to, string $from): bool
    {
        return '' !== MbConvertEncodingJitHelper::convertArgv('a', $to, $from);
    }
}
