--TEST--
stdlib DatePeriod::createFromISO8601String Reflection + named args (#27923, ext/date/php_date.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$rf = new ReflectionMethod(DatePeriod::class, 'createFromISO8601String');
echo 'arity=', $rf->getNumberOfParameters(), "\n";
echo 'ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : 'NONE', "\n";
$parts = [];
foreach ($rf->getParameters() as $param) {
    $parts[] = $param->getName()
        .':opt='.($param->isOptional() ? '1' : '0')
        .':'.($param->hasType() ? (string) $param->getType() : '-')
        .':def='.($param->isDefaultValueAvailable() ? json_encode($param->getDefaultValue()) : '-');
}
echo 'params=', implode(',', $parts), "\n";

$p = DatePeriod::createFromISO8601String(specification: 'R1/2024-01-01T00:00:00Z/P1D');
$dates = [];
foreach ($p as $d) {
    $dates[] = $d->format('Y-m-d');
}
echo 'named=', implode(',', $dates), "\n";

$p2 = DatePeriod::createFromISO8601String(
    specification: 'R1/2024-01-01T00:00:00Z/P1D',
    options: DatePeriod::EXCLUDE_START_DATE
);
$dates2 = [];
foreach ($p2 as $d) {
    $dates2[] = $d->format('Y-m-d');
}
echo 'named_opts=', implode(',', $dates2), "\n";
--EXPECT--
arity=2
ret=static
params=specification:opt=0:string:def=-,options:opt=1:int:def=0
named=2024-01-01,2024-01-02
named_opts=2024-01-02
