<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** readline_read_history() — load history from file (ext/readline/readline.c; #7059). */
final class readline_read_history extends Internal
{
    public function __construct()
    {
        parent::__construct('readline_read_history');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError('readline_read_history() expects at most 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $filename = null;
        if (1 === $argc) {
            $v = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_NULL !== $v->type) {
                $filename = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'readline_read_history', 0, 'filename');
            }
        }
        $frame->returnVar->bool(VmReadline::readHistory($filename));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitReadline::invokeBool($context, false);
    }
}
