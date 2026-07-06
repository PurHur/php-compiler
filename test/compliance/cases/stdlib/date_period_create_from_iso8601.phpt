--TEST--
stdlib DatePeriod::createFromISO8601String() — ISO8601 period factory (issue #7296, ext/date/php_date.c)
--FILE--
<?php
var_export(method_exists(DatePeriod::class, 'createFromISO8601String'));
echo "\n";

$p = DatePeriod::createFromISO8601String('2024-01-01T00:00:00/2024-01-03T00:00:00/P1D');
foreach ($p as $d) {
    echo $d->format('Y-m-d'), "\n";
    break;
}

try {
    DatePeriod::createFromISO8601String('not-an-interval');
    echo "no_throw\n";
} catch (DateMalformedPeriodStringException $e) {
    echo str_contains($e->getMessage(), 'not-an-interval') ? "bad_spec\n" : "wrong_msg\n";
}
--EXPECT--
true
2024-01-01
bad_spec
