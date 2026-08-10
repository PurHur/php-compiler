<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mb_chr() — codepoint to multibyte character (php-src ext/mbstring/mbstring.c; #4559, #29778).
 */
final class mb_chr extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_chr');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(sprintf(
                'mb_chr() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        // Z_PARAM_LONG $codepoint — caller strict_types → TypeError on null (#29778).
        $codepoint = VmMath::parseZParamLongBuiltinArgForFrame(
            $frame,
            0,
            'mb_chr',
            1,
            'codepoint'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $encoding = 1 === $argc
            ? MbstringState::internalEncoding()
            : VmMbstring::coerceEncodingArg($frame->calledArgs[1], 'mb_chr', 1);
        $result = VmMbstring::chr($codepoint, $encoding);
        if (false === $result) {
            BuiltinExecute::writeReturn($frame, static fn (Variable $ret) => $ret->bool(false));

            return;
        }
        BuiltinExecute::writeReturn($frame, static fn (Variable $ret) => $ret->string($result));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('mb_chr() requires one or two arguments');
        }

        // Compile-time null $codepoint under caller strict_types → TypeError (#29778).
        $codepointIsNull = JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant;
        if ($codepointIsNull && $context->callerStrictTypes) {
            JitInternalStrictArg::rejectNullInt($context, $args[0], 'mb_chr', 'codepoint', 1);

            return self::foldFalse($context);
        }

        throw new \LogicException('mb_chr() is not lowered for JIT/AOT in this compiler build');
    }

    private static function foldFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

        return JitValueBox::pointer($context, $slot);
    }
}
