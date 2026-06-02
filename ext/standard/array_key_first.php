<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** array_key_first() — first key in internal order, or null when empty. */
final class array_key_first extends Internal
{
    public function __construct()
    {
        parent::__construct('array_key_first');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('array_key_first() expects exactly 1 argument, '.$argc.' given');
        }
        $ht = VmArray::requireArray($frame->calledArgs[0], 'array_key_first');
        if (null === $frame->returnVar) {
            return;
        }
        $key = VmArray::keyFirst($ht);
        if (null === $key) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->copyFrom($key);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            throw new \ArgumentCountError('array_key_first() expects exactly 1 argument, '.$argc.' given');
        }

        JitArrayKey::requireArrayArg($context, $args[0], 'array_key_first');

        return JitArrayKey::keyFirst($context, $args[0]);
    }
}
