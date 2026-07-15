--TEST--
date DateTime::__construct() with DateTimeZone under declare(strict_types=1) (#18920, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);

$tz = new DateTimeZone('Europe/London');
$dt = new DateTime('2020-06-21 12:00:00', $tz);
echo 'offset=', $dt->getOffset(), PHP_EOL;

$dti = new DateTimeImmutable('2020-06-21 12:00:00', $tz);
echo 'immutable_offset=', $dti->getOffset(), PHP_EOL;
?>
--EXPECT--
offset=3600
immutable_offset=3600
