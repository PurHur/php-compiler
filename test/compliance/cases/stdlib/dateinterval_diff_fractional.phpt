--TEST--
stdlib DateTime::diff()/date_diff() populate DateInterval::$f (fractional seconds) (#26693, ext/date/php_date.c)
--FILE--
<?php
$a = new DateTime('2020-01-01 00:00:00.000000');
$b = new DateTime('2020-01-01 00:00:00.500000');
$i = $a->diff($b);
echo 'same_second f=', $i->f, ' s=', $i->s, "\n";

$a2 = new DateTime('2020-01-01 00:00:00.750000');
$b2 = new DateTime('2020-01-01 00:00:01.250000');
$i2 = $a2->diff($b2);
echo 'cross_second f=', $i2->f, ' s=', $i2->s, "\n";

$a3 = new DateTime('2020-01-01 00:00:00.250000');
$b3 = new DateTime('2020-01-01 00:00:00.100000');
$i3 = $a3->diff($b3);
echo 'invert f=', $i3->f, ' s=', $i3->s, ' invert=', $i3->invert, "\n";

$imm = new DateTimeImmutable('2020-01-01 00:00:00.000000');
$imm2 = new DateTimeImmutable('2020-01-01 00:00:00.500000');
$i4 = $imm->diff($imm2);
echo 'immutable f=', $i4->f, ' s=', $i4->s, "\n";

$i5 = date_diff($a, $b);
echo 'date_diff f=', $i5->f, ' s=', $i5->s, "\n";

$a6 = new DateTime('2020-01-01 00:00:59.900000');
$b6 = new DateTime('2020-01-01 00:01:00.100000');
$i6 = $a6->diff($b6);
echo 'borrow f=', $i6->f, ' s=', $i6->s, ' i=', $i6->i, "\n";
?>
--EXPECT--
same_second f=0.5 s=0
cross_second f=0.5 s=0
invert f=0.15 s=0 invert=1
immutable f=0.5 s=0
date_diff f=0.5 s=0
borrow f=0.2 s=0 i=0
