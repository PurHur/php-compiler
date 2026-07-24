<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\PipeOperatorDesugar;
use PHPCompiler\Compiler\CompileFatal;

/**
 * Reject PHP 8.5+ pipe operator (|>) below PROFILE=8.5 (#12424, #18007, #22792).
 *
 * Must run before {@see Ast\PipeOperatorDesugar} so pipe syntax is not lowered into valid PHP.
 * php-src: Zend/zend_language_parser.y pipe expression grammar (PHP 8.5+).
 */
final class PipeOperatorSyntaxRejector
{
    public static function reject(string $code, string $filename = 'unknown'): string
    {
        if (ReferenceProfileTokenScan::shouldSkipReferenceProfileReject($code, $filename)) {
            return $code;
        }
        if (CompilerVersion::supportsPipeOperator()) {
            return $code;
        }
        $error = PipeOperatorDesugar::referenceProfileSyntaxError($code);
        if (null === $error) {
            return $code;
        }

        throw new CompileFatal($filename, $error['line'], $error['message']);
    }
}
