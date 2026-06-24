<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for strip_tags() runtime (#9196, #9746, php-in-PHP).
 *
 * Delegates to {@see VmString::stripTags()} so nested standalone JIT avoids loop/substr lowering.
 */
final class StripTagsJitHelper
{
    public static function stripTags(string $input, string $allowedMarkup): string
    {
        if ('' === $allowedMarkup) {
            return VmString::stripTags($input, null);
        }

        return VmString::stripTags($input, $allowedMarkup);
    }
}
