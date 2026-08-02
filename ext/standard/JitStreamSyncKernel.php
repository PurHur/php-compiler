<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\LastErrorRuntime;
use PHPCompiler\JIT\Builtin\SilenceRuntime;
use PHPCompiler\JIT\Builtin\StreamGlobalsJit;
use PHPCompiler\JIT\Builtin\StringTriggerError;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT ABI bridges for __compiler_fsync / __compiler_fdatasync via StreamSyncJitHelper PHP (#9815, #19660, #23004, #26929).
 *
 * Quarantined from lib/JIT/Builtin/StreamSyncJit — {@see \PHPCompiler\JIT\Builtin\StreamSync}
 * stays the thin orchestrator. Helper compile: {@see JitVmHelperLink::ensureCompiled}
 * (peer StreamMeta #22994 / StreamBuffer #22979 / StreamMode #22968).
 * Call-site {@see ensureLinked} restores the caller insert block after bridge emit (peer #26884 / #26900).
 *
 * php-src: ext/standard/file.c — PHP_FUNCTION(fsync) / fdatasync
 */
final class JitStreamSyncKernel
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

        // Preserve caller insert block — clearInsertionPosition alone orphans mid-emit
        // (fsync/fdatasync thin AOT: "Current basic block has no parent function", #26929 / peer #26900).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        StreamGlobalsJit::implement($context);
        self::ensureLibc($context);
        self::ensureJitHelperCompiled($context);

        self::implementIfMissing($context, '__compiler_fsync', static fn ($ctx, $fn) => self::emitSync($ctx, $fn, 0));
        self::implementIfMissing($context, '__compiler_fdatasync', static fn ($ctx, $fn) => self::emitSync($ctx, $fn, 1));
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

        return JitVmHelperLink::lookupCompiled($context, $logical, '#23004');
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

        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#23004'
        );
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
