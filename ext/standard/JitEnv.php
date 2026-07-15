<?php

declare(strict_types=1);

/**
 * JIT/AOT helpers for getenv() and putenv() via GetenvJitHelper PHP (#9092, #8992).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StreamIoRuntime;
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

        // Deferred user-script AOT: libc setenv only. Nested GetenvJitHelper::putenv
        // aborts on concat/slot temps (#17316). putenv_.php materializes via
        // __string__separate so the setenv mirror strdup sees a NUL-terminated buffer.
        // Skip syntax guard here: first-byte/length GEPs on some concat temps still
        // misfire under thin AOT even after separate (seen as SIGABRT in guard abort).
        if (StreamIoRuntime::shouldDeferHeavyStreamIoEmitters($context)) {
            self::emitLibcPutenvMirror($context, $assignmentStr);
            $i8 = $context->getTypeFromString('int8');

            return $i8->constInt(1, false);
        }

        self::emitPutenvSyntaxGuard($context, $assignmentStr);
        StringGetenv::ensurePutenvLinked($context);
        $result = JitNestedHelperCoerce::callHelper(
            $context,
            StringGetenv::helperFunction(
                $context,
                'PHPCompiler\\ext\\standard\\GetenvJitHelper::putenv'
            ),
            [$assignmentStr]
        );
        self::emitLibcPutenvMirror($context, $assignmentStr);

        return $result;
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
     * Callers must pass a NUL-terminated `__string__` (via `__string__separate` / literal).
     */
    private static function emitLibcPutenvMirror(Context $context, Value $assignmentStr): void
    {
        LibcExtern::register($context);
        $map = $context->structFieldMap['__string__'];
        $valueBytes = $context->builder->structGep($assignmentStr, $map['value']);
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);

        $dup = $context->builder->call($context->lookupFunction('strdup'), $valueBytes);
        $eq = $context->builder->call(
            $context->lookupFunction('strchr'),
            $dup,
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
            $dup,
            $valueStart,
            $i32->constInt(1, false)
        );
        $context->builder->branch($skip);
        $context->builder->positionAtEnd($skip);
        $context->builder->call($context->lookupFunction('free'), $dup);
    }
}
