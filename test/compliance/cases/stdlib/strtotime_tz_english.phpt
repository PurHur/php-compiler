--TEST--
stdlib strtotime() timezone suffix and English absolute dates (#11793, ext/date/lib/parse_date.re)
--FILE--
<?php
echo strtotime('2020-06-15 UTC'), "\n";
echo strtotime('2020-01-01T00:00:00+00:00'), "\n";
echo strtotime('15 June 2020'), "\n";
echo strtotime('June 15, 2020'), "\n";
$dt = date_create('2020-06-15 UTC');
echo $dt === false ? 'false' : (string) $dt->getTimestamp(), "\n";
?>
--EXPECT--
1592179200
1577836800
1592179200
1592179200
1592179200
