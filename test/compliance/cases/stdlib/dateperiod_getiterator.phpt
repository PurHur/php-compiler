--TEST--
stdlib DatePeriod::getIterator() InternalIterator + IteratorAggregate (#22263, ext/date/php_date.c)
--FILE--
<?php
$p = new DatePeriod(
    new DateTimeImmutable('2024-01-01'),
    new DateInterval('P1D'),
    new DateTimeImmutable('2024-01-04')
);
echo method_exists($p, 'getIterator') ? "method=Y\n" : "method=N\n";
echo ($p instanceof IteratorAggregate) ? "IA=Y\n" : "IA=N\n";
echo ($p instanceof Iterator) ? "I=Y\n" : "I=N\n";
echo ($p instanceof Traversable) ? "T=Y\n" : "T=N\n";
$n = 0;
foreach ($p as $d) {
    ++$n;
}
echo "foreach=$n\n";
$it = $p->getIterator();
echo get_class($it), "\n";
echo ($it instanceof Iterator) ? "it_I=Y\n" : "it_I=N\n";
$n2 = 0;
foreach ($it as $d) {
    ++$n2;
}
echo "iter_foreach=$n2\n";
$dates = [];
foreach ($p as $d) {
    $dates[] = $d->format('Y-m-d');
}
echo implode(',', $dates), "\n";
?>
--EXPECT--
method=Y
IA=Y
I=N
T=Y
foreach=3
InternalIterator
it_I=Y
iter_foreach=3
2024-01-01,2024-01-02,2024-01-03
