<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\GlobalDeprecatedConstRewriter;
use PHPCompiler\Compiler\CompileFatal;

/**
 * Reject PHP 8.4+ #[\Deprecated] on file/namespace constants on the Zend 8.2 reference profile (#16819).
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
