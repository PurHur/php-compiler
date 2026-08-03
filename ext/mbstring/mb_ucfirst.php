<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mb_ucfirst() — multibyte upper-case first character (php-src ext/mbstring/mbstring.c; PHP 8.4+, #17609, #22794).
 */
final class mb_ucfirst extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_ucfirst');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(sprintf(
                'mb_ucfirst() expects at least 1 argument, %d given',
                $argc
            ));
        }
        // Zend 8.4 ZPP soft-null + DEP (not TypeError) — #24176, reverts #19433.
        $string = VmString::trimFamilyStringArgForFrame($frame, 0, 'mb_ucfirst', 0, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        $encoding = $argc >= 2
            ? VmMbstring::coerceMbEncodingNameArg($frame->calledArgs[1], 'mb_ucfirst', 1)
            : MbstringState::internalEncoding();
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(VmMbstring::ucfirst($string, $encoding))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitMbUcfirstLcfirst::invoke(
            $context,
            'mb_ucfirst',
            static fn (string $string, string $encoding): string => VmMbstring::ucfirst($string, $encoding),
            $args
        );
    }
}
