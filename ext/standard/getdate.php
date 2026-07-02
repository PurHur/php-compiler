<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** getdate() — associative date/time breakdown (VM VmDate; JIT/AOT StringGetdate LLVM, #5256). */
final class getdate extends Internal
{
    public function __construct()
    {
        parent::__construct('getdate');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError('getdate() accepts at most 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $timestamp = null;
        if (1 === $argc) {
            $timestamp = VmDate::coerceNullableTimestampArgForFrame($frame, 0, 'getdate', 1, 'timestamp');
        }
        $frame->returnVar->array(VmDate::getdate($timestamp));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \LogicException('getdate() accepts at most one argument in this compiler build');
        }

        return JitGetdate::invoke($context, $args[0] ?? null);
    }

}
