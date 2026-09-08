<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Edit-scaffold session arm / state accessors for AOT CompileCache (#36387).
 *
 * Extracted from {@see CompileCache} so skip/partial/pending/bitcode-bound flags and
 * partial-emit base-object handoff stay a separate TU (split-TU / size-budget ratchet)
 * while {@see CompileCacheEditScaffold} keeps restore/strip/rebind. Distinct from
 * {@see CompileCacheRecording} and project-member maps.
 *
 * Move-only — no new C ABI. php-src analogy: Zend opcache request-time flags that
 * decide whether a cached script image can be reused vs recompiled
 * (Zend/zend_accelerator_module.c / Zend/zend_file_cache.c shape).
 */
trait CompileCacheEditSession
{
    public static function shouldSkipModuleFuncCompile(): bool
    {
        return self::$skipModuleFuncCompile;
    }

    public static function isEditScaffoldActive(): bool
    {
        return self::$editScaffoldActive;
    }

    /** True when edit-scaffold kept unchanged member bodies (#36387). */
    public static function isEditScaffoldPartial(): bool
    {
        return self::$editScaffoldPartial;
    }

    /** True when edit-scaffold left this user LLVM body in the module (#36387). */
    public static function isKeptUserSymbol(string $llvmName): bool
    {
        return '' !== $llvmName && isset(self::$keptUserSymbols[$llvmName]);
    }

    /**
     * Prior `aot.o` for partial delta link, or null when full emit is required (#36387).
     */
    public static function peekPartialEmitBaseObject(): ?string
    {
        $path = self::$partialEmitBaseObject;
        if (!is_string($path) || '' === $path || !is_file($path) || filesize($path) < 1) {
            return null;
        }

        return $path;
    }

    /**
     * Consume the base object path once (Linker inserts it after the delta `.o`) (#36387).
     */
    public static function consumePartialEmitBaseObject(): ?string
    {
        $path = self::peekPartialEmitBaseObject();
        self::$partialEmitBaseObject = null;

        return $path;
    }

    /**
     * Before TargetMachine emit on partial keep: drop bodies that already exist in the
     * prior `aot.o`, leaving declarations (#36387).
     *
     * @see CompileCachePartialEmitDemote::demoteBodiesForPartialObjectEmit()
     *
     * @return int number of functions demoted to declarations
     */
    public static function demoteBodiesForPartialObjectEmit(Context $context): int
    {
        return CompileCachePartialEmitDemote::demoteBodiesForPartialObjectEmit(
            $context,
            self::peekPartialEmitBaseObject(),
            self::$editScaffoldPartial,
            self::$strippedUserSymbols,
            self::$keptUserSymbols
        );
    }

    public static function isEditScaffoldBitcodeBound(): bool
    {
        return self::$editScaffoldBitcodeBound;
    }

    /**
     * Context parsed prior module.bc before CreateNamed — register() may early-return (#36387).
     */
    public static function markEditScaffoldBitcodeBound(): void
    {
        self::$editScaffoldBitcodeBound = true;
        self::$editScaffoldActive = true;
        self::$skipModuleFuncCompile = true;
    }

    /**
     * True when prior cache entry has module.bc + user_symbols (safe to thin-boot) (#36387).
     */
    public static function canUseEditScaffold(string $previousKey): bool
    {
        if ('' === $previousKey || !is_file(self::bitcodePath($previousKey))) {
            return false;
        }
        $raw = json_decode((string) file_get_contents(self::metaPath($previousKey)), true);
        if (!is_array($raw)) {
            return false;
        }
        $user = $raw['user_symbols'] ?? null;
        if (!is_array($user) || [] === $user) {
            return false;
        }

        return null !== self::readLinkManifest($previousKey);
    }

    /**
     * Arm edit-scaffold before Context construct (#36387).
     *
     * Thin boot loads prior module.bc first, then {@see Context::seedCoreTypesFromModuleForEditScaffold()}
     * + type register early-returns bind PHP-side maps without CreateNamed collisions.
     */
    public static function armEditScaffold(string $previousKey): void
    {
        if ('' === $previousKey) {
            return;
        }
        self::$pendingEditScaffoldKey = $previousKey;
    }

    public static function pendingEditScaffoldKey(): ?string
    {
        return self::$pendingEditScaffoldKey;
    }

    public static function takePendingEditScaffoldKey(): ?string
    {
        $key = self::$pendingEditScaffoldKey;
        self::$pendingEditScaffoldKey = null;

        return $key;
    }

    /**
     * True while Context should register decls/types only (no implement IR) (#36387).
     *
     * Pending alone is not enough: {@see Context::tryBindEditScaffoldBitcodeBeforeBuiltins()}
     * may fail to load module.bc while {@see armEditScaffold()} left a pending key. Skipping
     * {@see SuperglobalInit::initialize()} in that case leaves {@see SuperglobalInit::$globals}
     * empty and Slim/Composer rebuilds throw "Superglobal not initialized for JIT: _SERVER"
     * (#36382). Only skip after thin-boot bound the prior module (or restore completed).
     */
    public static function shouldSkipBuiltinImplement(): bool
    {
        return self::$editScaffoldActive || self::$editScaffoldBitcodeBound;
    }
}
