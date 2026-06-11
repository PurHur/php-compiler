<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** readline_on_new_line() — notify readline of cursor newline (ext/readline/readline.c; #7059). */
final class readline_on_new_line extends Internal
{
    public function __construct()
    {
        parent::__construct('readline_on_new_line');
    }

    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'readline_on_new_line() expects exactly 0 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        VmReadline::onNewLine();
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitReadline::invokeVoid($context);
    }
}
