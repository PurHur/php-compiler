<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\CloneWithDesugar;
use PHPCompiler\Compiler\CompileFatal;

/**
 * Reject non-php-src clone-with surface (#12987, #23877, #29187).
 *
 * Zend ships only parenthesized `clone($obj, array $withProperties)` on 8.5+.
 * Keyword forms (`clone $obj with { … }`, `clone $obj with […]`, `(clone $obj) with …`)
 * are a ParseError on every Zend version — reject them on all profiles.
 *
 * Must run before {@see Ast\CloneWithDesugar} so rejected forms are not lowered.
 * php-src: Zend/zend_language_parser.y clone expression (array form only).
 */
final class CloneWithSyntaxRejector
{
    public static function reject(string $code, string $filename = 'unknown'): string
    {
        if (ReferenceProfileTokenScan::shouldSkipReferenceProfileReject($code, $filename)) {
            return $code;
        }

        // Always reject keyword `with` forms — Zend never shipped them (#29187).
        $keywordError = CloneWithDesugar::keywordWithSyntaxError($code);
        if (null !== $keywordError) {
            throw new CompileFatal($filename, $keywordError['line'], $keywordError['message']);
        }

        if (CompilerVersion::supportsCloneWithSyntax()) {
            return $code;
        }

        $error = CloneWithDesugar::referenceProfileCloneCallSyntaxError($code);
        if (null === $error) {
            return $code;
        }

        throw new CompileFatal($filename, $error['line'], $error['message']);
    }
}
