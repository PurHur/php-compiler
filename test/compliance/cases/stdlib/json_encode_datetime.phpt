--TEST--
stdlib json_encode() DateTime/DateTimeImmutable/DateTimeZone Zend wire (#14143)
--FILE--
<?php
$dt = new DateTime('2020-01-01 00:00:00', new DateTimeZone('UTC'));
echo json_encode($dt), "\n";
echo json_encode(new DateTimeImmutable('2020-01-01 00:00:00', new DateTimeZone('UTC'))), "\n";
echo json_encode(new DateTimeZone('UTC')), "\n";
?>
--EXPECT--
{"date":"2020-01-01 00:00:00.000000","timezone_type":3,"timezone":"UTC"}
{"date":"2020-01-01 00:00:00.000000","timezone_type":3,"timezone":"UTC"}
{"timezone_type":3,"timezone":"UTC"}
