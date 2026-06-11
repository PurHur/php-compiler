<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** readline_add_history() — append line to readline history (ext/readline/readline.c; #7059). */
final class readline_add_history extends Internal
{
    public function __construct()
    {
        parent::__construct('readline_add_history');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('readline_add_history() expects exactly 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $line = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'readline_add_history', 0, 'prompt');
        $frame->returnVar->bool(VmReadline::addHistory($line));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitReadline::invokeBool($context, false);
    }
}
