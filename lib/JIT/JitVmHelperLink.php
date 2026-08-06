<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Type;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Compile lib/VM/*JitHelper.php into the active JIT module (#10311).
 */
final class JitVmHelperLink
{
    /**
     * @param list<string> $compiledHelpers fully-qualified logical callee names
     */
    public static function ensureCompiled(
        Context $context,
        string $relativeHelperPath,
        array $compiledHelpers,
        string $compileLabel,
        bool $skipHelperRuntimeCache = false
    ): void {
        self::ensureCompiledBundle(
            $context,
            [$relativeHelperPath],
            $compiledHelpers,
            $compileLabel,
            $skipHelperRuntimeCache
        );
    }

    /**
     * NestedJIT an in-memory helper source (per-TU generated maps — skip helper-runtime cache).
     *
     * Used by {@see Builtin\GetClassRuntime}: the class-id→name table is compile-unit-specific, so
     * the on-disk {@see \PHPCompiler\ext\standard\GetClassJitHelper} seed stub must not win via
     * {@see \PHPCompiler\AOT\HelperRuntimeCache::tryProvide} (#24976).
     *
     * @param list<string> $compiledHelpers fully-qualified logical callee names
     */
    public static function ensureCompiledFromSource(
        Context $context,
        string $source,
        string $basename,
        array $compiledHelpers,
        string $compileLabel
    ): void {
        if ('' === $basename) {
            throw new \InvalidArgumentException('ensureCompiledFromSource requires a basename ('.$compileLabel.')');
        }
        if (!self::compiledHelpersMissing($context, $compiledHelpers)) {
            return;
        }

        // Intentionally skip HelperRuntimeCache — source is per-TU dynamic (#24976).
        NestedVmActiveContextLlvm::ensureMethod($context);
        self::runNestedHelperCompile(
            $context,
            static function () use ($context, $source, $basename, $compileLabel): void {
                $block = $context->runtime->parseAndCompile($source, $basename);
                if (null === $block) {
                    throw new \LogicException($basename.' parseAndCompile failed ('.$compileLabel.')');
                }
                (new \PHPCompiler\JIT($context))->compile($block);
            }
        );
        self::assertCompiledHelpersPresent($context, $compiledHelpers, $compileLabel);
    }

    /**
     * NestedJIT several helper sources in one {@see NestedJitCompileScope} (#22981).
     *
     * Pack's Ieee754 → PackEngineEncode → PackJitEngine → PackJitHelper chain must share a
     * single scope: emitting PackJitHelper alone re-lowers the transitive closure under
     * ENV_EMITTING (no sibling cache) and does not terminate in practical time (#22843).
     * Peer: pre-#22843 StringPack multi-file NestedJitCompileScope.
     *
     * @param list<string> $relativeHelperPaths repo-root paths (/ext/… or /lib/…)
     * @param list<string> $compiledHelpers     fully-qualified logical callee names
     */
    public static function ensureCompiledBundle(
        Context $context,
        array $relativeHelperPaths,
        array $compiledHelpers,
        string $compileLabel,
        bool $skipHelperRuntimeCache = false
    ): void {
        if ([] === $relativeHelperPaths) {
            throw new \InvalidArgumentException('ensureCompiledBundle requires at least one path ('.$compileLabel.')');
        }

        if (!self::compiledHelpersMissing($context, $compiledHelpers)) {
            return;
        }

        // Split compilation (#15889): the cached helper TU provides these
        // symbols as available_externally imports + helpers.o at link time,
        // skipping the nested PHP lowering below entirely.
        // Thin AOT preg must not consume the cached full PregJitHelper (VmPregNative)
        // — that path returns wrong 0 under thin standalone (#26888 / #24115).
        if (!$skipHelperRuntimeCache
            && \PHPCompiler\AOT\HelperRuntimeCache::tryProvide($context, $compiledHelpers)) {
            if (!self::compiledHelpersMissing($context, $compiledHelpers)) {
                return;
            }
        }

        $paths = [];
        foreach ($relativeHelperPaths as $relativeHelperPath) {
            $path = self::resolveHelperPath($relativeHelperPath);
            $paths[] = [$path, \basename($path)];
        }
        NestedVmActiveContextLlvm::ensureMethod($context);
        self::runNestedHelperCompile(
            $context,
            static function () use ($context, $paths, $compileLabel): void {
                $runtime = $context->runtime;
                $jit = new \PHPCompiler\JIT($context);
                foreach ($paths as [$path, $basename]) {
                    $real = \realpath($path) ?: $path;
                    // Module-global helper TU dedupe — per-activeFunction include keys (#878)
                    // re-NestedJIT the same helper under a new scope and split statics (#27566).
                    if ($context->hasJitHelperTuCompiled($real)
                        || $context->hasJitIncludedFileCompiled($real)) {
                        $context->markJitHelperTuCompiled($real);
                        continue;
                    }
                    $block = $runtime->parseAndCompile((string) \file_get_contents($path), $basename);
                    if (null === $block) {
                        throw new \LogicException($basename.' parseAndCompile failed ('.$compileLabel.')');
                    }
                    $jit->compile($block);
                    $context->markJitIncludedFileCompiled($real);
                    $context->markJitHelperTuCompiled($real);
                }
            }
        );
        self::assertCompiledHelpersPresent($context, $compiledHelpers, $compileLabel);
    }

    /**
     * @param list<string> $compiledHelpers
     */
    private static function compiledHelpersMissing(Context $context, array $compiledHelpers): bool
    {
        foreach ($compiledHelpers as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $compiledHelpers
     */
    private static function assertCompiledHelpersPresent(
        Context $context,
        array $compiledHelpers,
        string $compileLabel
    ): void {
        foreach ($compiledHelpers as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT ('.$compileLabel.')');
            }
        }
    }

    /**
     * User-script env clear + NestedJIT + insert restore (#15407 / #21972 / #24976).
     *
     * @param callable(): void $compile
     */
    private static function runNestedHelperCompile(Context $context, callable $compile): void
    {
        // Restore caller insert after NestedJIT (#21972 / peer #21965 GethostbynamelRuntime).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        // User-script standalone: clear env so nested *JitHelper compile is full NestedJIT (#15407, #20246).
        $clearUserScriptEnv = $context->shouldClearUserScriptEnvForNestedHelperCompile();
        NestedJitCompileScope::run($context, static function () use ($compile, $clearUserScriptEnv): void {
            $prevUser = getenv('PHP_COMPILER_AOT_USER_SCRIPT');
            $prevSelf = getenv('PHP_COMPILER_SELFHOST_AOT');
            if ($clearUserScriptEnv && \function_exists('putenv')) {
                putenv('PHP_COMPILER_AOT_USER_SCRIPT=');
                unset($_ENV['PHP_COMPILER_AOT_USER_SCRIPT'], $_SERVER['PHP_COMPILER_AOT_USER_SCRIPT']);
                putenv('PHP_COMPILER_SELFHOST_AOT=0');
                $_ENV['PHP_COMPILER_SELFHOST_AOT'] = '0';
                $_SERVER['PHP_COMPILER_SELFHOST_AOT'] = '0';
            }
            try {
                $compile();
            } finally {
                if ($clearUserScriptEnv && \function_exists('putenv')) {
                    if (false === $prevUser || '' === (string) $prevUser) {
                        putenv('PHP_COMPILER_AOT_USER_SCRIPT=');
                        unset($_ENV['PHP_COMPILER_AOT_USER_SCRIPT'], $_SERVER['PHP_COMPILER_AOT_USER_SCRIPT']);
                    } else {
                        putenv('PHP_COMPILER_AOT_USER_SCRIPT='.$prevUser);
                        $_ENV['PHP_COMPILER_AOT_USER_SCRIPT'] = $prevUser;
                        $_SERVER['PHP_COMPILER_AOT_USER_SCRIPT'] = $prevUser;
                    }
                    if (false === $prevSelf || '' === (string) $prevSelf) {
                        putenv('PHP_COMPILER_SELFHOST_AOT=');
                        unset($_ENV['PHP_COMPILER_SELFHOST_AOT'], $_SERVER['PHP_COMPILER_SELFHOST_AOT']);
                    } else {
                        putenv('PHP_COMPILER_SELFHOST_AOT='.$prevSelf);
                        $_ENV['PHP_COMPILER_SELFHOST_AOT'] = $prevSelf;
                        $_SERVER['PHP_COMPILER_SELFHOST_AOT'] = $prevSelf;
                    }
                }
            }
        });
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    public static function lookupCompiled(Context $context, string $logical, string $issueTag): LlvmFunction
    {
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after VM helper compile ('.$issueTag.')');
        }

        return $fn;
    }

    /**
     * @param list<Type> $paramTypes
     */
    public static function ensureBridge(
        Context $context,
        string $abiName,
        string $entryBlockName,
        array $paramTypes,
        Type $returnType,
        string $helperLogical,
        string $relativeHelperPath,
        array $compiledHelpers,
        string $issueTag,
        bool $skipHelperRuntimeCache = false
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (self::hasNamedBridgeEntry($probe, $entryBlockName)) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureCompiled(
            $context,
            $relativeHelperPath,
            $compiledHelpers,
            $issueTag,
            $skipHelperRuntimeCache
        );

        $helperFn = self::lookupCompiled($context, $helperLogical, $issueTag);
        $ft = $context->context->functionType($returnType, false, ...$paramTypes);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = self::bridgeEntryForEmit($fn, $entryBlockName);
        $context->builder->positionAtEnd($entry);
        $args = [];
        for ($i = 0, $n = $fn->countParams(); $i < $n; ++$i) {
            $abiParam = $fn->getParam($i);
            $helperTy = $helperFn->getParam($i)->typeOf();
            $args[] = JitNestedHelperCoerce::coerceArgForHelper($context, $abiParam, $helperTy);
        }
        $result = $context->builder->call($helperFn, ...$args);
        if ('void' === $context->getStringFromType($returnType)) {
            $context->builder->returnVoid();
        } else {
            $ret = JitNestedHelperCoerce::coerceBridgeResult($context, $result, $returnType);
            $context->builder->returnValue($ret);
        }
        $context->registerFunction($abiName, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $fallback = null;
            if ('' !== $context->activeFunction && isset($context->functions[$context->activeFunction])) {
                $active = $context->functions[$context->activeFunction];
                if ($active instanceof LlvmFunction) {
                    $fallback = $active;
                }
            }
            if (null === $fallback && $context->main instanceof LlvmFunction) {
                $fallback = $context->main;
            }
            if (null !== $fallback && $fallback->countBasicBlocks() > 0) {
                $context->builder->positionAtEnd($fallback->getEntryBasicBlock());
            } else {
                $context->builder->clearInsertionPosition();
            }
        }
    }

    /**
     * Resolve helper source path: ext/* and lib/* live at repo root; /VM/* under lib/.
     */
    private static function resolveHelperPath(string $relativeHelperPath): string
    {
        if (\str_starts_with($relativeHelperPath, '/ext/')
            || \str_starts_with($relativeHelperPath, '/lib/')) {
            return \dirname(__DIR__, 2).$relativeHelperPath;
        }

        return \dirname(__DIR__).$relativeHelperPath;
    }

    public static function hasNamedBridgeEntry(?LlvmFunction $probe, string $entryBlockName): bool
    {
        if (null === $probe || '' === $entryBlockName) {
            return false;
        }
        try {
            foreach ($probe->getBasicBlocks() as $block) {
                if ($block->getName() === $entryBlockName && null !== $block->getTerminator()) {
                    return true;
                }
            }
        } catch (\Throwable) {
        }

        return false;
    }

    private static function bridgeEntryComplete(?LlvmFunction $probe): bool
    {
        if (null === $probe || 0 === $probe->countBasicBlocks()) {
            return false;
        }
        try {
            $blocks = $probe->getBasicBlocks();
            $entry = $blocks[0] ?? null;

            return null !== $entry && null !== $entry->getTerminator();
        } catch (\Throwable) {
            return false;
        }
    }

    public static function bridgeEntryForEmit(LlvmFunction $fn, string $entryBlockName): \PHPLLVM\BasicBlock
    {
        try {
            foreach ($fn->getBasicBlocks() as $block) {
                if ($block->getName() === $entryBlockName) {
                    return $block;
                }
            }
            $blocks = $fn->getBasicBlocks();
            $entry = $blocks[0] ?? null;
            if (null !== $entry && null === $entry->getTerminator()) {
                return $entry;
            }
        } catch (\Throwable) {
        }

        return $fn->appendBasicBlock($entryBlockName);
    }
}
