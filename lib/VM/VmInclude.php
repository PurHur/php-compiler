<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Include/require semantics SSOT for VM and compiled JIT/AOT (#10063, php-in-PHP).
 *
 * php-src: Zend/zend_execute.c — ZEND_INCLUDE_OR_EVAL, once-guard, return value
 */
final class VmInclude
{
    /** Zend: skipped include_once on already-included file returns true (int 1). */
    public const SKIPPED_SELFHOST_INCLUDE_RETURN = 1;

    /**
     * Paths omitted from self-host spine bundles (argv driver, vendor autoload).
     *
     * @return list<string> normalized path suffixes
     */
    public static function selfHostSpineSkipPathSuffixes(): array
    {
        return [
            'src/cli.php',
            'src/cli_driver.php',
            'vendor/autoload.php',
        ];
    }

    public static function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    public static function pathMatchesSelfHostSpineSkipSuffix(string $path): bool
    {
        $normalized = self::normalizePath($path);
        foreach (self::selfHostSpineSkipPathSuffixes() as $suffix) {
            if ($normalized === $suffix || str_ends_with($normalized, '/'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    public static function shouldSkipSelfHostSpineCliInclude(string $path): bool
    {
        $selfhost = getenv('PHP_COMPILER_SELFHOST_AOT');
        $cliSpine = getenv('PHP_COMPILER_CLI_SPINE_BUNDLE');
        $vmSpine = getenv('PHP_COMPILER_VM_SPINE_SMOKE');
        if (
            ('1' !== $selfhost && 'true' !== strtolower((string) $selfhost))
            && ('1' !== $cliSpine && 'true' !== strtolower((string) $cliSpine))
            && ('1' !== $vmSpine && 'true' !== strtolower((string) $vmSpine))
        ) {
            return false;
        }

        return self::pathMatchesSelfHostSpineSkipSuffix($path);
    }

    public static function callerIsSelfHostSpineSmokeEntry(string $callerPath): bool
    {
        $caller = self::normalizePath($callerPath);

        return str_ends_with($caller, '/test/selfhost/compiler_lib_spine_smoke/main.php');
    }

    public static function callerIsSelfHostSpineSmokeTree(string $callerPath): bool
    {
        $caller = self::normalizePath($callerPath);

        return str_contains($caller, '/test/selfhost/compiler_lib_spine_smoke/');
    }

    public static function callerIsCliEntry(string $callerPath): bool
    {
        $caller = self::normalizePath($callerPath);

        return str_ends_with($caller, '/bin/vm.php')
            || str_ends_with($caller, '/src/cli_driver.php');
    }

    /**
     * Stub dynamic requires while host-compiling M3 emit sidecars or full lib-spine AOT (#2699, #8559).
     */
    public static function shouldStubM3SidecarHostNonLiteralInclude(string $callerPath): bool
    {
        $isSpineSmokeEntry = self::callerIsSelfHostSpineSmokeEntry($callerPath);
        $isSpineSmokeTree = self::callerIsSelfHostSpineSmokeTree($callerPath);

        $sidecarHost = getenv('PHP_COMPILER_M3_SIDECAR_HOST');
        if ('1' === $sidecarHost || 'true' === strtolower((string) $sidecarHost)) {
            return self::callerIsCliEntry($callerPath) || $isSpineSmokeEntry;
        }

        $libSpineBundle = getenv('PHP_COMPILER_LIB_SPINE_BUNDLE');
        if ('1' === $libSpineBundle || 'true' === strtolower((string) $libSpineBundle)) {
            return true;
        }

        $selfhost = getenv('PHP_COMPILER_SELFHOST_AOT');
        if ('1' === $selfhost || 'true' === strtolower((string) $selfhost)) {
            return $isSpineSmokeEntry
                || $isSpineSmokeTree
                || self::callerIsCliEntry($callerPath)
                || str_ends_with(self::normalizePath($callerPath), '/src/cli.php');
        }

        return false;
    }
}
