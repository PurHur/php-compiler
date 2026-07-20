<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/**
 * DOMXPath::evaluate() scalar helpers for compiled JIT/AOT modules (#18526).
 *
 * SSOT: {@see VmDomXPath::evaluate()}. String ABI calls evaluate()->toString() (#21238);
 * user-script folding remains preferred for foldable literals (#19352).
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
        // Native string return for the AOT/JIT string ABI (#21238). User-script
        // folding remains preferred for foldable literals; this path covers
        // namespace-uri()/local-name()/string() when folding is unavailable.
        return VmDomXPath::evaluate($ctx, $xpath, $expression)->toString($ctx);
    }
}
