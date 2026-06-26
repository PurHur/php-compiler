<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * assert_options() — assert INI introspection (ext/standard/assert.c; issue #3316).
 */
final class assert_options extends Internal
{
    public function __construct()
    {
        parent::__construct('assert_options');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'assert_options() expects at least 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        $what = $frame->calledArgs[0]->resolveIndirect()->toInt();
        if (1 === $argc) {
            $result = VmAssertState::getOption($what);
        } else {
            $result = VmAssertState::setOption($what, $frame->calledArgs[1]);
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        if (null === $result) {
            $frame->returnVar->null();

            return;
        }
        if (\is_string($result)) {
            $frame->returnVar->string($result);
        } elseif (\is_bool($result)) {
            $frame->returnVar->bool($result);
        } else {
            $frame->returnVar->int((int) $result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitAssertOptions::invoke($context, ...$args);
    }
}
