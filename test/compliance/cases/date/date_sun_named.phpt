--TEST--
date_sun_info/sunrise/sunset Reflection named timestamp (issue #24363, php_date.stub.php)
--FILE--
<?php
foreach (['date_sun_info', 'date_sunrise', 'date_sunset'] as $f) {
    $names = [];
    foreach ((new ReflectionFunction($f))->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $f, ':', implode(',', $names), "\n";
}
$ts = 1577836800;
echo is_array(date_sun_info(timestamp: $ts, latitude: 31.5, longitude: 34.75)) ? "ts_ok\n" : "ts_bad\n";
try {
    date_sun_info(time: $ts, latitude: 31.5, longitude: 34.75);
    echo "time_should_fail\n";
} catch (Throwable $e) {
    echo "time_rejected\n";
}
echo date_sunrise(timestamp: $ts, returnFormat: SUNFUNCS_RET_STRING, latitude: 31.5, longitude: 34.75), "\n";
try {
    date_sunrise(time: $ts, format: SUNFUNCS_RET_STRING, latitude: 31.5, longitude: 34.75);
    echo "legacy_ok\n";
} catch (Throwable $e) {
    echo "legacy_rejected\n";
}
?>
--EXPECT--
date_sun_info:timestamp,latitude,longitude
date_sunrise:timestamp,returnFormat,latitude,longitude,zenith,utcOffset
date_sunset:timestamp,returnFormat,latitude,longitude,zenith,utcOffset
ts_ok
time_rejected
04:38
legacy_rejected
