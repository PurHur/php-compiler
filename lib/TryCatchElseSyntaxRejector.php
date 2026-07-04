<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\TryCatchElseSupport;
use PHPCompiler\Compiler\CompileFatal;

/**
 * Reject PHP 8.4 try/catch/else on the Zend 8.2 reference profile (#15817).
 *
 * Must run before {@see TryCatchElseSupport::extract()} so else clauses are not stripped.
 * php-src: Zend/zend_language_parser.y try_catch_list.
 */
final class TryCatchElseSyntaxRejector
{
    public static function reject(string $code, string $filename = 'unknown'): string
    {
        $error = TryCatchElseSupport::referenceProfileSyntaxError($code);
        if (null === $error) {
            return $code;
        }

        throw new CompileFatal($filename, $error['line'], $error['message']);
    }
}
