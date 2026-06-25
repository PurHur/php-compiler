<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStreamBucket;
use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for stream_bucket_* registry via StreamBucketJitHelper PHP (#9380).
 *
 * Replaces slot-table LLVM globals (~550 LOC). SSOT: {@see \PHPCompiler\ext\standard\StreamBucketJitHelper}.
 * php-src: ext/standard/streams.c — stream_bucket_new, brigade helpers
 */
final class StreamBucketRuntime
{
    public const BUCKET_HANDLE_BASE = 0x30000000;

    public const BRIGADE_HANDLE_BASE = 0x40000000;

    private const HELPER_PATH = '/ext/standard/StreamBucketJitHelper.php';

    private const REGISTER = 'PHPCompiler\\ext\\standard\\StreamBucketJitHelper::registerBucket';

    private const BUCKET_DATA = 'PHPCompiler\\ext\\standard\\StreamBucketJitHelper::bucketData';

    private const IS_BUCKET = 'PHPCompiler\\ext\\standard\\StreamBucketJitHelper::isBucketResource';

    private const IS_BRIGADE = 'PHPCompiler\\ext\\standard\\StreamBucketJitHelper::isBrigadeResource';

    private const BRIGADE_ALLOC = 'PHPCompiler\\ext\\standard\\StreamBucketJitHelper::brigadeAlloc';

    private const BRIGADE_PUSH = 'PHPCompiler\\ext\\standard\\StreamBucketJitHelper::brigadePush';

    private const BRIGADE_POP = 'PHPCompiler\\ext\\standard\\StreamBucketJitHelper::brigadePop';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::REGISTER,
        self::BUCKET_DATA,
        self::IS_BUCKET,
        self::IS_BRIGADE,
        self::BRIGADE_ALLOC,
        self::BRIGADE_PUSH,
        self::BRIGADE_POP,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_stream_bucket_register',
        '__compiler_stream_bucket_data',
        '__compiler_is_bucket_resource',
        '__compiler_is_brigade_resource',
        '__compiler_stream_brigade_alloc',
        '__compiler_stream_bucket_brigade_push',
        '__compiler_stream_bucket_brigade_pop',
        '__compiler_stream_bucket_object_new',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::registerDeclarations($context);
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            self::implement($context);
        }
    }

    /** Standalone AOT: emit bucket runtime into the module during Context init (#6323). */
    public static function ensureStandaloneBodies(Context $context): void
    {
        self::registerDeclarations($context);
        self::implement($context);
    }

    public static function registerDeclarations(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            self::declareFunction($context, $name);
        }
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_stream_bucket_register');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $restore = self::captureInsertBlock($context);
        self::ensureJitHelperCompiled($context);
        self::implementIfMissing($context, '__compiler_stream_bucket_register', self::implementRegisterBridge(...));
        self::implementIfMissing($context, '__compiler_stream_bucket_data', self::implementBucketDataBridge(...));
        self::implementIfMissing($context, '__compiler_is_bucket_resource', self::implementIsBucketBridge(...));
        self::implementIfMissing($context, '__compiler_is_brigade_resource', self::implementIsBrigadeBridge(...));
        self::implementIfMissing($context, '__compiler_stream_brigade_alloc', self::implementBrigadeAllocBridge(...));
        self::implementIfMissing($context, '__compiler_stream_bucket_brigade_push', self::implementBrigadePushBridge(...));
        self::implementIfMissing($context, '__compiler_stream_bucket_brigade_pop', self::implementBrigadePopBridge(...));
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            self::implementIfMissing($context, '__compiler_stream_bucket_object_new', self::emitBucketObjectNew(...));
        }
        self::registerLinkedRuntime($context);
        self::restoreInsertBlock($context, $restore);
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
        if (null !== self::captureInsertBlock($context)) {
            $context->builder->clearInsertionPosition();
        }
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
        $strPtr = $context->getTypeFromString('__string__*');

        $ft = match ($name) {
            '__compiler_stream_bucket_register' => $context->context->functionType($i64, false, $strPtr),
            '__compiler_stream_bucket_data' => $context->context->functionType($strPtr, false, $i64),
            '__compiler_is_bucket_resource', '__compiler_is_brigade_resource' => $context->context->functionType($i32, false, $i64),
            '__compiler_stream_brigade_alloc' => $context->context->functionType($i64, false),
            '__compiler_stream_bucket_brigade_push' => $context->context->functionType($i32, false, $i64, $i64),
            '__compiler_stream_bucket_brigade_pop' => $context->context->functionType($i64, false, $i64),
            '__compiler_stream_bucket_object_new' => $context->context->functionType(
                $context->getTypeFromString('__value__*'),
                false,
                $i64,
                $strPtr
            ),
            default => throw new \LogicException('StreamBucketRuntime: unknown '.$name),
        };

        $fn = $context->module->addFunction($name, $ft);
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function implementRegisterBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sb_reg_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::REGISTER),
            $fn->getParam(0)
        );
        $context->builder->returnValue($result);
    }

    private static function implementBucketDataBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sb_data_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::BUCKET_DATA),
            $fn->getParam(0)
        );
        $context->builder->returnValue($result);
    }

    private static function implementIsBucketBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sb_is_bucket_entry');
        $context->builder->positionAtEnd($entry);
        $i32 = $context->getTypeFromString('int32');
        $result = $context->builder->trunc(
            $context->builder->call(
                self::helperFunction($context, self::IS_BUCKET),
                $fn->getParam(0)
            ),
            $i32
        );
        $context->builder->returnValue($result);
    }

    private static function implementIsBrigadeBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sb_is_brig_entry');
        $context->builder->positionAtEnd($entry);
        $i32 = $context->getTypeFromString('int32');
        $result = $context->builder->trunc(
            $context->builder->call(
                self::helperFunction($context, self::IS_BRIGADE),
                $fn->getParam(0)
            ),
            $i32
        );
        $context->builder->returnValue($result);
    }

    private static function implementBrigadeAllocBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sb_brig_alloc_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(self::helperFunction($context, self::BRIGADE_ALLOC));
        $context->builder->returnValue($result);
    }

    private static function implementBrigadePushBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sb_push_entry');
        $context->builder->positionAtEnd($entry);
        $i32 = $context->getTypeFromString('int32');
        $result = $context->builder->trunc(
            $context->builder->call(
                self::helperFunction($context, self::BRIGADE_PUSH),
                $fn->getParam(0),
                $fn->getParam(1)
            ),
            $i32
        );
        $context->builder->returnValue($result);
    }

    private static function implementBrigadePopBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sb_pop_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::BRIGADE_POP),
            $fn->getParam(0)
        );
        $context->builder->returnValue($result);
    }

    private static function emitBucketObjectNew(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sb_obj_entry');
        $context->builder->positionAtEnd($entry);

        $bucketHandle = $fn->getParam(0);
        $dataStr = $fn->getParam(1);
        $ptr = JitStreamBucket::buildStdClassBucketValue($context, $bucketHandle, $dataStr);
        $context->builder->returnValue($ptr);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after StreamBucketJitHelper compile (#9380)');
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

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'StreamBucketJitHelper.php');
            if (null === $block) {
                throw new \LogicException('StreamBucketJitHelper.php parseAndCompile failed (#9380)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9380)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null !== $fn) {
                $context->registerFunction($name, $fn);
            }
        }
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);

            return;
        }
        $context->builder->clearInsertionPosition();
    }
}
