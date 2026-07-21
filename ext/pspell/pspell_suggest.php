<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pspell;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/**
 * pspell_suggest() — suggestion list (php-src ext/pspell/pspell.c; #6294).
 */
final class pspell_suggest extends Internal
{
    public function __construct()
    {
        parent::__construct('pspell_suggest');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pspell_suggest() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $dict = VmPspellArg::requireDictionary($frame->calledArgs[0], 'pspell_suggest', 1);
        $word = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pspell_suggest', 2, 'word');
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmPspellCore::suggest($dict, $word, $frame);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pspell_suggest() is not implemented for JIT in this compiler build (issue #6294)');
    }
}
