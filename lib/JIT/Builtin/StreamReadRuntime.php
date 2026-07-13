<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for stream read ABI via StreamReadJitHelper PHP (#9393, #12937, #18672).
 *
 * JIT embed and AOT standalone compile {@see \PHPCompiler\ext\standard\StreamReadJitHelper}; LLVM bridges
 * live in {@see StreamReadBridgeLlvm}. SSOT: {@see \PHPCompiler\ext\standard\StreamReadJitHelper}
 * php-src: ext/standard/flock.c, ext/standard/streams.c
 */
final class StreamReadRuntime
{
    private const HELPER_PATH = '/ext/standard/StreamReadJitHelper.php';

    public const FLOCK = 'PHPCompiler\\ext\\standard\\StreamReadJitHelper::flockArgv';

    public const FPASSTHRU = 'PHPCompiler\\ext\\standard\\StreamReadJitHelper::fpassthruArgv';

    public const FTRUNCATE = 'PHPCompiler\\ext\\standard\\StreamReadJitHelper::ftruncateArgv';

    public const FTELL = 'PHPCompiler\\ext\\standard\\StreamReadJitHelper::ftellArgv';

    public const FGETC = 'PHPCompiler\\ext\\standard\\StreamReadJitHelper::fgetcArgv';

    public const FGETS = 'PHPCompiler\\ext\\standard\\StreamReadJitHelper::fgetsArgv';

    public const STREAM_GET_LINE = 'PHPCompiler\\ext\\standard\\StreamReadJitHelper::streamGetLineArgv';

    public const FSEEK = 'PHPCompiler\\ext\\standard\\StreamReadJitHelper::fseekArgv';

    public const STREAM_GET_CONTENTS = 'PHPCompiler\\ext\\standard\\StreamReadJitHelper::streamGetContentsArgv';

    public const STREAM_COPY_TO_STREAM = 'PHPCompiler\\ext\\standard\\StreamReadJitHelper::streamCopyToStreamArgv';

    public const STREAM_COPY_TO_STRING = 'PHPCompiler\\ext\\standard\\StreamReadJitHelper::streamCopyToStringArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FLOCK,
        self::FPASSTHRU,
        self::FTRUNCATE,
        self::FTELL,
        self::FGETC,
        self::FGETS,
        self::STREAM_GET_LINE,
        self::FSEEK,
        self::STREAM_GET_CONTENTS,
        self::STREAM_COPY_TO_STREAM,
        self::STREAM_COPY_TO_STRING,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_flock',
        '__compiler_fpassthru',
        '__compiler_ftruncate',
        '__compiler_ftell',
        '__compiler_fgetc',
        '__compiler_fgets',
        '__compiler_stream_get_line',
        '__compiler_fseek',
        '__compiler_stream_get_contents',
        '__compiler_stream_copy_to_stream',
        '__compiler_stream_copy_to_string',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context, true);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context, true);
    }

    /** vfscanf LLVM only needs read/seek ABI — skip lifecycle/ob deps during defineBuiltins (#13137). */
    public static function ensureVfscanfAbi(Context $context): void
    {
        self::implement($context, false);
    }

    public static function implement(Context $context, bool $withLifecycleDeps = true): void
    {
        $probe = $context->module->getNamedFunction('__compiler_flock');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        StreamFilter::ensureLinked($context);
        if ($withLifecycleDeps) {
            StreamLifecycleRuntime::ensureLinked($context);
            ObOutputRuntime::ensureLinked($context);
        }
        self::ensureJitHelperCompiled($context);
        StreamReadBridgeLlvm::implementI32Bridge($context, '__compiler_flock', self::FLOCK, 2);
        StreamReadBridgeLlvm::implementI64Bridge($context, '__compiler_fpassthru', self::FPASSTHRU, 1);
        StreamReadBridgeLlvm::implementI32Bridge($context, '__compiler_ftruncate', self::FTRUNCATE, 2);
        StreamReadBridgeLlvm::implementI64Bridge($context, '__compiler_ftell', self::FTELL, 1);
        StreamReadBridgeLlvm::implementNullableStringBridge($context, '__compiler_fgetc', self::FGETC, 1);
        StreamReadBridgeLlvm::implementNullableStringBridge($context, '__compiler_fgets', self::FGETS, 2);
        StreamReadBridgeLlvm::implementStreamGetLineBridge($context);
        StreamReadBridgeLlvm::implementI64Bridge($context, '__compiler_fseek', self::FSEEK, 3);
        StreamReadBridgeLlvm::implementNullableStringBridge($context, '__compiler_stream_get_contents', self::STREAM_GET_CONTENTS, 3);
        StreamReadBridgeLlvm::implementI64Bridge($context, '__compiler_stream_copy_to_stream', self::STREAM_COPY_TO_STREAM, 4);
        StreamReadBridgeLlvm::implementNullableStringBridge($context, '__compiler_stream_copy_to_string', self::STREAM_COPY_TO_STRING, 3);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    public static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after StreamReadJitHelper compile (#9393)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'StreamReadJitHelper.php');
            if (null === $block) {
                throw new \LogicException('StreamReadJitHelper.php parseAndCompile failed (#9393)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT stream read (#9393)');
            }
        }
    }

    public static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after StreamReadRuntime bridge (#9393)');
            }
            $context->registerFunction($name, $fn);
        }
    }

    public static function shouldDeferInventoryEmitStubs(Context $context): bool
    {
        return StreamIoRuntime::shouldDeferHeavyStreamIoEmitters($context);
    }

    public static function ensureDeferredStubsForInventoryEmit(Context $context): void
    {
        if (!self::shouldDeferInventoryEmitStubs($context)) {
            return;
        }
        StreamReadBridgeLlvm::implementDeferredStubs($context);
    }
}
