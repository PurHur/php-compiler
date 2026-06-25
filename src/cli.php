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

if (!function_exists('php_compiler_cli_note_invocation_cwd')) {
    /**
     * Remember the process cwd before bin/*.php chdir to the repo root (#1770, #586).
     *
     * Relative script paths (e.g. MiniWebApp `public/index.php`) resolve against this directory.
     */
    function php_compiler_cli_note_invocation_cwd(): void
    {
        $cwd = getcwd();
        if (!is_string($cwd) || '' === $cwd) {
            return;
        }
        putenv('PHP_COMPILER_CLI_INVOCATION_CWD='.$cwd);
        $_ENV['PHP_COMPILER_CLI_INVOCATION_CWD'] = $cwd;
        $_SERVER['PHP_COMPILER_CLI_INVOCATION_CWD'] = $cwd;
    }
}

if (!function_exists('php_compiler_cli_standard_input_code_filename')) {
    /** Virtual filename for stdin bundles (Zend sapi/cli, issue #4374). */
    function php_compiler_cli_standard_input_code_filename(): string
    {
        return 'Standard input code';
    }
}

if (!function_exists('php_compiler_cli_command_line_code_filename')) {
    /** Virtual filename for {@code php -r} snippets (Zend sapi/cli, issue #11533). */
    function php_compiler_cli_command_line_code_filename(): string
    {
        return 'Command line code';
    }
}

if (!function_exists('php_compiler_cli_is_virtual_code_filename')) {
    /** True when {@param $path} is a synthetic eval/stdin label, not a filesystem path. */
    function php_compiler_cli_is_virtual_code_filename(string $path): bool
    {
        return '' === $path
            || '-' === $path
            || php_compiler_cli_standard_input_code_filename() === $path
            || 'Command line code' === $path;
    }
}

if (!function_exists('php_compiler_cli_apply_ini_overrides')) {
    /**
     * Apply Zend-style {@code -d name=value} overrides to the compiled VM context (#11558).
     *
     * @param array<string, string> $overrides
     */
    function php_compiler_cli_apply_ini_overrides(\PHPCompiler\VM\Context $ctx, array $overrides): void
    {
        foreach ($overrides as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            \PHPCompiler\ext\standard\VmIni::set($ctx, $key, $value);
        }
    }
}

if (!function_exists('php_compiler_cli_resolve_user_path')) {
    /**
     * Resolve a user-supplied relative path against the pre-chdir invocation cwd.
     */
    function php_compiler_cli_resolve_user_path(string $path): string
    {
        if (php_compiler_cli_is_virtual_code_filename($path)) {
            return $path;
        }
        if ('/' === $path[0]) {
            return $path;
        }
        if (
            strlen($path) >= 2
            && ctype_alpha($path[0])
            && (':' === $path[1] || (strlen($path) >= 3 && ':' === $path[2] && ('\\' === $path[1] || '/' === $path[1])))
        ) {
            return $path;
        }
        $base = getenv('PHP_COMPILER_CLI_INVOCATION_CWD');
        if (!is_string($base) || '' === $base) {
            return $path;
        }

        return rtrim($base, '/\\').'/'.$path;
    }
}
