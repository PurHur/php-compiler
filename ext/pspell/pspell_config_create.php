<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pspell;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** pspell_config_create() — php-src ext/pspell/pspell.c (#22229). */
final class pspell_config_create extends Internal
{
    public function __construct()
    {
        parent::__construct('pspell_config_create');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError(\sprintf(
                'pspell_config_create() expects at least 1 argument, %d given',
                $argc
            ));
        }
        if ($argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'pspell_config_create() expects at most 4 arguments, %d given',
                $argc
            ));
        }
        $language = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'pspell_config_create', 1, 'language');
        $spelling = $argc >= 2 ? VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pspell_config_create', 2, 'spelling') : '';
        $jargon = $argc >= 3 ? VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'pspell_config_create', 3, 'jargon') : '';
        $encoding = $argc >= 4 ? VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'pspell_config_create', 4, 'encoding') : '';
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('pspell_config_create() requires a VM context');
        }
        if (!VmPspellNative::available()) {
            throw new \Error('Call to undefined function pspell_config_create()');
        }
        $frame->returnVar->copyFrom(VmPspellCore::createConfig($ctx, $language, $spelling, $jargon, $encoding));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pspell_config_create() is not implemented for JIT in this compiler build (issue #22229)');
    }
}
