<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

/**
 * Normalizer::normalize() / normalizer_normalize() for compiled JIT/AOT (#28654).
 *
 * NestedJIT-self-contained via {@see UnicodeCanonical} (must be in the same
 * {@see \PHPCompiler\JIT\JitVmHelperLink::ensureCompiledBundle} — solo helper
 * NestedJIT leaves unbound class methods as ExternalMethod null #579).
 *
 * php-src: ext/intl/normalizer/normalizer_normalize.c — PHP_FUNCTION(normalizer_normalize)
 */
final class NormalizerNormalizeJitHelper
{
    public static function normalizeArgv(string $input, int $form): string
    {
        // Keep NestedJIT free of Frame/IntlError (peer NumberFormatterFormatJitHelper).
        if (4 === $form || 8 === $form) {
            return UnicodeCanonical::normalizeNfd($input);
        }
        if (16 === $form || 32 === $form) {
            return UnicodeCanonical::normalizeNfc($input);
        }
        throw new \ValueError(
            'normalizer_normalize(): Argument #2 ($form) must be one of Normalizer::FORM_* constants'
        );
    }
}
