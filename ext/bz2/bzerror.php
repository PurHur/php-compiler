<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** bzerror() — php-src ext/bz2/bz2.c (#22344). */
final class bzerror extends Internal
{
    public function __construct()
    {
        parent::__construct('bzerror');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('bzerror() expects exactly 1 argument in this compiler build');
        }
        $handle = VmBz2Error::requireBz2Handle($frame->calledArgs[0]->resolveIndirect(), 'bzerror');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom(VmJson::import(VmBz2Error::error($handle)));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('bzerror() expects exactly 1 argument in this compiler build');
        }

        return JitBzerror::invoke(
            $context,
            JitLongArg::lower($context, $args[0], 'bzerror() stream')
        );
    }
}
