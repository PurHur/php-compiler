<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

use PHPCfg\Block as CfgBlock;
use PHPCfg\Func as CfgFunc;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;
use PHPCompiler\Cfg\OpSubBlockAccess;
use PHPCompiler\Runtime;

/**
 * Discover literal include/require targets reachable from an entry file (issue #54, #85).
 */
final class LiteralIncludeDiscovery
{
    /**
     * @return list<string> absolute paths to bundle before the entry (dependency order)
     */
    /**
     * Literal include/require targets referenced directly in $entryFile (no transitive walk).
     *
     * @return list<string> absolute paths
     */
    public static function discoverDirectAbsolutePaths(Runtime $runtime, string $entryFile): array
    {
        $entryFile = realpath($entryFile) ?: $entryFile;
        if (!is_file($entryFile)) {
            return [];
        }
        $code = (string) file_get_contents($entryFile);
        $script = $runtime->parseForIncludeDiscovery($code, $entryFile);

        $paths = [];
        foreach (self::pathsFromMainScopeForBundle($script, $entryFile) as $includePath) {
            $resolved = IncludePathResolver::resolve($includePath, $entryFile);
            if (null !== $resolved) {
                $paths[] = $resolved;
            }
        }

        return array_values(array_unique($paths));
    }

    public static function discoverAbsolutePaths(Runtime $runtime, string $entryFile): array
    {
        return self::discoverTransitiveAbsolutePaths($runtime, $entryFile, false);
    }

    /**
     * Literal includes safe for AOT SourceBundler (main-scope require only, issue #739, #878).
     *
     * Skips method-body {@see include} targets (MiniWebApp templates): those are JIT-inlined and
     * must not be prepended at file scope or have their include lines stripped from Router.
     *
     * @return list<string> absolute paths to bundle before the entry (dependency order)
     */
    public static function discoverBundleAbsolutePaths(Runtime $runtime, string $entryFile): array
    {
        return self::discoverTransitiveAbsolutePaths($runtime, $entryFile, true);
    }

    /**
     * @return list<string>
     */
    private static function discoverTransitiveAbsolutePaths(
        Runtime $runtime,
        string $entryFile,
        bool $bundleScopeOnly
    ): array {
        $entryFile = realpath($entryFile) ?: $entryFile;
        if (!is_file($entryFile)) {
            return [];
        }
        $code = (string) file_get_contents($entryFile);
        $script = $runtime->parseForIncludeDiscovery($code, $entryFile);

        $paths = [];
        $seenFiles = [];
        $queue = [$entryFile => $script];

        while ([] !== $queue) {
            $currentFile = array_key_first($queue);
            $currentScript = $queue[$currentFile];
            unset($queue[$currentFile]);

            $literalPaths = $bundleScopeOnly
                ? self::pathsFromMainScopeForBundle($currentScript, $currentFile)
                : self::pathsFromScript($currentScript, $currentFile);
            foreach ($literalPaths as $includePath) {
                $resolved = IncludePathResolver::resolve($includePath, $currentFile);
                if (null === $resolved || isset($seenFiles[$resolved])) {
                    continue;
                }
                $seenFiles[$resolved] = true;
                $paths[] = $resolved;
                try {
                    $includedCode = (string) file_get_contents($resolved);
                    $included = $runtime->parseForIncludeDiscovery($includedCode, $resolved);
                } catch (\Throwable $e) {
                    continue;
                }
                $queue[$resolved] = $included;
            }
        }

        // Entry is compiled last inside SourceBundler; drop it from the include list.
        $filtered = [];
        foreach ($paths as $p) {
            if ($p !== $entryFile) {
                $filtered[] = $p;
            }
        }

        return array_values($filtered);
    }

    /**
     * Literal includes at script main scope only (issue #739 / #776).
     *
     * Method and nested-function includes are JIT-inlined; bundling them prepends template
     * bodies at file scope and strips the include line, breaking caller-local inheritance.
     *
     * @return list<string>
     */
    private static function pathsFromMainScopeForBundle(Script $script, string $fromFile): array
    {
        $paths = [];
        $seen = new \SplObjectStorage();
        if ($script->main->cfg instanceof CfgBlock) {
            self::walkCfgBlockForBundle($script->main->cfg, $fromFile, $paths, $seen);
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return list<string>
     */
    private static function pathsFromScript(Script $script, string $fromFile): array
    {
        $paths = [];
        $seen = new \SplObjectStorage();
        if ($script->main->cfg instanceof CfgBlock) {
            self::walkCfgBlock($script->main->cfg, $fromFile, $paths, $seen);
        }
        foreach ($script->functions as $func) {
            if ($func instanceof CfgFunc && $func->cfg instanceof CfgBlock) {
                self::walkCfgBlock($func->cfg, $fromFile, $paths, $seen);
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param list<string> $paths
     */
    private static function walkCfgBlock(
        CfgBlock $block,
        string $fromFile,
        array &$paths,
        \SplObjectStorage $seen
    ): void {
        self::walkCfgBlockInternal($block, $fromFile, $paths, $seen, false);
    }

    /**
     * @param list<string> $paths
     */
    private static function walkCfgBlockForBundle(
        CfgBlock $block,
        string $fromFile,
        array &$paths,
        \SplObjectStorage $seen
    ): void {
        self::walkCfgBlockInternal($block, $fromFile, $paths, $seen, true);
    }

    private static function isBundleScopeBoundary(Op $op): bool
    {
        return $op instanceof Op\Stmt\Class_
            || $op instanceof Op\Stmt\Interface_
            || $op instanceof Op\Stmt\Function_
            || $op instanceof Op\Stmt\Trait_;
    }

    /**
     * @param list<string> $paths
     */
    private static function walkCfgBlockInternal(
        CfgBlock $block,
        string $fromFile,
        array &$paths,
        \SplObjectStorage $seen,
        bool $mainScopeOnly
    ): void {
        if ($seen->contains($block)) {
            return;
        }
        $seen[$block] = true;

        foreach ($block->children as $child) {
            if ($child instanceof Op\Expr\Include_) {
                $isInclude = Op\Expr\Include_::TYPE_INCLUDE === $child->type
                    || Op\Expr\Include_::TYPE_INCLUDE_ONCE === $child->type;
                // Caller-scope includes are compile-time inlined (IncludeHelper); bundling would
                // reorder them before the entry body (issue #739, #471).
                if ($mainScopeOnly && $isInclude) {
                    continue;
                }
                $literal = ConstStringFolder::foldForInclude($block, $child->expr, $child->getFile() ?: $fromFile);
                if (null !== $literal) {
                    $paths[] = $literal;
                }
            }
            if (!$mainScopeOnly && $child instanceof Op\Stmt\Function_) {
                if ($child->func->cfg instanceof CfgBlock) {
                    self::walkCfgBlockInternal($child->func->cfg, $fromFile, $paths, $seen, false);
                }
            }
            if ($mainScopeOnly && self::isBundleScopeBoundary($child)) {
                continue;
            }
            OpSubBlockAccess::walkSubBlocks($child, static function (CfgBlock $sub) use ($fromFile, &$paths, $seen, $mainScopeOnly): void {
                self::walkCfgBlockInternal($sub, $fromFile, $paths, $seen, $mainScopeOnly);
            });
        }
    }

}
