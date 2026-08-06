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

if (!function_exists('php_compiler_cli_server_script_name')) {
    /**
     * $_SERVER['SCRIPT_NAME'] for CLI drivers (Zend sapi/cli, issue #17574).
     *
     * @param array<string, mixed> $options
     */
    function php_compiler_cli_server_script_name(string $filename, array $options): ?string
    {
        $fromOpt = $options['--script-name'] ?? null;
        if (is_string($fromOpt) && '' !== $fromOpt) {
            return $fromOpt;
        }
        if (php_compiler_cli_is_virtual_code_filename($filename)) {
            return php_compiler_cli_standard_input_code_filename();
        }

        return $filename;
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
            if (\PHPCompiler\ext\standard\VmIni::applyStartupIniOverride($key, $value)) {
                continue;
            }
            \PHPCompiler\ext\standard\VmIni::set($ctx, $key, $value);
        }
    }
}

if (!function_exists('php_compiler_cli_sync_host_error_reporting')) {
    /**
     * Inherit host {@code php -d error_reporting=...} into the guest VM when argv has no override (#19848).
     *
     * Guest {@see \PHPCompiler\VM\ErrorReporter::defaultStartupReporting()} clears
     * {@code E_DEPRECATED} on ≤8.3 / unset profiles (#4842); PROFILE≥8.4 matches Zend 8.4 E_ALL.
     * Compliance uses host {@code -d error_reporting=0} (#2055) and must keep that guest default —
     * only sync when the host level enables deprecations (e.g. {@code E_ALL}).
     *
     * @param array<string, mixed> $options
     */
    function php_compiler_cli_sync_host_error_reporting(\PHPCompiler\VM\Context $ctx, array $options): void
    {
        $overrides = $options['-d'] ?? null;
        if (is_array($overrides) && array_key_exists('error_reporting', $overrides)) {
            return;
        }
        $raw = getenv('PHP_COMPILER_CLI_HOST_ERROR_REPORTING');
        if (false === $raw || '' === $raw) {
            return;
        }
        $hostLevel = (int) $raw;
        if (0 === ($hostLevel & \PHPCompiler\VM\ErrorReporter::E_DEPRECATED)) {
            return;
        }
        \PHPCompiler\ext\standard\VmIni::set($ctx, 'error_reporting', (string) $hostLevel);
    }
}

if (!function_exists('php_compiler_cli_host_cmdline_has_dash_d')) {
    /**
     * True when the host PHP process was started with {@code -d <key>=...} (#28061 / #23408).
     *
     * Distro php.ini (e.g. Ubuntu production {@code zend.exception_ignore_args=On}) must not
     * override the guest compiled default (php-src {@code "0"}); only an explicit host {@code -d}
     * is mirrored so {@code php -d zend.exception_ignore_args=0 bin/vm.php} keeps working.
     */
    function php_compiler_cli_host_cmdline_has_dash_d(string $iniKey): bool
    {
        $cmdline = @file_get_contents('/proc/self/cmdline');
        if (false === $cmdline || '' === $cmdline) {
            return false;
        }
        $args = explode("\0", $cmdline);
        $want = strtolower($iniKey).'=';
        $n = count($args);
        for ($i = 0; $i < $n; ++$i) {
            $arg = $args[$i];
            if ('-d' === $arg) {
                $next = $args[$i + 1] ?? '';
                if (is_string($next) && 0 === strncasecmp($next, $want, strlen($want))) {
                    return true;
                }
                continue;
            }
            if (is_string($arg) && str_starts_with($arg, '-d') && strlen($arg) > 2) {
                $rest = substr($arg, 2);
                if (0 === strncasecmp($rest, $want, strlen($want))) {
                    return true;
                }
            }
        }

        return false;
    }
}

if (!function_exists('php_compiler_cli_sync_host_exception_ignore_args')) {
    /**
     * Inherit host {@code php -d zend.exception_ignore_args=...} into the guest VM (#23408 / #28061).
     *
     * Guest argv {@code bin/vm.php -d zend.exception_ignore_args=0} wins via
     * {@see php_compiler_cli_apply_ini_overrides}. Host php.ini alone is ignored so the guest
     * keeps php-src's compiled default Off (SensitiveParameter getTrace args present).
     *
     * @param array<string, mixed> $options
     */
    function php_compiler_cli_sync_host_exception_ignore_args(\PHPCompiler\VM\Context $ctx, array $options): void
    {
        $overrides = $options['-d'] ?? null;
        if (is_array($overrides)) {
            foreach ($overrides as $key => $_) {
                if (is_string($key) && 0 === strcasecmp($key, 'zend.exception_ignore_args')) {
                    return;
                }
            }
        }
        if (!php_compiler_cli_host_cmdline_has_dash_d('zend.exception_ignore_args')) {
            return;
        }
        $raw = @\ini_get('zend.exception_ignore_args');
        if (false === $raw) {
            return;
        }
        \PHPCompiler\ext\standard\VmIni::set($ctx, 'zend.exception_ignore_args', (string) $raw);
    }
}

if (!function_exists('php_compiler_cli_sync_host_exception_string_param_max_len')) {
    /**
     * Inherit host {@code php -d zend.exception_string_param_max_len=...} into the guest VM (#24486 / #28061).
     *
     * Guest argv {@code bin/vm.php -d zend.exception_string_param_max_len=0} wins via
     * {@see php_compiler_cli_apply_ini_overrides}. Host php.ini alone is ignored so the guest
     * keeps php-src's compiled default 15 (Ubuntu production sets 0).
     *
     * @param array<string, mixed> $options
     */
    function php_compiler_cli_sync_host_exception_string_param_max_len(\PHPCompiler\VM\Context $ctx, array $options): void
    {
        $overrides = $options['-d'] ?? null;
        if (is_array($overrides)) {
            foreach ($overrides as $key => $_) {
                if (is_string($key) && 0 === strcasecmp($key, 'zend.exception_string_param_max_len')) {
                    return;
                }
            }
        }
        if (!php_compiler_cli_host_cmdline_has_dash_d('zend.exception_string_param_max_len')) {
            return;
        }
        $raw = @\ini_get('zend.exception_string_param_max_len');
        if (false === $raw) {
            return;
        }
        \PHPCompiler\ext\standard\VmIni::set($ctx, 'zend.exception_string_param_max_len', (string) $raw);
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

if (!function_exists('php_compiler_cli_usage_error')) {
    /** Unknown CLI option — mirror Zend sapi/cli exit code 1 (issue #18691). */
    function php_compiler_cli_usage_error(string $message): never
    {
        fwrite(STDERR, rtrim($message, "\n")."\n");
        exit(1);
    }
}

if (!function_exists('php_compiler_cli_print_version')) {
    /** Minimal -v/--version banner for bin/* entrypoints (issue #18691, sapi/cli/php_cli.c). */
    function php_compiler_cli_print_version(): void
    {
        $profile = \PHPCompiler\CompilerVersion::reportedPhpVersion();
        $host = PHP_VERSION;
        $sapi = PHP_SAPI;
        echo "PHP Compiler {$profile} (host PHP {$host} ({$sapi}))\n";
    }
}

if (!function_exists('php_compiler_cli_user_script_argv_tail')) {
    /**
     * Trailing argv slice for user scripts — strips a leading "--" separator (Zend CLI parity, #4139, #15070).
     *
     * @param list<string> $argv
     *
     * @return list<string>
     */
    function php_compiler_cli_user_script_argv_tail(array $argv, int $startIndex): array
    {
        $rest = array_slice($argv, $startIndex);
        if (isset($rest[0]) && '--' === $rest[0]) {
            $rest = array_slice($rest, 1);
        }

        return array_values(array_map('strval', $rest));
    }
}
