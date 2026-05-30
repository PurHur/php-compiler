<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** umask() — process file-creation mask (VM host; JIT/AOT via libc umask(2), #3226). */
final class umask_ extends Internal
{
    public function __construct()
    {
        parent::__construct('umask');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException('umask() accepts at most one argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (0 === $argc) {
            $frame->returnVar->int((int) \umask());

            return;
        }
        $arg = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $arg->type) {
            throw new \LogicException('umask() mask must be an integer in this compiler build');
        }
        $frame->returnVar->int((int) \umask($arg->toInt()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \LogicException('umask() accepts at most one argument in this compiler build');
        }
        $mask = null;
        if (isset($args[0])) {
            $mask = JitLongArg::lower($context, $args[0], 'umask() mask');
        }

        return JitUmask::invoke($context, $mask);
    }
}
