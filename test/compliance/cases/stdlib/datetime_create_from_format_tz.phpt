--TEST--
stdlib DateTimeImmutable::createFromFormat() timezone format tokens (#11487, ext/date/php_date.c)
--FILE--
<?php
$dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s e', '2024-06-01 12:00:00 UTC');
echo $dt === false ? 'false' : $dt->format('e'), "\n";
echo $dt === false ? 'false' : $dt->format('Y-m-d H:i:s'), "\n";
$dt2 = DateTimeImmutable::createFromFormat('Y-m-d H:i:s T', '2024-06-01 12:00:00 UTC');
echo $dt2 === false ? 'false' : $dt2->format('e'), "\n";
?>
--EXPECT--
UTC
2024-06-01 12:00:00
UTC
