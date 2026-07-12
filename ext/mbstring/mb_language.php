<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** mb_language() — NLS language setting (php-src ext/mbstring/mbstring.c; #4636). */
final class mb_language extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_language');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(sprintf(
                'mb_language() expects at most 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (0 === $argc) {
            $frame->returnVar->string(MbstringState::language());

            return;
        }
        $language = VmMbstring::coerceLanguageArg($frame->calledArgs[0], 'mb_language', 0);
        $frame->returnVar->bool(MbstringState::language($language));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'mb_language() JIT is not supported in this compiler build'
        );
    }
}
