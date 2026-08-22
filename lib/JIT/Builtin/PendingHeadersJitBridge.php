<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\NestedVmActiveContextLlvm;
use PHPCompiler\JIT\VmActiveContextInitLlvm;
use PHPCompiler\JIT\VmActiveContextLlvm;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT embed + standalone link for pending HTTP headers via PendingHeadersJitHelper PHP (#9545, #20930, #33255).
 *
 * Owns pending-header ABI symbols module-locally (getNamedFunction first). Do not
 * re-add Type always-on empty decls — leftover mint pending_header_*.1 (#31894 / #32122 / #33255).
 *
 * Embed + thin standalone AOT: NestedJIT {@see \PHPCompiler\ext\standard\PendingHeadersJitHelper}
 * via {@see JitVmHelperLink::ensureCompiled} (peer FunctionExistsRuntime #22016 / RewriteVarsRuntime #21968).
 * SSOT: {@see \PHPCompiler\ext\standard\PendingHeadersJitHelper}.
 * php-src: ext/standard/head.c
 */
final class PendingHeadersJitBridge
{
    private const G_SAPI_STARTED = '__phpc_sapi_output_started';

    private const G_LLVM_HDR_COUNT = '__phpc_pending_hdr_llvm_count';

    private const G_LLVM_HDR_LINES = '__phpc_pending_hdr_llvm_lines';

    private const LLVM_HDR_CAP = 256;

    private const HELPER_PATH = '/ext/standard/PendingHeadersJitHelper.php';

    private const RESET_HELPER = 'PHPCompiler\\ext\\standard\\PendingHeadersJitHelper::reset';

    private const ENABLE_HELPER = 'PHPCompiler\\ext\\standard\\PendingHeadersJitHelper::enableHeaderQueue';

    private const IS_FLUSHED_HELPER = 'PHPCompiler\\ext\\standard\\PendingHeadersJitHelper::isFlushed';

    private const ADD_HEADER_HELPER = 'PHPCompiler\\ext\\standard\\PendingHeadersJitHelper::addHeader';

    private const REMOVE_HEADER_HELPER = 'PHPCompiler\\ext\\standard\\PendingHeadersJitHelper::removeHeader';

    private const LIST_TABLE_HELPER = 'PHPCompiler\\ext\\standard\\PendingHeadersJitHelper::listHeadersTable';

    private const SNAPSHOT_HEADERS_HELPER = 'PHPCompiler\\ext\\standard\\PendingHeadersJitHelper::snapshotHeadersTable';

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
        self::SNAPSHOT_HEADERS_HELPER,
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

    /**
     * Empty module-local decls only (no NestedJIT) when lookup precedes bodies
     * (#33255 / #33891). Not called from {@see Type::register}.
     */
    public static function declarePendingHeaderAbis(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');

        foreach (self::ABI_FUNCTIONS as $abiName) {
            $probe = $context->module->getNamedFunction($abiName);
            if (null !== $probe) {
                $context->registerFunction($abiName, $probe);
                continue;
            }
            if ('__phpc_headers_sent' === $abiName) {
                $ft = $context->context->functionType($i32, false);
            } elseif ('__phpc_pending_header_list' === $abiName) {
                $ft = $context->context->functionType($htPtr, false);
            } elseif ('__phpc_pending_header_add' === $abiName) {
                $ft = $context->context->functionType($voidTy, false, $strPtr, $i32);
            } elseif ('__phpc_pending_header_remove' === $abiName) {
                $ft = $context->context->functionType($voidTy, false, $strPtr);
            } elseif ('__phpc_setcookie_add' === $abiName) {
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
            } else {
                $ft = $context->context->functionType($voidTy, false);
            }
            $fn = $context->module->addFunction($abiName, $ft);
            $context->registerFunction($abiName, $fn);
        }
    }

    public static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        // Thin + embed: publish sg_vm_context before NestedJIT of PendingHeadersJitHelper (#17391).
        VmActiveContextInitLlvm::requestThinStandaloneInit($context);
        VmActiveContextLlvm::ensureAbi($context);
        NestedVmActiveContextLlvm::ensureMethod($context);
        DomInstanceMethodRuntime::ensureActiveContextProxy($context);

        $probe = $context->module->getNamedFunction('__phpc_pending_header_reset');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            if (!self::isThinLinkStub($probe)) {
                self::registerLinkedRuntime($context);

                return;
            }
            // User-script lowering runs inside NestedJitCompileScope, so the first
            // ensureLinked is a no-op; compileToFile then fills ret stubs (#1974).
            self::clearThinLinkStubBodies($context);
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
        self::implementFlushBridge($context);
        self::implementSetcookieBridge($context);
        self::registerLinkedRuntime($context);
        self::restoreInsertBlock($context, $restore);
        if (null === $restore) {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * Thin AOT link fillers for pending-header ABI shells (#20932 regression).
     *
     * Declares getNamedFunction-first when absent (no Type::register always-on after
     * #33891). Helper-runtime units and ScriptExit call them. NestedJIT during
     * Type::initialize segfaults (#13571), so fill no-op / alloc stubs here for link
     * + HelloWorld. Real NestedJIT still runs via {@see implement} when ensureLinked
     * is invoked with empty bodies.
     */
    public static function fillThinAotLinkStubs(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        self::ensureHashtableHelpers($context);
        $voidTy = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $zero = $i32->constInt(0, false);

        foreach (self::ABI_FUNCTIONS as $abiName) {
            $probe = $context->module->getNamedFunction($abiName);
            if (null !== $probe && $probe->countBasicBlocks() > 0) {
                $context->registerFunction($abiName, $probe);
                continue;
            }

            if ('__phpc_headers_sent' === $abiName) {
                $ft = $context->context->functionType($i32, false);
                $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
                $entry = $fn->appendBasicBlock('ph_sent_link_stub');
                $context->builder->positionAtEnd($entry);
                $context->builder->returnValue($zero);
                $context->registerFunction($abiName, $fn);
                continue;
            }

            if ('__phpc_pending_header_list' === $abiName) {
                $ft = $context->context->functionType($htPtr, false);
                $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
                $entry = $fn->appendBasicBlock('ph_list_link_stub');
                $context->builder->positionAtEnd($entry);
                $context->builder->returnValue($context->builder->call($context->lookupFunction('__hashtable__alloc')));
                $context->registerFunction($abiName, $fn);
                continue;
            }

            if ('__phpc_pending_header_add' === $abiName) {
                $ft = $context->context->functionType($voidTy, false, $strPtr, $i32);
                $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
                $entry = $fn->appendBasicBlock('ph_add_link_stub');
                $context->builder->positionAtEnd($entry);
                $context->builder->returnVoid();
                $context->registerFunction($abiName, $fn);
                continue;
            }

            if ('__phpc_pending_header_remove' === $abiName) {
                $ft = $context->context->functionType($voidTy, false, $strPtr);
                $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
                $entry = $fn->appendBasicBlock('ph_rem_link_stub');
                $context->builder->positionAtEnd($entry);
                $context->builder->returnVoid();
                $context->registerFunction($abiName, $fn);
                continue;
            }

            if ('__phpc_setcookie_add' === $abiName) {
                $i64 = $context->getTypeFromString('int64');
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
                $entry = $fn->appendBasicBlock('ph_sc_link_stub');
                $context->builder->positionAtEnd($entry);
                $context->builder->returnVoid();
                $context->registerFunction($abiName, $fn);
                continue;
            }

            $ft = $context->context->functionType($voidTy, false);
            $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
            $entry = $fn->appendBasicBlock('ph_void_link_stub');
            $context->builder->positionAtEnd($entry);
            $context->builder->returnVoid();
            $context->registerFunction($abiName, $fn);
        }

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
        self::ensureLlvmHeaderGlobals($context);
        $countPtr = $context->builder->pointerCast(
            $context->module->getNamedGlobal(self::G_LLVM_HDR_COUNT),
            $i32->pointerType(0)
        );
        $context->builder->store($i32->constInt(0, false), $countPtr);
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
        $i64 = $context->getTypeFromString('int64');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $strPtr, $i32);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('ph_add_bridge_entry');
        $storeBb = $fn->appendBasicBlock('ph_add_llvm_store');
        $doneBb = $fn->appendBasicBlock('ph_add_done');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(
            self::helperFunction($context, self::ADD_HEADER_HELPER),
            $fn->getParam(0),
            JitNestedHelperCoerce::scalarToI64($context, $fn->getParam(1), $i32)
        );
        // Dual-write into LLVM globals — NestedJIT static $headers[] is lost before flush (#1974).
        self::ensureLlvmHeaderGlobals($context);
        $countPtr = $context->builder->pointerCast(
            $context->module->getNamedGlobal(self::G_LLVM_HDR_COUNT),
            $i32->pointerType(0)
        );
        $count = $context->builder->load($countPtr);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_ULT, $count, $i32->constInt(self::LLVM_HDR_CAP, false)),
            $storeBb,
            $doneBb
        );
        $context->builder->positionAtEnd($storeBb);
        $lines = $context->module->getNamedGlobal(self::G_LLVM_HDR_LINES);
        $slot = $context->builder->inBoundsGEP(
            $lines,
            $i32->constInt(0, false),
            $context->builder->zExt($count, $i64)
        );
        $context->builder->store($fn->getParam(0), $slot);
        $context->builder->store(
            $context->builder->add($count, $i32->constInt(1, false)),
            $countPtr
        );
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
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

    private static function implementFlushBridge(Context $context): void
    {
        $abiName = '__phpc_response_headers_flush';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        LibcExtern::ensurePrintf($context);
        HttpResponseRuntime::ensureLinked($context);

        $voidTy = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $ft = $context->context->functionType($voidTy, false);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('ph_flush_entry');
        $alreadyBb = $fn->appendBasicBlock('ph_flush_already');
        $bodyBb = $fn->appendBasicBlock('ph_flush_body');
        $afterStatusBb = $fn->appendBasicBlock('ph_flush_after_status');
        $headersBb = $fn->appendBasicBlock('ph_flush_headers');
        $loopBb = $fn->appendBasicBlock('ph_flush_loop');
        $loopBodyBb = $fn->appendBasicBlock('ph_flush_loop_body');
        $afterHeadersBb = $fn->appendBasicBlock('ph_flush_after_headers');
        $blankBb = $fn->appendBasicBlock('ph_flush_blank');
        $markBb = $fn->appendBasicBlock('ph_flush_mark');

        $context->builder->positionAtEnd($entry);
        $wroteAlloca = $context->builder->alloca($i32);
        $context->builder->store($i32->constInt(0, false), $wroteAlloca);
        $flushedRaw = $context->builder->call(self::helperFunction($context, self::IS_FLUSHED_HELPER));
        $flushedI32 = JitNestedHelperCoerce::i64ToScalar($context, $flushedRaw, $i32);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $flushedI32, $i32->constInt(0, false)),
            $alreadyBb,
            $bodyBb
        );

        $context->builder->positionAtEnd($alreadyBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bodyBb);
        $status = HttpResponseRuntime::loadStatusRaw($context);
        $ge100 = $context->builder->icmp(Builder::INT_SGE, $status, $i32->constInt(100, false));
        $le599 = $context->builder->icmp(Builder::INT_SLE, $status, $i32->constInt(599, false));
        $statusOk = $context->builder->and($ge100, $le599);
        $printStatusBb = $fn->appendBasicBlock('ph_flush_print_status');
        $context->builder->branchIf($statusOk, $printStatusBb, $afterStatusBb);

        $context->builder->positionAtEnd($printStatusBb);
        $statusFmt = $context->builder->pointerCast(
            $context->constantFromString("Status: %d\r\n"),
            $charPtr
        );
        $context->builder->call($context->lookupFunction('printf'), $statusFmt, $status);
        $context->builder->store($i32->constInt(1, false), $wroteAlloca);
        $context->builder->branch($afterStatusBb);

        $context->builder->positionAtEnd($afterStatusBb);
        self::ensureLlvmHeaderGlobals($context);
        $countPtr = $context->builder->pointerCast(
            $context->module->getNamedGlobal(self::G_LLVM_HDR_COUNT),
            $i32->pointerType(0)
        );
        $hdrCount = $context->builder->load($countPtr);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $hdrCount, $i32->constInt(0, false)),
            $headersBb,
            $afterHeadersBb
        );

        $context->builder->positionAtEnd($headersBb);
        $lines = $context->module->getNamedGlobal(self::G_LLVM_HDR_LINES);
        $idxAlloca = $context->builder->alloca($i32);
        $context->builder->store($i32->constInt(0, false), $idxAlloca);
        $context->builder->branch($loopBb);

        $context->builder->positionAtEnd($loopBb);
        $idx = $context->builder->load($idxAlloca);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_ULT, $idx, $hdrCount),
            $loopBodyBb,
            $afterHeadersBb
        );

        $context->builder->positionAtEnd($loopBodyBb);
        $slot = $context->builder->inBoundsGEP(
            $lines,
            $i32->constInt(0, false),
            $context->builder->zExt($idx, $i64)
        );
        $line = $context->builder->load($slot);
        $strMap = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($line, $strMap['length']));
        $data = $context->builder->structGep($line, $strMap['value']);
        $lineFmt = $context->builder->pointerCast(
            $context->constantFromString("%.*s\r\n"),
            $charPtr
        );
        $context->builder->call($context->lookupFunction('printf'), $lineFmt, $len, $data);
        $context->builder->store($i32->constInt(1, false), $wroteAlloca);
        $context->builder->store(
            $context->builder->add($idx, $i32->constInt(1, false)),
            $idxAlloca
        );
        $context->builder->branch($loopBb);

        $context->builder->positionAtEnd($afterHeadersBb);
        $wrote = $context->builder->load($wroteAlloca);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $wrote, $i32->constInt(0, false)),
            $blankBb,
            $markBb
        );

        $context->builder->positionAtEnd($blankBb);
        $blankFmt = $context->builder->pointerCast(
            $context->constantFromString("\r\n"),
            $charPtr
        );
        $context->builder->call($context->lookupFunction('printf'), $blankFmt);
        $context->builder->branch($markBb);

        $context->builder->positionAtEnd($markBb);
        $context->builder->store($i32->constInt(0, false), $countPtr);
        $context->builder->call(self::helperFunction($context, self::FLUSH_HELPER));
        $sapi = $context->module->getNamedGlobal(self::G_SAPI_STARTED);
        if (null !== $sapi) {
            $context->builder->store(
                $i32->constInt(1, false),
                $context->builder->pointerCast($sapi, $i32->pointerType(0))
            );
        }
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function ensureLlvmHeaderGlobals(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        if (null === $context->module->getNamedGlobal(self::G_LLVM_HDR_COUNT)) {
            $g = $context->module->addGlobal($i32, self::G_LLVM_HDR_COUNT);
            $g->setInitializer($i32->constInt(0, false));
        }
        if (null === $context->module->getNamedGlobal(self::G_LLVM_HDR_LINES)) {
            $arrTy = $strPtr->arrayType(self::LLVM_HDR_CAP);
            $g = $context->module->addGlobal($arrTy, self::G_LLVM_HDR_LINES);
            $g->setInitializer($arrTy->constNull());
        }
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
        // PendingHeadersJitHelper uses @getenv under NestedJIT (#21888 / #29313).
        StringGetenv::ensureNativeHtInternalProxies($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#22034'
        );
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

    private static function isThinLinkStub(LlvmFunction $fn): bool
    {
        foreach ($fn->getBasicBlocks() as $block) {
            if (false !== strpos($block->getName(), '_link_stub')) {
                return true;
            }
        }

        return false;
    }

    private static function clearThinLinkStubBodies(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $abiName) {
            $fn = $context->module->getNamedFunction($abiName);
            if (null === $fn || 0 === $fn->countBasicBlocks() || !self::isThinLinkStub($fn)) {
                continue;
            }
            foreach (array_reverse($fn->getBasicBlocks()) as $block) {
                $block->delete();
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
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
