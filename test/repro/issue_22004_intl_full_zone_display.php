<?php
/**
 * #22004 — IntlDateFormatter FULL time style emits ICU long zone names (zzzz),
 * not Olson IDs. Also IntlTimeZone::getDisplayName(DISPLAY_LONG).
 */
$fmt = new IntlDateFormatter(
    'en_US',
    IntlDateFormatter::FULL,
    IntlDateFormatter::FULL,
    'America/New_York',
    IntlDateFormatter::GREGORIAN
);
echo $fmt->format(strtotime('2020-01-15 12:00:00 America/New_York')), "\n";
echo $fmt->format(strtotime('2020-07-15 12:00:00 America/New_York')), "\n";

$fmtUtc = new IntlDateFormatter(
    'en_US',
    IntlDateFormatter::FULL,
    IntlDateFormatter::FULL,
    'UTC',
    IntlDateFormatter::GREGORIAN
);
echo $fmtUtc->format(strtotime('2020-01-15 12:00:00 UTC')), "\n";

$tz = IntlTimeZone::createTimeZone('America/New_York');
echo $tz->getDisplayName(false, IntlTimeZone::DISPLAY_LONG), "\n";
echo $tz->getDisplayName(true, IntlTimeZone::DISPLAY_LONG), "\n";
$utc = IntlTimeZone::createTimeZone('UTC');
echo $utc->getDisplayName(false, IntlTimeZone::DISPLAY_LONG), "\n";
