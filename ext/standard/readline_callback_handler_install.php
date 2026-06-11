<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** readline_callback_handler_install() — async readline callback (ext/readline/readline.c; #7059). */
final class readline_callback_handler_install extends Internal
{
    public function __construct()
    {
        parent::__construct('readline_callback_handler_install');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError('readline_callback_handler_install() expects exactly 2 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $prompt = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'readline_callback_handler_install', 0, 'prompt');
        $callback = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING === $callback->type) {
            $frame->returnVar->bool(VmReadline::callbackHandlerInstall($prompt, $callback->toString()));

            return;
        }
        $frame->returnVar->bool(false);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitReadline::invokeBool($context, false);
    }
}
