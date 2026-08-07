<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\CatchIntersectionSupport;
use PHPCompiler\Compiler\CompileFatal;

/**
 * Reject catch intersection / parenthesized catch types like Zend (#28439).
 *
 * Must run before {@see Ast\CatchIntersectionSupport::rewrite()}.
 * php-src: Zend/zend_language_parser.y catch_name_list (`|` only).
 */
final class CatchIntersectionSyntaxRejector
{
    public static function reject(string $code, string $filename = 'unknown'): string
    {
        if (ReferenceProfileTokenScan::shouldSkipReferenceProfileReject($code, $filename)) {
            return $code;
        }
        $error = CatchIntersectionSupport::referenceProfileSyntaxError($code);
        if (null === $error) {
            return $code;
        }

        throw new CompileFatal($filename, $error['line'], $error['message']);
    }
}
