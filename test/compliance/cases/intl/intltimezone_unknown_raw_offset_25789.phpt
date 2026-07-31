--TEST--
IntlTimeZone Etc/Unknown getRawOffset/getDSTSavings silent (#25789)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlTimeZone withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
error_reporting(E_ALL);
$tz = IntlTimeZone::createTimeZone('NoSuch/Zone');
echo 'id=', $tz->getID(), "\n";
echo 'raw=', $tz->getRawOffset(), "\n";
echo 'dst=', $tz->getDSTSavings(), "\n";
echo 'use=', (int) $tz->useDaylightTime(), "\n";
$raw = -1;
$dst = -1;
$ok = $tz->getOffset(0.0, false, $raw, $dst);
echo 'off=', (int) $ok, ' raw=', $raw, ' dst=', $dst, "\n";
$u = IntlTimeZone::getUnknown();
echo 'unknown_raw=', $u->getRawOffset(), "\n";
echo 'unknown_dst=', $u->getDSTSavings(), "\n";
?>
--EXPECT--
id=Etc/Unknown
raw=0
dst=3600000
use=0
off=1 raw=0 dst=0
unknown_raw=0
unknown_dst=3600000
