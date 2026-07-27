<?php
/**
 * Repro #23707 — DateInterval::__construct Zend stub named duration.
 * php-src: ext/date/php_date.stub.php
 */
$r = new ReflectionMethod('DateInterval', '__construct');
$names = [];
foreach ($r->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'params=', implode(',', $names), "\n";
try {
    $i = new DateInterval(duration: 'P2D');
    echo 'duration_named=', $i->d, "\n";
} catch (Throwable $e) {
    echo 'duration_fail ', get_class($e), ':', $e->getMessage(), "\n";
}
$i2 = new DateInterval('P2D');
echo 'positional=', $i2->d, "\n";
try {
    new DateInterval(spec: 'P1D');
    echo "legacy_spec_ok\n";
} catch (Throwable $e) {
    echo 'legacy=', $e->getMessage(), "\n";
}
