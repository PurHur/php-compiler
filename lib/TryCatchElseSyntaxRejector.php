<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\TryCatchElseSupport;
use PHPCompiler\Compiler\CompileFatal;

/**
 * Reject try/catch/else on all php-src-strict profiles (#31159, re-#15817).
 *
 * php-src never shipped this production; Zend parse-errors `else` after catch/finally
 * (`unexpected token "else"`) on every shipping version including 8.4/8.5.
 * Must run before {@see TryCatchElseSupport::extract()} so else clauses are not stripped.
 * php-src: Zend/zend_language_parser.y try_catch_list; Zend/zend_compile.c zend_compile_try.
 */
final class TryCatchElseSyntaxRejector
{
    public static function reject(string $code, string $filename = 'unknown'): string
    {
        if (ReferenceProfileTokenScan::shouldSkipReferenceProfileReject($code, $filename)) {
            return $code;
        }
        $error = TryCatchElseSupport::referenceProfileSyntaxError($code);
        if (null === $error) {
            return $code;
        }

        throw new CompileFatal($filename, $error['line'], $error['message']);
    }
}
