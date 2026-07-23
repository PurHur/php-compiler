<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pspell;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** pspell_store_replacement() — php-src ext/pspell/pspell.c (#22229). */
final class pspell_store_replacement extends Internal
{
    public function __construct()
    {
        parent::__construct('pspell_store_replacement');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pspell_store_replacement() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        $dict = VmPspellArg::requireDictionary($frame->calledArgs[0], 'pspell_store_replacement', 1);
        $miss = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pspell_store_replacement', 2, 'misspelled');
        $corr = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'pspell_store_replacement', 3, 'correct');
        $ok = VmPspellCore::storeReplacement($dict, $miss, $corr, $frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pspell_store_replacement() is not implemented for JIT in this compiler build (issue #22229)');
    }
}
