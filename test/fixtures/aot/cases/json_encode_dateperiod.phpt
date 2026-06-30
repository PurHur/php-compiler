--TEST--
AOT: json_encode(DatePeriod) Zend wire (#14144)
--FILE--
<?php
$start = new DateTime('2020-01-01 00:00:00', new DateTimeZone('UTC'));
$interval = new DateInterval('P1D');
$period = new DatePeriod($start, $interval, 3);
echo json_encode($period), "\n";
--EXPECT--
{"start":{"date":"2020-01-01 00:00:00.000000","timezone_type":3,"timezone":"UTC"},"current":null,"end":null,"interval":{"y":0,"m":0,"d":1,"h":0,"i":0,"s":0,"f":0,"invert":0,"days":false,"from_string":false},"recurrences":4,"include_start_date":true,"include_end_date":false}
--EXPECT_EXIT--
0
