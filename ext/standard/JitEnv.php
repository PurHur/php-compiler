<?php

declare(strict_types=1);

/**
 * JIT/AOT helpers for getenv() and putenv() via PutenvJitHelper / GetenvJitHelper (#9092, #8992, #20499, #21023, #23414).
 *
 * Embed + thin standalone AOT: syntax guard + {@see PutenvJitHelper::putenv} overlay;
 * process-environ mirror lives inside the helper via {@see phpc_putenv_kernel}
 * (no caller-side libc emission).
 * Bool results are bare {@see int1} so Internal::call type inference succeeds (#21023).
 * php-src: ext/standard/basic_functions.c — zif_putenv
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringGetenv;
use PHPCompiler\JIT\Builtin\StringGetenvAll;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NestedJitCompileScope;
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
        // Nested helper compile: libc leaf without re-entering GetenvLookupJitHelper (#29313).
        if (NestedJitCompileScope::isActive()) {
            return self::getenvNestedLeaf($context, $nameStr, $localOnlyI8);
        }

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

    /**
     * NestedJIT leaf for getenv() — libc environ into a value box (#29313 / chdir #29219).
     *
     * @return Value __value__*
     */
    private static function getenvNestedLeaf(Context $context, Value $nameStr, Value $localOnlyI8): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $strPtrTy = $context->getTypeFromString('__string__*');
        $fn = $context->builder->getInsertBlock()->getParent();

        $isLocal = $context->builder->icmp(Builder::INT_NE, $localOnlyI8, $i8->constInt(0, false));
        $lookupBb = $fn->appendBasicBlock('getenv_nested_lookup');
        $missingBb = $fn->appendBasicBlock('getenv_nested_missing');
        $hitBb = $fn->appendBasicBlock('getenv_nested_hit');
        $doneBb = $fn->appendBasicBlock('getenv_nested_done');
        $context->builder->branchIf($isLocal, $missingBb, $lookupBb);

        $context->builder->positionAtEnd($lookupBb);
        $owned = StringGetenv::invokeNestedLeaf($context, $nameStr);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $owned, $strPtrTy->constNull());
        $context->builder->branchIf($isNull, $missingBb, $hitBb);

        $context->builder->positionAtEnd($hitBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $owned
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($missingBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $ptr,
            $i32->constInt(0, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $ptr;
    }

    public static function putenv(Context $context, Value $assignmentStr): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'putenv_emit_cont');

        // Always NestedJIT PutenvJitHelper (overlay + process-environ kernel) (#21023 / #23414).
        self::emitPutenvSyntaxGuard($context, $assignmentStr);
        StringGetenv::ensurePutenvLinked($context);
        $result = JitNestedHelperCoerce::callHelper(
            $context,
            StringGetenv::helperFunction(
                $context,
                'PHPCompiler\ext\standard\PutenvJitHelper::putenv'
            ),
            [$assignmentStr]
        );

        // Prefer bare i1 for Internal::call inference; value-box bools also work via extract.
        return JitNestedHelperCoerce::extractBoolFromHelperResult($context, $result);
    }

    /**
     * putenv from a compile-time "NAME=value" literal — avoid __string__ GEPs (#5965).
     *
     * Helper-only: {@see PutenvJitHelper::putenv} owns overlay + process-environ mirror via
     * {@see phpc_putenv_kernel} (no inline libc emission here — #23414).
     */
    public static function putenvFromCStringLiteral(Context $context, string $assignment): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'putenv_lit_emit_cont');
        $i1 = $context->getTypeFromString('int1');
        $eqPos = strpos($assignment, '=');
        if (false === $eqPos || 0 === $eqPos) {
            return $i1->constInt(0, false);
        }

        $str = $context->builder->load($context->constantStringFromString($assignment));
        StringGetenv::ensurePutenvLinked($context);
        JitNestedHelperCoerce::callHelper(
            $context,
            StringGetenv::helperFunction(
                $context,
                'PHPCompiler\ext\standard\PutenvJitHelper::putenv'
            ),
            [$str]
        );

        return $i1->constInt(1, false);
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
