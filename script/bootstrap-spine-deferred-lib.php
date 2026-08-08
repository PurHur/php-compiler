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
    return [
        // Alternate PregJitHelper for thin standalone AOT (#24115) — same FQCN as
        // PregJitHelper.php. NestedJIT loads this file instead of the full helper; a
        // literal spine require alongside the full unit fatals under Zend (redeclare)
        // and would emit duplicate symbols on a full spine AOT link.
        'ext/standard/PregJitHelperThinAot.php',
        // Alternate NetworkServicesNameLookupJitHelper for thin standalone AOT (#27103)
        // — same FQCN as NetworkServicesNameLookupJitHelper.php (peer of Preg ThinAot).
        'ext/standard/NetworkServicesNameLookupThinAot.php',
        // Huge nested array literal — top-level spine require dies at bundle scale with
        // Undefined constant "defined" in JIT CONST_FETCH (re-#16866). Standalone and
        // method-body require still compile; un-defer when #29111 lands. (#28998)
        'ext/standard/TimezoneAbbreviationsData.php',
    ];
}

/** Inventory paths covered by spine shims without a 1:1 literal require_once (issue #2543, #2868). */
function bootstrap_spine_shim_substitute_extra_inventory(): int
{
    // Inventory paths covered by spine shims (not a 1:1 require_once in the spine bundle).
    // Keep in sync with script/check-selfhost-spine-coverage-sync.php `$spineSubstitutes`.
    // 0 when every Phase A file has a literal require_once or is listed in
    // bootstrap_spine_native_link_deferred() (7249/7252 + 3 deferred, Aug 2026).
    return 0;
}
