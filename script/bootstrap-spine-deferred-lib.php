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

/**
 * Inventory prefixes intentionally deferred from the M2 spine bundle.
 *
 * When inventory regeneration runs ahead of the curated spine, we defer whole
 * families of helper units (issue #1922) until the next M2/M3 batch pulls them in.
 *
 * @return list<string>
 */
function bootstrap_spine_inventory_ahead_deferred_prefixes(): array
{
    return [
        // ext/* is inventory SSOT, but the M2 spine bundle only pulls a curated subset.
        // Defer the rest of ext/standard until a dedicated M2 stdlib batch absorbs it (#1922, #1945).
        'ext/standard/',
    ];
}

function bootstrap_spine_is_inventory_ahead_deferred(string $rel): bool
{
    foreach (bootstrap_spine_inventory_ahead_deferred_prefixes() as $prefix) {
        if (str_starts_with($rel, $prefix)) {
            return true;
        }
    }

    return false;
}

/** Inventory paths covered by spine shims without a 1:1 literal require_once (issue #2543, #2868). */
function bootstrap_spine_shim_substitute_extra_inventory(): int
{
    return 0;
}
