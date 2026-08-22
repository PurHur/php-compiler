--TEST--
AOT: foreach (new DatePeriod(...)) + format matches Zend (#33744)
--FILE--
<?php
$out = [];
foreach (new DatePeriod(new DateTimeImmutable('2020-01-01'), new DateInterval('P1D'), new DateTimeImmutable('2020-01-05')) as $d) {
    $out[] = $d->format('Y-m-d');
}
echo implode(',', $out), "\n";
--EXPECT--
2020-01-01,2020-01-02,2020-01-03,2020-01-04
