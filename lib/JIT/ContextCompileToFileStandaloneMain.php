<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Standalone AOT {@code main} IR prologue/epilogue for {@see Context::compileToFile} (#36387).
 *
 * Extracted from {@see ContextCompileToFile} so request-boundary emission around the
 * user script stays a separate TU from TargetMachine object emit + link (peer
 * ContextCompileToFileEmitAndLink / size-budget ratchet, #36199 / #36403).
 *
 * Used via {@code use ContextCompileToFileStandaloneMain;} on {@see Context}.
 * compileToFile orchestration: {@see ContextCompileToFile}.
 *
 * No new C ABI. php-src analogy: php_request_startup / php_request_shutdown and
 * argv / SAPI glue wrap the user script in main/main.c and Zend/zend_execute_API.c
 * rather than living inside the object emitter / linker.
 */
trait ContextCompileToFileStandaloneMain
{
    /**
     * Emit the LLVM {@code main} that stores argv, runs init, the PHP entry, and shutdown.
     */
    private function emitStandaloneMainFunction(): void
    {
        if (is_null($this->main)) {
            return;
        }
        $i32 = $this->context->int32Type();
        $i8pp = $this->getTypeFromString('int8**');
        $signature = $this->context->functionType($i32, false, $i32, $i8pp);
        $main = $this->module->addFunction('main', $signature);
        $standaloneMainBlock = $main->appendBasicBlock('standalone_main');
        $emitInStandaloneMain = function (callable $emit) use ($standaloneMainBlock): void {
            $this->builder->positionAtEnd($standaloneMainBlock);
            $emit();
        };
        $emitInStandaloneMain(function () use ($main): void {
            $this->builder->call(
                $this->lookupFunction('__phpc_cli_store_argv'),
                $main->getParam(0),
                $main->getParam(1)
            );
        });
        $emitInStandaloneMain(fn () => Progress::emitNativeNote($this, 'c:main_before_init'));
        $emitInStandaloneMain(fn () => $this->builder->call($this->initFunc));
        $emitInStandaloneMain(fn () => Progress::emitNativeNote($this, 'c:main_after_init'));
        if (Builtin::LOAD_TYPE_STANDALONE === $this->loadType) {
            // php-src zend_reset_lc_ctype_locale — idle nl_langinfo(CODESET) → UTF-8 (#30789).
            $emitInStandaloneMain(fn () => Builtin\LocaleStartupRuntime::emitResetLcCtypeForStandaloneMain($this));
            // Thin user-script AOT still needs pending Error clear/abort for final/readonly
            // property writes (#23665, #3149). Session/header resets stay full-init only.
            if (!$this->isThinStandaloneAotMain()) {
                // HttpResponseRuntime always-on ensure removed (#35803 / peer #35443):
                // HttpResponseCode::emitResetForStandaloneMain already ensureLinked
                // (implement restores insert block — #33965). Full standalone must not
                // NestedJIT http_response_code bridges during compileToFile prologue when
                // the script never calls http_response_code() — leftover Context NestedJIT
                // vs Runtime ABI drift mints http_response_code_apply.1 (#31894 / #32122).
                $emitInStandaloneMain(fn () => Builtin\HttpResponseCode::emitResetForStandaloneMain($this));
                $emitInStandaloneMain(fn () => Builtin\SessionId::emitResetForStandaloneMain($this));
                $emitInStandaloneMain(fn () => Builtin\SessionName::emitResetForStandaloneMain($this));
                $emitInStandaloneMain(fn () => Builtin\SessionModuleName::emitResetForStandaloneMain($this));
                $emitInStandaloneMain(fn () => Builtin\PendingHeaders::emitResetForStandaloneMain($this));
            }
            $emitInStandaloneMain(fn () => $this->builder->call($this->lookupFunction('__superglobals__refresh')));
            if (!$this->isThinStandaloneAotMain()) {
                $emitInStandaloneMain(fn () => Builtin\JitThrow::registerDeclarations($this));
                $emitInStandaloneMain(fn () => $this->builder->call($this->lookupFunction('phpc_jit_clear_throw_pending')));
                // ensureLinked fills return-pending bodies after ensureFull drop (#35073).
                $emitInStandaloneMain(fn () => Builtin\JitReturnPending::ensureLinked($this));
                $emitInStandaloneMain(fn () => $this->builder->call($this->lookupFunction('phpc_jit_clear_return_pending')));
            }
            // ErrorBridge always-on ensure removed (#35443 / peer #35099): emitClear /
            // emitAbort already ErrorRaise / ReadonlyRaise::ensureLinked (insert restore)
            // before lookup. Thin hello-world must not NestedJIT AssertionErrorRaise during
            // {main} prologue — leftover Context NestedJIT vs Runtime ABI drift mints *.1
            // (#31894 / #32122). Thin still clears/aborts pending Error for final/readonly
            // writes (#23665, #3149).
            $emitInStandaloneMain(fn () => ErrorBridge::emitClearForStandaloneMain($this));
            if (!$this->isThinStandaloneAotMain()) {
                $emitInStandaloneMain(fn () => ExceptionBridge::emitClearForStandaloneMain($this));
            }
        }
        if (Builtin::LOAD_TYPE_STANDALONE === $this->loadType && $this->isUserScriptAot()) {
            // Helper .o static ctors register into phpc_gc_count before user code (#36245).
            $emitInStandaloneMain(fn () => Builtin\GcCollectCyclesRuntime::emitUserScriptStandaloneRegistryReset($this));
        }
        // Request boundary: reset Native emalloc counters (php_request_startup) (#36388).
        if (Builtin::LOAD_TYPE_STANDALONE === $this->loadType) {
            $emitInStandaloneMain(fn () => Builtin\MemoryRuntime::emitRequestBeginForStandaloneMain($this));
        }
        $emitInStandaloneMain(fn () => Progress::emitNativeNote($this, 'c:main_before_php'));
        if (Builtin::LOAD_TYPE_STANDALONE === $this->loadType) {
            $emitInStandaloneMain(fn () => Builtin\ObjectHandleRuntime::emitSnapBaselineForStandaloneMain($this));
        }
        if (Builtin::LOAD_TYPE_STANDALONE === $this->loadType
            && \PHPCompiler\JIT\Builtin\Refcount::runtimeAssertInjectDoubleDelrefEnabled()) {
            $emitInStandaloneMain(fn () => \PHPCompiler\JIT\Builtin\Refcount::emitInjectDoubleDelrefCall($this));
        }
        if (Builtin::LOAD_TYPE_STANDALONE === $this->loadType
            && \PHPCompiler\JIT\Builtin\Refcount::runtimeAssertInjectSharedWriteEnabled()) {
            $emitInStandaloneMain(fn () => \PHPCompiler\JIT\Builtin\Refcount::emitInjectSharedWriteCall($this));
        }
        if (Builtin::LOAD_TYPE_STANDALONE === $this->loadType
            && !$this->shouldSkipStandaloneMainEnvProbeGate()) {
            $emitInStandaloneMain(fn () => VmDriverExecuteNative::emitStandaloneMainEnvProbeGate($this, $this->main));
        } else {
            $emitInStandaloneMain(fn () => $this->builder->call($this->main));
        }
        $emitInStandaloneMain(fn () => Progress::emitNativeNote($this, 'c:main_after_php'));
        if (Builtin::LOAD_TYPE_STANDALONE === $this->loadType) {
            // Always abort pending Errors after user script — thin AOT previously skipped this
            // and silently no-op'd final/readonly writes (#23665, readonly_property_write AOT).
            // emitAbort self-ensures (#35443); do not NestedJIT ErrorBridge here.
            $emitInStandaloneMain(fn () => ErrorBridge::emitAbortIfPendingForStandaloneMain($this));
            // Thin AOT: still flush OB when stack was linked (URL-Rewriter endAll, #27566).
            // emitEndAllForStandalone no-ops unless __phpc_ob_end_all has a body (#13571).
            $emitInStandaloneMain(fn () => Builtin\ObOutput::emitEndAllForStandalone($this));
            if (!$this->isThinStandaloneAotMain()) {
                $emitInStandaloneMain(fn () => ExceptionBridge::emitAbortIfPendingForStandaloneMain($this));
            }
            // Thin AOT skipped this flush, so header() without exit produced no CGI Status
            // (header_redirect.phpt / #1974). emitFlushForStandalone no-ops unless the
            // PendingHeaders helper was NestedJIT-linked (header()/exit).
            $emitInStandaloneMain(fn () => Builtin\PendingHeaders::emitFlushForStandalone($this));
        }
        if (!$this->isThinStandaloneAotMain()) {
            // User __destruct before __shutdown__ frees compile-time strings / sg_* (#4013).
            $emitInStandaloneMain(fn () => $this->type->object->emitShutdownDestructorsCall());
        }
        $emitInStandaloneMain(fn () => $this->builder->call($this->shutdownFunc));
        // Bump-arena release AFTER shutdown/dtors — php_request_shutdown frees the request
        // heap only once request zvals are gone (zend_alloc). Earlier free → UAF (#36388).
        if (Builtin::LOAD_TYPE_STANDALONE === $this->loadType) {
            $emitInStandaloneMain(fn () => Builtin\MemoryRuntime::emitRequestEndForStandaloneMain($this));
        }
        $emitInStandaloneMain(fn () => $this->builder->returnValue($i32->constInt(0, false)));
    }
}
