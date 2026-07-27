--TEST--
stdlib DateInterval::__construct Zend stub duration named param (#23707, ext/date/php_date.stub.php)
--FILE--
<?php
declare(strict_types=1);

$r = new ReflectionMethod('DateInterval', '__construct');
$names = [];
foreach ($r->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'params=', implode(',', $names), "\n";

$i = new DateInterval(duration: 'P2D');
echo 'duration_named=', $i->d, "\n";
$i2 = new DateInterval('P2D');
echo 'positional=', $i2->d, "\n";

try {
    new DateInterval(spec: 'P1D');
    echo "legacy_ok\n";
} catch (Throwable $e) {
    echo 'legacy: ', $e->getMessage(), "\n";
}
--EXPECT--
params=duration
duration_named=2
positional=2
legacy: Unknown named parameter $spec
