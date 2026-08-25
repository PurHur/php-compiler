<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * mb_strlen() — UTF-8 character count (php-src ext/mbstring/mbstring.c; #158, #5695, #4405, #34625).
 *
 * Full mbstring parity (additional encodings, mb_substr, …) tracked in #4405, #3239.
 * JIT/AOT runtime encoding via {@see JitMbStrlen::invoke} NestedJIT (#34625).
 *
 * Excess argc → Zend `expects at most` ArgumentCountError (#30891).
 */
final class mb_strlen extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_strlen');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity 1..2 — excess uses at-most wording (#30891).
        $this->requireArgCountRange($frame, 'mb_strlen', 1, 2);
        $argc = \count($frame->calledArgs);
        // Z_PARAM_STR $string — non-strict null is E_DEPRECATED + '' on 8.4 (php-src mbstring.c / #21197).
        $str = VmString::trimFamilyStringArgForFrame($frame, 0, 'mb_strlen', 0, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        $internal = MbstringState::internalEncoding();
        $encoding = 2 === $argc
            ? VmMbstring::resolveValidatedEncodingArg(
                $frame->calledArgs[1],
                'mb_strlen',
                1,
                $internal
            )
            : $internal;
        $frame->returnVar->int(VmMbstring::strlen($str, $encoding));
    }

    public function call(Context $context, Variable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30891).
        if (!$this->requireArgCountRangeJit($context, $args, 'mb_strlen', 1, 2)) {
            return $context->constantFromInteger(0, 'int64');
        }

        return JitMbStrlen::invoke($context, $args);
    }
}
