<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

use PHPCompiler\JIT\Context;
use PHPCompiler\Config;

require_once __DIR__.'/HelperRuntimeBindInlineOnly.php';

/**
 * Helper-runtime bitcode bind / type localize / unit lifecycle (#15889 / #16075 / #36155).
 *
 * Extracted from {@see HelperRuntimeCache} so the cache hub stays under the size-budget
 * ratchet (#36403) and gen-0 / split-TU bind hooks stay a separate TU (#36387).
 * NestedJIT-force policy lives in {@see HelperRuntimeBindInlineOnly}.
 * Callers keep using HelperRuntimeCache::tryProvide / declareExternFromBitcode thin delegates.
 *
 * php-src analogy: Zend opcache shared-memory bind of cached scripts into the executor
 * (Zend/zend_file_cache.c + Zend/zend_persist.c shape) — attach precompiled helper
 * symbols with types localized to the consuming module's named structs.
 */
final class HelperRuntimeBind
{
    /** @var array<string, object> unit dir → parsed bitcode module (kept alive: types are shared) */
    private static array $parsedUnits = [];

    /** @var array<string, object> bitcode path → parsed module (chunk manifest bind, #36155) */
    private static array $parsedBitcodeFiles = [];

    private static bool $loggedHit = false;


    /**
     * Bind every cached helper among $logicalNames into $context->functions as
     * an extern declaration with the exact type from the unit's bitcode.
     *
     * @param list<string> $logicalNames
     */
    public static function tryProvide(Context $context, array $logicalNames): bool
    {
        if (!HelperRuntimeCache::enabled()) {
            return false;
        }
        $index = HelperRuntimeCache::helperIndex();
        $lib = $context->llvm->lib;
        $bound = 0;
        foreach ($logicalNames as $logical) {
            $lc = strtolower($logical);
            if (HelperRuntimeBindInlineOnly::shouldInlineOnlyForUserScript($lc)) {
                continue;
            }
            if (isset($context->functions[$lc]) || !isset($index[$lc])) {
                continue;
            }
            $symbol = $index[$lc]['symbol'];
            $unitDir = $index[$lc]['dir'];

            $existing = $context->module->getNamedFunction($symbol);
            if (null !== $existing) {
                $context->functions[$lc] = $existing;
                self::wireUnitLifecycle($context, $index[$lc]);
                HelperRuntimeLink::markUnitUsed($unitDir);
                ++$bound;

                continue;
            }

            $parsed = self::parsedUnit($context, $unitDir);
            if (null === $parsed) {
                continue;
            }
            $source = $parsed->getNamedFunction($symbol);
            if (null === $source) {
                continue;
            }
            $fnType = $lib->LLVMGetElementType($lib->LLVMTypeOf($source->value));
            if (null === $fnType) {
                continue;
            }
            // Parsing unit bitcode into a context that already defines the
            // named structs re-suffixes them (__string__ -> __string__.12);
            // declarations bound with suffixed types fail module verify at the
            // call sites. Rebuild the type against the LOCAL named structs.
            $type = self::localizedFunctionType($context, $source, $fnType)
                ?? $context->llvm->factory->type($context->context, $fnType);
            $context->functions[$lc] = $context->module->addFunction($symbol, $type);
            self::wireUnitLifecycle($context, $index[$lc]);
            HelperRuntimeLink::markUnitUsed($unitDir);
            ++$bound;
        }

        if ($bound > 0 && !self::$loggedHit) {
            $user = Config::getenv('PHP_COMPILER_AOT_USER_SCRIPT');
            if ('1' === $user || 'true' === strtolower((string) $user)) {
                if (\defined('STDERR') && \is_resource(STDERR)) {
                    fwrite(STDERR, sprintf(
                        "phpc build: helper-runtime cache hit (%d helpers, core=%s) (#15889)\n",
                        $bound,
                        HelperRuntimeCache::coreFingerprint()
                    ));
                }
                self::$loggedHit = true;
            }
        }

        return $bound > 0;
    }

    /**
     * Declare an extern from a producer chunk's bitcode (#36155 Phase C).
     *
     * Mirrors {@see tryProvide} but reads an explicit bitcode file instead of the
     * helper-runtime index — used when a consumer chunk binds cross-TU via manifest.
     */
    public static function declareExternFromBitcode(Context $context, string $symbol, string $bitcodePath): ?object
    {
        if ('' === $symbol || '' === $bitcodePath || !is_file($bitcodePath)) {
            return null;
        }
        $existing = $context->module->getNamedFunction($symbol);
        if (null !== $existing) {
            return $existing;
        }
        $parsed = self::parsedBitcodeFile($context, $bitcodePath);
        if (null === $parsed) {
            return null;
        }
        $source = $parsed->getNamedFunction($symbol);
        if (null === $source) {
            return null;
        }
        $lib = $context->llvm->lib;
        $fnType = $lib->LLVMGetElementType($lib->LLVMTypeOf($source->value));
        if (null === $fnType) {
            return null;
        }
        $type = self::localizedFunctionType($context, $source, $fnType)
            ?? $context->llvm->factory->type($context->context, $fnType);

        return $context->module->addFunction($symbol, $type);
    }

    /**
     * Function type rebuilt from the local context's named structs, or null
     * when any component type is unknown locally (caller falls back to the
     * parsed type verbatim).
     */
    private static function localizedFunctionType(Context $context, object $source, object $fnType): ?object
    {
        $lib = $context->llvm->lib;
        try {
            $params = [];
            for ($i = 0, $n = $source->countParams(); $i < $n; ++$i) {
                $params[] = self::localizedType($context, $lib->LLVMTypeOf($source->getParam($i)->value));
            }
            $ret = self::localizedType($context, $lib->LLVMGetReturnType($fnType));
            if (null === $ret || \in_array(null, $params, true)) {
                return null;
            }

            return $context->context->functionType($ret, false, ...$params);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Localize one raw FFI type: named structs (possibly context-suffixed,
     * __string__.12) map to the local struct of the base name at the same
     * pointer depth; everything else wraps verbatim.
     */
    private static function localizedType(Context $context, object $rawTy): ?object
    {
        $lib = $context->llvm->lib;
        $depth = 0;
        $t = $rawTy;
        while (\llvm\llvm::LLVMPointerTypeKind === $lib->LLVMGetTypeKind($t)) {
            $t = $lib->LLVMGetElementType($t);
            ++$depth;
        }
        if (\llvm\llvm::LLVMStructTypeKind === $lib->LLVMGetTypeKind($t)) {
            $name = $lib->LLVMGetStructName($t);
            $name = \is_object($name) ? $name->toString() : (string) $name;
            if ('' === $name) {
                return null; // anonymous struct — no local identity to map to
            }
            $base = (string) preg_replace('/\\.\\d+$/', '', $name);

            try {
                return $context->getTypeFromString($base.str_repeat('*', $depth));
            } catch (\Throwable) {
                return null;
            }
        }

        return $context->llvm->factory->type($context->context, $rawTy);
    }

    /** @var array<string, true> unit dir → lifecycle calls already wired */
    private static array $wiredLifecycles = [];

    /**
     * First use of a unit: the consuming script's __init__/__shutdown__ call
     * the unit's uniquely-named init/shutdown (the colliding __init__ symbols
     * were muldefs-discarded and unit module state never ran, #16075 step 4).
     * Units emitted before init symbols existed have no manifest entry and
     * keep the old (uninitialized) behavior.
     *
     * @param array{symbol: string, dir: string, init: ?string, shutdown: ?string, init_via_global_ctor?: bool} $entry
     */
    private static function wireUnitLifecycle(Context $context, array $entry): void
    {
        $unitDir = $entry['dir'];
        if (isset(self::$wiredLifecycles[$unitDir])) {
            return;
        }
        self::$wiredLifecycles[$unitDir] = true;
        if (!empty($entry['init_via_global_ctor'])) {
            // Unit init runs via llvm.global_ctors at load time (#16075 step 4).
            return;
        }
        $voidFn = static function (string $name) use ($context): object {
            $fn = $context->module->getNamedFunction($name);
            if (null !== $fn) {
                return $fn;
            }

            return $context->module->addFunction(
                $name,
                $context->context->functionType($context->context->voidType(), false)
            );
        };
        // Legacy units without global ctors: user-script AOT must skip emitInInit
        // wiring — calling unit inits from script __init__ aliases muldefs-merged
        // globals (#17069).
        $userAot = Config::getenv('PHP_COMPILER_AOT_USER_SCRIPT');
        $skipInit = '1' === $userAot || 'true' === strtolower((string) $userAot);
        if (!$skipInit && null !== $entry['init'] && '' !== $entry['init']) {
            $initFn = $voidFn($entry['init']);
            $context->emitInInit(static function (Context $ctx) use ($initFn): void {
                $ctx->builder->call($initFn);
            });
        }
        // Deliberately NOT wiring the unit's __shutdown__: after -z muldefs
        // symbol unification the unit's globals partially alias the script's,
        // and running both shutdowns double-frees (SIGABRT at exit). Leaking
        // at process end matches the previous behavior and is safe.
    }

    private static function parsedUnit(Context $context, string $unitDir): ?object
    {
        if (isset(self::$parsedUnits[$unitDir])) {
            return self::$parsedUnits[$unitDir];
        }
        $parsed = self::parsedBitcodeFile($context, $unitDir.'/unit.bc');
        if (null !== $parsed) {
            self::$parsedUnits[$unitDir] = $parsed;
        }

        return $parsed;
    }

    private static function parsedBitcodeFile(Context $context, string $path): ?object
    {
        if (isset(self::$parsedBitcodeFiles[$path])) {
            return self::$parsedBitcodeFiles[$path];
        }
        $data = is_file($path) ? (string) file_get_contents($path) : '';
        if ('' === $data) {
            return null;
        }
        // createMemoryBufferWithString instead of ...WithFile: the vendored
        // ...WithFile references an unimported FFI class (latent php-llvm bug).
        $buffer = $context->llvm->createMemoryBufferWithString($data, basename($path));

        try {
            // Kept referenced for the process lifetime — declaration types
            // point into the shared LLVMContext.
            return self::$parsedBitcodeFiles[$path] = $buffer->parseBitcode($context->context);
        } catch (\Throwable) {
            return null;
        }
    }


}
