<?php
/**
 * Repro #26937 — AOT DatePeriod::createFromISO8601String() foreach snapshot.
 *
 * Requires PHP_COMPILER_PROFILE=8.4 (method is 8.4+).
 *
 * Snapshot stamps compileTimeDatePeriodTimestamps so thin-AOT foreach does not
 * take the Iterator rewind path (segfault). format() under PROFILE=8.4 remains
 * a separate NestedJIT gap — this repro asserts class/count only.
 *
 *   PHP_COMPILER_PROFILE=8.4 PHP_COMPILER_HELPER_RUNTIME_O=0 \
 *     ./phpc build -o /tmp/dp_iso test/repro/issue_26937_dateperiod_iso8601_foreach_aot.php
 *   /tmp/dp_iso
 */
$p = DatePeriod::createFromISO8601String('R2/2024-01-01T00:00:00Z/P1D');
$n = 0;
foreach ($p as $d) {
    echo get_class($d), "\n";
    ++$n;
}
echo $n, "\n";
