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
    // ThinAot alternates (PregJitHelperThinAot, NetworkServicesNameLookupThinAot) are
    // literal require_once lines in compiler_lib_spine_smoke alongside their full peers
    // (#24115, #27103). NestedJIT selects the thin unit at runtime; both inventory paths
    // are spine-covered — nothing remains deferred for native-link accounting (#2202).
    return [];
}

/** Inventory paths covered by spine shims without a 1:1 literal require_once (issue #2543, #2868). */
function bootstrap_spine_shim_substitute_extra_inventory(): int
{
    // Inventory paths covered by spine shims (not a 1:1 require_once in the spine bundle).
    // Keep in sync with script/check-selfhost-spine-coverage-sync.php `$spineSubstitutes`.
    // 0 when every Phase A file has a literal require_once in compiler_lib_spine_smoke
    // (7721/7721, Aug 2026 — ThinAot alternates included, #2202).
    return 0;
}
