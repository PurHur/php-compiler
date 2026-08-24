--TEST--
AOT: empty array before DateTime / inline DatePeriod foreach must not clobber append (#34461)
--FILE--
<?php
$out = [];
$p = new DateTimeImmutable('2020-01-01');
$out[] = 'x';
echo count($out), "\n";
$dates = [];
foreach (new DatePeriod(new DateTimeImmutable('2020-01-01'), new DateInterval('P1D'), new DateTimeImmutable('2020-01-05')) as $d) {
    $dates[] = $d->format('Y-m-d');
}
echo implode(',', $dates), "\n";
--EXPECT--
1
2020-01-01,2020-01-02,2020-01-03,2020-01-04
