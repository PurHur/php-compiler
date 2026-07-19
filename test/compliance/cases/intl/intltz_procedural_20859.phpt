--TEST--
intltz_* procedural aliases for IntlTimeZone (#20859)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlTimeZone withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$names = [
    'intltz_get_gmt',
    'intltz_create_time_zone',
    'intltz_get_id',
    'intltz_get_display_name',
    'intltz_get_raw_offset',
    'intltz_get_dst_savings',
    'intltz_create_default',
    'intltz_from_date_time_zone',
    'intltz_to_date_time_zone',
    'intltz_get_canonical_id',
    'intltz_get_region',
];
foreach ($names as $name) {
    echo $name, '=', function_exists($name) ? 'yes' : 'no', "\n";
}

$gmt = intltz_get_gmt();
echo 'gmt_id=', intltz_get_id($gmt), "\n";

$tz = intltz_create_time_zone('Europe/Berlin');
echo 'id=', intltz_get_id($tz), "\n";
echo 'raw=', intltz_get_raw_offset($tz), "\n";
echo 'dst=', intltz_get_dst_savings($tz), "\n";
echo 'short=', intltz_get_display_name($tz, false, IntlTimeZone::DISPLAY_SHORT), "\n";
echo 'long_gmt=', intltz_get_display_name($tz, false, IntlTimeZone::DISPLAY_LONG_GMT), "\n";

$dtz = intltz_to_date_time_zone($tz);
echo 'dtz=', ($dtz instanceof DateTimeZone) ? $dtz->getName() : 'false', "\n";

$from = intltz_from_date_time_zone(new DateTimeZone('UTC'));
echo 'from_id=', intltz_get_id($from), "\n";

echo 'region=', intltz_get_region('Europe/Berlin'), "\n";
echo 'canon=', intltz_get_canonical_id('Europe/Berlin'), "\n";

$def = intltz_create_default();
echo 'default_obj=', ($def instanceof IntlTimeZone) ? 'yes' : 'no', "\n";
?>
--EXPECT--
intltz_get_gmt=yes
intltz_create_time_zone=yes
intltz_get_id=yes
intltz_get_display_name=yes
intltz_get_raw_offset=yes
intltz_get_dst_savings=yes
intltz_create_default=yes
intltz_from_date_time_zone=yes
intltz_to_date_time_zone=yes
intltz_get_canonical_id=yes
intltz_get_region=yes
gmt_id=GMT
id=Europe/Berlin
raw=3600000
dst=3600000
short=CET
long_gmt=GMT+01:00
dtz=Europe/Berlin
from_id=UTC
region=DE
canon=Europe/Berlin
default_obj=yes
