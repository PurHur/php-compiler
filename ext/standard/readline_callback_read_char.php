<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** readline_callback_read_char() — read char in callback mode (ext/readline/readline.c; #7059). */
final class readline_callback_read_char extends Internal
{
    public function __construct()
    {
        parent::__construct('readline_callback_read_char');
    }

    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'readline_callback_read_char() expects exactly 0 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        VmReadline::callbackReadChar();
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitReadline::invokeVoid($context);
    }
}
