<?php
/**
 * Issue #21317 — strncmp/strncasecmp/strnatcmp/strnatcasecmp soft-null under PROFILE=8.4.
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_strncmp_family_null_forward84.php
 */
set_error_handler(static function (int $no, string $msg): bool {
    return E_DEPRECATED === $no;
});

$cases = [
    ['strncmp', static fn () => strncmp(null, 'a', 1), -1],
    ['strncasecmp', static fn () => strncasecmp(null, 'a', 1), -1],
    ['strnatcmp', static fn () => strnatcmp(null, 'a'), -1],
    ['strnatcasecmp', static fn () => strnatcasecmp(null, 'a'), -1],
];

foreach ($cases as [$label, $fn, $expect]) {
    try {
        $r = $fn();
        echo $label, ':', ($r === $expect ? 'OK' : 'BAD '.var_export($r, true)), ' ', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $label, ':', get_class($e), ' ', $e->getMessage(), "\n";
    }
}
