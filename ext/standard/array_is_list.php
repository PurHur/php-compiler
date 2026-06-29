<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\ArrayIsListRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** array_is_list() — true when keys are consecutive integers 0..count-1 (issue #2211). */
final class array_is_list extends Internal
{
    public function __construct()
    {
        parent::__construct('array_is_list');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('array_is_list() expects exactly 1 argument, '.$argc.' given');
        }
        $ht = VmArray::requireArray($frame->calledArgs[0], 'array_is_list');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmArray::isList($ht));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            throw new \ArgumentCountError('array_is_list() expects exactly 1 argument, '.$argc.' given');
        }

        JitArrayKey::requireArrayArg($context, $args[0], 'array_is_list');
        if (!JitArrayIsList::canLowerOperand($args[0])) {
            return JitArrayIsList::unreachableBool($context);
        }

        return ArrayIsListRuntime::isList($context, $args[0]);
    }
}
