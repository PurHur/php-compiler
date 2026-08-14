--TEST--
DateTime add/sub DateInterval time units elapsed across DST (#31050)
--FILE--
<?php
date_default_timezone_set('America/New_York');
$a = new DateTime('2024-03-10 01:00:00');
$b = clone $a;
$b->add(new DateInterval('PT2H'));
echo $b->format('Y-m-d H:i T'), "\n";
$c = new DateTimeImmutable('2024-03-10 01:00:00');
echo $c->add(new DateInterval('PT2H'))->format('Y-m-d H:i T'), "\n";
$d = new DateTime('2024-03-10 01:00:00');
$d->sub(new DateInterval('PT2H'));
echo $d->format('Y-m-d H:i T'), "\n";
?>
--EXPECT--
2024-03-10 04:00 EDT
2024-03-10 04:00 EDT
2024-03-09 23:00 EST
