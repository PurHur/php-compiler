<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * readline() — CLI line input (ext/readline/readline.c parity, issue #3776).
 *
 * VM: host readline() when ext/readline is loaded; otherwise native STDIN fgets fallback (#6216).
 * JIT/AOT: returns false (no interactive stdin in compiled binaries).
 */
final class readline extends Internal
{
    public function __construct()
    {
        parent::__construct('readline');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException('readline() accepts at most one argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $prompt = null;
        if (1 === $argc) {
            $v = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_NULL !== $v->type) {
                $prompt = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'readline', 0, 'prompt');
            }
        }
        $line = VmReadline::read($prompt);
        if (false === $line) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($line);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \LogicException('readline() accepts at most one argument in this compiler build');
        }

        return JitReadline::invoke($context);
    }
}
