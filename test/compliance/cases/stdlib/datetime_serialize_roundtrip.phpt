--TEST--
stdlib DateTime serialize/unserialize Zend wire (#10710)
--FILE--
<?php
$dt = new DateTime('2020-01-01 00:00:00', new DateTimeZone('UTC'));
echo serialize($dt), "\n";
$round = unserialize(serialize($dt));
echo $round->format('Y-m-d'), "\n";
$zend = 'O:8:"DateTime":3:{s:4:"date";s:26:"2020-01-01 00:00:00.000000";s:13:"timezone_type";i:3;s:8:"timezone";s:3:"UTC";}';
$fromZend = unserialize($zend);
echo $fromZend->format('Y-m-d'), "\n";
?>
--EXPECT--
O:8:"DateTime":3:{s:4:"date";s:26:"2020-01-01 00:00:00.000000";s:13:"timezone_type";i:3;s:8:"timezone";s:3:"UTC";}
2020-01-01
2020-01-01
