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
 * mb_substr_count() — multibyte non-overlapping substring count (php-src ext/mbstring/mbstring.c; #4637).
 *
 * Excess argc → Zend `expects at most` ArgumentCountError (#30786).
 */
final class mb_substr_count extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_substr_count');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity 2..3 — excess uses at-most wording (#30786).
        $this->requireArgCountRange($frame, 'mb_substr_count', 2, 3);
        $argc = \count($frame->calledArgs);
        // Z_PARAM_STR — non-strict null is E_DEPRECATED + '' on 8.4 (php-src mbstring.c / #21282).
        $haystack = VmString::trimFamilyStringArgForFrame($frame, 0, 'mb_substr_count', 0, 'haystack');
        if (null === $frame->returnVar) {
            return;
        }
        $needle = VmString::trimFamilyStringArgForFrame($frame, 1, 'mb_substr_count', 1, 'needle');
        if ('' === $needle) {
            throw new \ValueError('mb_substr_count(): Argument #2 ($needle) must not be empty');
        }
        $encoding = 'UTF-8';
        if ($argc >= 3) {
            $encoding = VmMbstring::coerceEncodingArg($frame->calledArgs[2], 'mb_substr_count', 2);
            $encoding = VmMbstring::resolveNumericEntityEncoding($encoding, 'mb_substr_count', 2);
        }
        VmMbstring::assertSubstrCountEncoding($encoding);

        $frame->returnVar->int(VmString::substr_count($haystack, $needle));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30786).
        if (!$this->requireArgCountRangeJit($context, $args, 'mb_substr_count', 2, 3)) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        $argc = \count($args);
        if (
            2 === $argc
            && JITVariable::TYPE_STRING === $args[0]->type
            && JITVariable::TYPE_STRING === $args[1]->type
            && null !== ($args[0]->compileTimeString ?? null)
            && null !== ($args[1]->compileTimeString ?? null)
            && '' !== $args[1]->compileTimeString
        ) {
            return $context->constantFromInteger(
                VmString::substr_count($args[0]->compileTimeString, $args[1]->compileTimeString),
                'int64'
            );
        }

        throw new \LogicException('mb_substr_count() is not lowered for JIT/AOT in this compiler build');
    }
}
