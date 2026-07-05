<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\ReadonlyAnonymousClassSyntax;
use PHPCompiler\Compiler\CompileFatal;

/**
 * Reject PHP 8.3+ `new readonly class` on the Zend 8.2 reference profile (#16255).
 *
 * php-src: Zend/zend_language_parser.y / Zend/zend_compile.c — ZEND_ACC_READONLY_ANON_CLASS (#6991).
 */
final class ReadonlyAnonymousClassSyntaxRejector
{
    public static function reject(string $code, string $filename = 'unknown'): string
    {
        if (CompilerVersion::supportsReadonlyAnonymousClass()) {
            return $code;
        }
        $error = ReadonlyAnonymousClassSyntax::referenceProfileSyntaxError($code);
        if (null === $error) {
            return $code;
        }

        throw new CompileFatal($filename, $error['line'], $error['message']);
    }
}
