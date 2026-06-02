<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * highlight_string() / highlight_file() — delegate to Zend tokenizer HTML (VM host).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/php_highlight.h
 */
final class VmHighlight
{
    /**
     * @return string|bool HTML when $return is true, otherwise bool success from Zend
     */
    public static function highlightString(string $code, bool $return): string|bool
    {
        return \highlight_string($code, $return);
    }

    /**
     * @return string|bool HTML when $return is true, otherwise bool success from Zend
     */
    public static function highlightFile(string $filename, bool $return): string|bool
    {
        if (is_readable($filename) && is_file($filename)) {
            return \highlight_file($filename, $return);
        }
        $contents = VmFs::fileGetContents($filename);
        if (false !== $contents) {
            return self::highlightString($contents, $return);
        }

        return \highlight_file($filename, $return);
    }
}
