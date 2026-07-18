<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\NewDereferenceableDesugar;
use PHPCompiler\Compiler\CompileFatal;

/**
 * Reject illegal / profile-gated `new` dereference forms before desugar (#19684, #20598).
 *
 * - Always: bare named-class `new Name->…` / `new Name?->…` (no ctor `()`) — Zend rejects on 8.4+ too.
 * - Reference profile only: `new Name()->…` without outer parentheses (#19684).
 *
 * Must run before {@see Ast\NewDereferenceableDesugar} so gated forms are not lowered into valid PHP.
 * php-src: Zend/zend_language_parser.y — new_dereferenceable / new_non_dereferenceable.
 */
final class NewDereferenceableSyntaxRejector
{
    public static function reject(string $code, string $filename = 'unknown'): string
    {
        if (ReferenceProfileTokenScan::shouldSkipReferenceProfileReject($code, $filename)) {
            return $code;
        }

        $bare = NewDereferenceableDesugar::bareNamedClassObjectDerefSyntaxError($code);
        if (null !== $bare) {
            throw new CompileFatal($filename, $bare['line'], $bare['message']);
        }

        if (CompilerVersion::supportsDereferencableNewWithoutOuterParens()) {
            return $code;
        }
        $error = NewDereferenceableDesugar::referenceProfileSyntaxError($code);
        if (null === $error) {
            return $code;
        }

        throw new CompileFatal($filename, $error['line'], $error['message']);
    }
}
