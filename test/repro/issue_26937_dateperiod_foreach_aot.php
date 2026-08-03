<?php
/**
 * Repro #26937 — AOT DatePeriod ctor foreach must not segfault.
 *
 *   PHP_COMPILER_HELPER_RUNTIME_O=0 ./phpc build -o /tmp/dp test/repro/issue_26937_dateperiod_foreach_aot.php
 *   /tmp/dp
 *
 * Note: DatePeriod::createFromISO8601String() foreach under PROFILE=8.4 remains a
 * separate thin-AOT snapshot gap (factory return lacks compileTimeDatePeriodTimestamps).
 */
$start = new DateTime('2024-01-01');
$period = new DatePeriod($start, new DateInterval('P1D'), 2);
foreach ($period as $d) {
    echo $d->format('Y-m-d'), "\n";
}
