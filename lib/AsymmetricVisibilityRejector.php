<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\AsymmetricVisibilityRewriter;
use PHPCompiler\Compiler\CompileFatal;

/**
 * Reject PHP 8.4 asymmetric visibility on the Zend 8.2 reference profile (#12508).
 *
 * Must run before {@see SourcePreprocessor\PropertyHooks} so hook-block `private(set);`
 * is not lowered into parseable markers. php-src: Zend/zend_language_parser.y T_PRIVATE_SET.
 */
final class AsymmetricVisibilityRejector
{
    /** php-parser / Zend 8.2 profile message for `private(set)` in promoted params. */
    public const PARSE_MESSAGE = 'Syntax error, unexpected \')\', expecting T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG';

    public static function reject(string $code, string $filename = 'unknown'): string
    {
        if (CompilerVersion::supportsAsymmetricVisibility()) {
            return $code;
        }
        if (!AsymmetricVisibilityRewriter::containsAsymmetricVisibilitySyntax($code)) {
            return $code;
        }

        $line = self::lineOfFirstAsymmetricSyntax($code);
        throw new CompileFatal($filename, $line, self::PARSE_MESSAGE);
    }

    private static function lineOfFirstAsymmetricSyntax(string $code): int
    {
        foreach (['(set)', '(get)'] as $needle) {
            $pos = stripos($code, $needle);
            if (false !== $pos) {
                return substr_count(substr($code, 0, $pos), "\n") + 1;
            }
        }

        return 1;
    }
}
