<?php

declare(strict_types=1);

/**
 * Spine-safe cli entry shim for src/cli.php (issues #1467, #2868).
 *
 * src/cli_driver.php is a literal require_once in compiler_lib_spine_smoke; this shim
 * only supplies php_compiler_cli_should_skip_entry_driver() for src/cli.php coverage.
 */

if (!\function_exists('php_compiler_cli_should_skip_entry_driver')) {
    function php_compiler_cli_should_skip_entry_driver(): bool
    {
        return true;
    }
}
