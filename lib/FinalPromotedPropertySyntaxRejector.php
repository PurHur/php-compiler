<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\FinalPromotedPropertyRewriter;
use PHPCompiler\Compiler\CompileFatal;

/**
 * Reject `final` on constructor-promoted parameters below PROFILE=8.5 (#27123, #31153).
 *
 * Must run before {@see Ast\FinalPromotedPropertyRewriter} so the rewrite cannot
 * strip `final` and accept the construct on Zend 8.4 profiles.
 * php-src: Zend/zend_language_parser.y / zend_compile.c —
 * ≤8.3 Parse error {@code unexpected token "final"}; 8.4 compile fatal
 * {@code Cannot use the final modifier on a parameter}.
 */
final class FinalPromotedPropertySyntaxRejector
{
    public static function reject(string $code, string $filename = 'unknown'): string
    {
        if (ReferenceProfileTokenScan::shouldSkipReferenceProfileReject($code, $filename)) {
            return $code;
        }
        if (CompilerVersion::supportsFinalPromotedProperties()) {
            return $code;
        }
        $error = FinalPromotedPropertyRewriter::referenceProfileSyntaxError($code);
        if (null === $error) {
            return $code;
        }

        throw new CompileFatal($filename, $error['line'], $error['message']);
    }
}
