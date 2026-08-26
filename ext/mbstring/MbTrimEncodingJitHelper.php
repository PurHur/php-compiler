<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_trim encoding NestedJIT assert — separate unit from {@see MbTrimJitHelper}
 * so ValueError leaf does not share a module with trim bodies (#35199 / #34379).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_trim) Argument #3
 */
final class MbTrimEncodingJitHelper
{
    /**
     * Int-returning encoding check — NestedJIT ValueError from string-returning helpers
     * SIGSEGVs under thin AOT; int helpers match {@see MbScrubJitHelper::assertEncodingArgv} (#35199).
     *
     * Argument #3 ($encoding) for mb_trim / mb_ltrim / mb_rtrim.
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
}
