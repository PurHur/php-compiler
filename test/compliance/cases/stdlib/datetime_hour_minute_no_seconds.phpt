--TEST--
stdlib DateTime parses Y-m-d H:i without seconds (#25309, php-src-strict)
--FILE--
<?php
echo (new DateTime('2020-01-15 12:00'))->format('Y-m-d H:i:s'), "\n";
echo (new DateTime('2020-01-15T12:00'))->format('Y-m-d H:i:s'), "\n";
echo (new DateTime('2020-01-15T12:00:00'))->format('Y-m-d H:i:s'), "\n";
echo (new DateTime('2020-01-15 12:00:30'))->format('Y-m-d H:i:s'), "\n";
echo (new DateTimeImmutable('2020-01-15T12:00'))->format('Y-m-d H:i:s'), "\n";
echo date_create('2020-01-15 12:00')->format('Y-m-d H:i:s'), "\n";
?>
--EXPECT--
2020-01-15 12:00:00
2020-01-15 12:00:00
2020-01-15 12:00:00
2020-01-15 12:00:30
2020-01-15 12:00:00
2020-01-15 12:00:00
