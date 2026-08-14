--TEST--
JIT: DateTime(Immutable)::setMicrosecond out-of-range DateRangeError with given value (#31118)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    [new DateTime('2020-01-01'), 1000000],
    [new DateTimeImmutable('2020-01-01'), -1],
] as [$dt, $us]) {
    try {
        $dt->setMicrosecond($us);
        echo "ok\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
$ok = new DateTime('2020-01-01');
$ok->setMicrosecond(1);
echo $ok->format('u'), "\n";
--EXPECT--
DateRangeError: DateTime::setMicrosecond(): Argument #1 ($microsecond) must be between 0 and 999999, 1000000 given
DateRangeError: DateTimeImmutable::setMicrosecond(): Argument #1 ($microsecond) must be between 0 and 999999, -1 given
000001
