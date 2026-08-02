--TEST--
intltz_* procedurals after OOP count/windows/enum/DST (#20925)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlTimeZone withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$names = [
    'intltz_count_equivalent_ids',
    'intltz_get_equivalent_id',
    'intltz_get_windows_id',
    'intltz_get_id_for_windows_id',
    'intltz_create_enumeration',
    'intltz_create_time_zone_id_enumeration',
    'intltz_get_unknown',
    'intltz_get_utc', // never in php-src (#26745)
    'intltz_get_gmt',
    'intltz_get_tz_data_version',
    'intltz_use_daylight_time',
    'intltz_has_same_rules',
    'intltz_get_error_code',
    'intltz_get_error_message',
    'intltz_get_offset',
];
foreach ($names as $name) {
    echo $name, '=', function_exists($name) ? 'yes' : 'no', "\n";
}

$paris = intltz_create_time_zone('Europe/Paris');
echo 'dst=', intltz_use_daylight_time($paris) ? 'yes' : 'no', "\n";
echo 'oop_dst=', $paris->useDaylightTime() ? 'yes' : 'no', "\n";

$count = intltz_count_equivalent_ids('Europe/Paris');
echo 'equiv_count=', $count, "\n";
echo 'oop_equiv_count=', IntlTimeZone::countEquivalentIDs('Europe/Paris'), "\n";
echo 'equiv0=', intltz_get_equivalent_id('Europe/Paris', 0), "\n";

echo 'windows=', intltz_get_windows_id('Europe/Paris'), "\n";
echo 'windows_round=', intltz_get_id_for_windows_id('Romance Standard Time'), "\n";

$gmt = intltz_get_gmt();
echo 'gmt=', intltz_get_id($gmt), "\n";
$unknown = intltz_get_unknown();
echo 'unknown=', intltz_get_id($unknown), "\n";
echo 'tzdata_len=', strlen(intltz_get_tz_data_version()) > 0 ? 'gt0' : '0', "\n";

$enum = intltz_create_enumeration();
echo 'enum_iter=', (int) ($enum instanceof IntlIterator), "\n";
$ids = iterator_to_array($enum);
echo 'enum_gt100=', (int) (count($ids) > 100), "\n";

$idEnum = intltz_create_time_zone_id_enumeration(IntlTimeZone::TYPE_ANY);
echo 'idenum_iter=', (int) ($idEnum instanceof IntlIterator), "\n";

$same = intltz_has_same_rules($paris, intltz_create_time_zone('Europe/Paris'));
echo 'same_rules=', $same ? 'yes' : 'no', "\n";
echo 'err=', intltz_get_error_code($gmt), "\n";
echo 'errmsg=', intltz_get_error_message($gmt), "\n";

$raw = 0;
$dstOff = 0;
$ok = intltz_get_offset($paris, 1719835200000.0, false, $raw, $dstOff);
echo 'offset_ok=', $ok ? 'yes' : 'no', "\n";
echo 'raw_ms=', $raw, "\n";
echo 'dst_ms=', $dstOff, "\n";
?>
--EXPECT--
intltz_count_equivalent_ids=yes
intltz_get_equivalent_id=yes
intltz_get_windows_id=yes
intltz_get_id_for_windows_id=yes
intltz_create_enumeration=yes
intltz_create_time_zone_id_enumeration=yes
intltz_get_unknown=yes
intltz_get_utc=no
intltz_get_gmt=yes
intltz_get_tz_data_version=yes
intltz_use_daylight_time=yes
intltz_has_same_rules=yes
intltz_get_error_code=yes
intltz_get_error_message=yes
intltz_get_offset=yes
dst=yes
oop_dst=yes
equiv_count=1
oop_equiv_count=1
equiv0=Europe/Paris
windows=Romance Standard Time
windows_round=Europe/Paris
gmt=GMT
unknown=Etc/Unknown
tzdata_len=gt0
enum_iter=1
enum_gt100=1
idenum_iter=1
same_rules=yes
err=0
errmsg=U_ZERO_ERROR
offset_ok=yes
raw_ms=3600000
dst_ms=3600000
