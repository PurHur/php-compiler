<?php

declare(strict_types=1);

/**
 * SSOT for M2 spine paths deferred from native-link smoke (issues #1960, #2134, #2202).
 *
 * Consumed by check-selfhost-spine-coverage-sync.php and check-selfhost-spine-deferred-sync.php.
 */

/**
 * @return list<string> repo-relative inventory paths not yet in compiler_lib_spine_smoke native link
 */
function bootstrap_spine_native_link_deferred(): array
{
    return [];
}

/** Inventory paths covered by spine shims without a 1:1 literal require_once (issue #2543, #2868). */
function bootstrap_spine_shim_substitute_extra_inventory(): int
{
    return 0;
}
