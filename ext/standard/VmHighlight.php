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
     * Resolve highlight_string()/highlight_file() $return flag (php-src zend_parse_parameters "b", #9140).
     */
    public static function resolveReturnFlag(\PHPCompiler\VM\Variable $var, string $functionName): bool
    {
        $var = $var->resolveIndirect();
        if (\PHPCompiler\VM\Variable::TYPE_BOOLEAN === $var->type) {
            return $var->toBool();
        }
        if (\PHPCompiler\VM\Variable::TYPE_INTEGER === $var->type) {
            $iv = $var->toInt();
            if (0 === $iv || 1 === $iv) {
                return 0 !== $iv;
            }
        }

        throw new \LogicException($functionName.'() expects bool for argument 2 in this compiler build');
    }

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
