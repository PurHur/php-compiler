--TEST--
DateTime::diff()/date_diff() DST span uses elapsed hours (#30970)
--FILE--
<?php
$tz = new DateTimeZone('America/New_York');
$a = new DateTime('2024-03-10 01:00:00', $tz);
$b = new DateTime('2024-03-10 04:00:00', $tz);
$d = $a->diff($b);
echo "spring h={$d->h} days={$d->days}\n";
$proc = date_diff($a, $b);
echo "date_diff h={$proc->h}\n";
$c = new DateTime('2024-11-03 00:30:00', $tz);
$e = new DateTime('2024-11-03 02:30:00', $tz);
$df = $c->diff($e);
echo "fall h={$df->h}\n";
$btz = new DateTimeZone('Europe/Berlin');
$ba = new DateTimeImmutable('2024-03-31 01:00:00', $btz);
$bb = new DateTimeImmutable('2024-03-31 04:00:00', $btz);
$bd = $ba->diff($bb);
echo "berlin h={$bd->h}\n";
$u = new DateTime('2024-03-10 01:00:00', new DateTimeZone('UTC'));
$v = new DateTime('2024-03-10 04:00:00', new DateTimeZone('UTC'));
$du = $u->diff($v);
echo "utc h={$du->h}\n";
?>
--EXPECT--
spring h=2 days=0
date_diff h=2
fall h=3
berlin h=2
utc h=3
