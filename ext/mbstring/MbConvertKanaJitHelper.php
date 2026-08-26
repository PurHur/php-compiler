<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_convert_kana() NestedJIT runtime (#34294 leftover of #13099).
 *
 * NestedJIT cannot call {@see KanaConvert::convert} from a frame that received a
 * runtime string parameter (SIGSEGV) — #35193. Encoding is asserted in a separate
 * helper; convert helpers take only string/mode (UTF-8 transform).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_convert_kana)
 */
final class MbConvertKanaJitHelper
{
    /**
     * Int-returning encoding check — NestedJIT ValueError from string-returning helpers
     * SIGSEGVs under thin AOT; int helpers match {@see MbConvertCaseJitHelper::assertEncodingArgv} (#35193).
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
            throw new \ValueError(
                $function.'(): Argument #3 ($encoding) must be a valid encoding, "'.$encoding.'" given'
            );
        }

        return 1;
    }

    /**
     * Explicit $mode — UTF-8 kana transform (encoding already asserted) (#35193).
     */
    public static function convertArgv(string $string, string $mode): string
    {
        return KanaConvert::convert($string, $mode, 'UTF-8');
    }

    /**
     * Omitted $mode → php-src default "KV" (#35193).
     */
    public static function convertDefaultArgv(string $string): string
    {
        return KanaConvert::convert($string, null, 'UTF-8');
    }
}
