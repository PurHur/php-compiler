<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StreamIoRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for stream buffer/chunk/timeout ABI via StreamBufferJitHelper PHP (#14462, #19788, #22979).
 *
 * Quarantined from lib/JIT/Builtin/StreamBufferRuntime — {@see \PHPCompiler\JIT\Builtin\StreamBufferRuntime}
 * stays the thin orchestrator. Helper compile: {@see JitVmHelperLink::ensureCompiled}
 * (peer StreamMode #22968 / StreamFilter #21041).
 *
 * Thin user-script AOT: {@see implementThinWriteReadBuffers} — NestedJIT {@see StreamBufferJitHelper}
 * → {@see VmFs} never sees {@see \PHPCompiler\JIT\Builtin\StreamGlobalsJit} slots that
 * {@see JitStreamIoKernel} fopen fills (peer {@see JitStreamMetaThinAot} / #30787 gzwrite).
 * php-src streamsfuncs.c returns EOF (-1) when set_option is NOTIMPL for write buffer on
 * plainfile; read buffer returns 0 (#30788).
 *
 * SSOT: {@see StreamBufferJitHelper}
 * php-src: main/streams/streams.c — php_stream_set_chunk_size / set_option buffer+timeout
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
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        if ($context->isThinStandaloneAotMain()) {
            // Avoid NestedJIT/VmFs under thin AOT — helper statics never see libc fopen slots (#30788).
            self::implementThinStandaloneBuffers($context);
            self::registerLinkedRuntime($context);

            return;
        }

        if (self::allRuntimeFunctionsLinked($context)) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        try {
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
        } finally {
            if (null !== $savedBlock) {
                BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
            } else {
                $context->builder->clearInsertionPosition();
            }
        }
    }

    /**
     * Thin AOT stream_set_* returns — match Zend plainfile/memory set_option shape (#30788).
     *
     * php-src `ext/standard/streamsfuncs.c`: write/read use `RETURN_LONG(ret == 0 ? 0 : EOF)`.
     * Plainfile WRITE_BUFFER is NOTIMPL → EOF (-1); READ_BUFFER / timeout return 0 on this profile.
     * Chunk size returns prior default 8192 (php_stream chunk_size init).
     */
    private static function implementThinStandaloneBuffers(Context $context): void
    {
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        try {
            self::implementThinConstantReturn(
                $context,
                '__compiler_stream_set_write_buffer',
                'int64',
                -1,
                'sswb_thin',
                2
            );
            self::implementThinConstantReturn(
                $context,
                '__compiler_stream_set_read_buffer',
                'int64',
                0,
                'ssrb_thin',
                2
            );
            self::implementThinConstantReturn(
                $context,
                '__compiler_stream_set_chunk_size',
                'int64',
                8192,
                'sscs_thin',
                2
            );
            self::implementThinConstantReturn(
                $context,
                '__compiler_stream_set_timeout',
                'int32',
                0,
                'sst_thin',
                3
            );
        } finally {
            if (null !== $savedBlock) {
                BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
            } else {
                $context->builder->clearInsertionPosition();
            }
        }
    }

    private static function implementThinConstantReturn(
        Context $context,
        string $name,
        string $retType,
        int $constant,
        string $prefix,
        int $argc
    ): void {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0 && !StreamIoRuntime::isDeferStub($probe)) {
            $context->registerFunction($name, $probe);

            return;
        }
        if (null !== $probe && StreamIoRuntime::isDeferStub($probe)) {
            foreach (\array_reverse($probe->getBasicBlocks()) as $block) {
                $block->delete();
            }
        }

        $i64 = $context->getTypeFromString('int64');
        $ret = $context->getTypeFromString($retType);
        $params = [];
        for ($i = 0; $i < $argc; ++$i) {
            $params[] = $i64;
        }
        $ft = $context->context->functionType($ret, false, ...$params);
        $fn = null !== $probe ? $probe : $context->module->addFunction($name, $ft);
        $entry = $fn->appendBasicBlock($prefix.'_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue($ret->constInt($constant, true));
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0 && !StreamIoRuntime::isDeferStub($probe)) {
            $context->registerFunction($name, $probe);

            return;
        }
        if (null !== $probe && StreamIoRuntime::isDeferStub($probe)) {
            foreach (\array_reverse($probe->getBasicBlocks()) as $block) {
                $block->delete();
            }
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

        return JitVmHelperLink::lookupCompiled($context, $logical, '#22979');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#22979'
        );
    }

    private static function allRuntimeFunctionsLinked(Context $context): bool
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks() || StreamIoRuntime::isDeferStub($fn)) {
                return false;
            }
        }

        return true;
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks() || StreamIoRuntime::isDeferStub($fn)) {
                throw new \LogicException($name.' missing after JitStreamBufferKernel bridge (#14462)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
