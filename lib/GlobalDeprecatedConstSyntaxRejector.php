<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\GlobalDeprecatedConstRewriter;
use PHPCompiler\Compiler\CompileFatal;

/**
 * Reject attributed file/namespace constants when TARGET_CONSTANT is unavailable (#16819, #26308).
 *
 * Matches Zend ≤8.4 parse error (`syntax error, unexpected token "const"`). Allowed on PROFILE≥8.5.
 */
final class GlobalDeprecatedConstSyntaxRejector
{
    public static function reject(string $code, string $filename = 'unknown'): string
    {
        if (ReferenceProfileTokenScan::shouldSkipReferenceProfileReject($code, $filename)) {
            return $code;
        }
        if (CompilerVersion::supportsGlobalDeprecatedConstAttributes()) {
            return $code;
        }
        $error = GlobalDeprecatedConstRewriter::referenceProfileSyntaxError($code);
        if (null === $error) {
            return $code;
        }

        throw new CompileFatal($filename, $error['line'], $error['message']);
    }
}
