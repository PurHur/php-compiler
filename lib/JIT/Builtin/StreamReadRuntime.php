<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStreamReadBridgeKernel;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\NestedVmActiveContextLlvm;
use PHPCompiler\JIT\VmActiveContextInitLlvm;
use PHPCompiler\JIT\VmActiveContextLlvm;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for stream read ABI via StreamReadJitHelper PHP (#9393, #20982).
 *
 * Embed + thin standalone AOT: NestedJIT {@see \PHPCompiler\ext\standard\StreamReadJitHelper}
 * via {@see JitVmHelperLink} (StreamLifecycle #20966 / StreamIo #20943 shape — no deferred stub fork).
 * LLVM bridges live in {@see JitStreamReadBridgeKernel}.
 * SSOT: {@see \PHPCompiler\ext\standard\StreamReadJitHelper}
 * php-src: ext/standard/file.c, ext/standard/flock_compat.c
 */
final class StreamReadRuntime
{
    private const HELPER_PATH = '/ext/standard/StreamReadJitHelper.php';

    public const FLOCK = 'PHPCompiler\\ext\\standard\\StreamReadJitHelper::flockArgv';

    public const FPASSTHRU = 'PHPCompiler\\ext\\standard\\StreamReadJitHelper::fpassthruArgv';

    public const FTRUNCATE = 'PHPCompiler\\ext\\standard\\StreamReadJitHelper::ftruncateArgv';

    public const FTELL = 'PHPCompiler\\ext\\standard\\StreamIoJitHelper::ftellArgv';

    public const FGETC = 'PHPCompiler\\ext\\standard\\StreamReadJitHelper::fgetcArgv';

    public const FGETS = 'PHPCompiler\\ext\\standard\\StreamReadJitHelper::fgetsArgv';

    public const STREAM_GET_LINE = 'PHPCompiler\\ext\\standard\\StreamReadJitHelper::streamGetLineArgv';

    public const FSEEK = 'PHPCompiler\\ext\\standard\\StreamIoJitHelper::fseekArgv';

    public const STREAM_GET_CONTENTS = 'PHPCompiler\\ext\\standard\\StreamReadJitHelper::streamGetContentsArgv';

    public const STREAM_COPY_TO_STREAM = 'PHPCompiler\\ext\\standard\\StreamReadJitHelper::streamCopyToStreamArgv';

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
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        // Thin + embed: publish sg_vm_context before NestedJIT of StreamReadJitHelper (#17391).
        VmActiveContextInitLlvm::requestThinStandaloneInit($context);
        VmActiveContextLlvm::ensureAbi($context);
        NestedVmActiveContextLlvm::ensureMethod($context);
        DomInstanceMethodRuntime::ensureActiveContextProxy($context);

        $probe = $context->module->getNamedFunction('__compiler_flock');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);
            if ($context->isThinStandaloneAotMain()) {
                self::forceLibcStreamPositionAbis($context);
            }

            return;
        }

        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);

        StreamFilter::ensureLinked($context);
        StreamIoRuntime::ensureLinked($context);
        if ($withLifecycleDeps) {
            StreamLifecycleRuntime::ensureLinked($context);
            ObOutputRuntime::ensureLinked($context);
        }
        self::ensureJitHelperCompiled($context);
        JitStreamReadBridgeKernel::implementI32Bridge($context, '__compiler_flock', self::FLOCK, 2);
        JitStreamReadBridgeKernel::implementI64Bridge($context, '__compiler_fpassthru', self::FPASSTHRU, 1);
        JitStreamReadBridgeKernel::implementI32Bridge($context, '__compiler_ftruncate', self::FTRUNCATE, 2);
        JitStreamReadBridgeKernel::implementI64Bridge($context, '__compiler_ftell', self::FTELL, 1);
        JitStreamReadBridgeKernel::implementNullableStringBridge($context, '__compiler_fgetc', self::FGETC, 1);
        JitStreamReadBridgeKernel::implementNullableStringBridge($context, '__compiler_fgets', self::FGETS, 2);
        JitStreamReadBridgeKernel::implementStreamGetLineBridge($context);
        JitStreamReadBridgeKernel::implementI64Bridge($context, '__compiler_fseek', self::FSEEK, 3);
        JitStreamReadBridgeKernel::implementNullableStringBridge($context, '__compiler_stream_get_contents', self::STREAM_GET_CONTENTS, 3);
        JitStreamReadBridgeKernel::implementI64Bridge($context, '__compiler_stream_copy_to_stream', self::STREAM_COPY_TO_STREAM, 4);
        self::registerLinkedRuntime($context);

        if ($context->isThinStandaloneAotMain()) {
            self::forceLibcStreamPositionAbis($context);
        }

        if (null !== $savedBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /** Thin AOT: libc FILE* fgets/fseek/ftell matching JitStreamIoKernel fopen/fwrite (#27663). */
    public static function forceLibcStreamPositionAbis(Context $context): void
    {
        \PHPCompiler\ext\standard\JitStreamIoKernel::implementFgetsForce($context);
        \PHPCompiler\ext\standard\JitStreamIoKernel::implementFseekForce($context);
        \PHPCompiler\ext\standard\JitStreamIoKernel::implementFtellForce($context);
    }

    public static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after StreamReadJitHelper compile (#20982)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#20982'
        );
    }

    public static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after StreamReadRuntime bridge (#20982)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
