<?php
/**
 * Repro #31047 — procedural date family uses default TZ civil fields.
 *
 *   php bin/vm.php test/repro/issue_31047_date_default_tz_civil.php
 *   php bin/jit.php test/repro/issue_31047_date_default_tz_civil.php
 */
date_default_timezone_set('America/New_York');
$ts = 1721059200;
echo 'date=', date('Y-m-d H:i:s e T I Z', $ts), "\n";
echo 'gmdate=', gmdate('Y-m-d H:i:s', $ts), "\n";
$gd = getdate($ts);
echo 'getdate_hours=', $gd['hours'], "\n";
$lt = localtime($ts, true);
echo 'localtime_hour=', $lt['tm_hour'], ' isdst=', $lt['tm_isdst'], "\n";
echo 'idate_H=', idate('H', $ts), ' idate_I=', idate('I', $ts), "\n";
echo 'strftime=', strftime('%H:%M %Z', $ts), "\n";
