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

/**
 * pspell_new() — open Aspell dictionary (php-src ext/pspell/pspell.c; #6294).
 *
 * Signature: pspell_new(string $language, string $spelling = "", string $jargon = "",
 * string $encoding = "", int $mode = 0): PSpell\Dictionary|false
 */
final class pspell_new extends Internal
{
    public function __construct()
    {
        parent::__construct('pspell_new');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError(\sprintf(
                'pspell_new() expects at least 1 argument, %d given',
                $argc
            ));
        }
        if ($argc > 5) {
            throw new \ArgumentCountError(\sprintf(
                'pspell_new() expects at most 5 arguments, %d given',
                $argc
            ));
        }
        $language = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'pspell_new', 1, 'language');
        $spelling = '';
        $jargon = '';
        $encoding = '';
        $mode = 0;
        if ($argc >= 2) {
            $spelling = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pspell_new', 2, 'spelling');
        }
        if ($argc >= 3) {
            $jargon = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'pspell_new', 3, 'jargon');
        }
        if ($argc >= 4) {
            $encoding = VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'pspell_new', 4, 'encoding');
        }
        if ($argc >= 5) {
            $mode = VmMath::parseIntBuiltinArg($frame->calledArgs[4], 'pspell_new', 5, 'mode');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('pspell_new() requires a VM context');
        }
        $result = VmPspellCore::newDictionary($ctx, $frame, $language, $spelling, $jargon, $encoding, $mode);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($result->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pspell_new() is not implemented for JIT in this compiler build (issue #6294)');
    }
}
