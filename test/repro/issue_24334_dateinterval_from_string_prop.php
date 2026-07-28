<?php
// #24334 — DateInterval from_string/date_string: direct prop Undefined; (array) still exposes.
$j = DateInterval::createFromDateString('last day of next month');
set_error_handler(static fn ($n, $s) => (print "warn:$s\n") || true);
$v = $j->from_string;
restore_error_handler();
echo 'from_string=', var_export($v, true), ' isset=', var_export(isset($j->from_string), true), "\n";
$cast = (array) $j;
echo 'cast=', var_export($cast['from_string'] ?? null, true), "\n";
