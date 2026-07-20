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
 */
final class mb_substr_count extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_substr_count');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(sprintf(
                'mb_substr_count() expects at least 2 arguments, %d given',
                $argc
            ));
        }
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
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('mb_substr_count() requires two or three arguments');
        }
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
