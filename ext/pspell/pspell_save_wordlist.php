<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pspell;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** pspell_save_wordlist() — php-src ext/pspell/pspell.c (#22229). */
final class pspell_save_wordlist extends Internal
{
    public function __construct()
    {
        parent::__construct('pspell_save_wordlist');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pspell_save_wordlist() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $dict = VmPspellArg::requireDictionary($frame->calledArgs[0], 'pspell_save_wordlist', 1);
        $ok = VmPspellCore::saveWordlist($dict, $frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pspell_save_wordlist() is not implemented for JIT in this compiler build (issue #22229)');
    }
}
