<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_convert_variables() NestedJIT runtime — string leaf convert/detect (#35315 leftover #4572).
 *
 * Array/object by-ref use pure LLVM {@see \PHPCompiler\JIT\MbConvertVariablesLlvm}.
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_convert_variables)
 */
final class MbConvertVariablesJitHelper
{
    /**
     * Convert one string; returns converted bytes (leaf encodings only).
     */
    public static function convertStringArgv(
        string $str,
        string $toEncoding,
        string $fromCsv,
        int $packedSubst = 63
    ): string {
        $fromList = self::parseFromCsv($fromCsv);
        if ([] === $fromList) {
            return '';
        }
        if (\count($fromList) > 1) {
            $detected = MbDetectEncodingJitHelper::detectArgv(
                $str,
                self::orderCodesFromList($fromList),
                0
            );
            if ('' === $detected || !self::leafPairSupported($toEncoding, $detected, $packedSubst)) {
                return '';
            }

            return MbConvertEncodingJitHelper::convertArgv($str, $toEncoding, $detected, $packedSubst);
        }

        $from = $fromList[0];
        if (!self::leafPairSupported($toEncoding, $from, $packedSubst)) {
            return '';
        }

        return MbConvertEncodingJitHelper::convertArgv($str, $toEncoding, $from, $packedSubst);
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
        if (\count($fromList) > 1) {
            $detected = MbDetectEncodingJitHelper::detectArgv(
                $str,
                self::orderCodesFromList($fromList),
                0
            );
            if ('' === $detected || !self::leafPairSupported($toEncoding, $detected)) {
                return '';
            }

            return $detected;
        }

        $from = $fromList[0];
        if (!self::leafPairSupported($toEncoding, $from)) {
            return '';
        }

        return $from;
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

    private static function leafPairSupported(string $to, string $from, int $packedSubst = 63): bool
    {
        return '' !== MbConvertEncodingJitHelper::convertArgv('a', $to, $from, $packedSubst);
    }

    /**
     * @param list<string> $fromList
     */
    private static function orderCodesFromList(array $fromList): string
    {
        $codes = '';
        foreach ($fromList as $from) {
            $codes .= self::orderCodeForEncoding($from);
        }

        return $codes;
    }

    private static function orderCodeForEncoding(string $encoding): string
    {
        $e = strtoupper($encoding);
        if ('UTF8' === $e || 'UTF-8' === $e) {
            return 'U';
        }
        if ('ASCII' === $e || 'US-ASCII' === $e) {
            return 'A';
        }
        if ('LATIN1' === $e || 'LATIN-1' === $e || 'ISO-8859-1' === $e) {
            return 'L';
        }
        if ('8BIT' === $e || 'BINARY' === $e) {
            return 'B';
        }

        return '';
    }
}
