<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\ExitFunctionDesugar;
use PHPCompiler\Compiler\CompileFatal;

/**
 * Reject PHP 8.4+ parenthesized exit/die function forms on the Zend 8.2 reference profile (#13973).
 *
 * Must run before {@see Ast\ExitFunctionDesugar} so named/two-arg/FCC forms are not lowered into valid PHP.
 * php-src: Zend/zend_compile.c — exit()/die() named status/message (PHP 8.4).
 */
final class ExitFunctionSyntaxRejector
{
    public static function reject(string $code, string $filename = 'unknown'): string
    {
        if (CompilerVersion::supportsExitFunctionForm()) {
            return $code;
        }
        $error = ExitFunctionDesugar::referenceProfileSyntaxError($code);
        if (null === $error) {
            return $code;
        }

        throw new CompileFatal($filename, $error['line'], $error['message']);
    }
}
