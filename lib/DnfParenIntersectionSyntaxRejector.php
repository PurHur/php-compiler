<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\DnfParenTypeRewriter;
use PHPCompiler\Compiler\CompileFatal;

/**
 * Reject PHP 8.3+ parenthesized DNF intersection-only types on the Zend 8.2 reference profile (#14904).
 *
 * Must run before {@see Ast\DnfParenTypeRewriter} so intersection-only leaves are not unwrapped into valid PHP.
 * php-src: Zend/zend_compile.c — zend_compile_type / DNF normalization.
 */
final class DnfParenIntersectionSyntaxRejector
{
    public static function reject(string $code, string $filename = 'unknown'): string
    {
        if (CompilerVersion::supportsParenthesizedDnfIntersectionTypes()) {
            return $code;
        }
        $error = DnfParenTypeRewriter::referenceProfileSyntaxError($code);
        if (null === $error) {
            return $code;
        }

        throw new CompileFatal($filename, $error['line'], $error['message']);
    }
}
