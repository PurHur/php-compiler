--TEST--
date() / getdate() / localtime() / idate() / strftime() use default timezone civil fields (#31047)
--FILE--
<?php
date_default_timezone_set('America/New_York');
$ts = 1721059200; // 2024-07-15 12:00:00 EDT
echo date('Y-m-d H:i:s T I', $ts), "\n";
echo getdate($ts)['hours'], "\n";
$lt = localtime($ts, true);
echo $lt['tm_hour'], ' ', $lt['tm_isdst'], "\n";
echo idate('H', $ts), ' ', idate('I', $ts), "\n";
echo strftime('%H:%M %Z', $ts), "\n";
echo gmdate('Y-m-d H:i:s', $ts), "\n";
date_default_timezone_set('America/New_York');
$winter = 1705334400; // 2024-01-15 12:00:00 EST
echo date('Y-m-d H:i:s T I', $winter), "\n";
?>
--EXPECT--
2024-07-15 12:00:00 EDT 1
12
12 1
12 1
12:00 EDT
2024-07-15 16:00:00
2024-01-15 12:00:00 EST 0
