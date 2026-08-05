<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for stream context ABI via StreamContextJitHelper PHP (#9340, #12895, #19817, #23049, #27573).
 *
 * Quarantined from lib/JIT/Builtin/StreamContextRuntime — {@see \PHPCompiler\JIT\Builtin\StreamContextRuntime}
 * stays the thin orchestrator. Helper compile: {@see JitVmHelperLink::ensureCompiled}
 * (peer StreamCaps #23012 / StreamSync #23004 / StreamMeta #22994).
 *
 * Thin AOT NestedJIT returns `__value__*` for HashTable — bridges use {@see JitNestedHelperCoerce}
 * (peer PasswordCryptoRuntime / StreamMetaKernel).
 *
 * SSOT: {@see StreamContextJitHelper}, {@see VmStreamContext}
 * php-src: main/streams/streams.c — stream_context_create, stream_context_get_default
 */
final class JitStreamContextKernel
{
    private const HELPER_PATH = '/ext/standard/StreamContextJitHelper.php';

    private const CREATE_HELPER = 'PHPCompiler\\ext\\standard\\StreamContextJitHelper::create';

    private const MERGE_HELPER = 'PHPCompiler\\ext\\standard\\StreamContextJitHelper::mergeOptions';

    private const GET_OPTIONS_HELPER = 'PHPCompiler\\ext\\standard\\StreamContextJitHelper::getOptions';

    private const SET_PARAMS_HELPER = 'PHPCompiler\\ext\\standard\\StreamContextJitHelper::setParams';

    private const SET_SINGLE_OPTION_HELPER = 'PHPCompiler\\ext\\standard\\StreamContextJitHelper::setSingleOption';

    private const GET_PARAMS_HELPER = 'PHPCompiler\\ext\\standard\\StreamContextJitHelper::getParams';

    private const GET_DEFAULT_HELPER = 'PHPCompiler\\ext\\standard\\StreamContextJitHelper::getDefault';

    private const SET_DEFAULT_HELPER = 'PHPCompiler\\ext\\standard\\StreamContextJitHelper::setDefault';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CREATE_HELPER,
        self::MERGE_HELPER,
        self::GET_OPTIONS_HELPER,
        self::SET_PARAMS_HELPER,
        self::SET_SINGLE_OPTION_HELPER,
        self::GET_PARAMS_HELPER,
        self::GET_DEFAULT_HELPER,
        self::SET_DEFAULT_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__phpc_stream_context_create',
        '__phpc_stream_context_merge_options',
        '__phpc_stream_context_get_options',
        '__phpc_stream_context_set_params',
        '__phpc_stream_context_set_single_option',
        '__phpc_stream_context_get_params',
    ];

    public static function ensureLinked(Context $context): void
    {
        // Peer StreamMeta/StreamIo: restore user insert block after NestedJIT (#27573).
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        self::implement($context);
        if (null !== $savedBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_stream_context_create');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        // Thin AOT: NestedJIT StreamContextJitHelper fails LLVM verify (#27573) — LLVM markers.
        if ($context->isThinStandaloneAotMain()) {
            JitStreamContextThinAot::implement($context);
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementCreateBridge($context);
        self::implementMergeBridge($context);
        self::implementGetOptionsBridge($context);
        self::implementSetParamsBridge($context);
        self::implementSetSingleOptionBridge($context);
        self::implementGetParamsBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    public static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#23049');
    }

    private static function implementCreateBridge(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        self::implementBridge(
            $context,
            '__phpc_stream_context_create',
            $context->context->functionType($htPtr, false, $htPtr, $htPtr),
            static function (Context $ctx, LlvmFunction $fn): Value {
                $raw = JitNestedHelperCoerce::callHelper(
                    $ctx,
                    self::helperFunction($ctx, self::CREATE_HELPER),
                    [$fn->getParam(0), $fn->getParam(1)]
                );

                return JitNestedHelperCoerce::coerceToHashtablePtr($ctx, $raw);
            }
        );
    }

    private static function implementMergeBridge(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        self::implementBridge(
            $context,
            '__phpc_stream_context_merge_options',
            $context->context->functionType($context->getTypeFromString('void'), false, $htPtr, $htPtr),
            static function (Context $ctx, LlvmFunction $fn): void {
                // NestedJIT keeps the optional $functionName param — pass explicit name (#27573).
                $fnName = $ctx->builder->load(
                    $ctx->constantStringFromString('stream_context_set_options')
                );
                JitNestedHelperCoerce::callHelper(
                    $ctx,
                    self::helperFunction($ctx, self::MERGE_HELPER),
                    [$fn->getParam(0), $fn->getParam(1), $fnName]
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
            static function (Context $ctx, LlvmFunction $fn): Value {
                $raw = JitNestedHelperCoerce::callHelper(
                    $ctx,
                    self::helperFunction($ctx, self::GET_OPTIONS_HELPER),
                    [$fn->getParam(0)]
                );

                return JitNestedHelperCoerce::coerceToHashtablePtr($ctx, $raw);
            }
        );
    }

    private static function implementSetParamsBridge(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        self::implementBridge(
            $context,
            '__phpc_stream_context_set_params',
            $context->context->functionType($context->getTypeFromString('void'), false, $htPtr, $htPtr),
            static function (Context $ctx, LlvmFunction $fn): void {
                JitNestedHelperCoerce::callHelper(
                    $ctx,
                    self::helperFunction($ctx, self::SET_PARAMS_HELPER),
                    [$fn->getParam(0), $fn->getParam(1)]
                );
                $ctx->builder->returnVoid();
            },
            returnsVoid: true
        );
    }

    private static function implementSetSingleOptionBridge(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $valPtr = $context->getTypeFromString('__value__*');
        self::implementBridge(
            $context,
            '__phpc_stream_context_set_single_option',
            $context->context->functionType($context->getTypeFromString('void'), false, $htPtr, $valPtr, $valPtr, $valPtr),
            static function (Context $ctx, LlvmFunction $fn): void {
                JitNestedHelperCoerce::callHelper(
                    $ctx,
                    self::helperFunction($ctx, self::SET_SINGLE_OPTION_HELPER),
                    [$fn->getParam(0), $fn->getParam(1), $fn->getParam(2), $fn->getParam(3)]
                );
                $ctx->builder->returnVoid();
            },
            returnsVoid: true
        );
    }

    private static function implementGetParamsBridge(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        self::implementBridge(
            $context,
            '__phpc_stream_context_get_params',
            $context->context->functionType($htPtr, false, $htPtr),
            static function (Context $ctx, LlvmFunction $fn): Value {
                $raw = JitNestedHelperCoerce::callHelper(
                    $ctx,
                    self::helperFunction($ctx, self::GET_PARAMS_HELPER),
                    [$fn->getParam(0)]
                );

                return JitNestedHelperCoerce::coerceToHashtablePtr($ctx, $raw);
            }
        );
    }

    /**
     * @param callable(Context, LlvmFunction): (Value|void) $emitReturn
     */
    private static function implementBridge(
        Context $context,
        string $abiName,
        $ft,
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
        if (!$returnsVoid) {
            $context->builder->returnValue($result);
        }
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

        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#23049'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after StreamContextRuntime bridge (#9340/#27573)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
