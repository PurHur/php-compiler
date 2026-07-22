<?php
$p = new DatePeriod(new DateTimeImmutable("2024-01-01"), new DateInterval("P1D"), new DateTimeImmutable("2024-01-04"));
echo "method=", method_exists($p, "getIterator") ? "Y" : "N", PHP_EOL;
echo "IA=", $p instanceof IteratorAggregate ? "Y" : "N", PHP_EOL;
echo "I=", $p instanceof Iterator ? "Y" : "N", PHP_EOL;
echo "traversable=", $p instanceof Traversable ? "Y" : "N", PHP_EOL;
$n = 0;
foreach ($p as $d) { $n++; }
echo "foreach=", $n, PHP_EOL;
$it = $p->getIterator();
echo "class=", get_class($it), PHP_EOL;
$n2 = 0;
foreach ($it as $d) { $n2++; }
echo "iter_foreach=", $n2, PHP_EOL;
