<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

require_once __DIR__.'/tokenizer-compat.php';
require_once __DIR__.'/yay-php8-compat.php';
require_once __DIR__.'/llvm-env.php';

if (!function_exists('php_compiler_cli_should_skip_entry_driver')) {
    /** Skip argv driver when bundled in compiler_lib_spine_smoke (issue #1467). */
    function php_compiler_cli_should_skip_entry_driver(): bool
    {
        $lc = strtolower((string) getenv('PHP_COMPILER_CLI_SPINE_BUNDLE'));

        return '1' === $lc || 'true' === $lc;
    }
}

if (!function_exists('php_compiler_cli_should_skip_vendor_autoload')) {
    /**
     * Determine whether the CLI entry driver should avoid composer autoload.
     *
     * Self-host AOT / compiled CLI driver mode must not pull in vendor/autoload.php at runtime
     * (issue #2641). It may still be enabled explicitly for Zend-run workflows.
     */
    function php_compiler_cli_should_skip_vendor_autoload(): bool
    {
        $skipVendor = getenv('PHP_COMPILER_CLI_SKIP_VENDOR');
        if ('1' === $skipVendor || 'true' === strtolower((string) $skipVendor)) {
            return true;
        }
        if ('0' === $skipVendor || 'false' === strtolower((string) $skipVendor)) {
            return false;
        }

        $compiled = getenv('PHP_COMPILER_CLI_COMPILED');
        if ('1' === $compiled || 'true' === strtolower((string) $compiled)) {
            return true;
        }

        $selfhostAot = getenv('PHP_COMPILER_SELFHOST_AOT');
        if ('1' === $selfhostAot || 'true' === strtolower((string) $selfhostAot)) {
            return true;
        }

        // Vendor prelink bundles use literal require_once; composer autoload is not available (#2849).
        $vendorPrelink = getenv('PHP_COMPILER_VENDOR_PRELINK');

        return '1' === $vendorPrelink || 'true' === strtolower((string) $vendorPrelink);
    }
}
