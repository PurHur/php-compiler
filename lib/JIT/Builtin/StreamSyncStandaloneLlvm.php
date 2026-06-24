<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\VmStreamSync;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM lowering for fsync()/fdatasync() stream sync helpers (#6062, #6813).
 *
 * Replaces __compiler_fsync / __compiler_fdatasync in lib/AOT/runtime/phpc_stream.c.
 * Stream resolve stays thin C ABI ({@see __phpc_resolve_stream}).
 *
 * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(fsync), PHP_FUNCTION(fdatasync)
 */
final class StreamSyncStandaloneLlvm
{
    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_fsync',
        '__compiler_fdatasync',
    ];

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_fsync');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureLibc($context);

        self::implementIfMissing($context, '__compiler_fsync', static fn ($ctx, $fn) => self::emitFsync($ctx, $fn));
        self::implementIfMissing($context, '__compiler_fdatasync', static fn ($ctx, $fn) => self::emitFdatasync($ctx, $fn));
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareFunction($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $fn = $context->module->addFunction(
            $name,
            $context->context->functionType($i32, false, $i64)
        );
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');

        foreach ([
            ['strlen', $i64, [$i8p]],
            ['__compiler_trigger_error', $i8p, [$i8p, $i64, $i32, $i8p, $i32]],
            ['fsync', $i32, [$i32]],
            ['fdatasync', $i32, [$i32]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function emitUnsyncableWarning(Context $context, string $function): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $message = 'fdatasync' === $function
            ? VmStreamSync::FDATASYNC_UNSYNCABLE_WARNING
            : VmStreamSync::FSYNC_UNSYNCABLE_WARNING;
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $context->builder->call($context->lookupFunction('strlen'), $msgPtr);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(2, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }

    private static function emitFlushFileno(
        Context $context,
        LlvmFunction $fn,
        \PHPLLVM\Value $handle,
        string $function
    ): array {
        $entry = $fn->appendBasicBlock('sync_entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);
        $nullFile = $i8p->constNull();

        $fp = $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $handle);
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $nullFile);
        $failSilent = $fn->appendBasicBlock('sync_fail_silent');
        $flush = $fn->appendBasicBlock('sync_flush');
        $context->builder->branchIf($fpNull, $failSilent, $flush);

        $context->builder->positionAtEnd($flush);
        $ffRc = $context->builder->call($context->lookupFunction('fflush'), $fp);
        $ffBad = $context->builder->icmp(Builder::INT_NE, $ffRc, $zero);
        $filenoBlock = $fn->appendBasicBlock('sync_fileno');
        $warnFail = $fn->appendBasicBlock('sync_warn_fail');
        $context->builder->branchIf($ffBad, $warnFail, $filenoBlock);

        $context->builder->positionAtEnd($filenoBlock);
        $fd = $context->builder->call($context->lookupFunction('fileno'), $fp);
        $fdBad = $context->builder->icmp(Builder::INT_SLT, $fd, $zero);
        $sync = $fn->appendBasicBlock('sync_do');
        $context->builder->branchIf($fdBad, $warnFail, $sync);

        $context->builder->positionAtEnd($warnFail);
        self::emitUnsyncableWarning($context, $function);
        $context->builder->returnValue($zero);

        $context->builder->positionAtEnd($failSilent);
        $context->builder->returnValue($zero);

        return [$sync, $fd, $one, $zero];
    }

    private static function emitFsync(Context $context, LlvmFunction $fn): void
    {
        $handle = $fn->getParam(0);
        [$sync, $fd, $one, $zero] = self::emitFlushFileno($context, $fn, $handle, 'fsync');

        $context->builder->positionAtEnd($sync);
        $rc = $context->builder->call($context->lookupFunction('fsync'), $fd);
        $ok = $context->builder->icmp(Builder::INT_EQ, $rc, $zero);
        $context->builder->returnValue($context->builder->select($ok, $one, $zero));
    }

    private static function emitFdatasync(Context $context, LlvmFunction $fn): void
    {
        $handle = $fn->getParam(0);
        [$sync, $fd, $one, $zero] = self::emitFlushFileno($context, $fn, $handle, 'fdatasync');

        $context->builder->positionAtEnd($sync);
        $rc = $context->builder->call($context->lookupFunction('fdatasync'), $fd);
        $ok = $context->builder->icmp(Builder::INT_EQ, $rc, $zero);
        $context->builder->returnValue($context->builder->select($ok, $one, $zero));
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StreamSyncJit implement');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
