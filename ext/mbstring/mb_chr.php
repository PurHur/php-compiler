<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mb_chr() — codepoint to multibyte character (php-src ext/mbstring/mbstring.c; #4559, #29778, #30759, #34250).
 *
 * JIT/AOT: compile-time fold + NestedJIT runtime via {@see JitMbChrOrd::invokeChr}.
 * Leftover #33536: catchable argc/TypeError (array) paths (peer openssl_pkey_new #33530).
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
        // Zend stub argc: at least 1 / at most 2 (#33536; php-src ZEND_PARSE_PARAMETERS).
        if (0 === $argc) {
            throw new \ArgumentCountError('mb_chr() expects at least 1 argument, 0 given');
        }
        if ($argc > 2) {
            throw new \ArgumentCountError(
                'mb_chr() expects at most 2 arguments, '.$argc.' given'
            );
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
        if (0 === $argc) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                'mb_chr() expects at least 1 argument, 0 given'
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_chr_argc_min_cont');

            return self::foldFalse($context);
        }
        if ($argc > 2) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                'mb_chr() expects at most 2 arguments, '.$argc.' given'
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_chr_argc_max_cont');

            return self::foldFalse($context);
        }

        // Compile-time null $codepoint under caller strict_types → TypeError (#29778).
        $codepointIsNull = JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant;
        if ($codepointIsNull && $context->callerStrictTypes) {
            JitInternalStrictArg::rejectNullInt($context, $args[0], 'mb_chr', 'codepoint', 1);

            return self::foldFalse($context);
        }

        // Array/object never coerce to Z_PARAM_LONG — bake TypeError before fold (#33536).
        $badCodepoint = self::compileTimeNonCoercibleIntLabel($args[0]);
        if (null !== $badCodepoint) {
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'mb_chr(): Argument #1 ($codepoint) must be of type int, '.$badCodepoint.' given'
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_chr_te_cont');

            return self::foldFalse($context);
        }

        return JitMbChrOrd::invokeChr($context, $args);
    }

    /**
     * Types that never coerce to Z_PARAM_LONG under weak types (array/object).
     * Empty `[]` often arrives as TYPE_VALUE + compileTimeEmptyArrayLiteral (#33536).
     *
     * @return non-empty-string|null
     */
    private static function compileTimeNonCoercibleIntLabel(JITVariable $arg): ?string
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type
            || (($arg->type & JITVariable::IS_NATIVE_ARRAY) !== 0)
            || ($arg->compileTimeEmptyArrayLiteral ?? false)
            || null !== ($arg->compileTimeArray ?? null)
        ) {
            return 'array';
        }
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return (null !== $arg->classUserType && '' !== $arg->classUserType)
                ? $arg->classUserType
                : 'object';
        }

        return null;
    }

    private static function foldFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

        return JitValueBox::pointer($context, $slot);
    }
}
