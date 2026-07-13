<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/**
 * DOMXPath::evaluate() scalar helpers for compiled JIT/AOT modules (#18526).
 *
 * SSOT: {@see VmDomXPath::evaluate()}
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
}
