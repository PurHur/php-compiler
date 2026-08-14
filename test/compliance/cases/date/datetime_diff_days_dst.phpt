--TEST--
DateTime diff DateInterval days calendar count across DST (#31055)
--FILE--
<?php
$tz = new DateTimeZone('America/New_York');
$a = new DateTime('2026-03-07 12:00:00', $tz);
$b = new DateTime('2026-03-09 12:00:00', $tz);
$d = $a->diff($b);
echo "span48 d={$d->d} h={$d->h} days={$d->days}\n";
$c = new DateTime('2026-03-07 12:00:00', $tz);
$e = new DateTime('2026-03-08 12:00:00', $tz);
$df = $c->diff($e);
echo "span24 d={$df->d} h={$df->h} days={$df->days}\n";
$u = new DateTime('2026-03-07 12:00:00', new DateTimeZone('UTC'));
$v = new DateTime('2026-03-09 12:00:00', new DateTimeZone('UTC'));
$du = $u->diff($v);
echo "utc48 d={$du->d} h={$du->h} days={$du->days}\n";
?>
--EXPECT--
span48 d=2 h=0 days=2
span24 d=1 h=0 days=1
utc48 d=2 h=0 days=2
