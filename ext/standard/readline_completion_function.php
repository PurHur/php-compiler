<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** readline_completion_function() — tab-completion callback (ext/readline/readline.c; #7059). */
final class readline_completion_function extends Internal
{
    public function __construct()
    {
        parent::__construct('readline_completion_function');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('readline_completion_function() expects exactly 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $callback = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING === $callback->type) {
            $frame->returnVar->bool(VmReadline::completionFunction($callback->toString()));

            return;
        }
        $frame->returnVar->bool(false);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitReadline::invokeBool($context, false);
    }
}
