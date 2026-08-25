--TEST--
AOT: unserialize DateTime named zone format(c)/getOffset (#34614)
--FILE--
<?php
$u = unserialize(serialize(new DateTime('2020-01-15 12:30:45', new DateTimeZone('Europe/Berlin'))));
echo $u->format('c'), ' ', $u->getOffset(), "\n";
--EXPECT--
2020-01-15T12:30:45+01:00 3600
