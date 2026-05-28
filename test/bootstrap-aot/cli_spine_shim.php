<?php

declare(strict_types=1);

/**
 * Spine-safe cli entry shim for src/cli.php (issues #1467, #2868).
 *
 * Substitutes for src/cli.php in compiler_lib_spine_smoke so the bundle does not pull
 * llvm-env/macro argv chains during self-host link; src/cli_driver.php is literal (#2868).
 */

if (!\function_exists('php_compiler_cli_should_skip_entry_driver')) {
    function php_compiler_cli_should_skip_entry_driver(): bool
    {
        return true;
    }
}
