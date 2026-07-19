<?php

declare(strict_types=1);

/**
 * JIT/AOT helpers for getenv() and putenv() via GetenvJitHelper PHP (#9092, #8992, #20499, #21023).
 *
 * Embed + thin standalone AOT: syntax guard + {@see GetenvJitHelper::putenv} overlay +
 * libc setenv mirror (getenv #20644 / rename #20603 shape — no thin libc-only fork).
 * Bool results are bare {@see int1} so Internal::call type inference succeeds (#21023).
 * php-src: ext/standard/basic_functions.c — zif_putenv
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringGetenv;
use PHPCompiler\JIT\Builtin\StringGetenvAll;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
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
        BasicBlockHelper::ensureOpenInsertBlock($context, 'putenv_emit_cont');

        // Always NestedJIT GetenvJitHelper overlay + libc mirror (#21023).
        // putenv_.php materializes via __string__separate so the setenv mirror sees a
        // NUL-terminated buffer.
        self::emitPutenvSyntaxGuard($context, $assignmentStr);
        StringGetenv::ensurePutenvLinked($context);
        $result = JitNestedHelperCoerce::callHelper(
            $context,
            StringGetenv::helperFunction(
                $context,
                'PHPCompiler\ext\standard\GetenvJitHelper::putenv'
            ),
            [$assignmentStr]
        );
        self::emitLibcPutenvMirror($context, $assignmentStr);

        // Prefer bare i1 for Internal::call inference; value-box bools also work via extract.
        return JitNestedHelperCoerce::extractBoolFromHelperResult($context, $result);
    }

    /**
     * putenv from a compile-time "NAME=value" literal — avoid __string__ GEPs (#5965).
     *
     * Uses the same malloc+NUL+setenv path as {@see emitLibcPutenvMirror} so long values
     * with CR/LF (multipart REQUEST_BODY) round-trip under user-script AOT.
     * Direct setenv(nameConst, valueConst) left REQUEST_BODY empty after getenv (#5965).
     * Always NestedJIT {@see GetenvJitHelper::putenv} overlay (#21023 / #20499).
     */
    public static function putenvFromCStringLiteral(Context $context, string $assignment): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'putenv_lit_emit_cont');
        LibcExtern::register($context);
        $i1 = $context->getTypeFromString('int1');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $eqPos = strpos($assignment, '=');
        if (false === $eqPos || 0 === $eqPos) {
            return $i1->constInt(0, false);
        }

        $len = strlen($assignment);
        $src = $context->pointerFromStringConstant($assignment);
        $one = $sizeT->constInt(1, false);
        $lenVal = $sizeT->constInt($len, false);
        $bufLen = $context->builder->add($lenVal, $one);
        $buf = $context->builder->call($context->lookupFunction('malloc'), $bufLen);
        $cStr = $context->builder->pointerCast($buf, $i8p);
        $context->intrinsic->memcpy($cStr, $src, $i64->constInt($len, false), false);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($cStr, $lenVal)
        );

        $eq = $context->builder->call(
            $context->lookupFunction('strchr'),
            $cStr,
            $i32->constInt(ord('='), false)
        );
        $hasEq = $context->builder->icmp(Builder::INT_NE, $eq, $i8p->constNull());
        $ok = BasicBlockHelper::append($context, 'putenv_lit_setenv_ok');
        $skip = BasicBlockHelper::append($context, 'putenv_lit_setenv_skip');
        $context->builder->branchIf($hasEq, $ok, $skip);

        $context->builder->positionAtEnd($ok);
        $context->builder->store($i8->constInt(0, false), $eq);
        $valueStart = $context->builder->inBoundsGEP($eq, $one);
        $context->builder->call(
            $context->lookupFunction('setenv'),
            $cStr,
            $valueStart,
            $i32->constInt(1, false)
        );
        $context->builder->branch($skip);
        $context->builder->positionAtEnd($skip);
        $context->builder->call($context->lookupFunction('free'), $cStr);

        $str = $context->builder->load($context->constantStringFromString($assignment));
        StringGetenv::ensurePutenvLinked($context);
        JitNestedHelperCoerce::callHelper(
            $context,
            StringGetenv::helperFunction(
                $context,
                'PHPCompiler\ext\standard\GetenvJitHelper::putenv'
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

    /**
     * Mirror overlay putenv into process environ for libc getenv readers (#17316).
     *
     * Uses POSIX setenv() (copies name/value) — not putenv(malloc'd "NAME=value"), which
     * heap-corrupts when parse_str/strtok later touch getenv buffers under ≥2 mirrors.
     *
     * Copy via length+NUL (not strdup on `__string__.value`): string constants may lack a
     * trailing NUL, so strdup over-reads and corrupts the heap on some literal lengths.
     */
    private static function emitLibcPutenvMirror(Context $context, Value $assignmentStr): void
    {
        LibcExtern::register($context);
        $map = $context->structFieldMap['__string__'];
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);
        $zero = $i64->constInt(0, false);

        $len = $context->builder->load(
            $context->builder->structGep($assignmentStr, $map['length'])
        );
        $bytes = $context->builder->structGep($assignmentStr, $map['value']);
        $bufLen = $context->builder->add(
            $len->typeOf() === $sizeT ? $len : $context->builder->truncOrBitCast($len, $sizeT),
            $one
        );
        $buf = $context->builder->call($context->lookupFunction('malloc'), $bufLen);
        $cStr = $context->builder->pointerCast($buf, $i8p);
        $context->intrinsic->memcpy($cStr, $bytes, $len, false);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($cStr, $len)
        );

        $eq = $context->builder->call(
            $context->lookupFunction('strchr'),
            $cStr,
            $i32->constInt(ord('='), false)
        );
        $hasEq = $context->builder->icmp(Builder::INT_NE, $eq, $i8p->constNull());
        $ok = BasicBlockHelper::append($context, 'putenv_setenv_ok');
        $skip = BasicBlockHelper::append($context, 'putenv_setenv_skip');
        $context->builder->branchIf($hasEq, $ok, $skip);

        $context->builder->positionAtEnd($ok);
        $context->builder->store($i8->constInt(0, false), $eq);
        $valueStart = $context->builder->inBoundsGEP($eq, $one);
        $context->builder->call(
            $context->lookupFunction('setenv'),
            $cStr,
            $valueStart,
            $i32->constInt(1, false)
        );
        $context->builder->branch($skip);
        $context->builder->positionAtEnd($skip);
        $context->builder->call($context->lookupFunction('free'), $cStr);
    }
}
