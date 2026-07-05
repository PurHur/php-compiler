<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\ClassConstBraceSyntax;
use PHPCompiler\Compiler\CompileFatal;

/**
 * Reject PHP 8.3+ class constant brace dereference on the Zend 8.2 reference profile (#16597).
 *
 * php-src: Zend/zend_language_parser.y / Zend/zend_compile.c — braced class constant name (PHP 8.3).
 */
final class ClassConstBraceSyntaxRejector
{
    public static function reject(string $code, string $filename = 'unknown'): string
    {
        if (CompilerVersion::supportsClassConstBraceDereference()) {
            return $code;
        }
        $error = ClassConstBraceSyntax::referenceProfileSyntaxError($code);
        if (null === $error) {
            return $code;
        }

        throw new CompileFatal($filename, $error['line'], $error['message']);
    }
}
