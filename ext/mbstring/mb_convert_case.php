<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mb_convert_case() — multibyte case conversion (php-src ext/mbstring/mbstring.c; #7014).
 *
 * Excess argc → Zend `expects at most` ArgumentCountError (#30786).
 */
final class mb_convert_case extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_convert_case');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity 2..3 — excess uses at-most wording (#30786).
        $this->requireArgCountRange($frame, 'mb_convert_case', 2, 3);
        $argc = \count($frame->calledArgs);
        // Z_PARAM_STR $string — non-strict null is E_DEPRECATED + '' on 8.4 (php-src mbstring.c / #21313).
        $source = VmString::trimFamilyStringArgForFrame($frame, 0, 'mb_convert_case', 0, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        $mode = VmMbstring::coerceModeArg($frame->calledArgs[1], 'mb_convert_case', 1);
        $encoding = 2 === $argc
            ? MbstringState::internalEncoding()
            : VmMbstring::coerceMbEncodingNameArg($frame->calledArgs[2], 'mb_convert_case', 2);
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(
                VmMbstring::convertCase($source, $mode, $encoding, 'mb_convert_case', 2)
            )
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30786).
        if (!$this->requireArgCountRangeJit($context, $args, 'mb_convert_case', 2, 3)) {
            return $context->builder->load($context->constantStringFromString(''));
        }

        $folded = JitMbConvertCase::tryCompileTimeFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        return JitMbConvertCase::lowerRuntime($context, $args);
    }
}
