<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for stream buffer/chunk/timeout ABI via StreamBufferJitHelper PHP (#14462, #19788).
 *
 * Quarantined from lib/JIT/Builtin/StreamBufferRuntime — {@see \PHPCompiler\JIT\Builtin\StreamBufferRuntime}
 * stays the thin orchestrator.
 *
 * SSOT: {@see StreamBufferJitHelper}
 * php-src: ext/standard/streams.c
 */
final class JitStreamBufferKernel
{
    private const HELPER_PATH = '/ext/standard/StreamBufferJitHelper.php';

    private const SET_CHUNK_SIZE = 'PHPCompiler\\ext\\standard\\StreamBufferJitHelper::setChunkSizeArgv';

    private const SET_TIMEOUT = 'PHPCompiler\\ext\\standard\\StreamBufferJitHelper::setTimeoutArgv';

    private const SET_WRITE_BUFFER = 'PHPCompiler\\ext\\standard\\StreamBufferJitHelper::setWriteBufferArgv';

    private const SET_READ_BUFFER = 'PHPCompiler\\ext\\standard\\StreamBufferJitHelper::setReadBufferArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SET_CHUNK_SIZE,
        self::SET_TIMEOUT,
        self::SET_WRITE_BUFFER,
        self::SET_READ_BUFFER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_stream_set_chunk_size',
        '__compiler_stream_set_timeout',
        '__compiler_stream_set_write_buffer',
        '__compiler_stream_set_read_buffer',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (self::allRuntimeFunctionsLinked($context)) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureJitHelperCompiled($context);
        self::implementIfMissing(
            $context,
            '__compiler_stream_set_chunk_size',
            static function (Context $ctx, LlvmFunction $fn): void {
                self::implementI64I64Bridge($ctx, $fn, self::SET_CHUNK_SIZE);
            }
        );
        self::implementIfMissing($context, '__compiler_stream_set_timeout', self::implementTimeoutBridge(...));
        self::implementIfMissing(
            $context,
            '__compiler_stream_set_write_buffer',
            static function (Context $ctx, LlvmFunction $fn): void {
                self::implementI64I64Bridge($ctx, $fn, self::SET_WRITE_BUFFER);
            }
        );
        self::implementIfMissing(
            $context,
            '__compiler_stream_set_read_buffer',
            static function (Context $ctx, LlvmFunction $fn): void {
                self::implementI64I64Bridge($ctx, $fn, self::SET_READ_BUFFER);
            }
        );
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
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
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $ft = match ($name) {
            '__compiler_stream_set_timeout' => $context->context->functionType($i32, false, $i64, $i64, $i64),
            default => $context->context->functionType($i64, false, $i64, $i64),
        };
        $fn = $context->module->addFunction($name, $ft);
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function implementI64I64Bridge(Context $context, LlvmFunction $fn, string $helperLogical): void
    {
        $entry = $fn->appendBasicBlock('stream_buffer_i64_entry');
        $context->builder->positionAtEnd($entry);

        $i64 = $context->getTypeFromString('int64');
        $result = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $helperLogical),
            [$fn->getParam(0), $fn->getParam(1)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $result, $i64)
        );
    }

    private static function implementTimeoutBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('stream_buffer_timeout_entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $result = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::SET_TIMEOUT),
            [$fn->getParam(0), $fn->getParam(1), $fn->getParam(2)]
        );
        $context->builder->returnValue(
            $context->builder->truncOrBitCast(
                JitNestedHelperCoerce::coerceBridgeResult($context, $result, $i32),
                $i32
            )
        );
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after StreamBufferJitHelper compile (#14462)');
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
        $path = \dirname(__DIR__, 2).self::HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'StreamBufferJitHelper.php');
            if (null === $block) {
                throw new \LogicException('StreamBufferJitHelper.php parseAndCompile failed (#14462)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#14462)');
            }
        }
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

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after JitStreamBufferKernel bridge (#14462)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
