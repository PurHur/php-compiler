<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules that call highlight_string() at runtime (#3164, #3447).
 *
 * php-src: ext/standard/php_highlight.h — tokenizer → HTML color spans.
 */
final class HighlightJitHelper
{
    public static function renderString(string $code): string
    {
        return HighlightEngine::render($code);
    }
}
