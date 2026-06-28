<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\CloneWithDesugar;
use PHPCompiler\Compiler\CompileFatal;

/**
 * Reject PHP 8.3+ clone-with syntax on the Zend 8.2 reference profile (#12987).
 *
 * Must run before {@see Ast\CloneWithDesugar} so clone-with is not lowered into valid PHP.
 * php-src: Zend/zend_language_parser.y clone_expr with clause; zend_clones.c.
 */
final class CloneWithSyntaxRejector
{
    public static function reject(string $code, string $filename = 'unknown'): string
    {
        if (CompilerVersion::supportsCloneWithSyntax()) {
            return $code;
        }
        $error = CloneWithDesugar::referenceProfileSyntaxError($code);
        if (null === $error) {
            return $code;
        }

        throw new CompileFatal($filename, $error['line'], $error['message']);
    }
}
