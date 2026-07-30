--TEST--
stdlib DatePeriod::__construct Zend stub named params (#25164, ext/date/php_date.stub.php)
--FILE--
<?php
declare(strict_types=1);

$s = new DateTime('2024-01-01');
$i = new DateInterval('P1D');
$e = new DateTime('2024-01-04');
$p = new DatePeriod(start: $s, interval: $i, end: $e);
$n = 0;
foreach ($p as $_) {
    ++$n;
}
echo 'named_end=', $n, "\n";

$rf = new ReflectionMethod('DatePeriod', '__construct');
$parts = [];
foreach ($rf->getParameters() as $param) {
    $parts[] = $param->getName()
        .':opt='.($param->isOptional() ? '1' : '0')
        .':'.($param->hasType() ? (string) $param->getType() : '-')
        .':def='.($param->isDefaultValueAvailable() ? json_encode($param->getDefaultValue()) : '-');
}
echo 'params=', implode(',', $parts), "\n";

try {
    new DatePeriod(start: $s, interval: $i, recur: 2);
    echo "recur_ok\n";
} catch (Throwable $ex) {
    echo 'recur: ', $ex->getMessage(), "\n";
}
--EXPECT--
named_end=3
params=start:opt=0:-:def=-,interval:opt=1:-:def=-,end:opt=1:-:def=-,options:opt=1:-:def=-
recur: Unknown named parameter $recur
