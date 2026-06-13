<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\OutputBuffer;

/**
 * highlight_string() / highlight_file() — native tokenizer HTML (#4824).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/php_highlight.h
 */
final class VmHighlight
{
    /**
     * @return string|bool HTML when $return is true, otherwise bool success
     */
    public static function highlightString(string $code, bool $return): string|bool
    {
        $html = HighlightEngine::render($code);
        if ($return) {
            return $html;
        }
        OutputBuffer::append($html);

        return true;
    }

    /**
     * @return string|bool HTML when $return is true, otherwise bool success
     */
    public static function highlightFile(string $filename, bool $return): string|bool
    {
        $contents = VmFs::fileGetContents($filename);
        if (false === $contents) {
            return false;
        }

        return self::highlightString($contents, $return);
    }
}
