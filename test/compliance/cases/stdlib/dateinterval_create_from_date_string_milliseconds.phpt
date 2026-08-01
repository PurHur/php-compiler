--TEST--
stdlib DateInterval::createFromDateString() millisecond/microsecond units → $f (#26694, ext/date/php_date.c)
--FILE--
<?php
$i = DateInterval::createFromDateString('500 milliseconds');
echo 'ms s=', $i->s, ' f=', $i->f, "\n";

$j = date_interval_create_from_date_string('250 milliseconds');
echo 'fn s=', $j->s, ' f=', $j->f, "\n";

$k = DateInterval::createFromDateString('1 second 500 milliseconds');
echo 'combo s=', $k->s, ' f=', $k->f, "\n";

$u = DateInterval::createFromDateString('500 microseconds');
echo 'us s=', $u->s, ' f=', $u->f, "\n";

$m = DateInterval::createFromDateString('2 msec');
echo 'msec s=', $m->s, ' f=', $m->f, "\n";

$neg = DateInterval::createFromDateString('-500 milliseconds');
echo 'neg s=', $neg->s, ' f=', $neg->f, ' invert=', $neg->invert, "\n";

$bad = @DateInterval::createFromDateString('500 widgets');
echo 'bad=', false === $bad ? 'false' : 'ok', "\n";
?>
--EXPECT--
ms s=0 f=0.5
fn s=0 f=0.25
combo s=1 f=0.5
us s=0 f=0.0005
msec s=0 f=0.002
neg s=0 f=-0.5 invert=0
bad=false
