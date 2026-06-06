<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** array_is_assoc() — true when non-empty and keys are not 0..count-1 (issue #7016). */
final class array_is_assoc extends Internal
{
    public function __construct()
    {
        parent::__construct('array_is_assoc');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('array_is_assoc() expects exactly 1 argument, '.$argc.' given');
        }
        $ht = VmArray::requireArray($frame->calledArgs[0], 'array_is_assoc');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmArray::isAssoc($ht));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            throw new \ArgumentCountError('array_is_assoc() expects exactly 1 argument, '.$argc.' given');
        }

        JitArrayKey::requireArrayArg($context, $args[0], 'array_is_assoc');
        if (!JitArrayIsList::canLowerOperand($args[0])) {
            return JitArrayIsList::unreachableBool($context);
        }

        return JitArrayIsList::invokeAssoc($context, $args[0]);
    }
}
