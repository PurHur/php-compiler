--TEST--
IntlTimeZone getRawOffset/getDSTSavings/useDaylightTime/getDisplayName/toDateTimeZone (#20769)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlTimeZone withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
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
?>
--EXPECT--
methods=1
raw=3600000
dst=3600000
use=1
short=CET
long_gmt=GMT+01:00
dtz=Europe/Berlin
region=DE
canon=Europe/Berlin
utc_raw=0
utc_dst=0
utc_use=0
same=1
offset_ok=1 raw=3600000 dst=0
