--TEST--
AOT: DatePeriod::createFromISO8601String foreach snapshot (#26937)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$p = DatePeriod::createFromISO8601String('R2/2024-01-01T00:00:00Z/P1D');
$n = 0;
foreach ($p as $d) {
    echo get_class($d), "\n";
    ++$n;
}
echo $n, "\n";
--EXPECT--
DateTimeImmutable
DateTimeImmutable
DateTimeImmutable
3
