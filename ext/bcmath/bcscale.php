<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmMath;
use PHPLLVM\Value;

/**
 * bcscale() — set or get default bcmath scale (php-src ext/bcmath/bcmath.c; issue #3365).
 *
 * Signature: bcscale(?int $scale = null): int — explicit null is the getter (#20974).
 */
final class bcscale extends Internal
{
    public function __construct()
    {
        parent::__construct('bcscale');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError('bcscale() expects at most 1 argument, '.$argc.' given');
        }
        if (1 === $argc) {
            // Z_PARAM_LONG_OR_NULL — null means get current scale (php-src bcmath.stub.php; #20974).
            $scale = VmMath::parseNullableIntBuiltinArgForFrame($frame, 0, 'bcscale', 1, 'scale');
            $result = VmBcmath::scale($scale);
        } else {
            $result = VmBcmath::scale();
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitBcmath::scale($context, ...$args);
    }
}
