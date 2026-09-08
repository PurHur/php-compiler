<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Config;

/**
 * Thin / full standalone AOT body install helpers for {@see Context} (#36387).
 *
 * Extracted from {@see Context} so the user-script / bootstrap-aot standalone-body
 * cluster (isThinStandaloneAotMain … ensureFullStandaloneBodies) stays a separate TU
 * from the Context compile / type / NestedJIT hubs (split-TU / size-budget ratchet).
 *
 * Used via {@code use ContextStandaloneBodies;} on {@see Context}.
 *
 * No new C ABI. php-src analogy: Zend SAPI / request startup installs a minimal vs full
 * executor environment before running {main} (Zend/zend_execute_API.c,
 * main/main.c php_request_startup) — thin vs full init, not a new runtime surface.
 */
trait ContextStandaloneBodies
{
    public function isThinStandaloneAotMain(): bool
    {
        return $this->isUserScriptAot() || $this->shouldUseBootstrapAotStandaloneBodies();
    }

    /**
     * Nested *JitHelper compile under user-script standalone: temporarily clear
     * PHP_COMPILER_AOT_USER_SCRIPT so helpers get full NestedJIT (#15407, #16734, #20246).
     *
     * Former {@see UserScriptAotDeferNestedJit::shouldDefer} — keep STANDALONE + user-script
     * only (do not widen to bootstrap-aot-link thin path).
     */
    public function shouldClearUserScriptEnvForNestedHelperCompile(): bool
    {
        return Builtin::LOAD_TYPE_STANDALONE === $this->loadType && $this->isUserScriptAot();
    }

    /**
     * After preg prelink on a temporary full-init Context, restore user-script standalone bodies (#16075).
     */
    public function retrofitUserScriptStandaloneAfterPregPrelink(): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE !== $this->loadType || !$this->isUserScriptAot()) {
            return;
        }
        $this->ensureMinimalUserStandaloneBodies();
    }

    public function isUserScriptAot(): bool
    {
        return UserScriptAotEnv::isActive();
    }

    /** bootstrap-aot-link: thin LLVM during Context init — defer nested php-in-PHP JIT (#14459, #13245). */
    private function shouldUseBootstrapAotStandaloneBodies(): bool
    {
        $bootstrapLink = Config::getenv('PHP_COMPILER_BOOTSTRAP_AOT_LINK');
        if ('1' === $bootstrapLink || 'true' === strtolower((string) $bootstrapLink)) {
            return true;
        }

        return false;
    }

    /** examples/000–009 user-script AOT: thin LLVM bridges only — no nested-JIT stdlib during init (#13571). */
    private function ensureMinimalUserStandaloneBodies(): void
    {
        // StringHtmlspecialchars always-on removed (#34642): htmlspecialchars.php already
        // ensureLinked before lookup (peer #34612 HtmlEntities/Decode). Leftover Context
        // NestedJIT vs Runtime ABI drift mints *.1 (#31894 / #32122). Thin hello-world must
        // not NestedJIT htmlspecialchars ABI during init.
        // HtmlEntities / HtmlspecialcharsDecode always-on removed (#34612): htmlentities.php /
        // JitHtmlspecialcharsDecode already ensureLinked before lookup (peer #34605). Leftover
        // Context NestedJIT vs Runtime ABI drift mints *.1 (#31894 / #32122).
        // ExceptionBridge always-on removed (#34732): TypeErrorRaise::ensureLinked /
        // ExceptionBridge::emitTypeError* / emitClear+emitAbort already implement standalone
        // bodies before lookup (peer #34695). Thin hello-world must not NestedJIT TypeErrorRaise
        // / JitThrow during init — thin {main} skips ExceptionBridge clear/abort anyway.
        // Leftover Context NestedJIT vs Runtime ABI drift mints *.1 (#31894 / #32122).
        // ErrorBridge always-on removed (#34769): ErrorRaise / AssertionErrorRaise /
        // ReadonlyRaise ensureLinked + emitClear/emitAbort/emitRaise already implement
        // standalone bodies before lookup (peer #34732). Thin hello-world must not NestedJIT
        // pending-Error ABI during init — thin {main} skips ErrorBridge clear/abort when unused.
        // Leftover Context NestedJIT vs Runtime ABI drift mints *.1 (#31894 / #32122).
        // Full standalone drop is #35099 (peer #35089 / #35086).
        // ErrorHandler / ExceptionHandler always-on removed (#34612): JitErrorHandler /
        // JitTriggerErrorKernel / JitExceptionHandler / TryCatchHelper already ensureLinked
        // before lookup (peer #34605). implement() paths restore builder insert mid-{main}.
        // StreamLifecycle / StreamRead / StreamBucket always-on removed (#34836): call-site
        // StreamLifecycleRuntime::ensureLinked(ForUserScriptLowering) / StreamReadRuntime::
        // ensureLinked / StreamBucket::ensureLinked already run before lookup (JitFclose /
        // JitFeof / JitFflush / JitFgetc / JitFgets / JitStreamBucket / JitIsResource /
        // StringVarDump / StringPrintR / SilenceRuntime — peer Type::initialize #34439 /
        // #20966 / #20982 / #20998). ensureMinimal is reached for user-script AOT and via
        // bootstrap-aot ensureBootstrapAotStandaloneBodies; the old `!$isUserScriptAot`
        // guard only NestedJIT Stream* on the bootstrap path and still risked feof.1 /
        // stream_bucket_*.1 (#31894 / #32122). Full standalone drop is #35086 (peer #35073).
        // StringTriggerError always-on removed (#34641): trigger_error_.php / JitBuiltinWarning /
        // JitIncDec / HashTableResourceKeyLlvm / JitTriggerErrorKernel already ensureLinked before
        // lookup (peer #34631 / #33234). JitTriggerErrorKernel restores builder insert mid-{main}.
        // Leftover Context NestedJIT vs Runtime ABI drift mints *.1 (#31894 / #32122).
        // AssertFail always-on removed (#34605): JitAssert already ensureLinked before lookup
        // (peer #34578). Full standalone still ensureStandaloneBodies below.
        // JitReturnPending always-on removed (#34621): TryCatchHelper / emitPendingReturnResume
        // already ensureLinked before lookup (peer #34612). JitHelperAbiBridge restores insert
        // mid-{main}. Leftover Context NestedJIT vs Runtime ABI drift mints *.1 (#31894 / #32122).
        // ObOutput always-on removed (#34695): ValueEchoHelper / ValueEchoRuntime /
        // StringVarDump / ObOutput / StreamReadRuntime already ensureLinked before
        // __phpc_ob_echo_* lookup (peer #34642). Leftover Context NestedJIT vs Runtime ABI
        // drift mints ob_*.1 (#31894 / #32122). Thin hello-world must not NestedJIT ob during init.
        // StringRandomBytes / Utf8Latin1 / RewriteVars / Define / StrContains / StatPath /
        // FileGetContents / MetaTags / HashCrypto / MbNumericEntity / Readfile / Bin2hex /
        // Addslashes / Stripslashes / FilePutContents / IniRuntime always-on removed (#34578):
        // call-site ensureLinked / ensureStandaloneBodies / emit* / invoke* already run before
        // lookup (peer #34566 SessionStorageGlobals). Thin AOT hello-world must not NestedJIT
        // those ABIs. Leftover Context NestedJIT vs Runtime ABI drift mints *.1 (#31894 / #32122).
        // Type::register __compiler_ini_* shells are gone (#34474); do not re-add IniRuntime here.
        // ProgressNote / GcCollectCycles always-on removed (#34605): tryResolveProgressStaticCall /
        // JitGcCollectCycles / Object_ / GcStatusRuntime already ensureLinked before lookup
        // (peer #34578). Full standalone still ensureStandaloneBodies below.
        // LastError always-on removed (#34631): JitErrorGetLast / JitTriggerErrorKernel already
        // ensureLinked before lookup (peer #34621). LastErrorRuntime restores builder insert
        // mid-{main}. Leftover Context NestedJIT vs Runtime ABI drift mints *.1 (#31894 / #32122).
        // Full standalone still ensureStandaloneBodies below (StringTriggerError then LastError).
        // EnvLocal always-on removed (#34807): getenv()/putenv() lower via StringGetenv /
        // PutenvJitHelper / GetenvLookupJitHelper (#32665 / #23414 / #29313). No call site looks
        // up __compiler_env_local_* — NestedJIT of EnvLocalJitHelper during thin init only
        // risked env_local_lookup.1 (#31894 / #32122). bootstrap-aot still
        // ensureBootstrapAotStubLinked below. EnvLocalRuntime::ensureLinked stays callable.
        // SuperglobalName always-on removed (#34812): JitSuperglobalName / JIT.php
        // compileSuperglobalNameNative already StringSuperglobalName::ensureLinked before lookup
        // (peer #34807 / #33235). Compile-time paths use Web\Superglobals::isSuperglobalName —
        // not the LLVM ABI. Thin hello-world must not NestedJIT SuperglobalNameJitHelper during
        // init. Leftover Context NestedJIT vs Runtime ABI drift mints is_superglobal_name.1
        // (#31894 / #32122). Full standalone still ensureLinked below.
        // CliArgv always-on removed (#34822 / #35133): compileToFile (all standalone) +
        // CliArgvGlobalInit / JitGetopt already ensureLinked / ensureStandaloneBodies before
        // lookup (peer #34812 / #34463). Thin hello-world must not link CLI argv ABI during
        // ensureMinimal init — main() still gets __phpc_cli_store_argv from compileToFile.
        // Mid-{main} $argc/$argv restores insert block after ABI emit (#27317). Leftover Context
        // NestedJIT vs Runtime ABI drift mints cli_*.1 (#31894 / #32122). Full standalone also
        // deferred to compileToFile (#35133); bootstrap-aot still ensureStandaloneBodies in
        // ensureBootstrapAotStandaloneBodies.
        // DomStandaloneAotInit / DomInstanceMethod always-on removed (#34605):
        // VmActiveContextInitLlvm::emitPendingBeforeSeal ensureLinked DomStandaloneAotInit when
        // thin init is requested; DomInstanceMethodRuntime::invoke ensureBridge per arity
        // (peer #34578). Leftover Context NestedJIT vs Runtime ABI drift mints
        // dom_standalone_aot_init.1 / dom_instance_method_*.1 (#31894 / #32122).
    }

    /** bootstrap-aot-link fixtures: minimal init + CLI argv / superglobal refresh for standalone main (#14459). */
    private function ensureBootstrapAotStandaloneBodies(): void
    {
        $this->ensureMinimalUserStandaloneBodies();
        Builtin\EnvLocalRuntime::ensureBootstrapAotStubLinked($this);
        Builtin\CliArgvRuntime::ensureStandaloneBodies($this);
        Builtin\SuperglobalRefreshRuntime::ensureStandaloneBodies($this);
    }

    private function ensureFullStandaloneBodies(): void
    {
        Builtin\StreamIoRuntime::beginStandaloneInitPhase();
        try {
            // ExceptionBridge / ErrorBridge always-on removed (#35099): TypeErrorRaise /
            // JitThrow / ErrorRaise / AssertionErrorRaise / ReadonlyRaise ensureLinked +
            // emitClear/emitAbort/emitRaise already implement standalone bodies before lookup
            // (peer ensureMinimal #34732 / #34769). compileToFile clear/abort also drop
            // eager ErrorBridge::ensureLinked (#35443) — emit* self-ensure. Full
            // standalone must not NestedJIT type_error_* / error_* during init — leftover
            // Context NestedJIT vs Runtime ABI drift mints *.1 (#31894 / #32122).
            // StreamLifecycle / StreamRead always-on removed (#35086): JitFclose / JitFeof /
            // JitFflush / JitFgetc / JitFgets / StringVarDump / StringPrintR / SilenceRuntime /
            // StreamReadJit / StringFgetcsvJit already ensureLinked(ForUserScriptLowering)
            // before lookup (peer ensureMinimal #34836 / #20966 / #20982). Full standalone
            // must not NestedJIT feof/fclose/fflush/fgets during init — leftover Context
            // NestedJIT vs Runtime ABI drift mints feof.1 / fflush.1 (#31894 / #32122).
            // StringTriggerError / AssertFail / AssertOptions / JitReturnPending always-on
            // removed (#35073): JitAssert / JitAssertOptions / TryCatchHelper /
            // AssertFail::ensureLinked (→ StringTriggerError) already ensure before lookup
            // (peer ensureMinimal #34605 / #34621 / #34641). Full standalone must not
            // NestedJIT assert_fail* / assert_options / return_pending / trigger_error
            // during init — leftover Context NestedJIT vs Runtime ABI drift mints *.1
            // (#31894 / #32122).
            // ObOutput always-on removed (#34695): ValueEchoHelper / ValueEchoRuntime::emitValue
            // ensureLinked ObOutput before __phpc_ob_echo_* lookup (peer #34642).
            // ValueEcho always-on removed (#35143): emitValue / StringVarDump / StringPrintR /
            // StringVarExport already ValueEchoRuntime::ensureLinked before type-bridge use
            // (peer #35137 SuperglobalRefresh / #35133 CliArgv). Full standalone must not
            // NestedJIT value_echo_* during init — leftover Context NestedJIT vs Runtime ABI
            // drift mints value_echo_*.1 (#31894 / #32122).
            // CliArgv always-on removed (#35133 / peer ensureMinimal #34822): compileToFile
            // ensures CliArgvRuntime for every LOAD_TYPE_STANDALONE before main emits
            // __phpc_cli_store_argv; CliArgvGlobalInit / JitGetopt ensureLinked before lookup.
            // Full standalone must not NestedJIT cli_* during init — leftover Context NestedJIT
            // vs Runtime ABI drift mints cli_*.1 (#31894 / #32122).
            // Soundex/Quotemeta/PregQuote/Nl2br/Ucwords/Metaphone/Wordwrap/MbNumericEntity/
            // Bin2hex/Base64*/Strrev/StrRepeat/StrPad/StrRot13/Uniqid/ChunkSplit/
            // GraphemeStrSplit/Hex2bin/Levenshtein/SubstrCount/CountChars/NCompare/
            // StrWordCount/StripTags/Strtr/ParseStr always-on removed (#35099): the old
            // `!isStandaloneInitPhase()` gate never ran — ensureFull always
            // beginStandaloneInitPhase() first (#14472 / #20571). Call sites already
            // ensureLinked before lookup (peer ensureMinimal #34578). Do not NestedJIT
            // those ABIs during full init (#31894 / #32122).
            // StringFormat always-on removed (#35130): JitSprintf / JitPrintf /
            // JitNumberFormat / JitFprintf / JitVfprintf / JitVsprintf / vprintf_ /
            // vfprintf_ already implementIfDeclared / ensureLinked before lookup
            // (peer #35127 / Type #32921 / #15642). Full standalone must not NestedJIT
            // __compiler_sprintf / __compiler_printf / __compiler_number_format during
            // init — leftover Context NestedJIT vs Runtime ABI drift mints sprintf.1 /
            // printf.1 / number_format.1 (#31894 / #32122).
            // StringStrReplace always-on removed (#35160): JitStrReplace / StringStrReplace::invoke
            // already ensureLinked before lookup. With HelperRuntimeCache enabled, ensureLinked
            // still implements under NestedJitCompileScope (the #23970 no-op is cache-off only);
            // bin/compile.php already forces PHP_COMPILER_HELPER_RUNTIME_O=1 for skip-bundle
            // compile_driver. Full standalone must not NestedJIT phpc_str_replace during init —
            // leftover Context NestedJIT vs Runtime ABI drift mints phpc_str_replace.1
            // (#31894 / #32122).
            // StringJsonEncode / StringJsonDecode always-on removed (#35065): JitJsonEncode /
            // JitJsonDecode / JsonEncodeArrayLlvm / JitJsonValidate / … already ensureLinked /
            // ensureJitHelperCompiled before lookup (peer #35035). Full standalone must not
            // NestedJIT json_* during init — leftover Context NestedJIT vs Runtime ABI drift
            // mints json_encode_*.1 / json_decode*.1 (#31894 / #32122).
            // StringRandomBytes always-on removed (#35113): JitRandomBytes / ArrayRandLlvm /
            // SessionCreateIdRuntime already StringRandomBytes::ensureLinked before lookup
            // (peer ensureMinimal #34578 / Type #33160 / #34332). Full standalone must not
            // NestedJIT __compiler_random_bytes during init — leftover Context NestedJIT vs
            // Runtime ABI drift mints random_bytes.1 (#31894 / #32122).
            // ScalarDimFetchRuntime / StringOffsetRuntime always-on removed (#35065):
            // emitWarning / dimFetch / readDimAsString / … already ensureLinked before ABI use
            // (peer #35035). Do not NestedJIT offset / scalar-dim helpers during full init.
            // UndefinedVariableRuntime: ensureLinked only — emitWarningForName uses __compiler_trigger_error
            // (call sites / AssertFail ensure StringTriggerError; avoid duplicate bodies — #10524 / #35073).
            // StreamFilter / StreamBucket always-on removed (#35086): StreamIoJit /
            // StreamReadJit / StreamReadRuntime / JitStreamBucket / JitIsResource already
            // StreamFilter::ensureLinked / StreamBucket::ensureLinked before lookup
            // (peer ensureMinimal #34836 / #21041 / #20998). Full standalone must not
            // NestedJIT stream_filter_* / stream_bucket_* during init — leftover Context
            // NestedJIT vs Runtime ABI drift mints stream_bucket_*.1 (#31894 / #32122).
            // GcToggle / GcCollect / ProgressNote / LastError always-on removed (#35073):
            // JitGcToggle / JitGcCollectCycles / ProgressNoteRuntime / JitErrorGetLast /
            // JitTriggerErrorKernel / Object_ delref already ensureLinked before lookup
            // (peer ensureMinimal #34605 / #34631). Full standalone must not NestedJIT
            // gc_* / progress_note / last_error during init (#31894 / #32122).
            // FunctionStatic always-on removed (#35086): FunctionStaticHelper::ensureRuntime
            // already FunctionStaticRuntime::ensureLinked before phpc_fn_static_* lookup
            // (#10173). Full standalone must not emit fn-static table ABI during init —
            // leftover Context NestedJIT vs Runtime ABI drift mints phpc_fn_static_*.1
            // (#31894 / #32122).
            // StringUtf8Latin1 / RewriteVars / Define / Strspn / FileGetContents / Readfile
            // always-on removed (#35089): JitUtf8Latin1 / JitDefine / SpnJitLowering /
            // JitParseStrUserScriptCstrKernel / JitFileGetContents / readfile.php /
            // RewriteVarsRuntime::emit* / BootstrapCompileSmokeM3Emit already ensureLinked
            // before lookup (peer ensureMinimal #34578 / Type #34474 / #34423). Full
            // standalone must not NestedJIT those ABIs during init — leftover Context
            // NestedJIT vs Runtime ABI drift mints utf8_*.1 / define.1 /
            // file_get_contents.1 / readfile.1 / strspn.1 (#31894 / #32122).
            // SuperglobalRefresh always-on removed (#35137 / peer #35133 CliArgv):
            // compileToFile ensures for every LOAD_TYPE_STANDALONE before main emits
            // __superglobals__refresh; thin keeps ensureUserScriptRefreshEmit. Full
            // standalone must not NestedJIT __superglobals__refresh during init —
            // leftover Context NestedJIT vs Runtime ABI drift mints
            // __superglobals__refresh.1 (#31894 / #32122).
            // SuperglobalName always-on removed (#35035): JitSuperglobalName / JIT.php
            // StringSuperglobalName::ensureLinked before lookup (peer ensureMinimal #34812 /
            // #33235). Full standalone must not NestedJIT is_superglobal_name during init —
            // leftover Context NestedJIT vs Runtime ABI drift mints is_superglobal_name.1
            // (#31894 / #32122).
            // TokenGetAll / Highlight / Hebrev / Hebrevc always-on removed (#35035): each
            // ensureStandaloneBodies is a no-op — helper LLVM compiles on first lowering
            // (TokenGetAll::helperFunction / JitHighlight / JitHebrev). Do not re-add eager
            // NestedJIT here (#31894 / #32122 .1 mint class).
            // JitStreamBucketKernel always-on removed (#35086): StreamBucket::ensureLinked →
            // JitStreamBucketKernel::ensureLinked (JitStreamBucket / JitIsResource) already
            // implement before lookup (peer #34836). Do not NestedJIT stream_bucket_* here.
            // StringGetenv / StringGetenvAll always-on removed (#35127): JitEnv::getenv /
            // getenvAll already StringGetenv::ensureLinked / StringGetenvAll::ensureLinked
            // before lookup (peer ensureMinimal Type #32665 / #34807). Full standalone must
            // not NestedJIT __compiler_getenv / __compiler_getenv_all during init — leftover
            // Context NestedJIT vs Runtime ABI drift mints getenv.1 / getenv_all.1
            // (#31894 / #32122). Post-init always-helper (#20156) was the prior reason these
            // sat after endStandaloneInitPhase; call-site ensureLinked is enough now.
        } finally {
            Builtin\StreamIoRuntime::endStandaloneInitPhase();
        }
    }
}
