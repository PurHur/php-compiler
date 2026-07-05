<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Compiler\CompileFatal;

/**
 * Reject PHP 8.5+ comma-separated enum case lists on the Zend 8.2 reference profile (#16665).
 *
 * Must run before {@see EnumCaseListRewriter} so comma lists are not desugared into valid PHP.
 * php-src: Zend/zend_language_parser.y enum_case_list (PHP 8.5); Zend/zend_compile.c.
 */
final class EnumCaseListSyntaxRejector
{
    public static function reject(string $code, string $filename = 'unknown'): string
    {
        if (CompilerVersion::supportsEnumCaseList()) {
            return $code;
        }
        $error = EnumCaseListRewriter::referenceProfileSyntaxError($code);
        if (null === $error) {
            return $code;
        }

        throw new CompileFatal($filename, $error['line'], $error['message']);
    }
}
