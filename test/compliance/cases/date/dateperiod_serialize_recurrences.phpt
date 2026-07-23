--TEST--
Stdlib: serialize(DatePeriod end-date) recurrences matches Zend i:1 (#22463, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);

$p = new DatePeriod(new DateTime('2024-01-01'), new DateInterval('P1D'), new DateTime('2024-01-03'));
$s = serialize($p);
if (!preg_match('/recurrences";i:(-?\d+)/', $s, $m)) {
    echo "NO_MATCH\n";
    exit(1);
}
echo 'recurrences=', $m[1], "\n";

$p2 = unserialize($s);
echo 'getRecurrences=', var_export($p2->getRecurrences(), true), "\n";
echo 'end=', $p2->getEndDate()->format('Y-m-d'), "\n";
$n = 0;
foreach ($p2 as $d) {
    echo 'item=', $d->format('Y-m-d'), "\n";
    $n++;
}
echo 'count=', $n, "\n";

// Recurrence-count form still exports user+1 (#22463 peer).
$p3 = new DatePeriod(new DateTime('2024-01-01'), new DateInterval('P1D'), 3);
preg_match('/recurrences";i:(-?\d+)/', serialize($p3), $m3);
echo 'count_form=', $m3[1], "\n";
--EXPECT--
recurrences=1
getRecurrences=NULL
end=2024-01-03
item=2024-01-01
item=2024-01-02
count=2
count_form=4
