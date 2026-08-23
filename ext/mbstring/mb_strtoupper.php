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
 * mb_strtoupper() — multibyte upper case (php-src ext/mbstring/mbstring.c; #3239).
 *
 * JIT/AOT: compile-time fold + runtime NestedJIT via {@see JitMbCase} (peer MbStrwidth #3495).
 *
 * Excess argc → Zend `expects at most` ArgumentCountError (#31036).
 */
final class mb_strtoupper extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_strtoupper');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity 1..2 — excess uses at-most wording (#31036).
        $this->requireArgCountRange($frame, 'mb_strtoupper', 1, 2);
        $argc = \count($frame->calledArgs);
        // Z_PARAM_STR $string — non-strict null is E_DEPRECATED + '' on 8.4 (php-src mbstring.c / #21313).
        $string = VmString::trimFamilyStringArgForFrame($frame, 0, 'mb_strtoupper', 0, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        // php-src ?string $encoding = null — explicit null uses internal encoding (#31312).
        $internal = MbstringState::internalEncoding();
        $encoding = $argc >= 2
            ? VmMbstring::resolveValidatedEncodingArg($frame->calledArgs[1], 'mb_strtoupper', 1, $internal)
            : $internal;
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(VmMbstring::strtoupper($string, $encoding))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#31036).
        if (!$this->requireArgCountRangeJit($context, $args, 'mb_strtoupper', 1, 2)) {
            return $context->builder->load($context->constantStringFromString(''));
        }

        return JitMbCase::invokeStrtoupper($context, $args);
    }
}
