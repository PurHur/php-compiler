<?php
/** Repro #25228 — localtime tm_yday (ICU 1-based) + unset clock from wall clock. */
$f = new IntlDateFormatter('en_US', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', null, 'yyyy-MM-dd');

$before = time();
$off = 0;
$r = $f->localtime('2024-07-15', $off);
$after = time();
echo 'jul15_yday=', $r['tm_yday'], "\n";
echo 'jul15_date=', $r['tm_mday'], '/', $r['tm_mon'], '/', $r['tm_year'], "\n";

$got = sprintf('%02d:%02d:%02d', $r['tm_hour'], $r['tm_min'], $r['tm_sec']);
$clockOk = false;
for ($t = $before; $t <= $after + 1; $t++) {
    if (gmdate('H:i:s', $t) === $got) {
        $clockOk = true;
        break;
    }
}
echo 'jul15_clock_from_now=', $clockOk ? 'yes' : 'no', " hms=$got\n";

$off = 0;
$r2 = $f->localtime('2024-01-01', $off);
echo 'jan1_yday=', $r2['tm_yday'], "\n";

$f2 = new IntlDateFormatter('en_US', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', null, 'yyyy-MM-dd HH:mm:ss');
$off = 0;
$r3 = $f2->localtime('2024-07-15 00:00:00', $off);
echo 'with_time_yday=', $r3['tm_yday'], ' h=', $r3['tm_hour'], "\n";
