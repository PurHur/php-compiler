<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\GlobalTypedConstRewriter;
use PHPCompiler\Compiler\CompileFatal;

/**
 * Reject PHP 8.3+ file/namespace typed constants on the Zend 8.2 reference profile (#16651).
 *
 * php-src: Zend/zend_compile.c — compile-unit typed const (PHP 8.3+).
 */
final class GlobalTypedConstSyntaxRejector
{
    public static function reject(string $code, string $filename = 'unknown'): string
    {
        if (ReferenceProfileTokenScan::shouldSkipReferenceProfileReject($code, $filename)) {
            return $code;
        }
        if (CompilerVersion::supportsGlobalTypedConstants()) {
            return $code;
        }
        $error = GlobalTypedConstRewriter::referenceProfileSyntaxError($code);
        if (null === $error) {
            return $code;
        }

        throw new CompileFatal($filename, $error['line'], $error['message']);
    }
}
