<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * mb_scrub() — replace invalid byte sequences (php-src ext/mbstring/mbstring.c; PHP 8.4, #6050).
 *
 * Excess argc → Zend `expects at most` ArgumentCountError (#30786).
 */
final class mb_scrub extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_scrub');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity 1..2 — excess uses at-most wording (#30786).
        $this->requireArgCountRange($frame, 'mb_scrub', 1, 2);
        $argc = \count($frame->calledArgs);
        // Soft-null DEP+coerce on 8.4 (php-src mbstring.c; #21516, reverts #21061 TypeError).
        $string = VmString::trimFamilyStringArgForFrame($frame, 0, 'mb_scrub', 0, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        $encoding = null;
        if (2 === $argc) {
            $encoding = VmMbstring::coerceEncodingArg(
                $frame->calledArgs[1],
                'mb_scrub',
                1
            );
        }
        $frame->returnVar->string(VmMbstring::scrub($string, $encoding));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30786).
        if (!$this->requireArgCountRangeJit($context, $args, 'mb_scrub', 1, 2)) {
            return $context->builder->load($context->constantStringFromString(''));
        }
        // Soft-null DEP+coerce on 8.4 (php-src mbstring.c; #21516, reverts #21061 TypeError).
        $folded = JitMbScrub::tryCompileTimeFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        throw new \LogicException(
            'mb_scrub() JIT requires compile-time string and encoding literals in this compiler build'
        );
    }
}
