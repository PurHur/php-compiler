<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmString;
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
 * mb_ord() — multibyte character to codepoint (php-src ext/mbstring/mbstring.c; #4559, #29778, #30759, #34243).
 *
 * JIT/AOT: compile-time fold + NestedJIT runtime via {@see JitMbChrOrd::invokeOrd}.
 * Leftover #33547: catchable argc/TypeError (array) paths (peer mb_chr #33536).
 */
final class mb_ord extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_ord');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        // Zend stub argc: at least 1 / at most 2 (#33547; php-src ZEND_PARSE_PARAMETERS).
        if (0 === $argc) {
            throw new \ArgumentCountError('mb_ord() expects at least 1 argument, 0 given');
        }
        if ($argc > 2) {
            throw new \ArgumentCountError(
                'mb_ord() expects at most 2 arguments, '.$argc.' given'
            );
        }
        // Z_PARAM_STR $string — caller strict_types → TypeError on null (#29778).
        $string = VmString::zparamStrBuiltinArgForFrame($frame, 0, 'mb_ord', 0, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        $encoding = 1 === $argc
            ? MbstringState::internalEncoding()
            : VmMbstring::coerceEncodingArg($frame->calledArgs[1], 'mb_ord', 1);
        $result = VmMbstring::ord($string, $encoding);
        if (false === $result) {
            BuiltinExecute::writeReturn($frame, static fn (Variable $ret) => $ret->bool(false));

            return;
        }
        BuiltinExecute::writeReturn($frame, static fn (Variable $ret) => $ret->int($result));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (0 === $argc) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                'mb_ord() expects at least 1 argument, 0 given'
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_ord_argc_min_cont');

            return self::foldFalse($context);
        }
        if ($argc > 2) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                'mb_ord() expects at most 2 arguments, '.$argc.' given'
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_ord_argc_max_cont');

            return self::foldFalse($context);
        }

        // Compile-time null $string under caller strict_types → TypeError (#29778).
        $stringIsNull = JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant;
        if ($stringIsNull && $context->callerStrictTypes) {
            JitInternalStrictArg::rejectNullString($context, $args[0], 'mb_ord', 'string', 1);

            return self::foldFalse($context);
        }

        // Array/object never coerce to Z_PARAM_STR — bake TypeError before fold (#33547).
        $badString = self::compileTimeNonCoercibleStringLabel($args[0]);
        if (null !== $badString) {
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'mb_ord(): Argument #1 ($string) must be of type string, '.$badString.' given'
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_ord_te_cont');

            return self::foldFalse($context);
        }

        return JitMbChrOrd::invokeOrd($context, $args);
    }

    /**
     * Types that never coerce to Z_PARAM_STR under weak types (array/object).
     * Empty `[]` often arrives as TYPE_VALUE + compileTimeEmptyArrayLiteral (#33547).
     *
     * @return non-empty-string|null
     */
    private static function compileTimeNonCoercibleStringLabel(JITVariable $arg): ?string
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
