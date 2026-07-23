--TEST--
Stdlib: unserialize(DatePeriod) restores nested DateTime for format/foreach (#22447, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);

$p = new DatePeriod(new DateTime('2024-01-01'), new DateInterval('P1D'), new DateTime('2024-01-03'));
echo 'class=', get_class($p), "\n";
$p2 = unserialize(serialize($p));
echo 'class=', get_class($p2), "\n";
echo 'start=', $p2->start->format('Y-m-d'), "\n";
$n = 0;
foreach ($p2 as $d) {
    echo 'item=', $d->format('Y-m-d'), "\n";
    $n++;
}
echo 'count=', $n, "\n";

// Nested DateTime inside an array must also initialize (#22447 materializer path).
$bag = unserialize(serialize(['dt' => new DateTimeImmutable('2024-06-15')]));
echo 'nested=', $bag['dt']->format('Y-m-d'), "\n";
--EXPECT--
class=DatePeriod
class=DatePeriod
start=2024-01-01
item=2024-01-01
item=2024-01-02
count=2
nested=2024-06-15
