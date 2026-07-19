<?php
$tz = IntlTimeZone::createTimeZone('Europe/Berlin');
echo 'methods=', (int) method_exists($tz, 'getRawOffset'), "\n";
echo 'raw=', $tz->getRawOffset(), "\n";
echo 'dst=', $tz->getDSTSavings(), "\n";
echo 'use=', (int) $tz->useDaylightTime(), "\n";
echo 'short=', $tz->getDisplayName(false, IntlTimeZone::DISPLAY_SHORT), "\n";
echo 'long_gmt=', $tz->getDisplayName(false, IntlTimeZone::DISPLAY_LONG_GMT), "\n";
$dtz = $tz->toDateTimeZone();
echo 'dtz=', ($dtz instanceof DateTimeZone) ? $dtz->getName() : 'false', "\n";
echo 'region=', IntlTimeZone::getRegion('Europe/Berlin'), "\n";
echo 'canon=', IntlTimeZone::getCanonicalID('Europe/Berlin'), "\n";
$utc = IntlTimeZone::createTimeZone('UTC');
echo 'utc_raw=', $utc->getRawOffset(), "\n";
echo 'utc_dst=', $utc->getDSTSavings(), "\n";
echo 'utc_use=', (int) $utc->useDaylightTime(), "\n";
echo 'same=', (int) $tz->hasSameRules(IntlTimeZone::createTimeZone('Europe/Berlin')), "\n";
$raw = 0;
$dst = 0;
$ok = $tz->getOffset(1705312800000.0, false, $raw, $dst); // 2024-01-15 UTC
echo 'offset_ok=', (int) $ok, ' raw=', $raw, ' dst=', $dst, "\n";
