<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * preg_replace() empty-regex fast path for compiled JIT/AOT modules (#11024, php-in-PHP).
 *
 * SSOT: {@see PregEmptyPatternReplace}; VM path uses the same logic via {@see VmPregNative}.
 */
final class PregEmptyPatternReplaceJitHelper
{
    public static function replace(
        string $pattern,
        string $replacement,
        string $subject,
        int $limit
    ): string {
        $parsed = PregEmptyPatternReplace::parseEmptyPattern($pattern);
        if (null === $parsed) {
            return '';
        }
        [, $opts] = $parsed;
        $count = 0;

        return PregEmptyPatternReplace::replace($replacement, $subject, $limit, $opts, $count);
    }
}
