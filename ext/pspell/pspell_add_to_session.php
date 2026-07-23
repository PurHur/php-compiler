<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pspell;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** pspell_add_to_session() — php-src ext/pspell/pspell.c (#22229). */
final class pspell_add_to_session extends Internal
{
    public function __construct()
    {
        parent::__construct('pspell_add_to_session');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pspell_add_to_session() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $dict = VmPspellArg::requireDictionary($frame->calledArgs[0], 'pspell_add_to_session', 1);
        $word = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pspell_add_to_session', 2, 'word');
        $ok = VmPspellCore::addToSession($dict, $word, $frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pspell_add_to_session() is not implemented for JIT in this compiler build (issue #22229)');
    }
}
