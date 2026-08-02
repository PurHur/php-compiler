<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StreamGlobalsJit;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT ABI bridges for __compiler_fsync / __compiler_fdatasync (#9815, #19660, #23004, #26929).
 *
 * Thin AOT NestedJIT of the former stream-sync PHP helper mis-saw fopen handles as unsyncable
 * then aborted in the warn path (#26929). Happy path is libc fsync(2)/fdatasync(2) after
 * __phpc_resolve_stream + fflush + fileno — same shape as getmypid → getpid (#26944).
 * VM SSOT stays {@see VmFs::fsync} / {@see VmPhpFdStream::syncFileno}.
 *
 * Call-site {@see ensureLinked} restores the caller insert block after bridge emit
 * (peer #26884 / #26900 — parentless BB).
 *
 * php-src: ext/standard/file.c — PHP_FUNCTION(fsync) / fdatasync
 */
final class JitStreamSyncKernel
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

        // Preserve caller insert block — clearInsertionPosition alone orphans mid-emit
        // (fsync/fdatasync thin AOT: "Current basic block has no parent function", #26929 / peer #26900).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        StreamGlobalsJit::implement($context);
        self::ensureLibc($context);

        self::implementIfMissing($context, '__compiler_fsync', static fn ($ctx, $fn) => self::emitSync($ctx, $fn, false));
        self::implementIfMissing($context, '__compiler_fdatasync', static fn ($ctx, $fn) => self::emitSync($ctx, $fn, true));
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
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
            ['__phpc_resolve_stream', $i8p, [$i64]],
            ['fflush', $i32, [$i8p]],
            ['fileno', $i32, [$i8p]],
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

    private static function emitSync(Context $context, LlvmFunction $fn, bool $dataOnly): void
    {
        $handle = $fn->getParam(0);
        $entry = $fn->appendBasicBlock('sync_entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);
        $nullFile = $i8p->constNull();

        $fp = $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $handle);
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $nullFile);
        $failBb = $fn->appendBasicBlock('sync_fail');
        $flushBb = $fn->appendBasicBlock('sync_flush');
        $context->builder->branchIf($fpNull, $failBb, $flushBb);

        $context->builder->positionAtEnd($flushBb);
        $ffRc = $context->builder->call($context->lookupFunction('fflush'), $fp);
        $ffBad = $context->builder->icmp(Builder::INT_NE, $ffRc, $zero);
        $filenoBb = $fn->appendBasicBlock('sync_fileno');
        $context->builder->branchIf($ffBad, $failBb, $filenoBb);

        $context->builder->positionAtEnd($filenoBb);
        $fd = $context->builder->call($context->lookupFunction('fileno'), $fp);
        $fdBad = $context->builder->icmp(Builder::INT_SLT, $fd, $zero);
        $doSyncBb = $fn->appendBasicBlock('sync_do');
        $context->builder->branchIf($fdBad, $failBb, $doSyncBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($zero);

        $context->builder->positionAtEnd($doSyncBb);
        $syncName = $dataOnly ? 'fdatasync' : 'fsync';
        $rc = $context->builder->call($context->lookupFunction($syncName), $fd);
        $ok = $context->builder->icmp(Builder::INT_EQ, $rc, $zero);
        $context->builder->returnValue($context->builder->select($ok, $one, $zero));
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after JitStreamSyncKernel implement (#9815)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
