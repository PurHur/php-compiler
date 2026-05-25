<?php

declare(strict_types=1);

/**
 * Spine-safe cli entry shims (issues #1467, #2050).
 *
 * Substitutes for src/cli.php + src/cli_driver.php in compiler_lib_spine_smoke so the
 * bundle does not pull vendor/autoload argv driver or Expr_Closure during self-host link.
 */

if (!\function_exists('php_compiler_cli_should_skip_entry_driver')) {
    function php_compiler_cli_should_skip_entry_driver(): bool
    {
        return true;
    }
}
