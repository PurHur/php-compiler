--TEST--
stdlib date_sunrise()/date_sunset()/localtime() inline strtotime() matches variable form (re-#11338, #17937, ext/date/php_date.c)
--FILE--
<?php
$ts = strtotime('2026-07-11');
$varLocal = localtime($ts, true)['tm_mday'] ?? null;
$nestedLocal = localtime(strtotime('2026-07-11'), true)['tm_mday'] ?? null;
echo ($varLocal === $nestedLocal ? 'localtime_match' : 'localtime_mismatch'), "\n";

$varSunrise = date_sunrise($ts, SUNFUNCS_RET_STRING, 40.7, -74.0, 90, 1);
$nestedSunrise = date_sunrise(strtotime('2026-07-11'), SUNFUNCS_RET_STRING, 40.7, -74.0, 90, 1);
echo (\is_string($varSunrise) && $varSunrise === $nestedSunrise ? 'sunrise_match' : 'sunrise_mismatch'), "\n";
echo preg_match('/^\d{2}:\d{2}$/', (string) $nestedSunrise) ? "sunrise_hhmm\n" : "sunrise_bad\n";

$varSunset = date_sunset($ts, SUNFUNCS_RET_STRING, 40.7, -74.0, 90, 1);
$nestedSunset = date_sunset(strtotime('2026-07-11'), SUNFUNCS_RET_STRING, 40.7, -74.0, 90, 1);
echo (\is_string($varSunset) && $varSunset === $nestedSunset ? 'sunset_match' : 'sunset_mismatch'), "\n";
--EXPECT--
localtime_match
sunrise_match
sunrise_hhmm
sunset_match
