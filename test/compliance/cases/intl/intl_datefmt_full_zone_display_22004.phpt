--TEST--
IntlDateFormatter FULL zzzz long zone names + IntlTimeZone DISPLAY_LONG (#22004)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlDateFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$fmt = new IntlDateFormatter(
    'en_US',
    IntlDateFormatter::FULL,
    IntlDateFormatter::FULL,
    'America/New_York',
    IntlDateFormatter::GREGORIAN
);
echo 'ny_winter=', $fmt->format(strtotime('2020-01-15 12:00:00 America/New_York')), "\n";
echo 'ny_summer=', $fmt->format(strtotime('2020-07-15 12:00:00 America/New_York')), "\n";

$fmtUtc = new IntlDateFormatter(
    'en_US',
    IntlDateFormatter::FULL,
    IntlDateFormatter::FULL,
    'UTC',
    IntlDateFormatter::GREGORIAN
);
echo 'utc=', $fmtUtc->format(strtotime('2020-01-15 12:00:00 UTC')), "\n";

$tz = IntlTimeZone::createTimeZone('America/New_York');
echo 'disp_std=', $tz->getDisplayName(false, IntlTimeZone::DISPLAY_LONG), "\n";
echo 'disp_dst=', $tz->getDisplayName(true, IntlTimeZone::DISPLAY_LONG), "\n";
echo 'disp_gen=', $tz->getDisplayName(false, IntlTimeZone::DISPLAY_LONG_GENERIC), "\n";
$utc = IntlTimeZone::createTimeZone('UTC');
echo 'utc_long=', $utc->getDisplayName(false, IntlTimeZone::DISPLAY_LONG), "\n";

$pos = 0;
$parsed = $fmt->parse($fmt->format(strtotime('2020-01-15 12:00:00 America/New_York')), $pos);
echo 'parse=', (int) $parsed, ' pos=', $pos, "\n";
?>
--EXPECT--
ny_winter=Wednesday, January 15, 2020 at 12:00:00 PM Eastern Standard Time
ny_summer=Wednesday, July 15, 2020 at 12:00:00 PM Eastern Daylight Time
utc=Wednesday, January 15, 2020 at 12:00:00 PM Coordinated Universal Time
disp_std=Eastern Standard Time
disp_dst=Eastern Daylight Time
disp_gen=Eastern Time
utc_long=Coordinated Universal Time
parse=1579107600 pos=66
