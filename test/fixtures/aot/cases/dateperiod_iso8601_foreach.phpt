--TEST--
AOT: DatePeriod::createFromISO8601String foreach + format (#26937, #27192)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$p = DatePeriod::createFromISO8601String('R2/2024-01-01T00:00:00Z/P1D');
$n = 0;
foreach ($p as $d) {
    echo get_class($d), ' ', $d->format('Y-m-d'), "\n";
    ++$n;
}
echo $n, "\n";
--EXPECT--
DateTimeImmutable 2024-01-01
DateTimeImmutable 2024-01-02
DateTimeImmutable 2024-01-03
3
