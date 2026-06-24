<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_fsync / __compiler_fdatasync via StreamSyncJitHelper PHP (#9815).
 *
 * JIT embed and AOT standalone compile {@see StreamSyncJitHelper} into the module; thin LLVM
 * bridges forward __compiler_fsync/__compiler_fdatasync ABI. php-src: ext/standard/file.c
 */
final class StreamSyncJit
{
    private const HELPER_PATH = '/ext/standard/StreamSyncJitHelper.php';

    private const IS_SUPPORTED_HELPER = 'PHPCompiler\\ext\\standard\\StreamSyncJitHelper::isSyncSupported';

    private const WARN_HELPER = 'PHPCompiler\\ext\\standard\\StreamSyncJitHelper::warnUnsyncable';

    private const SYNC_FILENO_HELPER = 'PHPCompiler\\ext\\standard\\StreamSyncJitHelper::syncFileno';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::IS_SUPPORTED_HELPER,
        self::WARN_HELPER,
        self::SYNC_FILENO_HELPER,
    ];

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

        StreamGlobalsJit::implement($context);
        self::ensureLibc($context);
        self::ensureJitHelperCompiled($context);

        self::implementIfMissing($context, '__compiler_fsync', static fn ($ctx, $fn) => self::emitSync($ctx, $fn, 0));
        self::implementIfMissing($context, '__compiler_fdatasync', static fn ($ctx, $fn) => self::emitSync($ctx, $fn, 1));
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
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

    private static function emitSync(Context $context, LlvmFunction $fn, int $dataOnly): void
    {
        $handle = $fn->getParam(0);
        $entry = $fn->appendBasicBlock('sync_entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);
        $nullFile = $i8p->constNull();

        $supportedRaw = $context->builder->call(
            self::helperFunction($context, self::IS_SUPPORTED_HELPER),
            $handle
        );
        $supported = JitNestedHelperCoerce::i64ToScalar($context, $supportedRaw, $i32);
        $notSupported = $context->builder->icmp(Builder::INT_EQ, $supported, $zero);
        $warnBb = $fn->appendBasicBlock('sync_warn');
        $resolveBb = $fn->appendBasicBlock('sync_resolve');
        $context->builder->branchIf($notSupported, $warnBb, $resolveBb);

        $context->builder->positionAtEnd($warnBb);
        $context->builder->call(
            self::helperFunction($context, self::WARN_HELPER),
            JitNestedHelperCoerce::scalarToI64($context, $i32->constInt($dataOnly, false), $i32)
        );
        $context->builder->returnValue($zero);

        $context->builder->positionAtEnd($resolveBb);
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
        $context->builder->call(
            self::helperFunction($context, self::WARN_HELPER),
            JitNestedHelperCoerce::scalarToI64($context, $i32->constInt($dataOnly, false), $i32)
        );
        $context->builder->returnValue($zero);

        $context->builder->positionAtEnd($doSyncBb);
        $rcRaw = $context->builder->call(
            self::helperFunction($context, self::SYNC_FILENO_HELPER),
            JitNestedHelperCoerce::scalarToI64($context, $fd, $i32),
            JitNestedHelperCoerce::scalarToI64($context, $i32->constInt($dataOnly, false), $i32)
        );
        $context->builder->returnValue(JitNestedHelperCoerce::i64ToScalar($context, $rcRaw, $i32));
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after StreamSyncJitHelper compile (#9815)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        LastErrorRuntime::ensureLinked($context);
        SilenceRuntime::ensureLinked($context);
        StringTriggerError::ensureLinked($context);

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'StreamSyncJitHelper.php');
            if (null === $block) {
                throw new \LogicException('StreamSyncJitHelper.php parseAndCompile failed (#9815)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9815)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StreamSyncJit implement (#9815)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
