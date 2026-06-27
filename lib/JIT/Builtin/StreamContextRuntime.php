<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\BasicBlock;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for stream context ABI via StreamContextJitHelper PHP (#9340, #12895).
 *
 * JIT embed and AOT standalone compile {@see \PHPCompiler\ext\standard\StreamContextJitHelper}; thin LLVM bridges
 * forward the ABI. SSOT: {@see \PHPCompiler\ext\standard\VmStreamContext}
 * php-src: ext/standard/streams.c — stream_context_create, stream_context_get_default
 */
final class StreamContextRuntime
{
    private static int $blockSerial = 0;

    private const HELPER_PATH = '/ext/standard/StreamContextJitHelper.php';

    private const CREATE_HELPER = 'PHPCompiler\\ext\\standard\\StreamContextJitHelper::create';

    private const MERGE_HELPER = 'PHPCompiler\\ext\\standard\\StreamContextJitHelper::mergeOptions';

    private const GET_OPTIONS_HELPER = 'PHPCompiler\\ext\\standard\\StreamContextJitHelper::getOptions';

    private const SET_PARAMS_HELPER = 'PHPCompiler\\ext\\standard\\StreamContextJitHelper::setParams';

    private const GET_DEFAULT_HELPER = 'PHPCompiler\\ext\\standard\\StreamContextJitHelper::getDefault';

    private const SET_DEFAULT_HELPER = 'PHPCompiler\\ext\\standard\\StreamContextJitHelper::setDefault';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CREATE_HELPER,
        self::MERGE_HELPER,
        self::GET_OPTIONS_HELPER,
        self::SET_PARAMS_HELPER,
        self::GET_DEFAULT_HELPER,
        self::SET_DEFAULT_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__phpc_stream_context_create',
        '__phpc_stream_context_merge_options',
        '__phpc_stream_context_get_options',
        '__phpc_stream_context_set_params',
    ];

    public static function ensureLinked(Context $context): void
    {
        $savedInsert = $context->builder->getInsertBlock();
        self::implement($context);
        self::resumeBuilderAfterEnsureLinked($context, $savedInsert);
    }

    /** Reposition builder in the user function after runtime helper codegen (#6367). */
    public static function resumeBuilderAfterEnsureLinked(Context $context, ?BasicBlock $savedInsert): void
    {
        unset($savedInsert);

        $targetFn = null;
        if ('' !== $context->activeFunction && isset($context->functions[$context->activeFunction])) {
            $targetFn = $context->functions[$context->activeFunction];
        }
        if (null === $targetFn) {
            $targetFn = $context->main;
        }
        if (null === $targetFn) {
            throw new \LogicException('StreamContext JIT: no active insert block after ensureLinked');
        }

        $resume = $targetFn->appendBasicBlock('stream_ctx_jit_resume_'.(++self::$blockSerial));
        $context->builder->positionAtEnd($resume);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_stream_context_create');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementCreateBridge($context);
        self::implementMergeBridge($context);
        self::implementGetOptionsBridge($context);
        self::implementSetParamsBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    public static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after StreamContextJitHelper compile (#9340)');
        }

        return $fn;
    }

    private static function implementCreateBridge(Context $context): void
    {
        self::implementBridge(
            $context,
            '__phpc_stream_context_create',
            $context->context->functionType(
                $context->getTypeFromString('__hashtable__*'),
                false,
                $context->getTypeFromString('__hashtable__*'),
                $context->getTypeFromString('__hashtable__*')
            ),
            self::CREATE_HELPER,
            static fn (Context $ctx, LlvmFunction $fn) => $ctx->builder->call(
                self::helperFunction($ctx, self::CREATE_HELPER),
                $fn->getParam(0),
                $fn->getParam(1)
            )
        );
    }

    private static function implementMergeBridge(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        self::implementBridge(
            $context,
            '__phpc_stream_context_merge_options',
            $context->context->functionType($context->getTypeFromString('void'), false, $htPtr, $htPtr),
            self::MERGE_HELPER,
            static function (Context $ctx, LlvmFunction $fn): void {
                $ctx->builder->call(
                    self::helperFunction($ctx, self::MERGE_HELPER),
                    $fn->getParam(0),
                    $fn->getParam(1)
                );
                $ctx->builder->returnVoid();
            },
            returnsVoid: true
        );
    }

    private static function implementGetOptionsBridge(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        self::implementBridge(
            $context,
            '__phpc_stream_context_get_options',
            $context->context->functionType($htPtr, false, $htPtr),
            self::GET_OPTIONS_HELPER,
            static fn (Context $ctx, LlvmFunction $fn) => $ctx->builder->call(
                self::helperFunction($ctx, self::GET_OPTIONS_HELPER),
                $fn->getParam(0)
            )
        );
    }

    private static function implementSetParamsBridge(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        self::implementBridge(
            $context,
            '__phpc_stream_context_set_params',
            $context->context->functionType($context->getTypeFromString('void'), false, $htPtr, $htPtr),
            self::SET_PARAMS_HELPER,
            static function (Context $ctx, LlvmFunction $fn): void {
                $ctx->builder->call(
                    self::helperFunction($ctx, self::SET_PARAMS_HELPER),
                    $fn->getParam(0),
                    $fn->getParam(1)
                );
                $ctx->builder->returnVoid();
            },
            returnsVoid: true
        );
    }

    /**
     * @param callable(Context, LlvmFunction): Value|void $emitReturn
     */
    private static function implementBridge(
        Context $context,
        string $abiName,
        $ft,
        string $helperLogical,
        callable $emitReturn,
        bool $returnsVoid = false
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock($abiName.'_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $emitReturn($context, $fn);
        if ($returnsVoid) {
            return;
        }
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'StreamContextJitHelper.php');
            if (null === $block) {
                throw new \LogicException('StreamContextJitHelper.php parseAndCompile failed (#9340)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9340)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StreamContextRuntime bridge (#9340)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
