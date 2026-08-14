--TEST--
stdlib: DatePeriod accessors ArgumentCountError JIT (#30934)
--FILE--
<?php
$p = new DatePeriod(new DateTime('2020-01-01'), new DateInterval('P1D'), 2);
$pEnd = new DatePeriod(new DateTime('2020-01-01'), new DateInterval('P1D'), new DateTime('2020-01-03'));
foreach ([
    'interval' => static fn () => $p->getDateInterval(1),
    'start' => static fn () => $p->getStartDate(1),
    'end' => static fn () => $pEnd->getEndDate(1),
    'rec' => static fn () => $p->getRecurrences(1),
] as $name => $call) {
    try {
        $r = $call();
        echo $name, ' ', is_object($r) ? get_class($r) : var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
$okI = $p->getDateInterval();
$okS = $p->getStartDate();
$okE = $pEnd->getEndDate();
$okR = $p->getRecurrences();
echo 'ok=', (
    $okI instanceof DateInterval
    && $okS instanceof DateTime
    && $okE instanceof DateTime
    && 2 === $okR
) ? '1' : '0', "\n";
--EXPECT--
interval ArgumentCountError: DatePeriod::getDateInterval() expects exactly 0 arguments, 1 given
start ArgumentCountError: DatePeriod::getStartDate() expects exactly 0 arguments, 1 given
end ArgumentCountError: DatePeriod::getEndDate() expects exactly 0 arguments, 1 given
rec ArgumentCountError: DatePeriod::getRecurrences() expects exactly 0 arguments, 1 given
ok=1
