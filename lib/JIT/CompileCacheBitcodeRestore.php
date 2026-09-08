<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;

/**
 * MCJIT bitcode warm restore for CompileCache (#36387 / #36199).
 *
 * Extracted from {@see CompileCacheBitcodePersist} so module.bc tryRestore /
 * export rebind stays a separate TU from meta/stamp persist (split-TU /
 * compile-cache iterability). Used by the CompileCache hub composition.
 *
 * No new C ABI. php-src analogy: Zend opcache file-cache restore of a compiled
 * script image without re-parsing (Zend/zend_file_cache.c) — attach prior
 * bitcode + export meta and skip LLVM IR lowering.
 */
trait CompileCacheBitcodeRestore
{
    /**
     * @return bool true when bitcode was loaded and exports restored
     */
    public static function tryRestore(Context $context, Block $block, string $key): bool
    {
        $meta = self::readMeta($key);
        if (null === $meta) {
            return false;
        }
        $bcPath = self::bitcodePath($key);
        if (!is_file($bcPath)) {
            return false;
        }

        try {
            $context->replaceModuleFromBitcodeFile($bcPath);
        } catch (\Throwable $e) {
            return false;
        }

        SuperglobalInit::rebindGlobalsFromModule($context);
        self::restoreExports($context, $block, $meta['exports']);
        $context->rebindFunctionScopeFromModule();
        $context->rebindInitShutdownAfterModuleReplace();
        $context->refreshIntrinsicAfterModuleReplace();
        $context->syncIntrinsicBuilder();
        self::$skipModuleFuncCompile = true;
        self::$editScaffoldActive = false;

        return true;
    }

    /**
     * User-script main LLVM function after {@see tryRestore()} (#36199).
     */
    public static function resolveRestoredMainFunction(Context $context, string $key): ?\PHPLLVM\Value\Function_
    {
        $meta = self::readMeta($key);
        if (null === $meta) {
            return null;
        }
        foreach ($meta['exports'] as $entry) {
            if (($entry['scoped'] ?? '') !== '{main}') {
                continue;
            }
            $llvm = (string) ($entry['llvm'] ?? '');
            if ('' === $llvm) {
                continue;
            }
            $func = $context->module->getNamedFunction($llvm);
            if ($func instanceof \PHPLLVM\Value\Function_) {
                return $func;
            }
        }

        return null;
    }

    /**
     * @param list<array{llvm?: string, signature?: string, scoped?: string}> $exports
     */
    private static function restoreExports(Context $context, Block $block, array $exports): void
    {
        $blocksByScoped = self::collectBlocksByScopedName($block);
        foreach ($exports as $entry) {
            $llvm = $entry['llvm'] ?? '';
            $signature = $entry['signature'] ?? '';
            $scoped = $entry['scoped'] ?? '';
            if ('' === $llvm || '' === $signature || '' === $scoped) {
                continue;
            }
            if (!isset($blocksByScoped[$scoped])) {
                continue;
            }
            $context->addExport($llvm, $signature, $blocksByScoped[$scoped]);
        }
    }

    /**
     * @return array<string, Block>
     */
    private static function collectBlocksByScopedName(Block $root): array
    {
        $map = [];
        $queue = [$root];
        while ([] !== $queue) {
            $current = array_shift($queue);
            if (null !== $current->func) {
                $map[$current->func->getScopedName()] = $current;
            } else {
                $map['{main}'] = $current;
            }
            foreach ($current->blocks as $child) {
                $queue[] = $child;
            }
        }

        return $map;
    }
}
