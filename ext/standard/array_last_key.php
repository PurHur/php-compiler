<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** array_last_key() — last key in internal order, or null when empty (PHP 8.3, ext/standard/array.c). */
final class array_last_key extends Internal
{
    public function __construct()
    {
        parent::__construct('array_last_key');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('array_last_key() expects exactly 1 argument, '.$argc.' given');
        }
        $ht = VmArray::requireArray($frame->calledArgs[0], 'array_last_key');
        if (null === $frame->returnVar) {
            return;
        }
        $key = VmArray::keyLast($ht);
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
            throw new \ArgumentCountError('array_last_key() expects exactly 1 argument, '.$argc.' given');
        }

        JitArrayKey::requireArrayArg($context, $args[0], 'array_last_key');

        return JitArrayKey::keyLast($context, $args[0]);
    }
}
