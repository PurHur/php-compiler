<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_convert_kana() NestedJIT helpers (#34294 leftover of #13099 / #35193).
 *
 * Encoding gate only — NestedJIT of {@see KanaConvert} fails module verify / SIGSEGVs under
 * thin AOT. Foldable string/mode + runtime encoding: assert here, convert via compile-time
 * {@see KanaConvert} in {@see JitMbConvertKana} (#35193 peer #35151).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_convert_kana)
 */
final class MbConvertKanaJitHelper
{
    /**
     * Int-returning encoding check — NestedJIT ValueError from string-returning helpers
     * SIGSEGVs under thin AOT; int helpers match {@see MbConvertCaseJitHelper::assertEncodingArgv}.
     *
     * Argument #3 ($encoding) for mb_convert_kana.
     */
    public static function assertEncodingArgv(string $encoding, string $function): int
    {
        $ok = 0;
        if ('UTF-8' === $encoding || 'utf-8' === $encoding || 'UTF8' === $encoding || 'utf8' === $encoding) {
            $ok = 1;
        }
        if (
            'ASCII' === $encoding || 'ascii' === $encoding
            || 'US-ASCII' === $encoding || 'us-ascii' === $encoding
        ) {
            $ok = 1;
        }
        if ('8BIT' === $encoding || '8bit' === $encoding || 'BINARY' === $encoding || 'binary' === $encoding) {
            $ok = 1;
        }
        if (0 === $ok) {
            // Concat (not sprintf) — NestedJIT sprintf+throw breaks module verify (#34625).
            throw new \ValueError(
                $function.'(): Argument #3 ($encoding) must be a valid encoding, "'.$encoding.'" given'
            );
        }

        return 1;
    }
}
