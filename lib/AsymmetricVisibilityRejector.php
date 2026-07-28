<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\AsymmetricVisibilityRewriter;
use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\SourcePreprocessor\PropertyHooks;

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
        if (ReferenceProfileTokenScan::shouldSkipReferenceProfileReject($code, $filename)) {
            return $code;
        }
        if (!AsymmetricVisibilityRewriter::containsAsymmetricVisibilitySyntax($code)) {
            return $code;
        }

        $parenSet = AsymmetricVisibilityRewriter::findParenthesizedAsymmetricSetModifierError($code);
        $hasPropertyHooks = null !== PropertyHooks::referenceProfileHookSyntaxError($code);

        if (CompilerVersion::supportsAsymmetricVisibility()) {
            return $code;
        }

        // php-src: Zend/zend_compile.c — bare `public private(set)` before parenthesized forms (#18062).
        $multipleLine = AsymmetricVisibilityRewriter::findMultipleAccessModifierLine($code);
        if ($multipleLine > 0) {
            throw new CompileFatal(
                $filename,
                $multipleLine,
                AsymmetricVisibilityRewriter::referenceProfileMultipleModifierMessage($code, $multipleLine)
            );
        }

        // php-src: Zend/zend_compile.c — asymmetric scope before hook block on reference profile (#16452).
        if ($hasPropertyHooks && !CompilerVersion::supportsPropertyHooks() && null !== $parenSet) {
            throw new CompileFatal(
                $filename,
                $parenSet['line'],
                self::parenthesizedSetModifierMessage($parenSet['token'])
            );
        }

        // php-src: Zend/zend_compile.c — parenthesized `(private(set))` gated to 8.4+ (#16450).
        if (null !== $parenSet && !CompilerVersion::supportsParenthesizedAsymmetricSetModifier()) {
            throw new CompileFatal(
                $filename,
                $parenSet['line'],
                self::parenthesizedSetModifierMessage($parenSet['token'])
            );
        }

        if (null !== $parenSet) {
            throw new CompileFatal(
                $filename,
                $parenSet['line'],
                self::parenthesizedSetModifierMessage($parenSet['token'])
            );
        }

        $line = self::lineOfFirstAsymmetricSyntax($code);
        throw new CompileFatal($filename, $line, self::PARSE_MESSAGE);
    }

    private static function parenthesizedSetModifierMessage(string $token): string
    {
        return sprintf(AsymmetricVisibilityRewriter::PROMOTED_PARENTHESIZED_SET_MESSAGE, $token);
    }

    private static function lineOfFirstAsymmetricSyntax(string $code): int
    {
        return AsymmetricVisibilityRewriter::findFirstAsymmetricSyntaxLine($code);
    }
}
