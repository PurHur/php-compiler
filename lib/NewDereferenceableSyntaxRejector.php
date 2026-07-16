<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\NewDereferenceableDesugar;
use PHPCompiler\Compiler\CompileFatal;

/**
 * Reject PHP 8.4+ dereferencable `new` without outer parentheses on the Zend 8.2 reference profile (#19684).
 *
 * Must run before {@see Ast\NewDereferenceableDesugar} so the construct is not lowered into valid PHP.
 * php-src: Zend/zend_language_parser.y — new_dereferenceable / new_non_dereferenceable.
 */
final class NewDereferenceableSyntaxRejector
{
    public static function reject(string $code, string $filename = 'unknown'): string
    {
        if (ReferenceProfileTokenScan::shouldSkipReferenceProfileReject($code, $filename)) {
            return $code;
        }
        if (CompilerVersion::supportsDereferencableNewWithoutOuterParens()) {
            return $code;
        }
        $error = NewDereferenceableDesugar::referenceProfileSyntaxError($code);
        if (null === $error) {
            return $code;
        }

        throw new CompileFatal($filename, $error['line'], $error['message']);
    }
}
