<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM stream lifecycle helpers for standalone AOT — is_resource/fclose/feof/fflush (#5343, #9442).
 *
 * Embed JIT uses {@see StreamLifecycleRuntime} + {@see StreamLifecycleJitHelper} PHP instead.
 * php-src: ext/standard/file.c, ext/standard/streamsfuncs.c
 */
final class StreamLifecycleStandaloneLlvm
{
    private const MAX_HANDLES = 256;

    private const DIR_HANDLE_BASE = 0x10000000;

    private const GLOBAL_HANDLES = 'phpc_stream_handles';

    private const GLOBAL_PATHS = 'phpc_stream_paths';

    private const GLOBAL_IS_POPEN = 'phpc_stream_is_popen';

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_is_resource',
        '__compiler_fclose',
        '__compiler_pclose',
        '__compiler_feof',
        '__compiler_fflush',
    ];

    public static function implement(Context $context): void
    {
        if (self::allRuntimeFunctionsLinked($context)) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureExternGlobals($context);
        self::ensureLibc($context);

        self::implementIfMissing($context, '__compiler_is_resource', self::emitIsResource(...));
        self::implementIfMissing($context, '__compiler_fclose', self::emitFclose(...));
        self::implementIfMissing($context, '__compiler_pclose', self::emitPclose(...));
        self::implementIfMissing($context, '__compiler_feof', self::emitFeof(...));
        self::implementIfMissing($context, '__compiler_fflush', self::emitFflush(...));
    }

    private static function allRuntimeFunctionsLinked(Context $context): bool
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                return false;
            }
        }

        return true;
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
        $ft = match ($name) {
            '__compiler_is_resource', '__compiler_fclose', '__compiler_feof', '__compiler_fflush', '__compiler_pclose'
                => $context->context->functionType($i32, false, $i64),
            default => throw new \LogicException('StreamLifecycleStandaloneLlvm: unknown '.$name),
        };
        $fn = $context->module->addFunction($name, $ft);
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $void = $context->getTypeFromString('void');

        foreach ([
            ['__phpc_resolve_stream', $i8p, [$i64]],
            ['__compiler_is_dir_resource', $i32, [$i64]],
            ['__compiler_is_process_resource', $i32, [$i64]],
            ['fclose', $i32, [$i8p]],
            ['pclose', $i32, [$i8p]],
            ['feof', $i32, [$i8p]],
            ['fflush', $i32, [$i8p]],
            ['free', $void, [$i8p]],
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

    private static function ensureExternGlobals(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $tableTy = $i8p->arrayType(self::MAX_HANDLES);
        $i8 = $context->getTypeFromString('int8');
        $flagTy = $i8->arrayType(self::MAX_HANDLES);
        foreach ([self::GLOBAL_HANDLES => $tableTy, self::GLOBAL_PATHS => $tableTy, self::GLOBAL_IS_POPEN => $flagTy] as $name => $ty) {
            if (null !== $context->module->getNamedGlobal($name)) {
                continue;
            }
            $context->module->addGlobal($ty, $name);
        }
    }

    private static function loadTableSlot(Context $context, string $globalName, Value $handle): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal($globalName);
        if (null === $global) {
            throw new \LogicException('StreamLifecycleStandaloneLlvm: '.$globalName.' missing');
        }
        $slot = $context->builder->gep($global, $zero, $handle);

        return $context->builder->load($context->builder->bitcast($slot, $i8p->pointerType(0)));
    }

    private static function storeTableSlot(Context $context, string $globalName, Value $handle, Value $value): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal($globalName);
        if (null === $global) {
            throw new \LogicException('StreamLifecycleStandaloneLlvm: '.$globalName.' missing');
        }
        $slot = $context->builder->gep($global, $zero, $handle);
        $context->builder->store($value, $context->builder->bitcast($slot, $i8p->pointerType(0)));
    }

    private static function emitIsResource(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('is_res_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);
        $two = $i64->constInt(2, false);
        $dirBase = $i64->constInt(self::DIR_HANDLE_BASE, false);

        $isDirRange = $context->builder->icmp(Builder::INT_SGE, $handle, $dirBase);
        $dirCheckBb = $fn->appendBasicBlock('is_res_dir_check');
        $processCheckBb = $fn->appendBasicBlock('is_res_process_check');
        $context->builder->branchIf($isDirRange, $dirCheckBb, $processCheckBb);

        $context->builder->positionAtEnd($dirCheckBb);
        $isDir = $context->builder->call($context->lookupFunction('__compiler_is_dir_resource'), $handle);
        $dirOk = $context->builder->icmp(Builder::INT_NE, $isDir, $zeroI32);
        $dirTrueBb = $fn->appendBasicBlock('is_res_dir_true');
        $context->builder->branchIf($dirOk, $dirTrueBb, $processCheckBb);

        $context->builder->positionAtEnd($dirTrueBb);
        $context->builder->returnValue($oneI32);

        $context->builder->positionAtEnd($processCheckBb);
        $processBase = $i64->constInt(ProcessOpenJit::PROCESS_HANDLE_BASE, false);
        $isProcessRange = $context->builder->icmp(Builder::INT_SGE, $handle, $processBase);
        $stdioBb = $fn->appendBasicBlock('is_res_stdio');
        $processProbeBb = $fn->appendBasicBlock('is_res_process_probe');
        $context->builder->branchIf($isProcessRange, $processProbeBb, $stdioBb);

        $context->builder->positionAtEnd($processProbeBb);
        $isProcess = $context->builder->call($context->lookupFunction('__compiler_is_process_resource'), $handle);
        $processOk = $context->builder->icmp(Builder::INT_NE, $isProcess, $zeroI32);
        $processTrueBb = $fn->appendBasicBlock('is_res_process_true');
        $context->builder->branchIf($processOk, $processTrueBb, $stdioBb);

        $context->builder->positionAtEnd($processTrueBb);
        $context->builder->returnValue($oneI32);

        $context->builder->positionAtEnd($stdioBb);
        $isStdio = $context->builder->icmp(Builder::INT_SLE, $handle, $two);
        $falseBb = $fn->appendBasicBlock('is_res_false');
        $resolveBb = $fn->appendBasicBlock('is_res_resolve');
        $context->builder->branchIf($isStdio, $falseBb, $resolveBb);

        $context->builder->positionAtEnd($falseBb);
        $context->builder->returnValue($zeroI32);

        $context->builder->positionAtEnd($resolveBb);
        $i8p = $context->getTypeFromString('int8*');
        $fp = $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $handle);
        $open = $context->builder->icmp(Builder::INT_NE, $fp, $i8p->constNull());
        $context->builder->returnValue($context->builder->select($open, $oneI32, $zeroI32));
    }

    private static function emitFclose(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('fclose_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $zeroI64 = $i64->constInt(0, false);
        $max = $i64->constInt(self::MAX_HANDLES, false);
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);
        $nullPtr = $i8p->constNull();

        $badHandle = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLE, $handle, $zeroI64),
            $context->builder->icmp(Builder::INT_SGE, $handle, $max)
        );
        $failBb = $fn->appendBasicBlock('fclose_fail');
        $loadBb = $fn->appendBasicBlock('fclose_load');
        $context->builder->branchIf($badHandle, $failBb, $loadBb);

        $context->builder->positionAtEnd($loadBb);
        $fp = self::loadTableSlot($context, self::GLOBAL_HANDLES, $handle);
        $noFp = $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr);
        $workBb = $fn->appendBasicBlock('fclose_work');
        $context->builder->branchIf($noFp, $failBb, $workBb);

        $context->builder->positionAtEnd($workBb);
        self::storeTableSlot($context, self::GLOBAL_HANDLES, $handle, $nullPtr);
        $path = self::loadTableSlot($context, self::GLOBAL_PATHS, $handle);
        $hasPath = $context->builder->icmp(Builder::INT_NE, $path, $nullPtr);
        $freePathBb = $fn->appendBasicBlock('fclose_free_path');
        $closeBb = $fn->appendBasicBlock('fclose_close');
        $context->builder->branchIf($hasPath, $freePathBb, $closeBb);

        $context->builder->positionAtEnd($freePathBb);
        $context->builder->call($context->lookupFunction('free'), $path);
        self::storeTableSlot($context, self::GLOBAL_PATHS, $handle, $nullPtr);
        StreamPathRuntime::emitClearPath($context, $handle);
        $context->builder->branch($closeBb);

        $context->builder->positionAtEnd($closeBb);
        $rc = $context->builder->call($context->lookupFunction('fclose'), $fp);
        $ok = $context->builder->icmp(Builder::INT_EQ, $rc, $zeroI32);
        $context->builder->returnValue($context->builder->select($ok, $oneI32, $zeroI32));

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($zeroI32);
    }

    private static function emitPclose(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('pclose_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $zeroI64 = $i64->constInt(0, false);
        $max = $i64->constInt(self::MAX_HANDLES, false);
        $nullPtr = $i8p->constNull();
        $minusOne = $i32->constInt(-1, true);

        $badHandle = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLE, $handle, $zeroI64),
            $context->builder->icmp(Builder::INT_SGE, $handle, $max)
        );
        $failBb = $fn->appendBasicBlock('pclose_fail');
        $loadBb = $fn->appendBasicBlock('pclose_load');
        $context->builder->branchIf($badHandle, $failBb, $loadBb);

        $context->builder->positionAtEnd($loadBb);
        $fp = self::loadTableSlot($context, self::GLOBAL_HANDLES, $handle);
        $noFp = $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr);
        $checkPopenBb = $fn->appendBasicBlock('pclose_check');
        $context->builder->branchIf($noFp, $failBb, $checkPopenBb);

        $context->builder->positionAtEnd($checkPopenBb);
        $zeroI64Phi = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal(self::GLOBAL_IS_POPEN);
        if (null === $global) {
            throw new \LogicException('StreamLifecycleStandaloneLlvm: '.self::GLOBAL_IS_POPEN.' missing');
        }
        $flagSlot = $context->builder->gep($global, $zeroI64Phi, $handle);
        $isPopen = $context->builder->load($context->builder->bitcast($flagSlot, $i8->pointerType(0)));
        $notPopen = $context->builder->icmp(Builder::INT_EQ, $isPopen, $i8->constInt(0, false));
        $workBb = $fn->appendBasicBlock('pclose_work');
        $context->builder->branchIf($notPopen, $failBb, $workBb);

        $context->builder->positionAtEnd($workBb);
        self::storeTableSlot($context, self::GLOBAL_HANDLES, $handle, $nullPtr);
        $flagSlotClear = $context->builder->gep($global, $zeroI64Phi, $handle);
        $context->builder->store($i8->constInt(0, false), $context->builder->bitcast($flagSlotClear, $i8->pointerType(0)));
        $status = $context->builder->call($context->lookupFunction('pclose'), $fp);
        $context->builder->returnValue($status);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($minusOne);
    }

    private static function emitFeof(Context $context, LlvmFunction $fn): void
    {
        self::emitResolveBoolLibc($context, $fn, 'feof', trueWhenNull: true);
    }

    private static function emitFflush(Context $context, LlvmFunction $fn): void
    {
        self::emitResolveBoolLibc($context, $fn, 'fflush', trueWhenNull: false);
    }

    private static function emitResolveBoolLibc(
        Context $context,
        LlvmFunction $fn,
        string $libcFn,
        bool $trueWhenNull
    ): void {
        $entry = $fn->appendBasicBlock($libcFn.'_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);
        $nullRet = $trueWhenNull ? $oneI32 : $zeroI32;

        $fp = $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $handle);
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $i8p->constNull());
        $failBb = $fn->appendBasicBlock($libcFn.'_fail');
        $workBb = $fn->appendBasicBlock($libcFn.'_work');
        $context->builder->branchIf($fpNull, $failBb, $workBb);

        $context->builder->positionAtEnd($workBb);
        $rc = $context->builder->call($context->lookupFunction($libcFn), $fp);
        $ok = $context->builder->icmp(Builder::INT_EQ, $rc, $zeroI32);
        $context->builder->returnValue($context->builder->select($ok, $oneI32, $zeroI32));

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullRet);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StreamLifecycleJit implement');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
