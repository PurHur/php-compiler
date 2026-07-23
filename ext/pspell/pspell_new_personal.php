<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pspell;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** pspell_new_personal() — php-src ext/pspell/pspell.c (#22229). */
final class pspell_new_personal extends Internal
{
    public function __construct()
    {
        parent::__construct('pspell_new_personal');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'pspell_new_personal() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 6) {
            throw new \ArgumentCountError(\sprintf(
                'pspell_new_personal() expects at most 6 arguments, %d given',
                $argc
            ));
        }
        $personal = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'pspell_new_personal', 1, 'filename');
        $language = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pspell_new_personal', 2, 'language');
        $spelling = $argc >= 3 ? VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'pspell_new_personal', 3, 'spelling') : '';
        $jargon = $argc >= 4 ? VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'pspell_new_personal', 4, 'jargon') : '';
        $encoding = $argc >= 5 ? VmString::coerceStringBuiltinArg($frame->calledArgs[4], 'pspell_new_personal', 5, 'encoding') : '';
        $mode = $argc >= 6 ? VmMath::parseIntBuiltinArg($frame->calledArgs[5], 'pspell_new_personal', 6, 'mode') : 0;
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('pspell_new_personal() requires a VM context');
        }
        $result = VmPspellCore::newDictionaryPersonal($ctx, $frame, $personal, $language, $spelling, $jargon, $encoding, $mode);
        if (false === $result) {
            $frame->returnVar->bool(false);
            return;
        }
        $frame->returnVar->object($result->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pspell_new_personal() is not implemented for JIT in this compiler build (issue #22229)');
    }
}
