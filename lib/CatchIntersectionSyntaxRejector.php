<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\CatchIntersectionSupport;
use PHPCompiler\Compiler\CompileFatal;

/**
 * Reject catch intersection types below PHP 8.1 / mixed `|`+`&` lists (#28205).
 *
 * Must run before {@see Ast\CatchIntersectionSupport::rewrite()}.
 * php-src: Zend/zend_language_parser.y catch_list.
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
