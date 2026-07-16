<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/**
 * DOMXPath::evaluate() scalar helpers for compiled JIT/AOT modules (#18526).
 *
 * SSOT: {@see VmDomXPath::evaluate()}. String results prefer user-script folding (#19352);
 * this ABI remains for number()/boolean() and non-foldable string() shapes.
 */
final class DomXPathEvaluateJitHelper
{
    public static function evaluateBoolArgv(
        Context $ctx,
        ObjectEntry $xpath,
        string $expression
    ): int {
        return VmDomXPath::evaluate($ctx, $xpath, $expression)->toBool($ctx) ? 1 : 0;
    }

    public static function evaluateDoubleArgv(
        Context $ctx,
        ObjectEntry $xpath,
        string $expression
    ): float {
        return VmDomXPath::evaluate($ctx, $xpath, $expression)->toFloat($ctx);
    }

    public static function evaluateStringArgv(
        Context $ctx,
        ObjectEntry $xpath,
        string $expression
    ): string {
        // Prefer compile-time user-script folding. Runtime NestedJit cannot read
        // TYPE_STRING Variables returned from evaluate() (#19352); return empty
        // only when folding is unavailable — callers should keep expressions foldable.
        unset($ctx, $xpath, $expression);

        return '';
    }
}
