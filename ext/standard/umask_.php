<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** umask() — process file-creation mask (VM host; JIT/AOT via UmaskJitHelper PHP, #3226, #15497). */
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
        $mask = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            'umask',
            1,
            'mask'
        );
        $frame->returnVar->int((int) \umask($mask));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \LogicException('umask() accepts at most one argument in this compiler build');
        }
        $mask = null;
        if (isset($args[0])) {
            $mask = JitSleep::zParamLong($context, $args[0], 'umask', 1, 'mask');
        }

        return JitUmask::invoke($context, $mask);
    }
}
