--TEST--
stdlib DateTime::__construct — embedded string + inline DateTimeZone arg (#8561, #4604)
--FILE--
<?php
$dt = new DateTime('2026-06-01 12:00:00', new DateTimeZone('UTC'));
echo $dt->format('Y-m-d'), "\n";
?>
--EXPECT--
2026-06-01
