<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** readline_list_history() — list readline history entries (ext/readline/readline.c; #7059). */
final class readline_list_history extends Internal
{
    public function __construct()
    {
        parent::__construct('readline_list_history');
    }

    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'readline_list_history() expects exactly 0 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(VmReadline::listHistory());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitReadline::invokeEmptyArray($context);
    }
}
