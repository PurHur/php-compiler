<?php

declare(strict_types=1);

/**
 * SSOT for Phase A inventory paths excluded from M2 ratio math only (issues #2536, #2543).
 *
 * These files remain on the bin/vm.php dependency closure and in docs/bootstrap-inventory.md.
 * They are not omitted from the inventory scan — only from the Phase A file count used in
 * spine progress ratios (see bootstrap_phase_a_inventory_counts()).
 *
 * `lib/JIT/Builtin/StringPregMatch.php` is intentionally **not** deferred: it is bundled in
 * compiler_lib_spine_smoke and native-links today (external clang for preg_match bitcode is
 * permitted native floor per docs/self-host-target.md).
 */
function bootstrap_phase_a_ratio_deferred(): array
{
    return [
        'lib/VM/HashTable.php' => 'spine bundles lib/JIT/Builtin/Type/HashTable.php via ArrayIterator',
    ];
}

/**
 * @return list<string> repo-relative paths
 */
function bootstrap_phase_a_ratio_deferred_paths(): array
{
    $paths = array_keys(bootstrap_phase_a_ratio_deferred());
    sort($paths, SORT_STRING);

    return $paths;
}

/**
 * @param array<string, mixed> $inventoryReport from bootstrapCollectInventoryReport()
 *
 * @return array{vm_path_files: int, phase_a_inventory_files: int, phase_a_ratio_deferred: int}
 */
function bootstrap_phase_a_inventory_counts(array $inventoryReport): array
{
    $vmPathFiles = (int) ($inventoryReport['totals']['files'] ?? 0);
    $deferred = bootstrap_phase_a_ratio_deferred_paths();
    $deferredPresent = 0;
    foreach ($deferred as $rel) {
        if (isset($inventoryReport['files'][$rel])) {
            ++$deferredPresent;
        }
    }

    return [
        'vm_path_files' => $vmPathFiles,
        'phase_a_inventory_files' => max(0, $vmPathFiles - $deferredPresent),
        'phase_a_ratio_deferred' => $deferredPresent,
    ];
}
