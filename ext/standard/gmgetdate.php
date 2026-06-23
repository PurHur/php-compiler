<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** gmgetdate() — UTC associative date/time breakdown (VM VmDate; JIT GmgetdateJitHelper, #7001, #9181). */
final class gmgetdate extends Internal
{
    public function __construct()
    {
        parent::__construct('gmgetdate');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError('gmgetdate() accepts at most 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $timestamp = null;
        if (1 === $argc) {
            $timestamp = VmDate::coerceNullableTimestampArg($frame->calledArgs[0], 'gmgetdate', 1, 'timestamp');
        }
        $frame->returnVar->array(VmDate::gmgetdate($timestamp));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \LogicException('gmgetdate() accepts at most one argument in this compiler build');
        }

        return JitGmgetdate::invoke($context, $args[0] ?? null);
    }

}
