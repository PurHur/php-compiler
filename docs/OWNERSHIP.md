# Ownership map

Load-bearing paths, the gate that proves them, and the failure mode they have produced before.
Child of [#36403](https://github.com/PurHur/php-compiler/issues/36403). Budgets: `script/size-budgets.json` + `script/check-size-budgets.sh`.

## How to read this

| column | meaning |
|---|---|
| **owns** | What breaks if this path is wrong |
| **gate** | Cheapest command that must stay green after edits |
| **failure mode** | Documented incident (issue) — do not re-introduce |

Gates are **local/Docker only** — GitHub `CLEAN` means no checks configured (see `AGENTS.md`).

---

## `lib/`

| path | owns | gate | failure mode |
|---|---|---|---|
| `lib/Compiler.php` + `lib/Compiler/Concern/*` | PHP → CFG/opcodes | `php script/opcode-corpus-md5.php --check`; `script/differential-sweep.sh` | Super-quadratic call-arg scans (#36224); Concern extract opcode drift (#36230) |
| `lib/JIT.php` + `lib/JIT/Concern/*` | Opcode → LLVM IR | `./script/aot-smoke.sh`; IR size gate in same script | Silent whole-script VM fallback (#36222); alwaysinline never honoured (#36213). Traits live under `lib/JIT/Concern/` but stay in `namespace PHPCompiler` so relative `ext\` / `JIT\` resolves like the parent class. |
| `lib/VM.php` + `lib/VM/Concern/*` | Interpreter loop | `script/differential-sweep.sh`; targeted `./script/phpunit.sh --filter VMTest` | 1M-loop exit 255 (#36148/#15906); O(scope²) hot loop (#36207). Traits under `lib/VM/Concern/` stay in `namespace PHPCompiler` like JIT Concerns. Outgoing call-arg resolve lives in `OutgoingCallArgResolve` (companion to `OutgoingCallTempRelease`); internal ICALL + ASSIGN copyFrom in `InternalHandlerExecuteAndAssignCopy`; closure use()/invoke + function-static bridges in `ClosureBindAndFunctionStatic`; class-scope keywords + static property storage in `ClassScopeAndStaticPropertyResolve`; include path + `::class`/self/parent/static display in `IncludePathAndClassPseudoConst`; `iterator_to_array` materialize in `IteratorToArrayConvert`; property-hook frame identity + static hook link in `PropertyHookFrameAndStaticLink`; construct mark + pending outbound restore in `ConstructMarkAndPendingOutboundCall`; PROPERTY_FETCH/WRITE dispatch in `ObjectPropertyFetchDispatch`; ARRAY_DIM_FETCH/WRITE dispatch in `ArrayDimFetchDispatch`; TYPE_INIT_ARRAY/ADD_ARRAY_ELEMENT/ARRAY_SPREAD in `ArrayInitSpreadDispatch`; STATIC_PROPERTY_FETCH/UNSET dispatch in `StaticPropertyFetchDispatch`; TYPE_UNSET dispatch in `UnsetDispatch`; TYPE_ASSIGN/ASSIGN_REF dispatch in `AssignDispatch`; FUNCCALL_EXEC_RETURN/NORETURN dispatch in `FuncCallExecDispatch`; TYPE_ARG_RECV in `ArgRecvDispatch`; CAST_* in `ScalarCastDispatch`; IDENTICAL..SPACESHIP in `ScalarCompareDispatch`; POST_INC..BITWISE_NOT in `ScalarArithBitwiseUnaryDispatch`; CONCAT in `ScalarCastCompareArithConcatDispatch`; TYPE_CLASS_CONST_FETCH in `ClassConstFetchDispatch`; TYPE_ISSET in `IssetDispatch`; TYPE_INCLUDE/require in `IncludeDispatch`; TYPE_FUNCCALL_INIT in `FuncCallInitDispatch`; TYPE_NEW in `NewDispatch`; TYPE_METHODCALL_INIT in `MethodCallInitDispatch`; TYPE_ECHO/PRINT/EVAL in `EchoPrintEvalDispatch`; TYPE_ITER_RESET/VALID/KEY/VALUE in `ForeachIterDispatch`; TYPE_COALESCE/NULLSAFE/BEGIN_SILENCE/END_SILENCE/EXIT in `CoalesceNullsafeSilenceExitDispatch`; TYPE_JUMP/JUMPIF/CASE in `JumpCaseDispatch`; TYPE_CONST_FETCH/STATICCALL_INIT/INSTANCEOF/IN in `ConstFetchStaticCallInstanceofDispatch`; TYPE_DECLARE_INTERFACE/TRAIT/ENUM/CLASS in `DeclareClassLikeDispatch`; TYPE_TRY/CATCH/FINALLY/THROW/RETHROW in `TryCatchThrowDispatch`; TYPE_CLONE in `CloneDispatch`; TYPE_FUNCDEF/DECLARE_GLOBAL_CONST in `FuncDefAndGlobalConstDispatch`; TYPE_SCRIPT_MAGIC/TICK_SCOPE_*/TICKS in `ScriptMagicAndTickDispatch`; TYPE_RETURN/RETURN_VOID (+ return-complete epilogues) in `ReturnDispatch`; instanceof / static-call eligibility in `ClassInstanceAndStaticCallSupport`; `runFramesInner` opcode loop in `RunFramesInner`. ; TYPE_ARG_SEND in `ArgSendDispatch`; TYPE_VAR_FETCH/DECLARE_GLOBAL/DECLARE_FUNCTION_STATIC/JUMPIF_FUNCTION_STATIC_INITIALIZED/FUNCTION_STATIC_INIT_STORE in `VarFetchGlobalAndFunctionStaticDispatch`. TYPE_LIST_UNPACK_CHECK/LIST_SPREAD_ASSIGN in `FromCallableAndClosureDispatch + ListUnpackAndSpreadAssignDispatch`.
| `lib/AOT/Linker.php` | Native link line | `./script/aot-smoke.sh` (size gate) | Unconditional libsodium/… link (#36200); missing `--gc-sections` (#36198) |
| `lib/AOT/HelperRuntimeCache.php` | Split-TU helper `.o` cache hub | `php script/check-helper-runtime-prelink.php` | Fingerprint-stale cache silently disabled (#23457); monolithic `.text` vs `common.o` (#36246) |
| `lib/AOT/HelperRuntimePaths.php` | Cache enablement / unit dirs / arch slug paths | same + `./script/phpunit.sh --filter HelperRuntimeCacheFingerprint` | Wrong `PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR` / arch key → empty corpus (#15889/#36391) |
| `lib/AOT/HelperRuntimeFingerprint.php` + `HelperRuntimeFingerprintUnit.php` | Core/LLVM identity + per-unit deps/manifest v2 fingerprinting | same + `./script/phpunit.sh --filter HelperRuntimeCacheFingerprint` | Fingerprint restamp without rebuild (#23458); path-token LLVM identity (#24381) |
| `lib/AOT/HelperRuntimeLink.php` | Link selection + unit.o safety / gc_sections gates | same + `./script/phpunit.sh --filter HelperRuntimeCommonTest` | Mixed gc_sections into monolithic corpus → aot-smoke SIGSEGV (#36246/#36401) |
| `lib/AOT/HelperRuntimeBind.php` + `HelperRuntimeBindInlineOnly.php` + `HelperRuntimeBindTypeLocalize.php` | Bitcode bind / unit lifecycle + NestedJIT-force logical list + named-struct type remapping | same + `./script/phpunit.sh --filter HelperRuntimeCacheFingerprint` | Suffixed named-struct types fail module verify at call sites (#15889); muldefs init double-free (#16075); stale unit.o under thin AOT (#17954) |
| `lib/AOT/HelperRuntimeIndex.php` | Unit manifest / helperIndex scan | same + `./script/phpunit.sh --filter HelperRuntimeCacheFingerprint` | Stale build-cache unit.o shadowing prelinked (#15889); empty object wins over committed tier |
| `lib/AOT/HelperRuntimeWarm.php` | User-AOT warm / committed-tier corpus skip | same + `./script/phpunit.sh --filter HelperRuntimeCacheFingerprint` | Clean checkout re-emits 410 units despite prelinked cache (#24302/#32122) |
| `lib/AOT/HelperRuntimeCommon.php` | Shared runtime prologue | same + `PHP_COMPILER_HELPER_RUNTIME_COMMON=1` smoke | Auto-link segfault until gc-section corpus (#36423/#36429) |
| `lib/JIT/CompileCache.php` + `CompileCacheSemanticHash.php` + `CompileCacheSemanticFileParts.php` + `CompileCacheSemanticFunctionConsume.php` + `CompileCachePartialEmitPruneGlobals.php` + `CompileCachePartialEmitSymbolProbe.php` + `CompileCachePartialEmitLlvm.php` + `CompileCachePartialEmitDemote.php` + `CompileCacheArtifactPersist.php` + `CompileCacheObjectLinkPersist.php` + `CompileCacheEditScaffold.php` + `CompileCacheEditScaffoldRestore.php` + `CompileCacheEditScaffoldStrip.php` + `CompileCacheEditScaffoldPlan.php` + `CompileCacheProjectEntryMembers.php` + `CompileCacheProjectIndex.php` + `CompileCacheKeyLayout.php` + `CompileCacheBitcodePersist.php` + `CompileCacheBitcodeAotStamp.php` + `CompileCacheBitcodeRestore.php` + `CompileCacheRecordingMemberPath.php` + `CompileCacheRecording.php` + `CompileCacheEditSession.php` + `CompileCacheProjectMembers.php` + `CompileCacheArtifactFacade.php` + `CompileCacheSemanticHashFacade.php` + `CompileCacheProjectIndexFacade.php` + `CompileCacheHubState.php` + `CompileCacheKeyLayoutFacade.php` | MCJIT/AOT warm + semantic file-parts + per-function consume + artifact mid-tier + object/link mid-tier + edit-scaffold restore + plan + LLVM strip/rebind + partial-emit LLVM surgery + symbol probes + const-global prune + project index + entry→members + key/freshness + bitcode MCJIT save + AOT stamp persist + bitcode warm restore + cold-emit recording + member-path attribution + edit-scaffold session + project members + artifact facade + semantic-hash facade + project-index facade + hub state + KeyLayout public delegates | `./script/phpunit.sh --filter AotCompileCacheTest`; bench-gate one-file-edit | Comment-only edits falsely stripped (#36387); module.bc void* round-trip (#36479) |
| `lib/JIT/Context.php` + `ContextEditScaffoldCoreTypeSeed.php` + `ContextEditScaffoldFunctionScopeRebind.php` + `ContextEditScaffoldModuleRebind.php` + `ContextDefineBuiltins.php` + `ContextDefineBuiltinFunctionProxies.php` + `ContextDefineBuiltinFunctionProxiesDirectoryAndFile.php` + `ContextDefineBuiltinFunctionProxiesArrayIteratorAndObject.php` + `ContextDefineBuiltinFunctionProxiesSplIterators.php` + `ContextDefineBuiltinFunctionProxiesSplContainers.php` + `ContextDefineBuiltinFunctionProxiesWeakAndPhpToken.php` + `ContextDefineBuiltinFunctionProxiesReflectionAndException.php` + `ContextDefineBuiltinFunctionProxiesReflectionMembers.php` + `ContextDefineBuiltinFunctionProxiesExceptionAndError.php` + `ContextDefineBuiltinFunctionProxiesFiberGeneratorAndClosure.php` + `ContextDefineBuiltinFunctionProxiesDateAndXml.php` + `ContextDefineBuiltinFunctionProxiesFinfoPdoAndXml.php` + `ContextCompileToFile.php` + `ContextCompileToFileStandaloneMain.php` + `ContextCompileToFileEmitAndLink.php` + `ContextVariableOperandBinding.php` + `ContextVariableOperandAlias.php` + `ContextVariableOperandLookup.php` + `ContextFreeDeadAndConstantFetch.php` + `ContextConstantFetch.php` + `ContextFunctionProxyNestedJitKernelRegistry.php` + `ContextFunctionProxyExternalMethodStubReport.php` + `ContextFunctionProxyRegistration.php` + `ContextFunctionProxyAndNestedJitKernel.php` + `ContextTypeAndStructMap.php` + `ContextCastToBool.php` + `ContextTypeFromString.php` + `ContextStructFieldMap.php` + `ContextStandaloneBodies.php` + `ContextModuleInitShutdownBlocks.php` + `ContextModuleCompileAndOptimize.php` + `ContextModuleVerify.php` + `ContextModuleOptimizationPasses.php` + `ContextScriptGlobalsAndIncludeTracking.php` + `ContextLlvmConstantsAndRegistry.php` + `ContextLlvmConstantEmit.php` + `ContextScopeLifecycleAndInitEmit.php` | JIT Context hub + edit-scaffold + defineBuiltins + functionProxies (SPL) + Directory/SplFile* proxies + ReflectionClass proxies + Reflection member/extension proxies + Exception/Error proxies + Fiber/Generator/Closure proxies + DateTime proxies + finfo/PDO/XML proxies + ArrayIterator/ArrayObject proxies + SplIterators proxies + SplContainers proxies + Weak/PhpToken proxies + compileToFile + standalone-main + emit/link + variable-op + freeDead + CONST_FETCH + NestedJIT registry + function-proxy/stubs + type/struct map + castToBool + typeFromString + structField map + standalone bodies + init/shutdown blocks + MCJIT compile + module verify + IR opt passes + script-globals/include tracking + LLVM constants/registry + LLVM constant emit + scope/lifecycle/init-emit traits | `./script/phpunit.sh --filter AotCompileCacheTest`; aot-smoke | module.bc thin-boot SIGSEGV if seed/rebind stays on hub (#36387); compileToFile object/link path (#36387/#36199) |
| `lib/JIT/DiscardedPureCallElision.php` + `DiscardedPureCallElisionStringOps.php` + `DiscardedPureCallElisionArrayOps.php` + `DiscardedPureCallElisionDateCalOps.php` + `DiscardedPureCallElisionFormatAnalyzeOps.php` + `DiscardedPureCallElisionMathAndHashOps.php` + `DiscardedPureCallElisionRuntimeInfoOps.php` + `DiscardedPureCallElisionNativeLongFolds.php` + `DiscardedPureCallElisionMathGuardAndVoidNativeOps.php` + `DiscardedPureCallElisionIntrospectOps.php` + `DiscardedPureCallElisionTypeStringCoreOps.php` | Discarded pure builtin elision (call-overhead) | `./script/aot-smoke.sh`; differential | 6k-line monolith TU (#36387 split); wrong elision = silent wrong-output |
| `lib/JIT/NoThrowCallElision.php` + `NoThrowCallElisionRuntimeInfoOps.php` | Skip exception-stack / throw-pending for proven no-throw callees | `./script/aot-smoke.sh`; differential | Wrong proof = missing frames on uncaught traces (#36386/#36403) |
| `lib/JIT/Builtin/` | Runtime value model | aot-smoke + differential `--aot --repeat 3` | `__value__` align-1 UB (#36214); packed stride (#36214) || `lib/Config.php` | `PHP_COMPILER_*` env | `php script/generate-configuration-docs.php --check` | 204 unregistered getenv knobs (#36201) |
| `lib/ExtensionRegistry.php` + `ext/*/ext.json` | Extension load graph | `php script/sync-extension-manifests.php --check` | lib→ext imports / dual registries (#36204/#23480) |

## `ext/standard`

| path | owns | gate | failure mode |
|---|---|---|---|
| `ext/standard/*.php` + `*JitHelper.php` | Stdlib builtins | differential sweep; capability matrix `--check` | Literal-only `range()` (#36243); stale `compileTimeString` strlen (#36244/#36406) |
| `ext/standard/Module.php` | Registration | `php script/capability-matrix.php --check` | Silent-null methods before registry route (#36202) |

Do **not** add modules to `Runtime::loadCoreModules()` — every binary already links ~75 extensions.

## `script/ci-*` and verify presenters

| path | owns | gate | failure mode |
|---|---|---|---|
| `script/ci-defaults.env` | Memory / Docker caps | `script/check-size-budgets.sh` (export count) | Ceremony flag sprawl (#36211); OOM without 1536M (#497) |
| `script/ci-common.sh` / `ci-fast.sh` / `ci-local.sh` | PR / merge ladders | run the script itself | Gates that pass by absence (#36210/#36248); `\|\| true` swallow (#36209) |
| `script/check-generated-docs.sh` | Doc/inventory drift | itself (< 30 s) | Master red for every clone (#15619/#15621) |
| `script/check-size-budgets.sh` | Line-count ratchet | itself | Monotone growth of Compiler/JIT (#36403) |
| `script/aot-smoke.sh` | Toolchain liveness | itself (8–9/9) | Mass differential “regressions” from dead toolchain (#24194) |
| `script/differential-sweep.sh` | Zend-vs-us output | itself | Silent wrong output missed by compliance (#23354) |
| `script/north-star5-verify.sh` | M5 self-host presenter | `make north-star5-verify-fast` | Sidecar COPY reported as native (#21860/#36146) |
| `script/apply-patches.sh` + `script/lib/apply-php-*-overlays*.inc.sh` + `patch-already-applied.inc.sh` | Vendor patch apply | `--verify-pristine` in docs gate | structgep / simplifier guard drift (#36143/#36377); size ratchet extracts (#36403) |
| `script/bootstrap-inventory.php` | `bin/vm.php` require graph | `--check` | Inventory/spine desync blocks north-star5 |

## `prelinked/`

| path | owns | gate | failure mode |
|---|---|---|---|
| `prelinked/helper-runtime/<arch>/` | Committed helper units + `common.o` | `php script/check-helper-runtime-prelink.php` | 790 MB duplicate runtime in every `unit.o` (#36198/#36246); restamp ≠ rebuild |
| `prelinked/bootstrap-gen0/` | Seed native driver | `php script/bootstrap-gen0-staleness.php`; north-star5-fast | 272 restamps while driver cannot compile hello (#23468/#36145) |
| `prelinked/bootstrap-vendor/` | Vendor prelink sidecars | north-star5-fast / spine sync | Cold-boot without `vendor/` lies |

**Never restamp** a fingerprint without a build that produced the bytes (`artifact-honesty.mdc`).

## `patches/`

| path | owns | gate | failure mode |
|---|---|---|---|
| `patches/*.patch` | Vendor tree shape (php-cfg / php-llvm / php-types) | `script/apply-patches.sh --verify-pristine` | Fictional context → clean checkout unbuildable (#36377); structgep assert skip (#36143) |

Patch source of truth is moving toward forks (#36229); until then every patch needs an idempotent guard in `apply-patches.sh`.

---

## Size ratchet

After a Concern extract that shrinks a budgeted file, lower `budget` in `script/size-budgets.json` to the new line count (never raise it). Targets: Compiler/JIT ≤ 25k (then 20k), VM ≤ 15k, `apply-patches.sh` ≤ 4k then ≤ 2k (overlays under `script/lib/`, including mid php-cfg), `script/` ≤ 150 top-level files (issue-specific helpers go under `script/composer/`, `script/fuzz/`, `script/lib/`, …), `ci-defaults.env` ≤ 60 exports.

## Related ADRs / docs

- [`docs/adr/README.md`](adr/README.md) — settled + DECISION index (#36402)
- `docs/architecture-review-2026-07.md`, `docs/self-host-target.md`, `docs/bootstrap-m5-fast-path.md`
- `AGENTS.md` (five things that bite), `.cursor/rules/artifact-honesty.mdc`
