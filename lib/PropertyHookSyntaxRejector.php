<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\SourcePreprocessor\PropertyHooks;

/**
 * Reject PHP 8.4 property-hook syntax on the Zend 8.2 reference profile (#12574).
 *
 * Must run before {@see SourcePreprocessor\PropertyHooks} so hook blocks are not lowered.
 * php-src: Zend/zend_language_parser.y / Zend/zend_compile.c (PHP 8.4+).
 */
final class PropertyHookSyntaxRejector
{
    public static function reject(string $code, string $filename = 'unknown'): string
    {
        if (CompilerVersion::supportsPropertyHooks()) {
            return $code;
        }
        $error = PropertyHooks::referenceProfileHookSyntaxError($code);
        if (null === $error) {
            return $code;
        }

        throw new CompileFatal($filename, $error['line'], $error['message']);
    }
}
