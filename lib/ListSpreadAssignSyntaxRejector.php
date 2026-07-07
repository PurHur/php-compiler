<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\ListSpreadAssignSyntax;
use PHPCompiler\Compiler\CompileFatal;

/**
 * Reject PHP 8.4+ list destructuring spread assignment on the Zend 8.2 reference profile (#17182).
 *
 * php-src: Zend/zend_compile.c — "Spread operator is not supported in assignments".
 */
final class ListSpreadAssignSyntaxRejector
{
    public static function reject(string $code, string $filename = 'unknown'): string
    {
        if (ReferenceProfileTokenScan::shouldSkipReferenceProfileReject($code, $filename)) {
            return $code;
        }
        if (CompilerVersion::supportsListDestructuringSpreadAssign()) {
            return $code;
        }
        $error = ListSpreadAssignSyntax::referenceProfileSyntaxError($code);
        if (null === $error) {
            return $code;
        }

        throw new CompileFatal($filename, $error['line'], $error['message']);
    }
}
