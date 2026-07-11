<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * Guards reference-profile preprocess scans from materializing huge token arrays (#17150).
 *
 * JIT/AOT nested helper compilation can pass multi-megabyte lib/ sources through
 * preprocessSourceForParser; token_get_all() on those bundles OOMs MCJIT hosts.
 */
final class ReferenceProfileTokenScan
{
    /** Stay below MCJIT host headroom when nested helper JIT already holds LLVM IR (#17150). */
    public const MAX_SOURCE_BYTES = 524_288;

    public static function exceedsTokenScanBudget(string $source): bool
    {
        return \strlen($source) > self::MAX_SOURCE_BYTES;
    }

    public static function shouldSkipReferenceProfileReject(string $code, string $filename): bool
    {
        if (self::exceedsTokenScanBudget($code)) {
            return true;
        }

        if (str_ends_with($filename, 'Helper.php')) {
            return true;
        }

        if (str_starts_with(basename($filename), 'Vm') && str_ends_with($filename, '.php')) {
            return true;
        }

        $normalized = str_replace('\\', '/', $filename);

        return str_contains($normalized, '/lib/');
    }
}
