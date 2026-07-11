<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\TypedFunctionStaticRewriter;
use PHPCompiler\Compiler\CompileFatal;

/**
 * Reject PHP 8.3+ typed function-local static variables on the Zend 8.2 reference profile (#16512).
 *
 * php-src: Zend/zend_compile.c — typed static local compilation (PHP 8.3).
 */
final class TypedFunctionStaticSyntaxRejector
{
    public static function reject(string $code, string $filename = 'unknown'): string
    {
        if (ReferenceProfileTokenScan::shouldSkipReferenceProfileReject($code, $filename)) {
            return $code;
        }
        if (CompilerVersion::supportsTypedFunctionStatic()) {
            return $code;
        }
        $error = TypedFunctionStaticRewriter::referenceProfileSyntaxError($code);
        if (null === $error) {
            return $code;
        }

        throw new CompileFatal($filename, $error['line'], $error['message']);
    }
}
