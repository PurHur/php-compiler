--TEST--
timezone_location/offset/transitions_get Reflection named object (issue #24360, php_date.stub.php)
--FILE--
<?php
$tz = timezone_open('UTC');
$dt = new DateTime('2020-01-01', $tz);
foreach (['timezone_location_get', 'timezone_offset_get', 'timezone_transitions_get'] as $f) {
    $names = [];
    foreach ((new ReflectionFunction($f))->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $f, ':', implode(',', $names), "\n";
}
echo is_array(timezone_location_get(object: $tz)) ? "loc_ok\n" : "loc_bad\n";
echo timezone_offset_get(object: $tz, datetime: $dt), "\n";
$t = timezone_transitions_get(object: $tz, timestampBegin: 0, timestampEnd: 86400);
echo 'trans=', is_array($t) ? count($t) : 'bad', "\n";
try {
    timezone_transitions_get(timestamp_begin: 0, timestamp_end: 86400, object: $tz);
    echo "legacy_ok\n";
} catch (Throwable $e) {
    echo 'legacy=', $e->getMessage(), "\n";
}
?>
--EXPECT--
timezone_location_get:object
timezone_offset_get:object,datetime
timezone_transitions_get:object,timestampBegin,timestampEnd
loc_ok
0
trans=1
legacy=Unknown named parameter $timestamp_begin
