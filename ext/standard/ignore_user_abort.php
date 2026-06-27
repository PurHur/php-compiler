<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/** ignore_user_abort() — continue after client disconnect (ext/standard/basic_functions.c; #3242, JIT #8078). */
final class ignore_user_abort extends Internal
{
    public function __construct()
    {
        parent::__construct('ignore_user_abort');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'ignore_user_abort() expects at most 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('ignore_user_abort() requires VM context');
        }
        $setting = null;
        if (1 === $argc) {
            $setting = InternalStrictArg::parseBuiltinNullableBoolArg(
                $frame->calledArgs[0],
                'ignore_user_abort',
                0,
                'enable'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $previous = $frame->vmContext->executionLimits->ignoreUserAbort($setting);
        $frame->returnVar->int($previous);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitExecutionLimits::ignoreUserAbort($context, ...$args);
    }
}
