--TEST--
stdlib: PDO::getAvailableDrivers / pdo_drivers excess argc ArgumentCountError (#30994)
--FILE--
<?php
foreach ([
    'method' => static fn () => PDO::getAvailableDrivers(1),
    'proc' => static fn () => pdo_drivers(1),
] as $name => $call) {
    try {
        $r = $call();
        echo $name, ' ', is_array($r) ? 'array('.count($r).')' : var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
$ok = PDO::getAvailableDrivers();
$ok2 = pdo_drivers();
echo 'ok=', (is_array($ok) && is_array($ok2)) ? '1' : '0', "\n";
--EXPECT--
method ArgumentCountError: PDO::getAvailableDrivers() expects exactly 0 arguments, 1 given
proc ArgumentCountError: pdo_drivers() expects exactly 0 arguments, 1 given
ok=1
