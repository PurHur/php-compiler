<?php

declare(strict_types=1);

/**
 * JIT/AOT helpers for getenv() and putenv() via GetenvJitHelper PHP (#9092, #8992).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringGetenv;
use PHPCompiler\JIT\Builtin\StringGetenvAll;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitEnv
{
    /** Zero-arg getenv() — assoc array of all variables (#5075 phase 2). */
    public static function getenvAll(Context $context): Value
    {
        StringGetenvAll::ensureLinked($context);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_getenv_all'),
            $ptr
        );

        return $ptr;
    }

    /**
     * @return Value
     * (string on success, boolean false when unset)
     */
    public static function getenv(Context $context, Value $nameStr, Value $localOnlyI8): Value
    {
        StringGetenv::ensureLinked($context);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_getenv'),
            $nameStr,
            $localOnlyI8,
            $ptr
        );

        return $ptr;
    }

    public static function putenv(Context $context, Value $assignmentStr): Value
    {
        StringGetenv::ensurePutenvLinked($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'putenv_emit_cont');
        self::emitPutenvSyntaxGuard($context, $assignmentStr);

        return JitNestedHelperCoerce::callHelper(
            $context,
            StringGetenv::helperFunction(
                $context,
                'PHPCompiler\\ext\\standard\\GetenvJitHelper::putenv'
            ),
            [$assignmentStr]
        );
    }

    public static function apacheSetenv(Context $context, Value $variableStr, Value $valueStr): Value
    {
        StringGetenv::ensureLinked($context);

        return $context->builder->call(
            StringGetenv::helperFunction(
                $context,
                'PHPCompiler\\ext\\standard\\GetenvJitHelper::apacheSetenv'
            ),
            $variableStr,
            $valueStr
        );
    }

    private static function emitPutenvSyntaxGuard(Context $context, Value $assignmentStr): void
    {
        TypeErrorRaise::ensureLinked($context);

        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($assignmentStr, $map['length'])
        );
        $bytes = $context->builder->structGep($assignmentStr, $map['value']);
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $empty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);
        $firstByte = $context->builder->load($bytes);
        $isEq = $context->builder->icmp(
            Builder::INT_EQ,
            $firstByte,
            $i8->constInt(ord('='), false)
        );
        $invalid = $context->builder->or($empty, $isEq);

        $ok = BasicBlockHelper::append($context, 'putenv_syntax_ok');
        $bad = BasicBlockHelper::append($context, 'putenv_syntax_bad');
        $context->builder->branchIf($invalid, $bad, $ok);
        $context->builder->positionAtEnd($bad);
        TypeErrorRaise::emitValueError($context, VmEnv::PUTENV_INVALID_SYNTAX_ERROR);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($ok);
    }
}
