--TEST--
AOT: json_encode(DateInterval) Zend wire (#14144 peer)
--FILE--
<?php
$di = new DateInterval('P1D');
echo json_encode($di), "\n";
$start = new DateTime('2020-01-01 00:00:00', new DateTimeZone('UTC'));
$end = new DateTime('2020-01-03 00:00:00', new DateTimeZone('UTC'));
echo json_encode($start->diff($end)), "\n";
--EXPECT--
{"y":0,"m":0,"d":1,"h":0,"i":0,"s":0,"f":0,"invert":0,"days":false,"from_string":false}
{"y":0,"m":0,"d":2,"h":0,"i":0,"s":0,"f":0,"invert":0,"days":2,"from_string":false}
--EXPECT_EXIT--
0
