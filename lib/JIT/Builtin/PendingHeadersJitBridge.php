<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT embed link for pending HTTP headers via PendingHeadersJitHelper PHP (#9545).
 *
 * Standalone AOT keeps LLVM in {@see PendingHeadersStandaloneLlvm}.
 * SSOT: {@see \PHPCompiler\ext\standard\PendingHeadersJitHelper}.
 * php-src: ext/standard/head.c
 */
final class PendingHeadersJitBridge
{
    private const G_SAPI_STARTED = '__phpc_sapi_output_started';

    private const HELPER_PATH = '/ext/standard/PendingHeadersJitHelper.php';

    private const RESET_HELPER = 'PHPCompiler\\ext\\standard\\PendingHeadersJitHelper::reset';

    private const ENABLE_HELPER = 'PHPCompiler\\ext\\standard\\PendingHeadersJitHelper::enableHeaderQueue';

    private const IS_FLUSHED_HELPER = 'PHPCompiler\\ext\\standard\\PendingHeadersJitHelper::isFlushed';

    private const ADD_HEADER_HELPER = 'PHPCompiler\\ext\\standard\\PendingHeadersJitHelper::addHeader';

    private const REMOVE_HEADER_HELPER = 'PHPCompiler\\ext\\standard\\PendingHeadersJitHelper::removeHeader';

    private const LIST_TABLE_HELPER = 'PHPCompiler\\ext\\standard\\PendingHeadersJitHelper::listHeadersTable';

    private const FLUSH_HELPER = 'PHPCompiler\\ext\\standard\\PendingHeadersJitHelper::flushResponseHeaders';

    private const ADD_SETCOOKIE_HELPER = 'PHPCompiler\\ext\\standard\\PendingHeadersJitHelper::addSetcookie';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RESET_HELPER,
        self::ENABLE_HELPER,
        self::IS_FLUSHED_HELPER,
        self::ADD_HEADER_HELPER,
        self::REMOVE_HEADER_HELPER,
        self::LIST_TABLE_HELPER,
        self::FLUSH_HELPER,
        self::ADD_SETCOOKIE_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__phpc_pending_header_reset',
        '__phpc_header_queue_enable',
        '__phpc_pending_header_add',
        '__phpc_pending_header_remove',
        '__phpc_pending_header_list',
        '__phpc_response_headers_flush',
        '__phpc_setcookie_add',
        '__phpc_headers_sent',
    ];

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_pending_header_reset');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $restore = self::captureInsertBlock($context);
        self::ensureHashtableHelpers($context);
        HttpResponseRuntime::ensureLinked($context);
        self::ensureJitHelperCompiled($context);

        self::implementResetBridge($context);
        self::implementVoidBridge($context, '__phpc_header_queue_enable', self::ENABLE_HELPER);
        self::implementHeadersSentBridge($context);
        self::implementAddHeaderBridge($context);
        self::implementRemoveHeaderBridge($context);
        self::implementListBridge($context);
        self::implementVoidBridge($context, '__phpc_response_headers_flush', self::FLUSH_HELPER);
        self::implementSetcookieBridge($context);
        self::registerLinkedRuntime($context);
        self::restoreInsertBlock($context, $restore);
        $context->builder->clearInsertionPosition();
    }

    private static function implementResetBridge(Context $context): void
    {
        $abiName = '__phpc_pending_header_reset';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('ph_reset_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(self::helperFunction($context, self::RESET_HELPER));
        $i32 = $context->getTypeFromString('int32');
        $sapi = $context->module->getNamedGlobal(self::G_SAPI_STARTED);
        if (null !== $sapi) {
            $context->builder->store(
                $i32->constInt(0, false),
                $context->builder->pointerCast($sapi, $i32->pointerType(0))
            );
        }
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementHeadersSentBridge(Context $context): void
    {
        $abiName = '__phpc_headers_sent';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('ph_sent_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $flushed = self::helperFunction($context, self::IS_FLUSHED_HELPER);
        $flushedRaw = $context->builder->call($flushed);
        $flushedI32 = JitNestedHelperCoerce::i64ToScalar($context, $flushedRaw, $i32);
        $sapiStarted = $i32->constInt(0, false);
        $sapi = $context->module->getNamedGlobal(self::G_SAPI_STARTED);
        if (null !== $sapi) {
            $sapiStarted = $context->builder->load(
                $context->builder->pointerCast($sapi, $i32->pointerType(0))
            );
        }
        $sent = $context->builder->or(
            $context->builder->icmp(Builder::INT_NE, $flushedI32, $i32->constInt(0, false)),
            $context->builder->icmp(Builder::INT_NE, $sapiStarted, $i32->constInt(0, false))
        );
        $context->builder->returnValue($context->builder->zExt($sent, $i32));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementAddHeaderBridge(Context $context): void
    {
        $abiName = '__phpc_pending_header_add';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $strPtr, $i32);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('ph_add_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(
            self::helperFunction($context, self::ADD_HEADER_HELPER),
            $fn->getParam(0),
            JitNestedHelperCoerce::scalarToI64($context, $fn->getParam(1), $i32)
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementRemoveHeaderBridge(Context $context): void
    {
        $abiName = '__phpc_pending_header_remove';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $strPtr);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('ph_rem_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(
            self::helperFunction($context, self::REMOVE_HEADER_HELPER),
            $fn->getParam(0)
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementListBridge(Context $context): void
    {
        $abiName = '__phpc_pending_header_list';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $ft = $context->context->functionType($htPtr, false);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('ph_list_bridge_entry');
        $emptyBb = $fn->appendBasicBlock('ph_list_empty');
        $bodyBb = $fn->appendBasicBlock('ph_list_body');
        $context->builder->positionAtEnd($entry);

        $htRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::LIST_TABLE_HELPER),
            []
        );
        $isNull = JitNestedHelperCoerce::isHelperResultNull($context, $htRaw);
        $context->builder->branchIf($isNull, $emptyBb, $bodyBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue($context->builder->call($context->lookupFunction('__hashtable__alloc')));

        $context->builder->positionAtEnd($bodyBb);
        $context->builder->returnValue(JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementSetcookieBridge(Context $context): void
    {
        $abiName = '__phpc_setcookie_add';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType(
            $voidTy,
            false,
            $strPtr,
            $strPtr,
            $i64,
            $strPtr,
            $strPtr,
            $i32,
            $i32,
            $strPtr,
            $i32
        );
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('sc_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(
            self::helperFunction($context, self::ADD_SETCOOKIE_HELPER),
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2),
            $fn->getParam(3),
            $fn->getParam(4),
            JitNestedHelperCoerce::scalarToI64($context, $fn->getParam(5), $i32),
            JitNestedHelperCoerce::scalarToI64($context, $fn->getParam(6), $i32),
            $fn->getParam(7),
            JitNestedHelperCoerce::scalarToI64($context, $fn->getParam(8), $i32)
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementVoidBridge(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('ph_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(self::helperFunction($context, $helperLogical));
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after PendingHeadersJitHelper compile (#9545)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'PendingHeadersJitHelper.php');
            if (null === $block) {
                throw new \LogicException('PendingHeadersJitHelper.php parseAndCompile failed (#9545)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9545)');
            }
        }
    }

    private static function ensureHashtableHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        try {
            $context->lookupFunction('__hashtable__alloc');
        } catch (\Throwable) {
            $fn = $context->module->addFunction(
                '__hashtable__alloc',
                $context->context->functionType($htPtr, false)
            );
            $context->registerFunction('__hashtable__alloc', $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after PendingHeadersJitBridge (#9545)');
            }
            $context->registerFunction($name, $fn);
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
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
