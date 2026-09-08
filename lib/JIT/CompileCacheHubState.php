<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Shared private static fields for AOT CompileCache edit-scaffold / recording / partial-emit (#36387).
 *
 * Extracted from the hub so recording maps, edit-scaffold strip state, and partial-emit
 * base-object handoff stay a separate TU (split-TU / size-budget ratchet) while
 * {@see CompileCacheRecording}, {@see CompileCacheEditSession}, and {@see CompileCacheEditScaffold} / {@see CompileCacheEditScaffoldRestore}
 * keep their accessors. Distinct from KeyLayout / ProjectIndex public facades and from
 * ArtifactFacade / SemanticHashFacade.
 *
 * Move-only — no new C ABI. php-src analogy: Zend opcache request globals that track
 * whether a cached script image is being reused vs recompiled
 * (Zend/zend_accelerator_module.c / Zend/zend_file_cache.c shape).
 */
trait CompileCacheHubState
{
    /** @var list<array{llvm: string, signature: string, scoped: string}>|null */
    private static ?array $recordingExports = null;

    /** @var list<string>|null LLVM names lowered outside NestedJIT (user TU) (#36387). */
    private static ?array $recordingUserSymbols = null;

    /** @var array<string, string>|null logical lc → LLVM name for NestedJIT helpers (#36387). */
    private static ?array $recordingHelperSymbols = null;

    private static ?string $recordingKey = null;

    private static bool $skipModuleFuncCompile = false;

    /** True after {@see tryRestoreEditScaffold()} — helpers kept, user symbols stripped. */
    private static bool $editScaffoldActive = false;

    /** True after Context parsed prior module.bc before namedStructType (#36387). */
    private static bool $editScaffoldBitcodeBound = false;

    /**
     * Prior cache key armed before {@see Context} construct so defineBuiltins can skip
     * implement() (Values would dangle after module replace) (#36387).
     */
    private static ?string $pendingEditScaffoldKey = null;

    /** @var array<string, list<string>>|null member path → LLVM names (#36387) */
    private static ?array $recordingUserSymbolsByMember = null;

    /**
     * member path → scoped name (Class::method or function) → LLVM names (#36387).
     *
     * @var array<string, array<string, list<string>>>|null
     */
    private static ?array $recordingUserSymbolsByFunction = null;

    /**
     * Loaded from prior AOT meta for keep-path planning (#36387).
     *
     * @var array<string, array<string, list<string>>>
     */
    private static array $editScaffoldByFunction = [];

    private static ?string $bundledSource = null;

    /**
     * Absolute paths whose *semantic* content changed vs the scaffold project index
     * (comments/whitespace-only edits do not strip that member's LLVM bodies) (#36387).
     *
     * @var list<string>
     */
    private static array $editChangedMembers = [];

    /**
     * Within a semantically-changed member: scoped names (lc) whose bodies changed.
     * Absent path ⇒ full member strip; non-empty ⇒ keep sibling functions (#36387).
     *
     * @var array<string, array<string, true>>
     */
    private static array $editChangedFunctions = [];

    /**
     * LLVM names of user symbols left in the module after edit-scaffold partial strip (#36387).
     *
     * @var array<string, true>
     */
    private static array $keptUserSymbols = [];

    /**
     * User LLVM names renamed to `*.stale` during edit-scaffold strip (#36387).
     *
     * @var list<string>
     */
    private static array $strippedUserSymbols = [];

    /** True when edit-scaffold kept at least one unchanged user body (#36387). */
    private static bool $editScaffoldPartial = false;

    /**
     * Prior cold `aot.o` linked after a demoted delta emit on partial keep (#36387).
     *
     * Delta object is listed first; `-z muldefs` keeps rebuilt symbols from the delta
     * and everything else (runtime + kept user bodies) from this base object.
     */
    private static ?string $partialEmitBaseObject = null;
}
